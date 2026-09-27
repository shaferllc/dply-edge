---
title: "Introduction"
description: "dply deploys websites and web apps from your Git repository to Cloudflare's edge network, with builds, domains, resources and observability managed for you."
---

dply is a managed hosting platform for websites and web applications. You connect a Git repository, dply detects how to build it, and each push deploys to Cloudflare's global edge network. Static sites, server-rendered JavaScript apps and full PHP, Ruby or Node servers all deploy the same way, from the same dashboard.

You don't need a Cloudflare account, servers or Docker knowledge. dply runs the builds, stores the output, routes traffic, issues TLS certificates and meters usage.

## Runtime modes

Every app runs in one of four modes. dply picks one from what it detects in your repository and shows it on the create page next to the framework name.

| Mode | Shown as | What runs | Use it for |
|---|---|---|---|
| Static | **Static** | Built files served from the edge cache | Static sites and static site generators |
| Hybrid | **Hybrid** | Static files from the edge; matching routes proxied to a server you already run | A server-rendered app that already has a host |
| Worker SSR | **Worker SSR** | Your app's server code as a Cloudflare Worker, next to its static assets | Next.js, SvelteKit, Astro, Remix and Keel |
| Container | **Container** | Your app in a container on Cloudflare Containers, built from your Dockerfile or one dply generates | Laravel, Symfony, Rails, Sinatra and Node HTTP servers |

See [How dply works](/docs/how-dply-works) for what each mode does with a request.

## Frameworks

dply recognizes these frameworks and runtimes. The full list, with versions and detection rules, is in [Frameworks & runtimes](/docs/frameworks).

| Framework | Language | Default mode |
|---|---|---|
| Laravel, Symfony, other Composer apps | PHP | Container |
| Rails, Sinatra, other Rack apps | Ruby | Container |
| Express, Fastify, Koa, NestJS | Node.js | Container |
| Next.js, Nuxt, SvelteKit, Remix, Astro, Hono, Keel | Node.js | Static, Hybrid or Worker SSR, depending on the project |
| Vite, Gatsby, Eleventy, VitePress, Docusaurus | Node.js | Static |
| Plain HTML | None | Static |

Python (Django, Flask, FastAPI), Go and WordPress are not supported.

## What dply manages

- **Builds.** Each deploy clones your repository and runs its build in an isolated container. See [Builds](/docs/builds).
- **Deployments.** On GitHub, every push to your production branch can deploy automatically. Earlier deployments stay available to roll back to. See [Deployments](/docs/deployments).
- **Preview deployments.** Pull requests on GitHub get their own preview URL. See [Preview deployments](/docs/preview-deployments).
- **Domains and TLS.** Every app gets a dply hostname. You can attach your own domains, and certificates are issued for you. See [Domains](/docs/domains).
- **Resources.** Key-value storage, object storage, SQL databases, Postgres, MySQL, MongoDB, Valkey, queues, realtime WebSockets and more, attached to an app from its **Overview** page. See [Resources overview](/docs/resources).
- **Background work.** Queue workers and scheduled tasks for container apps. See [Queue workers](/docs/queue-workers) and [Scheduled tasks](/docs/scheduled-tasks).
- **Security.** Firewall rules, rate limits, bot protection, access control and a waiting room at the edge. See [Firewall](/docs/firewall).
- **Observability.** Traffic analytics, build and request logs, and alerts. See [Traffic & analytics](/docs/traffic).

## Prerequisites

- A GitHub, GitLab or Bitbucket account that can read the repository you want to deploy.
- A payment card. New organizations start with a 5-day trial of the Pro plan, and Checkout asks for a card up front. See [Free trial](/docs/free-trial).
- For Worker SSR, SvelteKit, Astro and Remix need their Cloudflare adapter in your project. Next.js and Keel don't. See [Server rendering (SSR)](/docs/server-rendering).

## Next steps

- [Quickstart](/docs/quickstart): deploy your first app.
- [How dply works](/docs/how-dply-works): what happens between a push and a live URL.
- [Plans & pricing](/docs/pricing): what each plan includes.
