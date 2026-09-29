---
title: "Troubleshooting builds"
description: "Find why a dply build or deploy failed, with the exact messages the build log shows and how to fix each one."
---

When a deploy fails, the reason is on the deployment itself. Start there, match the message to a section below, fix the cause, and redeploy.

## Where to look

- **Deploys** lists every deployment with its status. Open one to see the full build log and the failure summary.
- **Build & deploy logs** shows the same output across deployments.
- While a build runs, the card on **Overview** streams clone, install, build and publish steps.

The failure summary keeps the last 40 lines of output. Scroll up in the full log for the first error: later lines are often a consequence of it.

## A build that never starts

| Message | Cause and fix |
|---|---|
| "Start your 5-day trial on the billing page to deploy." | The organization has no plan yet. Start the trial on the organization's **Billing** page. See [Free trial](/docs/free-trial). |
| "This organization has no plan, so deploys are paused. Choose a plan on the billing page." | The trial ended or the subscription lapsed. Choose a plan; see [Paused accounts](/docs/paused-accounts). |
| "The trial's $5 usage credit is used up. Builds and traffic pause until the trial ends…" | Usage during the trial reached its $5 cap. Builds and container traffic stay paused until the trial converts to a paid plan. See [Spending caps & alerts](/docs/spending-alerts). |
| "Queued — waiting for an earlier build in this organization to finish." | Not an error. Your plan's concurrent builds are in use (1 on Starter, 2 on Pro, 5 on Team). The build starts when one finishes. |

## Clone and configuration

| Message | Cause and fix |
|---|---|
| "Git clone failed after … attempts: …" | dply could not fetch the repository. Check that the source control account still has access to it and that the branch or tag exists. See [Source control](/docs/source-control). |
| "dply config lint failed: …" | `dply.yaml` (or `dply.yml`, `dply.json`) has an error. Each `[dply.yaml] ERROR:` line in the log names the key. See [Configuration files](/docs/configuration-files). |
| "This repository looks like a framework or monorepo package root, not a single Edge site…" | The repository root is a library, not an app. Set **Repository root** under **Build** to the app's folder, such as `apps/web`. See [Monorepos](/docs/monorepos). |

## The build command failed

"Build failed (exit N): …" means your build command exited with an error. The lines after it are the tail of its output.

Common causes:

- **Wrong Node version.** The log shows `[node] Detected Node 20 from …` or `[node] No version hints in repo — using default …`. Pin a version with `engines.node`, `.nvmrc`, `.node-version` or the `packageManager` field. Node 18, 20, 22 and 24 are available.
- **Wrong package manager.** dply installs with the tool that matches your lockfile (`pnpm-lock.yaml`, `yarn.lock`, `bun.lock`, `package-lock.json`). Commit exactly one lockfile.
- **Missing environment variables at build time.** Variables under **Environment** are passed to the build. Add the ones your build reads, such as API keys used during prerendering, then redeploy.
- **Private packages.** Add the registry token (for example `NPM_TOKEN`) under **Environment** and reference it from `.npmrc`.

Reproduce locally with the same install and build command before redeploying.

## The output is missing or wrong

| Message | Cause and fix |
|---|---|
| "Build output directory not found: dist" | The build wrote somewhere else. Set **Output directory** under **Build** to the folder your framework writes (`dist`, `build`, `out`, `_site`, `public`). |
| "Build produced no files in output directory: …" | The folder exists but is empty. Check that the build actually ran and wrote files there. |
| "Build output is missing index.html at the root of: …" | Static sites need an `index.html` at the root of the output. For a server-rendered framework, use hybrid or Worker SSR instead. See [Static & hybrid sites](/docs/static-and-hybrid). |
| "Build artifacts exceed maximum allowed size." | The output is over 500 MB. Move large media to [Object storage](/docs/resources/object-storage). |

## Worker SSR builds

| Message | Cause and fix |
|---|---|
| "SSR Edge sites need one of: Keel, Next.js, Astro, SvelteKit, or Remix…" | Worker SSR needs one of these frameworks in `package.json`. Otherwise deploy as static or hybrid. |
| "… needs `@astrojs/cloudflare` in package.json before SSR builds work…" | Install the framework's Cloudflare adapter (`@astrojs/cloudflare`, `@sveltejs/adapter-cloudflare` or `@remix-run/cloudflare`) and redeploy. |
| "… build wrote … but … is missing — check the build log for adapter errors." | The adapter ran but did not produce its Worker entry. Read the adapter's output earlier in the log. |
| "SSR worker bundle (…) exceeds the per-script size limit (…)" | The Worker bundle is over 9 MB. Trim server dependencies or move heavy work to an API. |

Next.js in Worker SSR mode always runs `npx --yes @opennextjs/cloudflare@latest build`, whatever your build command. See [Deploy a Next.js app](/docs/guides/nextjs).

## Container builds

| Message | Cause and fix |
|---|---|
| "Container sites need a Dockerfile, composer.json (PHP), Gemfile (Ruby) or package.json (Node) at the repository root." | Set **Repository root** under **Build** to the app's folder, or add a `Dockerfile`. |
| "Container deploy failed: …" followed by image build errors | The image did not build. The lines shown are the errors from the image build; common ones are a PHP extension a package needs, or a gem that fails to compile. |
| "Container deploy failed: the container did not start (… answered HTTP 5xx …). Check the container logs for why it exited." | The image built, but the app crashed on boot. Open **Build & deploy logs**, then **Everything it printed in the last 15 minutes**. |
| "Container deploy failed: … answered HTTP 500: …" | The app started but its home page errors. The same logs show the exception; a missing environment variable or database is the usual cause. |
| "Container deploy failed: … did not answer: …" | The app did not respond within 90 seconds. Check that your own `Dockerfile` listens on the port it `EXPOSE`s, and that boot work (such as migrations) finishes quickly. |

The first build of a PHP or Ruby app compiles or pulls its base image and takes several minutes; later builds reuse the dependency layers until `composer.lock` or `Gemfile.lock` changes.

## Timeouts

A build that runs longer than your plan allows is stopped:

| Plan | Build timeout |
|---|---|
| Starter | 20 minutes |
| Pro | 45 minutes |
| Team | 60 minutes |

Speed up long builds by committing a lockfile, keeping the build cache warm (dply caches framework folders such as `.next/cache` between builds), and building only the package you deploy in a monorepo.

## Still stuck

Redeploy from **Deploys** to rule out a transient failure. If it fails the same way and nothing above fits, contact support with the deployment's link. See [Support](/docs/support).

## Related

- [Builds](/docs/builds)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
- [Deployments](/docs/deployments)
