terraform {
  required_version = ">= 1.9.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.50"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  backend "s3" {
    # Values supplied via -backend-config or backend.hcl at init time.
    # Never hardcode these — they may differ per environment.
    bucket         = "dermacells-tf-state"
    key            = "crm/terraform.tfstate"
    region         = "sa-east-1"
    encrypt        = true
    dynamodb_table = "dermacells-tf-lock"
  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "crm-dermacells"
      Environment = var.environment
      ManagedBy   = "terraform"
      Owner       = "devops"
    }
  }
}

provider "aws" {
  alias  = "us_east_1"
  region = "us-east-1" # ACM certificates for CloudFront must be in us-east-1

  default_tags {
    tags = {
      Project     = "crm-dermacells"
      Environment = var.environment
      ManagedBy   = "terraform"
      Owner       = "devops"
    }
  }
}
