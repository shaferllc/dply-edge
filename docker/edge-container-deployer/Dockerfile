# Toolchain for deploying dply container sites: wrangler builds the app image
# through the host Docker socket, pushes it to registry.cloudflare.com and
# rolls out the Worker + container. Built on first use by EdgeContainerDeployer.
FROM node:22-bookworm
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends docker.io \
 && apt-get clean
RUN npm install -g wrangler@4
