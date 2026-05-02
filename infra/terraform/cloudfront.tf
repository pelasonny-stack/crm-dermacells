###############################################################################
# CloudFront distribution for the React PWA
# Origin: S3 bucket (web_assets) via Origin Access Control (OAC)
# Domain: crm.dermacells.com.ar (or staging.dermacells.com.ar)
# ACM cert: us-east-1 (required for CloudFront)
###############################################################################

# --- ACM Certificate for the PWA domain (must be in us-east-1) ---

resource "aws_acm_certificate" "pwa" {
  provider          = aws.us_east_1
  domain_name       = var.environment == "production" ? var.domain_name : var.staging_domain_name
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-pwa-cert" }
}

resource "aws_route53_record" "pwa_acm_validation" {
  for_each = {
    for dvo in aws_acm_certificate.pwa.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  allow_overwrite = true
  name            = each.value.name
  records         = [each.value.record]
  ttl             = 60
  type            = each.value.type
  zone_id         = var.route53_zone_id
}

resource "aws_acm_certificate_validation" "pwa" {
  provider                = aws.us_east_1
  certificate_arn         = aws_acm_certificate.pwa.arn
  validation_record_fqdns = [for record in aws_route53_record.pwa_acm_validation : record.fqdn]
}

# --- ACM Certificate for the API ALB (sa-east-1) ---

resource "aws_acm_certificate" "api" {
  domain_name       = var.environment == "production" ? "api.${var.domain_name}" : "api.${var.staging_domain_name}"
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }

  tags = { Name = "${var.app_name}-${var.environment}-api-cert" }
}

resource "aws_route53_record" "api_acm_validation" {
  for_each = {
    for dvo in aws_acm_certificate.api.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  allow_overwrite = true
  name            = each.value.name
  records         = [each.value.record]
  ttl             = 60
  type            = each.value.type
  zone_id         = var.route53_zone_id
}

resource "aws_acm_certificate_validation" "api" {
  certificate_arn         = aws_acm_certificate.api.arn
  validation_record_fqdns = [for record in aws_route53_record.api_acm_validation : record.fqdn]
}

# --- CloudFront Origin Access Control ---

resource "aws_cloudfront_origin_access_control" "pwa" {
  name                              = "${var.app_name}-${var.environment}-pwa-oac"
  description                       = "OAC for PWA S3 bucket"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

# --- CloudFront Distribution ---

resource "aws_cloudfront_distribution" "pwa" {
  enabled             = true
  is_ipv6_enabled     = true
  default_root_object = "index.html"
  price_class         = "PriceClass_All" # Latin America requires All (no 100/200)
  comment             = "${var.app_name} ${var.environment} PWA"

  aliases = [var.environment == "production" ? var.domain_name : var.staging_domain_name]

  # S3 origin
  origin {
    domain_name              = aws_s3_bucket.web_assets.bucket_regional_domain_name
    origin_id                = "S3-${aws_s3_bucket.web_assets.id}"
    origin_access_control_id = aws_cloudfront_origin_access_control.pwa.id
  }

  # Default cache behavior (static assets)
  default_cache_behavior {
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "S3-${aws_s3_bucket.web_assets.id}"
    viewer_protocol_policy = "redirect-to-https"
    compress               = true

    cache_policy_id            = data.aws_cloudfront_cache_policy.caching_optimized.id
    origin_request_policy_id   = data.aws_cloudfront_origin_request_policy.cors_s3.id
    response_headers_policy_id = aws_cloudfront_response_headers_policy.security.id

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.spa_rewrite.arn
    }
  }

  # SPA routing: return index.html for all 403/404 (client-side routing)
  custom_error_response {
    error_code            = 403
    response_code         = 200
    response_page_path    = "/index.html"
    error_caching_min_ttl = 0
  }

  custom_error_response {
    error_code            = 404
    response_code         = 200
    response_page_path    = "/index.html"
    error_caching_min_ttl = 0
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    acm_certificate_arn      = aws_acm_certificate_validation.pwa.certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }

  tags = { Name = "${var.app_name}-${var.environment}-pwa-cf" }
}

# CloudFront function for SPA routing (rewrites /path to /index.html if no extension)
resource "aws_cloudfront_function" "spa_rewrite" {
  name    = "${var.app_name}-${var.environment}-spa-rewrite"
  runtime = "cloudfront-js-2.0"
  comment = "Rewrite requests to index.html for client-side routing"
  publish = true

  code = <<-EOT
    function handler(event) {
      var request = event.request;
      var uri = request.uri;
      // If the URI has no file extension, serve index.html
      if (!uri.includes('.') || uri.endsWith('/')) {
        request.uri = '/index.html';
      }
      return request;
    }
  EOT
}

# Response headers policy (security headers)
resource "aws_cloudfront_response_headers_policy" "security" {
  name    = "${var.app_name}-${var.environment}-security-headers"
  comment = "Security headers for CRM Dermacells PWA"

  security_headers_config {
    strict_transport_security {
      access_control_max_age_sec = 63072000
      include_subdomains         = true
      preload                    = true
      override                   = true
    }

    content_type_options {
      override = true
    }

    frame_options {
      frame_option = "DENY"
      override     = true
    }

    xss_protection {
      mode_block = true
      protection = true
      override   = true
    }

    referrer_policy {
      referrer_policy = "strict-origin-when-cross-origin"
      override        = true
    }
  }
}

# Data sources for managed CloudFront policies
data "aws_cloudfront_cache_policy" "caching_optimized" {
  name = "Managed-CachingOptimized"
}

data "aws_cloudfront_origin_request_policy" "cors_s3" {
  name = "Managed-CORS-S3Origin"
}
