###############################################################################
# ECS Fargate — 4 services
#   1. web        — FrankenPHP serving the Laravel API (port 8000)
#   2. horizon    — Laravel Horizon queue worker (no inbound port)
#   3. reverb     — Laravel Reverb WebSocket server (port 8080)
#   4. scheduler  — artisan schedule:work (singleton, no inbound port)
#
# Launch type: FARGATE (serverless, no EC2 fleets to manage)
# Platform:    LINUX/ARM64 (Graviton — ~20% cheaper, same perf for PHP)
###############################################################################

# --- Application Load Balancer ---

resource "aws_lb" "main" {
  name               = "${var.app_name}-${var.environment}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id

  enable_deletion_protection = var.environment == "production"

  access_logs {
    bucket  = aws_s3_bucket.web_assets.bucket
    prefix  = "alb-access-logs"
    enabled = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-alb" }
}

resource "aws_lb_listener" "http_redirect" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"
    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = aws_acm_certificate_validation.api.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }
}

# WebSocket listener rule: /app/* and /reverb/* routed to Reverb target group
resource "aws_lb_listener_rule" "reverb" {
  listener_arn = aws_lb_listener.https.arn
  priority     = 10

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.reverb.arn
  }

  condition {
    path_pattern {
      values = ["/app/*", "/reverb/*"]
    }
  }
}

# --- Target Groups ---

resource "aws_lb_target_group" "web" {
  name        = "${var.app_name}-${var.environment}-web-tg"
  port        = 8000
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip" # Required for Fargate

  health_check {
    enabled             = true
    path                = "/api/v1/health"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 30
    timeout             = 10
    matcher             = "200"
  }

  deregistration_delay = 30

  tags = { Name = "${var.app_name}-${var.environment}-web-tg" }
}

resource "aws_lb_target_group" "reverb" {
  name        = "${var.app_name}-${var.environment}-reverb-tg"
  port        = 8080
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  # WebSocket health check
  health_check {
    enabled             = true
    path                = "/up"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 30
    timeout             = 10
    matcher             = "200-404" # Reverb returns 404 on plain HTTP health checks
  }

  # Stickiness required for WebSocket connections
  stickiness {
    type            = "lb_cookie"
    cookie_duration = 86400
    enabled         = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-reverb-tg" }
}

# --- ECS Cluster ---

resource "aws_ecs_cluster" "main" {
  name = "${var.app_name}-${var.environment}"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = { Name = "${var.app_name}-${var.environment}-ecs-cluster" }
}

resource "aws_ecs_cluster_capacity_providers" "main" {
  cluster_name       = aws_ecs_cluster.main.name
  capacity_providers = ["FARGATE", "FARGATE_SPOT"]

  default_capacity_provider_strategy {
    base              = 1
    weight            = 100
    capacity_provider = "FARGATE"
  }
}

# --- CloudWatch Log Groups for ECS services ---

resource "aws_cloudwatch_log_group" "web" {
  name              = "/ecs/${var.app_name}-${var.environment}/web"
  retention_in_days = 30
}

resource "aws_cloudwatch_log_group" "horizon" {
  name              = "/ecs/${var.app_name}-${var.environment}/horizon"
  retention_in_days = 30
}

resource "aws_cloudwatch_log_group" "reverb" {
  name              = "/ecs/${var.app_name}-${var.environment}/reverb"
  retention_in_days = 30
}

resource "aws_cloudwatch_log_group" "scheduler" {
  name              = "/ecs/${var.app_name}-${var.environment}/scheduler"
  retention_in_days = 30
}

# --- Task Execution Role (shared) ---

resource "aws_iam_role" "ecs_task_execution" {
  name = "${var.app_name}-${var.environment}-ecs-task-execution"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_task_execution_managed" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# Allow the execution role to read from Secrets Manager (for environment injection at launch)
resource "aws_iam_role_policy" "ecs_task_execution_secrets" {
  name = "read-secrets-at-launch"
  role = aws_iam_role.ecs_task_execution.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["secretsmanager:GetSecretValue"]
      Resource = [aws_secretsmanager_secret.main.arn]
    }]
  })
}

# --- Task Definitions ---

# Web service (FrankenPHP)
resource "aws_ecs_task_definition" "web" {
  family                   = "${var.app_name}-${var.environment}-web"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.web_cpu
  memory                   = var.web_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task_web.arn

  runtime_platform {
    cpu_architecture        = "ARM64"
    operating_system_family = "LINUX"
  }

  container_definitions = jsonencode([{
    name      = "web"
    image     = var.api_image_uri
    essential = true

    portMappings = [{
      containerPort = 8000
      hostPort      = 8000
      protocol      = "tcp"
    }]

    command = ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]

    secrets = [{
      name      = "APP_KEY"
      valueFrom = "${aws_secretsmanager_secret.main.arn}:APP_KEY::"
    }]

    environment = [
      { name = "APP_ENV", value = var.environment },
      { name = "APP_DEBUG", value = "false" },
      { name = "AWS_DEFAULT_REGION", value = var.aws_region },
      { name = "AWS_SECRETS_MANAGER_ID", value = "dermacells/${var.environment}" },
      { name = "LOG_CHANNEL", value = "stderr" },
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.web.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "web"
      }
    }

    healthCheck = {
      command     = ["CMD-SHELL", "curl -f http://localhost:8000/api/v1/health || exit 1"]
      interval    = 30
      timeout     = 10
      retries     = 3
      startPeriod = 60
    }
  }])

  tags = { Name = "${var.app_name}-${var.environment}-web-task" }
}

# Horizon worker service
resource "aws_ecs_task_definition" "horizon" {
  family                   = "${var.app_name}-${var.environment}-horizon"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.horizon_cpu
  memory                   = var.horizon_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task_horizon.arn

  runtime_platform {
    cpu_architecture        = "ARM64"
    operating_system_family = "LINUX"
  }

  container_definitions = jsonencode([{
    name      = "horizon"
    image     = var.api_image_uri
    essential = true
    command   = ["php", "artisan", "horizon"]

    environment = [
      { name = "APP_ENV", value = var.environment },
      { name = "APP_DEBUG", value = "false" },
      { name = "AWS_DEFAULT_REGION", value = var.aws_region },
      { name = "AWS_SECRETS_MANAGER_ID", value = "dermacells/${var.environment}" },
      { name = "LOG_CHANNEL", value = "stderr" },
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.horizon.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "horizon"
      }
    }
  }])

  tags = { Name = "${var.app_name}-${var.environment}-horizon-task" }
}

# Reverb WebSocket service
resource "aws_ecs_task_definition" "reverb" {
  family                   = "${var.app_name}-${var.environment}-reverb"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.reverb_cpu
  memory                   = var.reverb_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task_web.arn # reuse web role

  runtime_platform {
    cpu_architecture        = "ARM64"
    operating_system_family = "LINUX"
  }

  container_definitions = jsonencode([{
    name      = "reverb"
    image     = var.api_image_uri
    essential = true
    command   = ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]

    portMappings = [{
      containerPort = 8080
      hostPort      = 8080
      protocol      = "tcp"
    }]

    environment = [
      { name = "APP_ENV", value = var.environment },
      { name = "APP_DEBUG", value = "false" },
      { name = "AWS_DEFAULT_REGION", value = var.aws_region },
      { name = "AWS_SECRETS_MANAGER_ID", value = "dermacells/${var.environment}" },
      { name = "LOG_CHANNEL", value = "stderr" },
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.reverb.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "reverb"
      }
    }
  }])

  tags = { Name = "${var.app_name}-${var.environment}-reverb-task" }
}

# Scheduler — runs artisan schedule:work (singleton pattern via desiredCount=1)
resource "aws_ecs_task_definition" "scheduler" {
  family                   = "${var.app_name}-${var.environment}-scheduler"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = var.scheduler_cpu
  memory                   = var.scheduler_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task_horizon.arn # same permissions as worker

  runtime_platform {
    cpu_architecture        = "ARM64"
    operating_system_family = "LINUX"
  }

  container_definitions = jsonencode([{
    name      = "scheduler"
    image     = var.api_image_uri
    essential = true
    command   = ["php", "artisan", "schedule:work"]

    environment = [
      { name = "APP_ENV", value = var.environment },
      { name = "APP_DEBUG", value = "false" },
      { name = "AWS_DEFAULT_REGION", value = var.aws_region },
      { name = "AWS_SECRETS_MANAGER_ID", value = "dermacells/${var.environment}" },
      { name = "LOG_CHANNEL", value = "stderr" },
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.scheduler.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "scheduler"
      }
    }
  }])

  tags = { Name = "${var.app_name}-${var.environment}-scheduler-task" }
}

# --- ECS Services ---

resource "aws_ecs_service" "web" {
  name            = "web"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.web.arn
  desired_count   = var.web_desired_count

  launch_type = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.private[*].id
    security_groups  = [aws_security_group.ecs_tasks.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.web.arn
    container_name   = "web"
    container_port   = 8000
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  deployment_controller {
    type = "ECS"
  }

  # Blue/green deployments: keep minimum 100% healthy during rolling updates
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200

  depends_on = [aws_lb_listener.https]

  tags = { Name = "${var.app_name}-${var.environment}-web-service" }

  lifecycle {
    ignore_changes = [desired_count] # allow auto-scaling to manage count
  }
}

resource "aws_ecs_service" "horizon" {
  name            = "horizon"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.horizon.arn
  desired_count   = var.horizon_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.private[*].id
    security_groups  = [aws_security_group.ecs_tasks.id]
    assign_public_ip = false
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-horizon-service" }
}

resource "aws_ecs_service" "reverb" {
  name            = "reverb"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.reverb.arn
  desired_count   = var.reverb_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.private[*].id
    security_groups  = [aws_security_group.ecs_tasks.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.reverb.arn
    container_name   = "reverb"
    container_port   = 8080
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  depends_on = [aws_lb_listener.https]

  tags = { Name = "${var.app_name}-${var.environment}-reverb-service" }
}

resource "aws_ecs_service" "scheduler" {
  name            = "scheduler"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.scheduler.arn
  desired_count   = 1 # ALWAYS exactly 1 — schedule:work is not distributed-safe
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = aws_subnet.private[*].id
    security_groups  = [aws_security_group.ecs_tasks.id]
    assign_public_ip = false
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-scheduler-service" }
}

# --- Auto-scaling for web service ---

resource "aws_appautoscaling_target" "web" {
  max_capacity       = 6
  min_capacity       = var.web_desired_count
  resource_id        = "service/${aws_ecs_cluster.main.name}/web"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "web_cpu" {
  name               = "${var.app_name}-${var.environment}-web-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.web.resource_id
  scalable_dimension = aws_appautoscaling_target.web.scalable_dimension
  service_namespace  = aws_appautoscaling_target.web.service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value       = 70.0
    scale_in_cooldown  = 300
    scale_out_cooldown = 60
  }
}
