variable "do_token" {
  description = "DigitalOcean API token (read/write). Pass with TF_VAR_do_token."
  type        = string
  sensitive   = true
}

variable "name" {
  description = "Prefix for everything this spike creates."
  type        = string
  default     = "dply-compute-spike"
}

variable "region" {
  type    = string
  default = "nyc3"
}

variable "node_size" {
  description = "Droplet size for the node pool. s-2vcpu-4gb matches our standard-1-ish container."
  type        = string
  default     = "s-2vcpu-4gb"
}

variable "min_nodes" {
  type    = number
  default = 1
}

variable "max_nodes" {
  type    = number
  default = 3
}

variable "knative_version" {
  description = "Knative Operator chart version; Serving is installed at the same version. Check https://github.com/knative/operator/releases before applying."
  type        = string
  default     = "v1.18.0"
}

variable "create_registry" {
  description = "DigitalOcean allows one registry per account. Leave false if the account already has one; the cluster is linked to it either way."
  type        = bool
  default     = false
}

variable "registry_name" {
  description = "Only used when create_registry = true."
  type        = string
  default     = "dply-compute"
}

variable "enable_gvisor" {
  description = "Create the gvisor RuntimeClass. DOKS nodes do not ship runsc, so pods using it will not start until the nodes have it. Finding out how to get it there is part of the spike (T-023)."
  type        = bool
  default     = false
}
