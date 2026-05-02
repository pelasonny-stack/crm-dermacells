###############################################################################
# Aurora PostgreSQL 16 — Multi-AZ for production, single for staging
# - force_ssl=on at parameter group level
# - Automated backups: 30d retention
# - Point-In-Time Recovery enabled (inherent to Aurora)
# - Deletion protection in production
###############################################################################

resource "aws_db_subnet_group" "main" {
  name        = "${var.app_name}-${var.environment}-db-subnet-group"
  description = "Private subnets for Aurora cluster"
  subnet_ids  = aws_subnet.private[*].id

  tags = { Name = "${var.app_name}-${var.environment}-db-subnet-group" }
}

resource "aws_rds_cluster_parameter_group" "main" {
  name        = "${var.app_name}-${var.environment}-aurora-pg16"
  family      = "aurora-postgresql16"
  description = "CRM Dermacells Aurora PG16 — force SSL, performance tuning"

  parameter {
    name         = "rds.force_ssl"
    value        = "1"
    apply_method = "immediate"
  }

  parameter {
    name         = "log_statement"
    value        = "ddl"
    apply_method = "immediate"
  }

  parameter {
    name         = "log_min_duration_statement"
    value        = "1000" # log queries slower than 1 second
    apply_method = "immediate"
  }

  parameter {
    name         = "shared_preload_libraries"
    value        = "pg_stat_statements"
    apply_method = "pending-reboot"
  }

  parameter {
    name         = "pg_stat_statements.track"
    value        = "all"
    apply_method = "immediate"
  }

  tags = { Name = "${var.app_name}-${var.environment}-aurora-pg16" }
}

resource "random_password" "db_master" {
  length           = 32
  special          = true
  override_special = "!#$%&*()-_=+[]{}<>:?"
}

resource "aws_secretsmanager_secret_version" "db_master_password" {
  secret_id     = aws_secretsmanager_secret.main.id
  secret_string = jsonencode(merge(
    jsondecode(aws_secretsmanager_secret_version.main_placeholder.secret_string),
    { DB_PASSWORD = random_password.db_master.result }
  ))

  lifecycle {
    ignore_changes = [secret_string]
  }
}

resource "aws_rds_cluster" "main" {
  cluster_identifier = "${var.app_name}-${var.environment}"
  engine             = "aurora-postgresql"
  engine_version     = "16.2"

  database_name   = var.db_name
  master_username = "dermacells_master"
  master_password = random_password.db_master.result

  db_subnet_group_name            = aws_db_subnet_group.main.name
  vpc_security_group_ids          = [aws_security_group.rds.id]
  db_cluster_parameter_group_name = aws_rds_cluster_parameter_group.main.name

  # Backups
  backup_retention_period   = var.db_backup_retention_days
  preferred_backup_window   = "03:00-04:00" # ART midnight is UTC+3 — 00:00 ART = 03:00 UTC
  preferred_maintenance_window = "sun:04:30-sun:05:30"

  # Encryption
  storage_encrypted = true

  # PITR is always enabled on Aurora — deletion_protection guards against accidental drops
  deletion_protection = var.environment == "production"

  # Skip final snapshot only for staging
  skip_final_snapshot       = var.environment != "production"
  final_snapshot_identifier = var.environment == "production" ? "${var.app_name}-${var.environment}-final-snapshot" : null

  # CloudWatch logs export
  enabled_cloudwatch_logs_exports = ["postgresql"]

  tags = { Name = "${var.app_name}-${var.environment}-aurora-cluster" }
}

# Writer instance (always deployed)
resource "aws_rds_cluster_instance" "writer" {
  identifier         = "${var.app_name}-${var.environment}-writer"
  cluster_identifier = aws_rds_cluster.main.id
  instance_class     = var.db_instance_class
  engine             = aws_rds_cluster.main.engine
  engine_version     = aws_rds_cluster.main.engine_version

  db_subnet_group_name    = aws_db_subnet_group.main.name
  publicly_accessible     = false
  auto_minor_version_upgrade = true

  performance_insights_enabled          = true
  performance_insights_retention_period = 7

  monitoring_interval = 60
  monitoring_role_arn = aws_iam_role.rds_enhanced_monitoring.arn

  tags = { Name = "${var.app_name}-${var.environment}-writer" }
}

# Reader instance — deployed in a different AZ when multi_az=true (production)
resource "aws_rds_cluster_instance" "reader" {
  count = var.rds_multi_az ? 1 : 0

  identifier         = "${var.app_name}-${var.environment}-reader-0"
  cluster_identifier = aws_rds_cluster.main.id
  instance_class     = var.db_instance_class
  engine             = aws_rds_cluster.main.engine
  engine_version     = aws_rds_cluster.main.engine_version

  db_subnet_group_name    = aws_db_subnet_group.main.name
  publicly_accessible     = false
  auto_minor_version_upgrade = true

  performance_insights_enabled          = true
  performance_insights_retention_period = 7

  monitoring_interval = 60
  monitoring_role_arn = aws_iam_role.rds_enhanced_monitoring.arn

  tags = { Name = "${var.app_name}-${var.environment}-reader-0" }
}

# Enhanced monitoring IAM role
resource "aws_iam_role" "rds_enhanced_monitoring" {
  name = "${var.app_name}-${var.environment}-rds-enhanced-monitoring"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "monitoring.rds.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy_attachment" "rds_enhanced_monitoring" {
  role       = aws_iam_role.rds_enhanced_monitoring.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole"
}
