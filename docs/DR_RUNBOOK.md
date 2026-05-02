# CRM Dermacells — Disaster Recovery Runbook

**RTO target:** 1 hour
**RPO target:** 15 minutes
**Last reviewed:** 2026-05-02
**Owner:** DevOps / SRE

---

## Table of Contents

1. [Backup Strategy](#1-backup-strategy)
2. [PITR Restore Procedure](#2-pitr-restore-procedure)
3. [Audit Log Integrity Restore](#3-audit-log-integrity-restore)
4. [ECS Rollback Procedure](#4-ecs-rollback-procedure)
5. [Full Environment Restore Checklist](#5-full-environment-restore-checklist)
6. [RTO / RPO Targets](#6-rto--rpo-targets)
7. [Contact Escalation Chain](#7-contact-escalation-chain)

---

## 1. Backup Strategy

### 1.1 RDS Aurora PostgreSQL 16

| Type | Frequency | Retention | Window |
|---|---|---|---|
| Automated daily snapshot | Daily | 30 days | 03:00-04:00 UTC |
| Pre-deploy manual snapshot | Before each production deploy | Until next deploy + 7 days | Manual |
| Point-in-Time Recovery (PITR) | Continuous | 30 days | N/A |

PITR is enabled by default on Aurora. Transaction logs are continuously streamed to S3. Recovery is possible to any second within the retention window.

**Pre-deploy snapshot command (run from CI/CD before `terraform apply` or ECS deploy):**

```bash
aws rds create-db-cluster-snapshot \
  --db-cluster-identifier dermacells-crm-production \
  --db-cluster-snapshot-identifier pre-deploy-$(date +%Y%m%d-%H%M%S) \
  --region sa-east-1
```

### 1.2 S3 Audit Log Exports (Object Lock COMPLIANCE)

Audit log dumps are exported weekly to `dermacells-crm-production-audit-exports` with:
- **Object Lock mode:** COMPLIANCE
- **Retention:** 7 years (cannot be shortened by any user, including root)
- **Encryption:** SSE-KMS

This bucket cannot be deleted or overwritten. Even a root account cannot remove COMPLIANCE-locked objects before their retention date. This satisfies Argentine regulatory requirements for commercial records.

### 1.3 S3 PDF Bucket (Xubio Invoices)

- Versioning: Enabled
- Lifecycle: Standard → Standard-IA (90d) → Glacier IR (365d)
- Cross-region replication: Not configured (single-region acceptable per risk assessment)

### 1.4 Redis (ElastiCache)

- Snapshot retention: 7 days
- Snapshot window: 05:00-06:00 UTC
- Recovery: Redis state is reconstructable from the database (queues, sessions, cache are ephemeral)
- Sessions: On Redis failure, re-login is required (Sanctum SPA cookies survive in browser)

---

## 2. PITR Restore Procedure

Use when: data corruption, accidental deletion, or a SQL bug must be reversed.

### Step 1 — Identify the target restore time

```bash
# Find the earliest restorable time
aws rds describe-db-clusters \
  --db-cluster-identifier dermacells-crm-production \
  --query 'DBClusters[0].EarliestRestorableTime' \
  --region sa-east-1
```

Note the exact UTC timestamp you want to restore to (e.g., `2026-05-02T14:30:00Z`).

### Step 2 — Restore to a new cluster

DO NOT restore over the existing cluster — restore to a new identifier first for validation.

```bash
aws rds restore-db-cluster-to-point-in-time \
  --db-cluster-identifier dermacells-crm-production-pitr-$(date +%Y%m%d) \
  --source-db-cluster-identifier dermacells-crm-production \
  --restore-to-time "2026-05-02T14:30:00Z" \
  --vpc-security-group-ids sg-XXXXXXXXXX \
  --db-subnet-group-name dermacells-crm-production-db-subnet-group \
  --region sa-east-1
```

### Step 3 — Add a DB instance to the restored cluster

```bash
aws rds create-db-instance \
  --db-instance-identifier dermacells-crm-pitr-writer \
  --db-cluster-identifier dermacells-crm-production-pitr-$(date +%Y%m%d) \
  --db-instance-class db.t4g.medium \
  --engine aurora-postgresql \
  --region sa-east-1
```

### Step 4 — Validate data on the restored cluster

Connect via psql (through a bastion or SSM session):

```bash
aws ssm start-session --target i-BASTION_ID --region sa-east-1

psql "host=<restored-cluster-endpoint> dbname=dermacells user=app_role sslmode=require"

-- Spot-check key tables
SELECT COUNT(*) FROM audit_log;
SELECT MAX(occurred_at) FROM audit_log;
SELECT COUNT(*) FROM sales WHERE status = 'confirmed';
```

### Step 5 — Switch application traffic (if validation passes)

Update the Secrets Manager secret to point to the restored cluster:

```bash
# Get current secret
aws secretsmanager get-secret-value \
  --secret-id dermacells/production \
  --region sa-east-1 \
  --query SecretString --output text > /tmp/current-secret.json

# Edit DB_HOST to the restored cluster endpoint, then:
aws secretsmanager put-secret-value \
  --secret-id dermacells/production \
  --secret-string file:///tmp/current-secret.json \
  --region sa-east-1

# Force new ECS deployments to pick up the new secret
aws ecs update-service --cluster dermacells-crm-production --service web --force-new-deployment --region sa-east-1
aws ecs update-service --cluster dermacells-crm-production --service horizon --force-new-deployment --region sa-east-1
aws ecs update-service --cluster dermacells-crm-production --service scheduler --force-new-deployment --region sa-east-1
```

### Step 6 — Verify application health

```bash
# Wait for services to stabilize
aws ecs wait services-stable \
  --cluster dermacells-crm-production \
  --services web horizon reverb \
  --region sa-east-1

# Check health endpoint
curl -f https://api.crm.dermacells.com.ar/api/v1/health
```

### Step 7 — Post-restore tasks

- Run `php artisan audit:verify` on the restored data to confirm chain integrity
- Notify affected users if data was lost between restore point and failure time
- Delete the original (corrupted) cluster only after 48-hour hold period
- Conduct a blameless post-mortem within 72 hours

---

## 3. Audit Log Integrity Restore

### 3.1 Scenario: HMAC chain mismatch detected

The `audit:verify` command (scheduled at 03:15 ART) sends a PagerDuty alert on failure.

#### Immediate triage

```bash
# Run with verbose output to find the first bad row
php artisan audit:verify 2>&1 | tail -20

# Narrow the search with --from
php artisan audit:verify --from="2026-05-01T00:00:00" --limit=10000
```

#### Determine cause

1. **Key rotation without re-hashing** — check if `AUDIT_HMAC_KEY` was changed without recomputing existing hashes. This is the most common cause.
2. **Manual UPDATE on `audit_log`** — check Postgres activity logs and CloudWatch for direct DB connections outside the app.
3. **Bug in AuditObserver** — check recent deployments that touched `AuditObserver.php`.

#### Restore from S3 audit export

If rows are confirmed tampered and cannot be repaired:

```bash
# List available exports
aws s3 ls s3://dermacells-crm-production-audit-exports/ --region sa-east-1

# Download the last clean export
aws s3 cp \
  s3://dermacells-crm-production-audit-exports/audit_log_2026-04-30.csv.gz \
  /tmp/audit_log_restore.csv.gz \
  --region sa-east-1

# Re-verify HMAC on the exported file (use the verify script in scripts/)
php artisan audit:verify-export /tmp/audit_log_restore.csv.gz
```

#### Re-hash after key rotation

If the HMAC key was legitimately rotated and all rows need re-hashing:

```bash
# ONLY run this command with Director approval and in a maintenance window
# It re-computes all row_hash and prev_hash values with the new key
php artisan audit:rehash --confirm
```

---

## 4. ECS Rollback Procedure

ECS services use rolling deployments with automatic rollback via circuit breaker. If a deploy fails health checks, ECS rolls back automatically within ~5 minutes.

### Manual rollback to previous task definition

```bash
# Find the previous task definition revision
aws ecs list-task-definitions \
  --family-prefix dermacells-crm-production-web \
  --sort DESC \
  --region sa-east-1 \
  --query 'taskDefinitionArns[:5]'

# Update service to use the previous revision (e.g., :47 instead of :48)
aws ecs update-service \
  --cluster dermacells-crm-production \
  --service web \
  --task-definition dermacells-crm-production-web:47 \
  --region sa-east-1

# Repeat for horizon, reverb, scheduler
aws ecs update-service --cluster dermacells-crm-production --service horizon --task-definition dermacells-crm-production-horizon:47 --region sa-east-1
aws ecs update-service --cluster dermacells-crm-production --service reverb  --task-definition dermacells-crm-production-reverb:47  --region sa-east-1
aws ecs update-service --cluster dermacells-crm-production --service scheduler --task-definition dermacells-crm-production-scheduler:47 --region sa-east-1
```

### Rollback database migration (if applicable)

Laravel migrations are one-directional per PLAN.md policy. If a migration must be reversed:

1. Write a new forward migration that undoes the schema change
2. Deploy the new migration via normal CI/CD
3. Never run `migrate:rollback` or `migrate:fresh` in production (CI/CD gate blocks this)

---

## 5. Full Environment Restore Checklist

For complete environment rebuild (catastrophic failure):

- [ ] Terraform state in S3 is intact (`dermacells-tf-state/crm/production/terraform.tfstate`)
- [ ] Run `terraform init && terraform apply` from `infra/terraform/`
- [ ] Restore Aurora from PITR or latest snapshot (see Section 2)
- [ ] Populate Secrets Manager with values from secure vault
- [ ] Push latest API image to ECR
- [ ] Force new ECS service deployments
- [ ] Run `php artisan migrate` via ECS exec (not `migrate:fresh`)
- [ ] Verify `audit:verify` passes on restored data
- [ ] Verify all 12 CloudWatch alarms are active
- [ ] Run k6 dashboard-flow test against restored environment
- [ ] Confirm Route 53 health checks are green
- [ ] Notify all users of any data gap

---

## 6. RTO / RPO Targets

| Failure scenario | RPO | RTO |
|---|---|---|
| Single ECS task crash | 0 (stateless) | < 2 min (ECS restarts) |
| Full ECS service failure | 0 | < 10 min (ECS circuit breaker rollback) |
| AZ failure | 0 (multi-AZ) | < 5 min (Aurora auto-failover) |
| Data corruption (accidental delete) | 15 min (PITR granularity) | 45 min |
| Full region failure | 15 min | Not covered (single-region) |
| Secrets Manager unavailable | 5 min (Redis cache) | Degraded (no new sessions) |

**Overall targets: RTO 1 hour, RPO 15 minutes.**

Full region failure is not in scope for the current DR plan. For multi-region DR, a separate runbook and infrastructure investment would be required.

---

## 7. Contact Escalation Chain

Replace placeholders before go-live.

| Role | Name | Contact | Hours |
|---|---|---|---|
| Primary On-Call | PLACEHOLDER — DevOps Lead | @oncall-devops / +54-11-XXXX-XXXX | 24/7 via PagerDuty |
| Secondary On-Call | PLACEHOLDER — Backend Lead | +54-11-XXXX-XXXX | Business hours first |
| Director (business) | PLACEHOLDER | PLACEHOLDER | Business hours |
| AWS Support | Premium Support ticket | console.aws.amazon.com/support | 24/7 |
| Aurora / RDS issues | AWS Premium Support + SA | See above | 24/7 |
| PagerDuty escalation policy | `dermacells-crm-production` | Auto-escalates after 15 min | Automatic |

### PagerDuty integration

All CloudWatch alarms route to the SNS topic `dermacells-crm-production-alarms`, which has a PagerDuty endpoint subscription configured at infrastructure provisioning time. The integration key is stored in Secrets Manager under `PAGERDUTY_INTEGRATION_KEY`.

The `AuditChainTamperingDetected` notification (sent by `app/Notifications/AuditChainTamperingDetected.php`) also triggers PagerDuty directly via the Events API v2 for immediate human response.
