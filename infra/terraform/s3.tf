###############################################################################
# S3 Buckets
#   1. pdfs          — Xubio invoice PDFs (private, server-side encryption)
#   2. audit-exports — Audit log dumps (Object Lock COMPLIANCE, 7 years)
#   3. web-assets    — PWA static files served via CloudFront
#   4. tf-state      — Terraform remote state (versioning + policy)
###############################################################################

# --- PDF bucket (Xubio invoices) ---

resource "aws_s3_bucket" "pdfs" {
  bucket        = "${var.app_name}-${var.environment}-pdfs"
  force_destroy = var.environment != "production"

  tags = { Name = "${var.app_name}-${var.environment}-pdfs", Purpose = "xubio-invoices" }
}

resource "aws_s3_bucket_versioning" "pdfs" {
  bucket = aws_s3_bucket.pdfs.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "pdfs" {
  bucket = aws_s3_bucket.pdfs.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "aws:kms"
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_public_access_block" "pdfs" {
  bucket                  = aws_s3_bucket.pdfs.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "pdfs" {
  bucket = aws_s3_bucket.pdfs.id

  rule {
    id     = "transition-to-ia"
    status = "Enabled"
    transition {
      days          = 90
      storage_class = "STANDARD_IA"
    }
    transition {
      days          = 365
      storage_class = "GLACIER_IR"
    }
  }
}

# --- Audit exports bucket (Object Lock COMPLIANCE — 7 years) ---

resource "aws_s3_bucket" "audit_exports" {
  bucket        = "${var.app_name}-${var.environment}-audit-exports"
  force_destroy = false # Never allow forced deletion of compliance-locked data

  object_lock_enabled = true

  tags = {
    Name        = "${var.app_name}-${var.environment}-audit-exports"
    Purpose     = "audit-log-compliance"
    Compliance  = "object-lock-7yr"
  }
}

resource "aws_s3_bucket_versioning" "audit_exports" {
  bucket = aws_s3_bucket.audit_exports.id
  # Object Lock requires versioning; it is automatically enabled with object_lock_enabled=true
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_object_lock_configuration" "audit_exports" {
  bucket = aws_s3_bucket.audit_exports.id

  rule {
    default_retention {
      mode  = "COMPLIANCE"
      years = 7
    }
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "audit_exports" {
  bucket = aws_s3_bucket.audit_exports.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "aws:kms"
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_public_access_block" "audit_exports" {
  bucket                  = aws_s3_bucket.audit_exports.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# --- Web assets bucket (PWA static files, served via CloudFront) ---

resource "aws_s3_bucket" "web_assets" {
  bucket        = "${var.app_name}-${var.environment}-web-assets"
  force_destroy = true

  tags = { Name = "${var.app_name}-${var.environment}-web-assets", Purpose = "pwa-static" }
}

resource "aws_s3_bucket_versioning" "web_assets" {
  bucket = aws_s3_bucket.web_assets.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "web_assets" {
  bucket = aws_s3_bucket.web_assets.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "web_assets" {
  bucket                  = aws_s3_bucket.web_assets.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Allow CloudFront OAC to read from web-assets
resource "aws_s3_bucket_policy" "web_assets_cloudfront" {
  bucket = aws_s3_bucket.web_assets.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid    = "AllowCloudFrontServicePrincipal"
      Effect = "Allow"
      Principal = {
        Service = "cloudfront.amazonaws.com"
      }
      Action   = "s3:GetObject"
      Resource = "${aws_s3_bucket.web_assets.arn}/*"
      Condition = {
        StringEquals = {
          "AWS:SourceArn" = aws_cloudfront_distribution.pwa.arn
        }
      }
    }]
  })

  depends_on = [aws_cloudfront_distribution.pwa]
}

# --- Terraform state bucket ---

resource "aws_s3_bucket" "tf_state" {
  bucket        = "dermacells-tf-state"
  force_destroy = false

  tags = { Name = "dermacells-tf-state", Purpose = "terraform-backend" }
}

resource "aws_s3_bucket_versioning" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "aws:kms"
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_public_access_block" "tf_state" {
  bucket                  = aws_s3_bucket.tf_state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# DynamoDB table for state locking
resource "aws_dynamodb_table" "tf_lock" {
  name         = "dermacells-tf-lock"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "LockID"

  attribute {
    name = "LockID"
    type = "S"
  }

  tags = { Name = "dermacells-tf-lock", Purpose = "terraform-state-lock" }
}
