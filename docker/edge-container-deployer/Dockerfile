# Toolchain for deploying dply container sites: wrangler builds the app image
# through the host Docker socket, pushes it to registry.cloudflare.com and
# rolls out the Worker + container. Built on first use by EdgeContainerDeployer.
FROM node:22-bookworm
# Current Docker CLI + buildx: Debian's docker.io (20.10) speaks an API version
# Docker Engine 29 build hosts reject.
COPY --from=docker:27-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker/buildx-bin:latest /buildx /usr/libexec/docker/cli-plugins/docker-buildx
RUN npm install -g wrangler@4
