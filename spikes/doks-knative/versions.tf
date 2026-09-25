terraform {
  required_version = ">= 1.6"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.50"
    }
    helm = {
      source  = "hashicorp/helm"
      version = "~> 2.17"
    }
    # kubectl_manifest applies CRs whose CRDs don't exist until the operator
    # is installed; hashicorp/kubernetes_manifest needs them at plan time.
    kubectl = {
      source  = "alekc/kubectl"
      version = "~> 2.1"
    }
  }
}

provider "digitalocean" {
  token = var.do_token
}

locals {
  kube = digitalocean_kubernetes_cluster.spike.kube_config[0]
}

provider "helm" {
  kubernetes {
    host                   = local.kube.host
    token                  = local.kube.token
    cluster_ca_certificate = base64decode(local.kube.cluster_ca_certificate)
  }
}

provider "kubectl" {
  host                   = local.kube.host
  token                  = local.kube.token
  cluster_ca_certificate = base64decode(local.kube.cluster_ca_certificate)
  load_config_file       = false
}
