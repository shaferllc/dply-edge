#!/usr/bin/env bash
# Create/update the dply Valkey cluster + registry. Asks before changing anything.
# Reads the DigitalOcean token from .secrets/do.env (gitignored).
set -euo pipefail
cd "$(dirname "$0")/terraform"
# shellcheck source=/dev/null
source ../.secrets/do.env
terraform init -input=false >/dev/null
terraform apply "$@"
