###############################################################################
# IAM Task Roles — minimal permissions per service
# Principle of least privilege: each ECS service gets only what it needs.
###############################################################################

# --- Web service task role ---
# Needs: RDS IAM auth, Secrets Manager read, S3 read (assets) + write (PDFs)

resource "aws_iam_role" "ecs_task_web" {
  name = "${var.app_name}-${var.environment}-ecs-task-web"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy" "ecs_task_web" {
  name = "web-task-policy"
  role = aws_iam_role.ecs_task_web.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "SecretsManagerRead"
        Effect = "Allow"
        Action = [
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret",
        ]
        Resource = [aws_secretsmanager_secret.main.arn]
      },
      {
        Sid    = "S3PDFWrite"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:GetObject",
          "s3:DeleteObject",
        ]
        Resource = "${aws_s3_bucket.pdfs.arn}/*"
      },
      {
        Sid    = "S3PDFBucketList"
        Effect = "Allow"
        Action = ["s3:ListBucket"]
        Resource = aws_s3_bucket.pdfs.arn
      },
      {
        Sid    = "S3WebAssetsRead"
        Effect = "Allow"
        Action = ["s3:GetObject"]
        Resource = "${aws_s3_bucket.web_assets.arn}/*"
      },
      {
        Sid    = "CloudWatchMetrics"
        Effect = "Allow"
        Action = ["cloudwatch:PutMetricData"]
        Resource = "*"
        Condition = {
          StringEquals = {
            "cloudwatch:namespace" = "DermaCells/App"
          }
        }
      },
    ]
  })
}

# --- Horizon worker + Scheduler task role ---
# Needs: same as web + S3 audit-exports write (for audit log dump job)

resource "aws_iam_role" "ecs_task_horizon" {
  name = "${var.app_name}-${var.environment}-ecs-task-horizon"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "ecs-tasks.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy" "ecs_task_horizon" {
  name = "horizon-task-policy"
  role = aws_iam_role.ecs_task_horizon.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "SecretsManagerRead"
        Effect = "Allow"
        Action = [
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret",
        ]
        Resource = [aws_secretsmanager_secret.main.arn]
      },
      {
        Sid    = "S3PDFReadWrite"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:GetObject",
        ]
        Resource = "${aws_s3_bucket.pdfs.arn}/*"
      },
      {
        Sid    = "S3AuditExportsWrite"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:GetObject",
        ]
        Resource = "${aws_s3_bucket.audit_exports.arn}/*"
      },
      {
        Sid    = "S3AuditBucketList"
        Effect = "Allow"
        Action = ["s3:ListBucket"]
        Resource = aws_s3_bucket.audit_exports.arn
      },
      {
        Sid    = "CloudWatchMetrics"
        Effect = "Allow"
        Action = ["cloudwatch:PutMetricData"]
        Resource = "*"
        Condition = {
          StringEquals = {
            "cloudwatch:namespace" = "DermaCells/App"
          }
        }
      },
    ]
  })
}

# --- CI/CD deployment role (used by GitHub Actions) ---
# Allows pushing to ECR and updating ECS services.

resource "aws_iam_role" "cicd_deploy" {
  name = "${var.app_name}-${var.environment}-cicd-deploy"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = {
        Federated = "arn:aws:iam::${data.aws_caller_identity.current.account_id}:oidc-provider/token.actions.githubusercontent.com"
      }
      Action    = "sts:AssumeRoleWithWebIdentity"
      Condition = {
        StringLike = {
          "token.actions.githubusercontent.com:sub" = "repo:OWNER/crm-dermacells:*"
        }
        StringEquals = {
          "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
        }
      }
    }]
  })
}

resource "aws_iam_role_policy" "cicd_deploy" {
  name = "cicd-deploy-policy"
  role = aws_iam_role.cicd_deploy.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "ECRAuth"
        Effect = "Allow"
        Action = ["ecr:GetAuthorizationToken"]
        Resource = "*"
      },
      {
        Sid    = "ECRPushPull"
        Effect = "Allow"
        Action = [
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage",
          "ecr:InitiateLayerUpload",
          "ecr:UploadLayerPart",
          "ecr:CompleteLayerUpload",
          "ecr:PutImage",
        ]
        Resource = aws_ecr_repository.api.arn
      },
      {
        Sid    = "ECSDeployServices"
        Effect = "Allow"
        Action = [
          "ecs:DescribeServices",
          "ecs:UpdateService",
          "ecs:RegisterTaskDefinition",
          "ecs:DescribeTaskDefinition",
        ]
        Resource = "*"
        Condition = {
          StringEquals = {
            "aws:ResourceTag/Project" = "crm-dermacells"
          }
        }
      },
      {
        Sid    = "PassRoleForTaskExecution"
        Effect = "Allow"
        Action = "iam:PassRole"
        Resource = [
          aws_iam_role.ecs_task_execution.arn,
          aws_iam_role.ecs_task_web.arn,
          aws_iam_role.ecs_task_horizon.arn,
        ]
      },
      {
        Sid    = "S3WebAssetsDeploy"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:ListBucket",
        ]
        Resource = [
          aws_s3_bucket.web_assets.arn,
          "${aws_s3_bucket.web_assets.arn}/*",
        ]
      },
      {
        Sid    = "CloudFrontInvalidate"
        Effect = "Allow"
        Action = ["cloudfront:CreateInvalidation"]
        Resource = aws_cloudfront_distribution.pwa.arn
      },
    ]
  })
}

# --- ECR Repository ---

resource "aws_ecr_repository" "api" {
  name                 = "${var.app_name}/api"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "KMS"
  }

  tags = { Name = "${var.app_name}/api" }
}

resource "aws_ecr_lifecycle_policy" "api" {
  repository = aws_ecr_repository.api.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Keep last 10 tagged production images"
        selection = {
          tagStatus     = "tagged"
          tagPrefixList = ["production-"]
          countType     = "imageCountMoreThan"
          countNumber   = 10
        }
        action = { type = "expire" }
      },
      {
        rulePriority = 2
        description  = "Expire untagged images after 7 days"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = 7
        }
        action = { type = "expire" }
      },
    ]
  })
}

# Data source for current account ID
data "aws_caller_identity" "current" {}
