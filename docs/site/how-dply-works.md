---
title: "How dply works"
description: "What happens between a Git push and a live URL, how requests reach your app, where your data lives, and how customers are kept apart."
---

dply runs your app on Cloudflare's global network. This page explains the pieces involved: the deploy pipeline that turns a commit into a deployment, the request path that serves it, and where your code and data are kept. You don't need any of this to deploy, but it helps when you choose a runtime mode or debug a request.

## The pieces

| Piece | Role |
|---|---|
| dply dashboard and API | Where you configure apps. Stores settings, environment variables and deployment history, and runs builds. |
| Build workers | Clone your repository and run your build in a throwaway container. |
| Object storage (Cloudflare R2) | Holds the files each deployment produced. |
| Host map | A table at the edge that maps every hostname to the deployment it serves and that app's routing, security and cache settings. |
| Platform Worker | dply's Cloudflare Worker. Every request to a dply app passes through it first. |
| Your Worker | For Worker SSR apps, your server code, uploaded as its own Worker script. |
| Your container | For container apps, your image running on Cloudflare Containers, fronted by a Worker of its own. |

## The deploy pipeline

A deployment starts when you push to your production branch on GitHub (with **Deploy on push** on), when you choose **Deploy** in the dashboard, or when you run `dply deploy` from the [CLI](/docs/cli).

1. **Queue.** dply records a new deployment for the commit.
2. **Clone.** A build worker clones the repository at that commit.
3. **Build.** The build runs in a fresh container.
   - Static, hybrid and Worker SSR apps build in a Node.js image with your build command. See [Frameworks & runtimes](/docs/frameworks).
   - Container apps build a container image from your `Dockerfile`, or from one dply generates.
4. **Upload.**
   - Static and hybrid: the output directory is uploaded to object storage. Files that haven't changed since the live deployment are copied, not uploaded again.
   - Worker SSR: the static assets go to object storage and the server bundle is uploaded as your Worker.
   - Container: the image and its fronting Worker are deployed to Cloudflare.
5. **Publish.** dply updates the host map so your hostnames point at the new deployment. This is the moment a static, hybrid or Worker SSR deployment goes live.

If the build fails, the previous deployment keeps serving. A container app's new image replaces the running one during the upload step, so a container deploy that fails after that point can leave the new image running. The build output is in **Build & deploy logs**. See [Deployments](/docs/deployments).

### Rollbacks

For static, hybrid and Worker SSR apps, a rollback points the host map back at an earlier deployment's files. Nothing is rebuilt, so it takes effect in seconds. Deployments older than your **Releases to keep** setting have their files deleted and can't be rolled back to directly.

A container app runs one image at a time, so rolling it back rebuilds and redeploys the earlier commit.

## The request path

Every request to a dply hostname, including your custom domains, arrives at the nearest Cloudflare data center and runs through the platform Worker:

1. The Worker looks up the hostname in the host map.
2. It applies the app's edge settings: firewall rules, rate limits, bot protection, access control, the waiting room, redirects, rewrites and headers.
3. It serves the request according to the app's runtime mode.

| Mode | How the request is served |
|---|---|
| Static | The file comes from the edge cache, or from object storage on a cache miss. |
| Hybrid | Static files come from the edge. Paths that match the app's proxy routes go to your origin server. |
| Worker SSR | Static assets come from the edge. Everything else runs in your Worker. |
| Container | The request goes to your container, which serves the whole app. A container that is asleep starts on the first request. |

See [Edge network](/docs/edge-network), [Caching](/docs/caching) and [Scaling & sleep](/docs/scaling-and-sleep).

## Preview deployments

A preview is a deployment of a branch or pull request that doesn't touch production. It gets its own hostname and runs through the same pipeline and request path. Each deployment also gets its own stable alias hostname, so you can always reach a specific build. See [Preview deployments](/docs/preview-deployments).

## Where your data lives

| Data | Where |
|---|---|
| Settings, deployment history and environment variables | dply's database. Environment variable values are encrypted at rest. The API and CLI return keys only; members who can edit the app see values in **Environment**. |
| Build output | Cloudflare R2 object storage, until the deployment passes your retention setting. |
| Cloudflare-native resources: key-value, object storage, Edge SQL, queues, state, vector search | Cloudflare, created and managed by dply for your app. |
| Postgres, MySQL, MongoDB and Valkey | Infrastructure dply runs. Container apps that use them are placed near them. |

See [Resources overview](/docs/resources) and [Data regions](/docs/data-regions).

## Isolation between customers

- **Builds** run in a fresh container per build, with no access to other builds. Package caches are kept per organization.
- **Server code** runs as a separate Worker script, or a separate container, for each app. Apps don't share a process or memory.
- **Resources** belong to one organization. An app can only attach resources from its own organization.
- **Environment variables** are encrypted at rest, and the API and CLI never return their values.

See [Platform security & isolation](/docs/platform-security) for details.

## Related

- [Frameworks & runtimes](/docs/frameworks)
- [Builds](/docs/builds)
- [Deployments](/docs/deployments)
- [Platform security & isolation](/docs/platform-security)
