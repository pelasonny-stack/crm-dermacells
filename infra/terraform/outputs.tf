# --- Networking ---

output "vpc_id" {
  description = "VPC ID"
  value       = aws_vpc.main.id
}

output "private_subnet_ids" {
  description = "IDs of the 3 private subnets"
  value       = aws_subnet.private[*].id
}

output "public_subnet_ids" {
  description = "IDs of the 3 public subnets"
  value       = aws_subnet.public[*].id
}

# --- RDS ---

output "rds_cluster_endpoint" {
  description = "Aurora cluster writer endpoint"
  value       = aws_rds_cluster.main.endpoint
  sensitive   = true
}

output "rds_cluster_reader_endpoint" {
  description = "Aurora cluster reader endpoint"
  value       = aws_rds_cluster.main.reader_endpoint
  sensitive   = true
}

output "rds_cluster_id" {
  description = "Aurora cluster identifier"
  value       = aws_rds_cluster.main.cluster_identifier
}

# --- Redis ---

output "redis_primary_endpoint" {
  description = "ElastiCache Redis primary endpoint"
  value       = aws_elasticache_replication_group.redis.primary_endpoint_address
  sensitive   = true
}

# --- ECS ---

output "ecs_cluster_name" {
  description = "ECS cluster name"
  value       = aws_ecs_cluster.main.name
}

output "ecs_cluster_arn" {
  description = "ECS cluster ARN"
  value       = aws_ecs_cluster.main.arn
}

output "alb_dns_name" {
  description = "Application Load Balancer DNS name"
  value       = aws_lb.main.dns_name
}

output "alb_zone_id" {
  description = "Application Load Balancer hosted zone ID (for Route 53 alias)"
  value       = aws_lb.main.zone_id
}

# --- S3 ---

output "s3_bucket_pdfs" {
  description = "S3 bucket for Xubio invoice PDFs"
  value       = aws_s3_bucket.pdfs.bucket
}

output "s3_bucket_audit_exports" {
  description = "S3 bucket for audit log exports (Object Lock COMPLIANCE)"
  value       = aws_s3_bucket.audit_exports.bucket
}

output "s3_bucket_web_assets" {
  description = "S3 bucket for PWA static assets"
  value       = aws_s3_bucket.web_assets.bucket
}

# --- CloudFront ---

output "cloudfront_distribution_id" {
  description = "CloudFront distribution ID for PWA"
  value       = aws_cloudfront_distribution.pwa.id
}

output "cloudfront_domain_name" {
  description = "CloudFront distribution domain name"
  value       = aws_cloudfront_distribution.pwa.domain_name
}

# --- Secrets ---

output "secrets_manager_arn" {
  description = "ARN of the main Secrets Manager secret"
  value       = aws_secretsmanager_secret.main.arn
}

# --- IAM ---

output "ecs_task_role_arn_web" {
  description = "IAM task role ARN for the web service"
  value       = aws_iam_role.ecs_task_web.arn
}

output "ecs_task_role_arn_horizon" {
  description = "IAM task role ARN for the Horizon worker"
  value       = aws_iam_role.ecs_task_horizon.arn
}

# --- CloudWatch ---

output "sns_alarm_topic_arn" {
  description = "SNS topic ARN used for CloudWatch alarms"
  value       = aws_sns_topic.alarms.arn
}
