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
  name   = "*.cache"
  value  = var.gateway_ip
  ttl    = 300
}

output "cluster_id" {
  value = digitalocean_kubernetes_cluster.valkey.id
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

# Databases over 0.5 CU (packages/valkey-gateway/placement.go: databasePool).
# An awake database requests its full memory (1 CU = 4 GiB) and up to one
# core, so they do not fit the db pool's 4 GB nodes (2.5 GiB allocatable).
# Both pools sit at zero nodes until a database needs one, like the pro
# pools, and carry the db taint so the gateway's existing toleration applies.
#   db-large  s-8vcpu-16gb  $96/mo, 13 GiB allocatable: 1 and 2 CU (4 / 8 GiB)
#   db-xl     m-4vcpu-32gb  $168/mo, 28 GiB allocatable: 4 CU (16 GiB)
# Large databases always stay on and each is priced to pay for a whole node
# alone (ruling r-gd2vgb7jd1b4vqtf, docs/pricing-review.md §9).
# Sizes are sold once DPLY_DATABASE_LARGE_SIZES=true (docs/launch-checklist.md).
locals {
  db_large_pools = {
    "db-large" = { size = "s-8vcpu-16gb", max_nodes = 2 }
    "db-xl"    = { size = "m-4vcpu-32gb", max_nodes = 2 }
  }
}

resource "digitalocean_kubernetes_node_pool" "db_large" {
  for_each   = local.db_large_pools
  cluster_id = digitalocean_kubernetes_cluster.valkey.id
  name       = each.key
  size       = each.value.size
  auto_scale = true
  min_nodes  = 0
  max_nodes  = each.value.max_nodes
  node_count = 0

  taint {
    key    = "dply.dev/db"
    value  = "true"
    effect = "NoSchedule"
  }
}

# Build servers (deploy/builders/): customer Edge builds and container image
# builds, DPLY_RUNTIME=builder pods with a docker:dind sidecar. The taint
# keeps everything else off; only the builder Deployment tolerates it.
# The cluster autoscaler adds nodes as KEDA adds builder pods (one pod per
# node: requests fill it), and removes them when the queue drains. The
# minimum node is a fixed cost (dply.unit_costs.build); the rest are variable.
# (The coordinator asked for `dply.io/role`; the other pools use `dply.dev/*`.)
variable "builder_node_size" {
  description = "One builder pod per node. s-4vcpu-8gb runs 2 concurrent builds (HORIZON_BUILD_MAX_PROCESSES)."
  type        = string
  default     = "s-4vcpu-8gb"
}

variable "builder_min_nodes" {
  type    = number
  default = 1
}

variable "builder_max_nodes" {
  description = "Upper bound for the autoscaler; keep >= the KEDA ScaledObject maxReplicaCount."
  type        = number
  default     = 4
}

resource "digitalocean_kubernetes_node_pool" "builders" {
  cluster_id = digitalocean_kubernetes_cluster.valkey.id
  name       = "builders"
  size       = var.builder_node_size
  auto_scale = true
  min_nodes  = var.builder_min_nodes
  max_nodes  = var.builder_max_nodes
  node_count = var.builder_min_nodes

  labels = {
    "dply.io/role" = "builder"
  }

  taint {
    key    = "dply.io/role"
    value  = "builder"
    effect = "NoSchedule"
  }
}
