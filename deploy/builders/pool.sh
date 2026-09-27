#!/usr/bin/env bash
# Create or update only the `builders` node pool on the DOKS cluster
# (deploy/valkey/terraform/main.tf). Other pools in main.tf are left alone.
#   deploy/builders/pool.sh
set -euo pipefail
cd "$(dirname "$0")/../valkey/terraform"
set -a; . ../.secrets/do.env; set +a
export TF_VAR_do_token="$DIGITALOCEAN_TOKEN"
terraform plan -input=false -target=digitalocean_kubernetes_node_pool.builders -out=builders.plan
terraform apply -input=false builders.plan
rm -f builders.plan
