#!/usr/bin/env bash
# FALLBACK: a standalone Ubuntu build server (22.04/24.04), for when the DOKS
# builder pool (deploy/builders/apply.sh) is unavailable. Same role: Horizon on
# the build queues only (DPLY_RUNTIME=builder). Idempotent: re-run to upgrade.
#
#   scp deploy/builders/.secrets/builder.env root@<box>:/root/builder.env
#   ssh root@<box> 'bash -s -- --name builder-3 --ref origin/main --concurrency 4' \
#       < deploy/builders/bootstrap.sh
#
# Options (all optional):
#   --name NAME          hostname + HORIZON_NAME (unique per builder; default: hostname)
#   --ref REF            git ref to run (default origin/main)
#   --repo URL           default https://github.com/shaferllc/dply-edge.git
#                        (private: export GIT_TOKEN, or use a deploy key)
#   --concurrency N      builds at once (HORIZON_BUILD_MAX_PROCESSES; default 4
#                        on 8 vCPU / 16 GB; use 2 on 4 vCPU / 8 GB)
#   --env FILE           builder env (default /root/builder.env); least privilege,
#                        see docs/self-hosting-runbook.md "Builders"
set -euo pipefail

NAME=$(hostname); REF=origin/main; REPO=https://github.com/shaferllc/dply-edge.git
CONCURRENCY=4; ENV_SRC=/root/builder.env; ROOT=/opt/dply; WORK=/var/lib/dply-builds
while [ $# -gt 0 ]; do
  case "$1" in
    --name) NAME=$2; shift 2 ;; --ref) REF=$2; shift 2 ;; --repo) REPO=$2; shift 2 ;;
    --concurrency) CONCURRENCY=$2; shift 2 ;; --env) ENV_SRC=$2; shift 2 ;; --) shift ;;
    *) echo "unknown option $1" >&2; exit 1 ;;
  esac
done
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
[ -s "$ENV_SRC" ] || { echo "missing $ENV_SRC (the builder env)" >&2; exit 1; }
if grep -qE '^(SECRET_VAULT_IDENTITY_PATH|DB_ADMIN_|SECRET_VAULT_CRITICAL_PG_PASSWORD)=' "$ENV_SRC"; then
  echo "$ENV_SRC holds admin/escrow-identity material; builders must not." >&2; exit 1
fi
hostnamectl set-hostname "$NAME"
export DEBIAN_FRONTEND=noninteractive

# --- packages: docker, git >= 2.31, php 8.4, composer, node 22, age, pg_dump
if ! command -v docker >/dev/null; then curl -fsSL https://get.docker.com | sh; fi
if ! command -v php8.4 >/dev/null; then
  apt-get update -q && apt-get install -yq software-properties-common
  add-apt-repository -y ppa:ondrej/php
fi
if ! command -v node >/dev/null || ! node -v | grep -q '^v22'; then curl -fsSL https://deb.nodesource.com/setup_22.x | bash -; fi
apt-get update -q
apt-get install -yq git unzip age postgresql-client iptables-persistent nodejs \
  php8.4-cli php8.4-pgsql php8.4-redis php8.4-intl php8.4-zip php8.4-bcmath php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
git --version | awk '{ split($3, v, "."); if (v[1] < 2 || (v[1] == 2 && v[2] < 31)) { print "git >= 2.31 required"; exit 1 } }'

# --- user, checkout, env
id dply >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash dply
usermod -aG docker dply
mkdir -p "$ROOT" "$WORK" && chown dply:dply "$ROOT" "$WORK"
url=$REPO; [ -n "${GIT_TOKEN:-}" ] && url=${REPO/https:\/\//https:\/\/x-access-token:$GIT_TOKEN@}
[ -d "$ROOT/.git" ] || sudo -u dply git clone --quiet "$url" "$ROOT"
sudo -u dply git -C "$ROOT" remote set-url origin "$url"
sudo -u dply git -C "$ROOT" fetch --quiet origin
sudo -u dply git -C "$ROOT" checkout --quiet --force "$REF"
sudo -u dply git -C "$ROOT" remote set-url origin "$REPO"   # no token left in .git/config
install -o dply -g dply -m 600 "$ENV_SRC" "$ROOT/.env"
set_env() { grep -q "^$1=" "$ROOT/.env" && sed -i "s#^$1=.*#$1=$2#" "$ROOT/.env" || echo "$1=$2" >> "$ROOT/.env"; }
set_env DPLY_RUNTIME builder
set_env HORIZON_NAME "$NAME"
set_env HORIZON_BUILD_MIN_PROCESSES "$CONCURRENCY"
set_env HORIZON_BUILD_MAX_PROCESSES "$CONCURRENCY"
set_env DPLY_EDGE_BUILD_WORK_ROOT "$WORK"
set_env LOG_CHANNEL stderr
sudo -u dply composer --working-dir="$ROOT" install --no-dev --optimize-autoloader --no-interaction --quiet
sudo -u dply php "$ROOT/artisan" config:clear >/dev/null

# --- build firewall (docs/edge-build-isolation.md), idempotent
docker network inspect dply-builds >/dev/null 2>&1 || docker network create --driver bridge \
  -o com.docker.network.bridge.enable_icc=false -o com.docker.network.bridge.name=dply-builds0 \
  --subnet 172.30.0.0/16 dply-builds
add() { iptables -C "$@" 2>/dev/null || iptables -I "$@"; }
add INPUT -i dply-builds0 -j DROP
for net in 169.254.0.0/16 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10; do
  add DOCKER-USER -i dply-builds0 -d "$net" -j DROP
done
add DOCKER-USER -i dply-builds0 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
netfilter-persistent save >/dev/null

# --- services: Horizon (build queues only) + runtime check / heartbeat
cat > /etc/systemd/system/dply-builder.service <<UNIT
[Unit]
Description=dply build server (Horizon, DPLY_RUNTIME=builder)
After=docker.service network-online.target
Requires=docker.service

[Service]
User=dply
WorkingDirectory=$ROOT
ExecStart=/usr/bin/php $ROOT/artisan horizon
# Drain: stop taking jobs, let running builds finish (HORIZON_BUILD_TIMEOUT).
ExecStop=/usr/bin/php $ROOT/artisan horizon:terminate --wait
TimeoutStopSec=7500
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/dply-runtime-check.service <<UNIT
[Unit]
Description=dply runtime check (builder heartbeat)
[Service]
Type=oneshot
User=dply
ExecStart=/usr/bin/php $ROOT/artisan dply:runtime:check
UNIT
cat > /etc/systemd/system/dply-runtime-check.timer <<UNIT
[Unit]
Description=dply runtime check every minute
[Timer]
OnBootSec=60
OnUnitActiveSec=60
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now dply-runtime-check.timer
systemctl enable dply-builder.service
systemctl restart dply-builder.service    # graceful: ExecStop drains first

sudo -u dply php "$ROOT/artisan" dply:runtime:check
echo "Builder $NAME is up at $(git -C "$ROOT" rev-parse --short HEAD)."
