###############################################################################
# Route 53 — DNS records for CRM Dermacells
# Assumes the hosted zone for dermacells.com.ar is already created in Route 53.
# Set route53_zone_id in terraform.tfvars to the zone ID.
###############################################################################

# Data source to validate the hosted zone exists
data "aws_route53_zone" "main" {
  zone_id = var.route53_zone_id
}

# --- PWA (React app) — CloudFront alias ---

resource "aws_route53_record" "pwa_a" {
  zone_id = var.route53_zone_id
  name    = var.environment == "production" ? var.domain_name : var.staging_domain_name
  type    = "A"

  alias {
    name                   = aws_cloudfront_distribution.pwa.domain_name
    zone_id                = aws_cloudfront_distribution.pwa.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "pwa_aaaa" {
  zone_id = var.route53_zone_id
  name    = var.environment == "production" ? var.domain_name : var.staging_domain_name
  type    = "AAAA"

  alias {
    name                   = aws_cloudfront_distribution.pwa.domain_name
    zone_id                = aws_cloudfront_distribution.pwa.hosted_zone_id
    evaluate_target_health = false
  }
}

# --- API (Laravel) — ALB alias ---

resource "aws_route53_record" "api_a" {
  zone_id = var.route53_zone_id
  name    = var.environment == "production" ? "api.${var.domain_name}" : "api.${var.staging_domain_name}"
  type    = "A"

  alias {
    name                   = aws_lb.main.dns_name
    zone_id                = aws_lb.main.zone_id
    evaluate_target_health = true
  }
}

# --- WebSocket (Reverb) — same ALB, path-based routing ---
# Reverb shares the API ALB via listener rules — no separate DNS record needed.
# The PWA connects to wss://api.crm.dermacells.com.ar/app/{key}

# --- Health check ---

resource "aws_route53_health_check" "api" {
  fqdn              = var.environment == "production" ? "api.${var.domain_name}" : "api.${var.staging_domain_name}"
  port              = 443
  type              = "HTTPS"
  resource_path     = "/api/v1/health"
  failure_threshold = 3
  request_interval  = 30

  tags = { Name = "${var.app_name}-${var.environment}-api-healthcheck" }
}
