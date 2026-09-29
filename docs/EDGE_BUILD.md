---
title: "Edge build"
slug: edge-build
category: "Edge"
order: 70
description: "How to configure how dply compiles an Edge repo, including build command, output directory, SPA fallback, dply.yaml config, and deploy retention."
group: edge
---

# Edge build

The **Build** section is where you configure **how dply compiles your repo** and what gets uploaded after each deploy. It does **not** cover webhooks, CDN delivery, env vars, or preview passwords — those live in sibling sidebar sections (see **Edge overview → Workspace sections**).

## What belongs here

| Topic | Where in Build |
|-------|----------------|
| Connected repo & branch (read-only) | **Repository & branch** |
| `dply.yaml` snapshot from last deploy | **Managed by dply.yaml** |
| Redirects / rewrites / headers (read-only summary) | **Redirects, rewrites & headers** |
| Build command, output dir, SPA fallback | **Build configuration** |
| Deploy-on-push toggle | **Build configuration** (webhook setup is under **Deploy triggers**) |
| How many past releases to keep | **Deploy retention** |

## Repository & branch (read-only)

Shows **Repository**, **Production branch**, and optional **Repository root** from site creation. Changing repo or branch requires a new Edge site in v1.

## Repo config (`dply.yaml`)

When your repo ships a `dply.yaml` (or `dply.toml`), its build keys (`build.command`, `build.output`, `build.root`) and parse warnings from the latest deploy appear in a row on Build that opens a dialog; overridden settings are marked with the file name. Repo config can override dashboard build fields and defines redirects, rewrites, headers, tags, snippets, forms, error pages, and more. Edit the file in Git and redeploy — routing rules stay read-only in the dashboard; tags/snippets/forms/error pages can also be edited in the UI (dashboard Save overrides the matching repo section).

See **Edge routing** for a focused view of redirects, rewrites, and headers.

## Build configuration

Editable fields (click **Save build settings**, then redeploy to apply):

| Field | Purpose |
|-------|---------|
| **Build command** | Shell command run in CI, e.g. `npm ci && npm run build` |
| **Output directory** | Folder uploaded to edge storage, e.g. `dist` |
| **Repository root** | Monorepo subdirectory; builds run from this folder |
| **SPA fallback** | Serve `index.html` when a static file is missing (SPA routers) |
| **Deploy on push** | When enabled, pushes to the production branch can trigger builds (requires **Deploy triggers → GitHub auto-deploy**) |

## Deploy retention

**Releases to keep** controls how many past deployments remain available for rollback in **Deploys**.

## Related sections

- **Environment** — production secrets for builds and workers
- **Deploy triggers** — deploy hooks and GitHub webhooks
- **Delivery** — CDN backend, hybrid SSR origin, image optimization
- **Routing** — full read-only view of `dply.yaml` routing rules
- **Previews** — preview protection and comment widget (parent sites only)

## After saving

Build changes apply on the **next deploy**. Use **Deploy** on Overview or **Deploys → Redeploy** to apply immediately.
