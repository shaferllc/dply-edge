#!/usr/bin/env bash
# Bootstrap Docker on a dply control-plane WORKER for Edge builds.
# Horizon runs as www-data (deploy/supervisor/dply-worker*.conf).
#
# Usage (as root on each worker):
#   ./deploy/ensure-edge-build-docker.sh
#   # or from the release root:
#   sudo php artisan dply:edge:ensure-build-docker
#   php artisan horizon:terminate

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

USER_NAME="${DPLY_EDGE_BUILD_DOCKER_USER:-${SUDO_USER:-www-data}}"

php artisan dply:edge:ensure-build-docker --user="$USER_NAME"
echo
echo "Next: as the deploy user, recycle Horizon:"
echo "  php artisan horizon:terminate"
echo "  php artisan dply:edge:ensure-build-docker --check"
