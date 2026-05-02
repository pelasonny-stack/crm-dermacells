# CRM Dermacells — Terraform Infrastructure

AWS sa-east-1 infrastructure for the CRM Dermacells monorepo. Manages:
- VPC (3-AZ, public + private subnets, NAT Gateways)
- Aurora PostgreSQL 16 (multi-AZ in production)
- ElastiCache Redis 7 (cluster mode disabled)
- ECS Fargate (4 services: web, horizon, reverb, scheduler)
- S3 buckets (pdfs, audit-exports with Object Lock, web-assets, tf-state)
- CloudFront PWA distribution
- Route 53 DNS + ACM certificates
- IAM roles (minimal per-service permissions)
- CloudWatch alarms (12) + SNS notifications
- Secrets Manager (`dermacells/{environment}`)

## Prerequisites

- Terraform >= 1.9.0
- AWS CLI configured with credentials for sa-east-1
- An existing Route 53 hosted zone for `dermacells.com.ar`
- The `dermacells-tf-state` S3 bucket and `dermacells-tf-lock` DynamoDB table created manually for bootstrapping (see below)

## Bootstrapping the remote state backend

The first time only, create the state resources manually:

```bash
aws s3 mb s3://dermacells-tf-state --region sa-east-1
aws s3api put-bucket-versioning --bucket dermacells-tf-state \
  --versioning-configuration Status=Enabled
aws s3api put-bucket-encryption --bucket dermacells-tf-state \
  --server-side-encryption-configuration '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"aws:kms"}}]}'
aws dynamodb create-table \
  --table-name dermacells-tf-lock \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --region sa-east-1
```

## Deploying staging

```bash
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars
# Edit terraform.tfvars — set environment=staging and real values

terraform init \
  -backend-config="bucket=dermacells-tf-state" \
  -backend-config="key=crm/staging/terraform.tfstate" \
  -backend-config="region=sa-east-1"

terraform plan -out=staging.tfplan
terraform apply staging.tfplan
```

## Deploying production

```bash
# Use a separate state key for production
terraform init \
  -backend-config="bucket=dermacells-tf-state" \
  -backend-config="key=crm/production/terraform.tfstate" \
  -backend-config="region=sa-east-1" \
  -reconfigure

# Set environment=production in tfvars
terraform plan -out=prod.tfplan -var="environment=production"
terraform apply prod.tfplan
```

## Populating secrets after first deploy

After `terraform apply`, the Secrets Manager secret is created with placeholder values.
Populate real secrets before deploying ECS services:

```bash
aws secretsmanager put-secret-value \
  --secret-id dermacells/production \
  --secret-string file://secrets-production.json \
  --region sa-east-1
```

`secrets-production.json` is a JSON file with all keys from `secrets.tf`.
Never commit this file.

## Updating ECS task definitions after image push

CI/CD handles this automatically (see `.github/workflows/release.yml`).
Manual update:

```bash
# Force new deployment with existing task definition
aws ecs update-service \
  --cluster dermacells-crm-production \
  --service web \
  --force-new-deployment \
  --region sa-east-1
```

## Key architecture decisions

| Decision | Choice | Reason |
|---|---|---|
| ECS launch type | Fargate | No EC2 fleet management; Graviton ARM64 for ~20% cost savings |
| Aurora type | Provisioned (not Serverless v2) | Predictable cost for steady 08:00-22:00 ART load |
| Redis topology | Cluster mode disabled | Laravel Horizon does not support Redis Cluster natively |
| S3 audit bucket | Object Lock COMPLIANCE 7yr | Regulatory requirement — cannot be overridden even by root |
| PWA delivery | CloudFront + S3 | Edge caching close to Argentina users; not ECS |
| Scheduler | Single ECS task (desiredCount=1) | `schedule:work` is not distributed-safe; onOneServer() in console.php |

## Destroying (staging only)

```bash
# Never destroy production without explicit approval
terraform destroy -var="environment=staging"
```
