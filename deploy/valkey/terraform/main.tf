# dply Valkey on DigitalOcean Kubernetes (ruling r-72p0gkdn9dqwxqha, T-021).
# Terraform owns the cluster, the registry and DNS. The gateway, cert-manager
# and every Secret go on with kubectl (deploy/valkey/apply.sh) so no secret
# lands in Terraform state.

terraform {
  required_version = ">= 1.6"
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.50"
    }
  }
}

variable "do_token" {
  type      = string
  sensitive = true
}

variable "region" {
  type    = string
  default = "nyc3"
}

variable "domain" {
  description = "DigitalOcean-hosted zone. Tenants connect to {id}.cache.<domain>:6380."
  type        = string
  default     = "dply.cloud"
}

variable "registry_name" {
  description = "Globally unique on DigitalOcean. One registry per account."
  type        = string
  default     = "dply-cloud"
}

variable "cluster_name" {
  description = "dply-pods for the first region; e.g. dply-pods-sfo3 for more (docs/DATA_REGIONS.md)."
  type        = string
  default     = "dply-pods"
}

variable "create_registry" {
  description = "Only the first region creates the account's registry."
  type        = bool
  default     = true
}

variable "dns_suffix" {
  description = "Empty for the first region (*.cache.dply.io); e.g. \".sfo\" for *.cache.sfo.dply.io."
  type        = string
  default     = ""
}

variable "manage_db_record" {
  description = "Let Terraform own *.db{suffix}: true for new regions."
  type        = bool
  default     = false
}

variable "node_size" {
  description = "Flex tenants only (250 MB - 2.5 GB). Pro sizes (5-50 GB) need a bigger pool."
  type        = string
  default     = "s-2vcpu-4gb"
}

variable "gateway_ip" {
  description = "The gateway Service's load balancer IP. Empty on the first apply; set it after apply.sh creates the Service."
  type        = string
  default     = ""
}

provider "digitalocean" {
  token = var.do_token
}

data "digitalocean_kubernetes_versions" "current" {}

# One registry per DigitalOcean account: only the first region creates it;
# other regions (create_registry = false) pull from the same one.
resource "digitalocean_container_registry" "dply" {
  count                  = var.create_registry ? 1 : 0
  name                   = var.registry_name
  subscription_tier_slug = "basic"
  region                 = var.region
}

# The registry gained a count: same resource, not a new one.
moved {
  from = digitalocean_container_registry.dply
  to   = digitalocean_container_registry.dply[0]
}

resource "digitalocean_kubernetes_cluster" "valkey" {
  name                 = var.cluster_name
  region               = var.region
  version              = data.digitalocean_kubernetes_versions.current.latest_version
  registry_integration = true
  auto_upgrade         = true
  surge_upgrade        = true

  maintenance_policy {
    day        = "sunday"
    start_time = "06:00"
  }

  # The cache pool: flex Valkey tenants and the gateway. Kept named "flex":
  # renaming the cluster's default pool replaces the whole cluster. Two nodes
  # so one failing does not take every cache down.
  node_pool {
    name       = "flex"
    size       = var.node_size
    auto_scale = true
    min_nodes  = 2
    max_nodes  = 3
  }

  depends_on = [digitalocean_container_registry.dply]
}

resource "digitalocean_record" "cache_wildcard" {
  count  = var.gateway_ip == "" ? 0 : 1
  domain = var.domain
  type   = "A"
  name   = "*.cache${var.dns_suffix}"
  value  = var.gateway_ip
  ttl    = 300
}

output "cluster_id" {
  value = digitalocean_kubernetes_cluster.valkey.id
}

# Databases answer on {id}.db{suffix}.{domain}. The first region's record was
# made by hand before this; new regions let Terraform own it.
resource "digitalocean_record" "db_wildcard" {
  count  = var.gateway_ip != "" && var.manage_db_record ? 1 : 0
  domain = var.domain
  type   = "A"
  name   = "*.db${var.dns_suffix}"
  value  = var.gateway_ip
  ttl    = 300
}

output "registry" {
  value = "registry.digitalocean.com/${var.registry_name}"
}

# Databases only (Postgres, MySQL, MongoDB): the gateway sets DB_NODE_POOL=db,
# and the taint keeps cache pods off. Their disk I/O and page cache would
# otherwise slow the cache nodes. Two nodes, so a database whose node fails
# reattaches its volume on the other one.
resource "digitalocean_kubernetes_node_pool" "db" {
  cluster_id = digitalocean_kubernetes_cluster.valkey.id
  name       = "db"
  size       = var.node_size
  auto_scale = true
  min_nodes  = 2
  max_nodes  = 4
  node_count = 2

  taint {
    key    = "dply.dev/db"
    value  = "true"
    effect = "NoSchedule"
  }
}

# Pro tenants only (packages/valkey-gateway/placement.go). Both pools sit at
# zero nodes until a pro tenant's pod needs one; the cluster autoscaler adds a
# node then and removes it when the last pro tenant on it is gone. The taint
# keeps flex pods and everything else off these machines.
locals {
  pro_pools = {
    "pro-16" = { size = "m-2vcpu-16gb", max_nodes = 1 } # $84/mo per node: Pro 5 GB, 12 GB
    # "pro-64" (m-8vcpu-64gb, $336/mo per node) serves Pro 25 GB / 50 GB. Not
    # created until a customer needs it; EdgeValkey::NOT_OFFERED hides those
    # sizes meanwhile. Add it back here and remove them from NOT_OFFERED.
  }
}

resource "digitalocean_kubernetes_node_pool" "pro" {
  for_each   = local.pro_pools
  cluster_id = digitalocean_kubernetes_cluster.valkey.id
  name       = each.key
  size       = each.value.size
  auto_scale = true
  min_nodes  = 0
  max_nodes  = each.value.max_nodes
  node_count = 0

  taint {
    key    = "dply.dev/pro"
    value  = "true"
    effect = "NoSchedule"
  }
}
