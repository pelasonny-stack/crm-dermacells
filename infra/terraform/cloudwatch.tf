###############################################################################
# CloudWatch Alarms — 12 alarms total
# Coverage: ECS, RDS, Redis, ALB, CloudFront, custom metrics (queue depth,
#           AI token consumption)
#
# SNS topic → email + optional PagerDuty integration
###############################################################################

# --- SNS Topic for alarm notifications ---

resource "aws_sns_topic" "alarms" {
  name = "${var.app_name}-${var.environment}-alarms"

  tags = { Name = "${var.app_name}-${var.environment}-alarms" }
}

resource "aws_sns_topic_subscription" "email" {
  topic_arn = aws_sns_topic.alarms.arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

# --- 1. ALB 5xx Error Rate > 5% ---

resource "aws_cloudwatch_metric_alarm" "alb_5xx_rate" {
  alarm_name          = "${var.app_name}-${var.environment}-alb-5xx-rate"
  alarm_description   = "ALB 5xx error rate exceeded 5% threshold for 5 minutes"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 5
  treat_missing_data  = "notBreaching"

  metric_query {
    id          = "error_rate"
    expression  = "100*(m_5xx/m_total)"
    label       = "5xx Error Rate"
    return_data = true
  }

  metric_query {
    id = "m_5xx"
    metric {
      namespace   = "AWS/ApplicationELB"
      metric_name = "HTTPCode_Target_5XX_Count"
      period      = 60
      stat        = "Sum"
      dimensions  = { LoadBalancer = aws_lb.main.arn_suffix }
    }
  }

  metric_query {
    id = "m_total"
    metric {
      namespace   = "AWS/ApplicationELB"
      metric_name = "RequestCount"
      period      = 60
      stat        = "Sum"
      dimensions  = { LoadBalancer = aws_lb.main.arn_suffix }
    }
  }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-alb-5xx-rate" }
}

# --- 2. ALB p95 Latency > 800ms ---

resource "aws_cloudwatch_metric_alarm" "alb_p95_latency" {
  alarm_name          = "${var.app_name}-${var.environment}-alb-p95-latency"
  alarm_description   = "ALB p95 target response time exceeded 800ms"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  threshold           = 0.8 # seconds
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/ApplicationELB"
  metric_name = "TargetResponseTime"
  period      = 60
  statistic   = "p95"
  dimensions  = { LoadBalancer = aws_lb.main.arn_suffix }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-alb-p95-latency" }
}

# --- 3. ECS Web Service CPU > 80% ---

resource "aws_cloudwatch_metric_alarm" "ecs_web_cpu" {
  alarm_name          = "${var.app_name}-${var.environment}-ecs-web-cpu"
  alarm_description   = "ECS web service CPU utilization exceeded 80% for 10 minutes"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 10
  threshold           = 80
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/ECS"
  metric_name = "CPUUtilization"
  period      = 60
  statistic   = "Average"
  dimensions = {
    ClusterName = aws_ecs_cluster.main.name
    ServiceName = "web"
  }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-ecs-web-cpu" }
}

# --- 4. ECS Web Service Memory > 85% ---

resource "aws_cloudwatch_metric_alarm" "ecs_web_memory" {
  alarm_name          = "${var.app_name}-${var.environment}-ecs-web-memory"
  alarm_description   = "ECS web service memory utilization exceeded 85%"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 85
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/ECS"
  metric_name = "MemoryUtilization"
  period      = 60
  statistic   = "Average"
  dimensions = {
    ClusterName = aws_ecs_cluster.main.name
    ServiceName = "web"
  }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-ecs-web-memory" }
}

# --- 5. ECS Horizon Worker CPU > 85% ---

resource "aws_cloudwatch_metric_alarm" "ecs_horizon_cpu" {
  alarm_name          = "${var.app_name}-${var.environment}-ecs-horizon-cpu"
  alarm_description   = "ECS Horizon worker CPU exceeded 85% — queue processing may be degraded"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 10
  threshold           = 85
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/ECS"
  metric_name = "CPUUtilization"
  period      = 60
  statistic   = "Average"
  dimensions = {
    ClusterName = aws_ecs_cluster.main.name
    ServiceName = "horizon"
  }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-ecs-horizon-cpu" }
}

# --- 6. RDS Aurora Writer CPU > 70% ---

resource "aws_cloudwatch_metric_alarm" "rds_cpu" {
  alarm_name          = "${var.app_name}-${var.environment}-rds-cpu"
  alarm_description   = "Aurora writer CPU exceeded 70%"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 70
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/RDS"
  metric_name = "CPUUtilization"
  period      = 60
  statistic   = "Average"
  dimensions  = { DBClusterIdentifier = aws_rds_cluster.main.cluster_identifier }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-rds-cpu" }
}

# --- 7. RDS Free Storage < 5 GB ---

resource "aws_cloudwatch_metric_alarm" "rds_free_storage" {
  alarm_name          = "${var.app_name}-${var.environment}-rds-free-storage"
  alarm_description   = "Aurora free storage dropped below 5 GB"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 3
  threshold           = 5368709120 # 5 GB in bytes
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/RDS"
  metric_name = "FreeLocalStorage"
  period      = 300
  statistic   = "Average"
  dimensions  = { DBClusterIdentifier = aws_rds_cluster.main.cluster_identifier }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-rds-free-storage" }
}

# --- 8. Redis Memory Usage > 75% ---

resource "aws_cloudwatch_metric_alarm" "redis_memory" {
  alarm_name          = "${var.app_name}-${var.environment}-redis-memory"
  alarm_description   = "ElastiCache Redis memory usage exceeded 75%"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 75
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/ElastiCache"
  metric_name = "DatabaseMemoryUsagePercentage"
  period      = 60
  statistic   = "Average"
  dimensions  = { ReplicationGroupId = aws_elasticache_replication_group.redis.replication_group_id }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-redis-memory" }
}

# --- 9. Horizon Queue Depth > 100 (Custom Metric from Laravel) ---
# The Horizon queue depth is published as a custom CloudWatch metric by a
# background job in the application: App\Jobs\PublishHorizonMetricsJob
# It runs every minute and pushes DermaCells/App::HorizonQueueDepth.

resource "aws_cloudwatch_metric_alarm" "horizon_queue_depth" {
  alarm_name          = "${var.app_name}-${var.environment}-horizon-queue-depth"
  alarm_description   = "Horizon queue depth exceeded 100 — workers may be falling behind"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 100
  treat_missing_data  = "notBreaching"

  namespace   = "DermaCells/App"
  metric_name = "HorizonQueueDepth"
  period      = 60
  statistic   = "Maximum"
  dimensions  = { Environment = var.environment }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-horizon-queue-depth" }
}

# --- 10. AI Token Monthly Spend > 90% of Cap (Custom Metric) ---
# Published by App\Jobs\PublishHorizonMetricsJob from ai_usage table.
# Dimension: Environment + ModelProvider

resource "aws_cloudwatch_metric_alarm" "ai_token_cap" {
  alarm_name          = "${var.app_name}-${var.environment}-ai-token-cap"
  alarm_description   = "AI token monthly usage reached 90% of configured cap"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  threshold           = 90 # percentage
  treat_missing_data  = "notBreaching"

  namespace   = "DermaCells/App"
  metric_name = "AiTokenCapPercent"
  period      = 300
  statistic   = "Maximum"
  dimensions  = { Environment = var.environment }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-ai-token-cap" }
}

# --- 11. CloudFront 5xx Error Rate > 3% ---

resource "aws_cloudwatch_metric_alarm" "cloudfront_5xx" {
  alarm_name          = "${var.app_name}-${var.environment}-cloudfront-5xx"
  alarm_description   = "CloudFront PWA 5xx error rate exceeded 3%"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  threshold           = 3
  treat_missing_data  = "notBreaching"

  namespace   = "AWS/CloudFront"
  metric_name = "5xxErrorRate"
  period      = 60
  statistic   = "Average"
  dimensions = {
    DistributionId = aws_cloudfront_distribution.pwa.id
    Region         = "Global"
  }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-cloudfront-5xx" }
}

# --- 12. Audit Chain Verifier Failure (Custom Metric) ---
# The VerifyAuditChain command publishes AuditChainFailures=1 when it detects
# tampering, via App\Console\Commands\VerifyAuditChain.
# Any non-zero value here requires immediate incident response.

resource "aws_cloudwatch_metric_alarm" "audit_chain_failure" {
  alarm_name          = "${var.app_name}-${var.environment}-audit-chain-failure"
  alarm_description   = "CRITICAL: audit_log HMAC chain verification failed — possible tampering. Requires immediate incident response."
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  threshold           = 0
  treat_missing_data  = "notBreaching"

  namespace   = "DermaCells/App"
  metric_name = "AuditChainFailures"
  period      = 300
  statistic   = "Sum"
  dimensions  = { Environment = var.environment }

  alarm_actions = [aws_sns_topic.alarms.arn]
  ok_actions    = [aws_sns_topic.alarms.arn]

  tags = { Name = "${var.app_name}-${var.environment}-audit-chain-failure" }
}

# --- CloudWatch Dashboard ---

resource "aws_cloudwatch_dashboard" "main" {
  dashboard_name = "${var.app_name}-${var.environment}"

  dashboard_body = jsonencode({
    widgets = [
      {
        type = "alarm"
        properties = {
          title  = "Active Alarms"
          alarms = [
            aws_cloudwatch_metric_alarm.alb_5xx_rate.arn,
            aws_cloudwatch_metric_alarm.alb_p95_latency.arn,
            aws_cloudwatch_metric_alarm.ecs_web_cpu.arn,
            aws_cloudwatch_metric_alarm.ecs_web_memory.arn,
            aws_cloudwatch_metric_alarm.ecs_horizon_cpu.arn,
            aws_cloudwatch_metric_alarm.rds_cpu.arn,
            aws_cloudwatch_metric_alarm.rds_free_storage.arn,
            aws_cloudwatch_metric_alarm.redis_memory.arn,
            aws_cloudwatch_metric_alarm.horizon_queue_depth.arn,
            aws_cloudwatch_metric_alarm.ai_token_cap.arn,
            aws_cloudwatch_metric_alarm.cloudfront_5xx.arn,
            aws_cloudwatch_metric_alarm.audit_chain_failure.arn,
          ]
        }
      }
    ]
  })
}
