###############################################################################
# ElastiCache Redis 7 — cluster mode DISABLED
# Single shard with optional read replica for production.
# Used by Laravel: session, cache, queues (Horizon), broadcasting (Reverb).
###############################################################################

resource "aws_elasticache_subnet_group" "main" {
  name        = "${var.app_name}-${var.environment}-redis-subnet-group"
  description = "Private subnets for ElastiCache Redis"
  subnet_ids  = aws_subnet.private[*].id

  tags = { Name = "${var.app_name}-${var.environment}-redis-subnet-group" }
}

resource "aws_elasticache_parameter_group" "redis7" {
  name        = "${var.app_name}-${var.environment}-redis7"
  family      = "redis7"
  description = "Redis 7 parameter group — optimised for Horizon queues"

  parameter {
    name  = "maxmemory-policy"
    value = "allkeys-lru"
  }

  parameter {
    name  = "notify-keyspace-events"
    value = "Ex" # Expired event notifications — useful for Sanctum token expiry hooks
  }

  tags = { Name = "${var.app_name}-${var.environment}-redis7" }
}

resource "aws_elasticache_replication_group" "redis" {
  replication_group_id = "${var.app_name}-${var.environment}-redis"
  description          = "Redis 7 for ${var.app_name} ${var.environment} — Horizon + Cache + Session"

  node_type       = var.redis_node_type
  engine_version  = "7.1"
  port            = 6379
  parameter_group_name = aws_elasticache_parameter_group.redis7.name
  subnet_group_name    = aws_elasticache_subnet_group.main.name
  security_group_ids   = [aws_security_group.redis.id]

  # Cluster mode is DISABLED — single shard topology.
  # num_cache_clusters=1 for staging, 2 for production (primary + read replica with auto-failover).
  num_cache_clusters         = var.redis_num_cache_nodes
  automatic_failover_enabled = var.redis_num_cache_nodes > 1
  multi_az_enabled           = var.redis_num_cache_nodes > 1

  # Encryption
  at_rest_encryption_enabled  = true
  transit_encryption_enabled  = true
  transit_encryption_mode     = "required"

  # Backup
  snapshot_retention_limit = 7
  snapshot_window          = "05:00-06:00" # UTC — after BCRA job and before business day

  # Maintenance
  maintenance_window          = "sun:06:00-sun:07:00"
  auto_minor_version_upgrade  = true

  # CloudWatch logs
  log_delivery_configuration {
    destination      = aws_cloudwatch_log_group.redis_slow_logs.name
    destination_type = "cloudwatch-logs"
    log_format       = "text"
    log_type         = "slow-log"
  }

  log_delivery_configuration {
    destination      = aws_cloudwatch_log_group.redis_engine_logs.name
    destination_type = "cloudwatch-logs"
    log_format       = "text"
    log_type         = "engine-log"
  }

  tags = { Name = "${var.app_name}-${var.environment}-redis" }
}

resource "aws_cloudwatch_log_group" "redis_slow_logs" {
  name              = "/aws/elasticache/${var.app_name}-${var.environment}/slow-log"
  retention_in_days = 14
}

resource "aws_cloudwatch_log_group" "redis_engine_logs" {
  name              = "/aws/elasticache/${var.app_name}-${var.environment}/engine-log"
  retention_in_days = 14
}
