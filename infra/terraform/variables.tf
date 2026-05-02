variable "aws_region" {
  description = "AWS region for all resources"
  type        = string
  default     = "sa-east-1"
}

variable "environment" {
  description = "Deployment environment (staging | production)"
  type        = string
  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be 'staging' or 'production'."
  }
}

variable "app_name" {
  description = "Application name used in resource naming"
  type        = string
  default     = "dermacells-crm"
}

# --- Networking ---

variable "vpc_cidr" {
  description = "CIDR block for the VPC"
  type        = string
  default     = "10.0.0.0/16"
}

variable "availability_zones" {
  description = "List of 3 AZs to spread subnets across"
  type        = list(string)
  default     = ["sa-east-1a", "sa-east-1b", "sa-east-1c"]
}

variable "public_subnet_cidrs" {
  description = "CIDRs for the 3 public subnets (ALB, NAT gateway)"
  type        = list(string)
  default     = ["10.0.0.0/24", "10.0.1.0/24", "10.0.2.0/24"]
}

variable "private_subnet_cidrs" {
  description = "CIDRs for the 3 private subnets (ECS tasks, RDS, Redis)"
  type        = list(string)
  default     = ["10.0.10.0/24", "10.0.11.0/24", "10.0.12.0/24"]
}

# --- RDS Aurora ---

variable "db_instance_class" {
  description = "Aurora instance class"
  type        = string
  default     = "db.t4g.medium"
}

variable "db_name" {
  description = "Initial database name"
  type        = string
  default     = "dermacells"
}

variable "db_backup_retention_days" {
  description = "Automated backup retention period in days"
  type        = number
  default     = 30
}

variable "rds_multi_az" {
  description = "Whether to deploy the Aurora cluster as multi-AZ (true for production)"
  type        = bool
  default     = true
}

# --- ElastiCache ---

variable "redis_node_type" {
  description = "ElastiCache node type"
  type        = string
  default     = "cache.t4g.small"
}

variable "redis_num_cache_nodes" {
  description = "Number of cache nodes (1 for staging, 2 for production with failover)"
  type        = number
  default     = 1
}

# --- ECS Fargate ---

variable "web_cpu" {
  description = "vCPU units for the web (FrankenPHP) task (1024 = 1 vCPU)"
  type        = number
  default     = 1024
}

variable "web_memory" {
  description = "Memory in MiB for the web (FrankenPHP) task"
  type        = number
  default     = 2048
}

variable "web_desired_count" {
  description = "Desired number of web tasks"
  type        = number
  default     = 2
}

variable "horizon_cpu" {
  description = "vCPU units for the Horizon worker task"
  type        = number
  default     = 512
}

variable "horizon_memory" {
  description = "Memory in MiB for the Horizon worker task"
  type        = number
  default     = 1024
}

variable "horizon_desired_count" {
  description = "Desired number of Horizon worker tasks"
  type        = number
  default     = 1
}

variable "reverb_cpu" {
  description = "vCPU units for the Reverb WebSocket task"
  type        = number
  default     = 512
}

variable "reverb_memory" {
  description = "Memory in MiB for the Reverb WebSocket task"
  type        = number
  default     = 1024
}

variable "reverb_desired_count" {
  description = "Desired number of Reverb tasks"
  type        = number
  default     = 1
}

variable "scheduler_cpu" {
  description = "vCPU units for the Laravel scheduler task (runs artisan schedule:work)"
  type        = number
  default     = 256
}

variable "scheduler_memory" {
  description = "Memory in MiB for the scheduler task"
  type        = number
  default     = 512
}

# --- ECR image ---

variable "api_image_uri" {
  description = "Full ECR image URI including tag for the API (web, horizon, reverb, scheduler)"
  type        = string
}

# --- Domain ---

variable "domain_name" {
  description = "Primary domain (e.g. crm.dermacells.com.ar)"
  type        = string
  default     = "crm.dermacells.com.ar"
}

variable "staging_domain_name" {
  description = "Staging domain (e.g. staging.dermacells.com.ar)"
  type        = string
  default     = "staging.dermacells.com.ar"
}

variable "route53_zone_id" {
  description = "Route 53 hosted zone ID for dermacells.com.ar"
  type        = string
}

# --- Alerting ---

variable "alarm_email" {
  description = "Email address for CloudWatch SNS alarm notifications"
  type        = string
  default     = "ops@dermacells.com.ar"
}

variable "pagerduty_integration_key" {
  description = "PagerDuty Events API v2 integration key (stored in Secrets Manager, referenced here for reference)"
  type        = string
  sensitive   = true
  default     = ""
}
