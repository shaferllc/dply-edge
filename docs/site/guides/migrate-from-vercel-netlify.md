---
title: "Migrate from Vercel or Netlify"
description: "Move a site from Vercel, Netlify or Cloudflare Pages to dply with the import wizard, then move redirects, environment variables and custom domains without downtime."
---

dply's import wizard reads a project's build settings and environment variables from Vercel, Netlify or Cloudflare Pages and hands them to the create page. You deploy once on a dply address, check the site, then move your domain. Nothing changes on the old host until you switch DNS.

## What carries over

| From the old host | On dply |
|---|---|
| Repository and production branch | Pre-filled on the create page |
| Build command and output directory | Pre-filled; editable later under **Build** |
| Framework | Sets the delivery mode (see below) |
| Environment variables | Saved as production variables on the new app |
| `_redirects` rules | Paste them under **Routing** (not read from the build output) |
| Custom domains | Listed in the wizard with a DNS runbook; you add them yourself |
| Serverless and edge functions | Not converted. See [Functions and server code](#functions-and-server-code) |

## Import the project

1. On the dashboard, choose **Import a site**.
2. Pick the provider and paste an access token:
   - **Vercel**: a personal access token (Account → Tokens). Team accounts also need the **Team id**.
   - **Netlify**: a personal access token (User settings → Applications → Personal access tokens).
   - **Pages**: an API token with Pages:Read plus the **Account id**.
3. Choose **Verify + list projects**, then pick the project.
4. Review **Preview import**: repository, branch, framework, **Runtime mode**, **Build command**, **Output dir** and the environment variable keys.
5. Choose **Continue to Create →**, then **Deploy**.

The token stays in memory for the session only; dply does not store it. Nothing is created until you choose **Deploy**.

> [!NOTE]
> Environment variables whose values the provider will not return, such as Vercel's sensitive variables or secrets Cloudflare Pages redacts, are skipped. Add them under **Environment** before the first deploy that needs them.

## Check the delivery mode

The wizard picks the mode from the framework:

- Static site generators and plain builds become **Static**.
- On Vercel, Next.js, Remix, SvelteKit and Nuxt projects are marked for **Worker SSR**.

Worker SSR on dply supports Next.js (through OpenNext), Astro, SvelteKit (with `@sveltejs/adapter-cloudflare`) and Remix (with `@remix-run/cloudflare`). A Nuxt project marked for SSR will not build; deploy it as a static site with `nuxt generate` instead. See [Deploy a Next.js app](/docs/guides/nextjs) for the Next.js options.

## Move redirects and headers

- **Netlify `_redirects`**: open **Routing**, choose **Import in bulk**, paste the file's lines under **Redirects to import**, and choose **Import**. Each line is `/from /to 301`. A `_redirects` file left in your build output is not read.
- **Cloudflare Bulk Redirects CSV** pastes into the same box.
- **`vercel.json` and `netlify.toml`** redirects, rewrites and headers: rewrite them in `dply.yaml`:

  ```yaml
  redirects:
    - from: /docs/*
      to: /guide/:splat
      status: 301

  rewrites:
    - from: /api/*
      to: https://api.example.com/:splat

  headers:
    - for: /assets/*
      values:
        Cache-Control: "public, max-age=31536000, immutable"
  ```

See [Routing, redirects & headers](/docs/routing) and [Configuration files](/docs/configuration-files).

## Functions and server code

dply does not convert Vercel or Netlify Functions. Your options:

- **Next.js API routes and middleware** run in Worker SSR mode.
- **Middleware for static sites**: see [Edge middleware](/docs/edge-middleware).
- **A separate API server** (Express, Fastify, Nest, Koa, Laravel, Rails) runs as its own container app. See [Container apps](/docs/containers).
- **An API you keep elsewhere**: proxy it with a `rewrites` rule, or use **Hybrid** mode with its URL as the origin.

Netlify Forms map to dply [Forms](/docs/forms).

## Move the domain

Deploy first and check the site on its dply address. Then follow the runbook the wizard shows:

1. Add each domain under **Routing**, **Domains**. dply shows the CNAME target for it.
2. Lower the TTL on the existing DNS record to 60 seconds and wait one full TTL.
3. Change the record that points at the old host (for example `cname.vercel-dns.com` or `apex-loadbalancer.netlify.com`) to the dply CNAME target. Apex domains need an ALIAS, ANAME or flattened CNAME at most DNS providers.
4. Choose **Verify DNS** and wait for the domain to show ready with TLS active.
5. Remove the domain from the old host.

See [Domains](/docs/domains) and [Domain verification](/docs/domain-verification).

## Next steps

- [Preview deployments](/docs/preview-deployments)
- [Environment variables](/docs/environment-variables)
- [Troubleshooting builds](/docs/guides/troubleshooting-builds)
