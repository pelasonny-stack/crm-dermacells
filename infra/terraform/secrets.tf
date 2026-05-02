###############################################################################
# Secrets Manager — dermacells/{environment}
# Stores all application secrets in a single JSON secret for simplicity.
# Rotation via Lambda placeholder — implement rotation logic when ready.
###############################################################################

resource "aws_secretsmanager_secret" "main" {
  name        = "dermacells/${var.environment}"
  description = "All application secrets for CRM Dermacells ${var.environment}"

  # Deletion protection: 30-day recovery window in production, 7 days in staging
  recovery_window_in_days = var.environment == "production" ? 30 : 7

  tags = { Name = "dermacells/${var.environment}" }
}

# Initial placeholder secret values.
# Real values must be populated BEFORE first ECS deploy.
# Use: aws secretsmanager put-secret-value --secret-id dermacells/production --secret-string '{...}'
resource "aws_secretsmanager_secret_version" "main_placeholder" {
  secret_id = aws_secretsmanager_secret.main.id

  secret_string = jsonencode({
    # Application
    APP_KEY  = "REPLACE_ME_base64:..."
    APP_DEBUG = "false"

    # Database
    DB_HOST     = "REPLACE_ME_aurora_writer_endpoint"
    DB_PORT     = "5432"
    DB_DATABASE = "dermacells"
    DB_USERNAME = "app_role"
    DB_PASSWORD = "REPLACE_ME_generate_with_aws_secrets_manager"

    DB_MIGRATION_USERNAME = "migration_role"
    DB_MIGRATION_PASSWORD = "REPLACE_ME"

    DB_WORKER_USERNAME = "worker_role"
    DB_WORKER_PASSWORD = "REPLACE_ME"

    # Redis
    REDIS_HOST     = "REPLACE_ME_elasticache_primary_endpoint"
    REDIS_PORT     = "6379"
    REDIS_PASSWORD = "null"

    # Sanctum / Session
    SESSION_DOMAIN = "crm.dermacells.com.ar"

    # OAuth
    GOOGLE_CLIENT_ID      = "REPLACE_ME"
    GOOGLE_CLIENT_SECRET  = "REPLACE_ME"
    GOOGLE_HD_ALLOWLIST   = "dermacells.com.ar"

    MICROSOFT_CLIENT_ID     = "REPLACE_ME"
    MICROSOFT_CLIENT_SECRET = "REPLACE_ME"
    MICROSOFT_TID_ALLOWLIST = "REPLACE_ME_azure_tenant_id"

    # Audit
    AUDIT_HMAC_KEY = "REPLACE_ME_base64:random_32_bytes"

    # AI
    OPENAI_API_KEY    = "REPLACE_ME"
    ANTHROPIC_API_KEY = "REPLACE_ME"

    # Xubio
    XUBIO_CLIENT_ID     = "REPLACE_ME"
    XUBIO_CLIENT_SECRET = "REPLACE_ME"

    # WhatsApp
    WHATSAPP_PHONE_NUMBER_ID        = "REPLACE_ME"
    WHATSAPP_BUSINESS_ACCOUNT_ID    = "REPLACE_ME"
    WHATSAPP_ACCESS_TOKEN           = "REPLACE_ME"
    WHATSAPP_VERIFY_TOKEN           = "REPLACE_ME"
    WHATSAPP_APP_SECRET             = "REPLACE_ME"

    # Firebase
    FIREBASE_PROJECT_ID = "REPLACE_ME"

    # Reverb
    REVERB_APP_KEY    = "REPLACE_ME"
    REVERB_APP_SECRET = "REPLACE_ME"

    # PagerDuty
    PAGERDUTY_INTEGRATION_KEY = "REPLACE_ME"

    # Sentry
    SENTRY_DSN = "REPLACE_ME"
  })

  lifecycle {
    # Prevent Terraform from overwriting secrets populated by CI/CD or manually
    ignore_changes = [secret_string]
  }
}

# --- Rotation Lambda placeholder ---
# Uncomment and implement when automated rotation is needed for DB credentials.
# The Lambda function itself is not created here — it should be deployed separately
# per AWS recommended architecture (arn:aws:lambda:...:function:dermacells-secret-rotation).

# resource "aws_secretsmanager_secret_rotation" "main" {
#   secret_id           = aws_secretsmanager_secret.main.id
#   rotation_lambda_arn = "arn:aws:lambda:${var.aws_region}:ACCOUNT_ID:function:dermacells-secret-rotation"
#
#   rotation_rules {
#     automatically_after_days = 30
#   }
# }
