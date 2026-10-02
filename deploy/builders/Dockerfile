# dply build server image (DPLY_RUNTIME=builder). Runs Horizon on the build
# queues only (dply-provision, dply-builder); Docker work goes to the dind
# sidecar over DOCKER_HOST (deploy/builders/k8s/builder.yaml).
#
# Build from the repo root (context = .), for the cluster's architecture:
#   TAG=$(git rev-parse --short HEAD)
#   docker buildx build --platform linux/amd64 -f deploy/builders/Dockerfile \
#     -t registry.digitalocean.com/dply-cloud/dply-builder:$TAG --push .
# (--push needs `doctl registry login`; see docs/self-hosting-runbook.md.)

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# The agent dply puts in front of container apps (packages/dply-agent,
# EdgeContainerAgent): a static linux/amd64 binary, copied into each image.
FROM golang:1.24-bookworm AS agent
WORKDIR /src
COPY packages/dply-agent/ ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" -o /dply-agent .

FROM php:8.4-cli-bookworm

# git >= 2.31 (bookworm: 2.39), node for the node-side jobs (middleware
# bundling, realtime check), pg_dump + age for the escrow jobs routed here.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl git unzip gnupg postgresql-client age \
 && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath gd opcache sockets

# Current Docker CLI + buildx (same pins as docker/edge-container-deployer).
COPY --from=docker:27-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker/buildx-bin:latest /buildx /usr/libexec/docker/cli-plugins/docker-buildx
COPY --from=agent /dply-agent /usr/local/bin/dply-agent

WORKDIR /app
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && rm -f bootstrap/cache/*.php \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && useradd --uid 1000 --create-home dply \
 && chown -R dply:dply storage bootstrap/cache

ENV DPLY_RUNTIME=builder \
    LOG_CHANNEL=stderr \
    DPLY_EDGE_BUILD_WORK_ROOT=/var/lib/dply-builds \
    DOCKER_HOST=tcp://127.0.0.1:2376 \
    DOCKER_TLS_VERIFY=1 \
    DOCKER_CERT_PATH=/certs/client

USER dply
# Config is read from env at boot (no config:cache: the Secret is the source).
CMD ["php", "artisan", "horizon"]
