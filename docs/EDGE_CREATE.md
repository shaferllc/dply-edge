---
title: "Create an Edge app"
slug: edge-create
category: "Edge"
order: 30
description: "Step-by-step Edge create wizard covering naming, connecting Git, build detection, static vs hybrid output, delivery backend, submission, and local fake-edge mode."
group: edge
---

# Create an Edge app

Open **Infrastructure → Edge → Deploy an edge app** to connect Git and publish your first deploy.

## Step 1 — Name your app

Enter an **App name** used in the Edge index, site workspace, and preview URLs. Pick something recognizable for your team.

## Step 2 — Connect Git

Choose a **linked source control account** (GitHub, GitLab, or Bitbucket) or enter the repository manually:

- **Repository** — `owner/repo` format
- **Production branch** — branch deployed to production (often `main`)

If you have no linked account, use **Connect a provider** from Profile → Source control first.

## Step 3 — Detect build settings

When you paste or select a repository, dply attempts to detect:

- Framework (Vite, Next.js, Astro, etc.)
- **Build command**
- **Output directory**

Click **Detect runtime** to retry detection manually. Review the detection panel before continuing.

## Step 4 — Build output

### Static / SSG (default)

For fully static output, keep **Static / SSG** selected. Optional overrides:

- **Build command** — e.g. `npm run build`
- **Output directory** — folder containing deployable files, e.g. `dist` or `out`

### Hybrid (SSR)

If your repo needs server rendering:

1. Select **Hybrid**.
2. Choose one of:
   - **Auto-provision hybrid stack** — dply creates a linked Cloud origin from the same repo
   - **Link existing Cloud app** — pick a Cloud site in this org with the same repository
   - **External origin URL** — HTTPS URL of an existing SSR server

Confirm the hybrid stack modal when auto-provisioning.

## Step 5 — Edge delivery

### Delivery backend

| Option | Description |
|--------|-------------|
| **Dply Edge (managed)** | Platform Cloudflare; instant `{slug}.on-dply.site` hostname |
| **Your Cloudflare account** | Pick an org credential; routes publish to your zone |

### Other options

- **SPA fallback** — serve `index.html` for unknown paths (client-side routers)
- **Deploy on push** — auto-deploy when commits land on the production branch

## Submit

- **Deploy edge app** — static/SSG path
- **Deploy hybrid stack** — when hybrid + auto-provision is selected

After submit you are redirected to the site **provisioning journey** until the first build completes, then the full workspace opens.

## Local testing notice

In local development you may see **Fake-edge mode is on**: builds use an in-memory backend with synthetic hostnames and no Cloudflare credentials.

## Sidebar pricing

The create sidebar summarizes the plan and included usage allowances. Sites past the plan and Worker SSR sites are billed on the organization invoice. Use **Browse documentation** for this guide anytime during create.
