---
title: "Apps"
description: "Create an app from a Git repository, understand what dply sets up for it, and delete it when you no longer need it."
---

An app is one deployable project in dply: a Git repository and branch, the settings used to build it, and everything that serves it, from its URLs and domains to its environment variables and resources. Each app has its own workspace with its deploy history, settings, and logs. Your apps are listed on the dashboard.

## Create an app

> [!NOTE]
> Creating an app needs a plan or an active trial. If your organization has neither, choosing **Deploy** takes you to checkout to start the free trial first. See [Free trial](/docs/free-trial).

1. Choose **New app** in the top bar, or **Deploy an app** on the dashboard.
2. **Connect your source control.** Pick a linked GitHub, GitLab, or Bitbucket account, or choose **Connect GitHub, GitLab, or Bitbucket** to link one. You can instead paste a repository into **Or paste a repository**. Choose **Next**.
3. **Select a repository.** Pick one from the account's list, or enter a **Repository URL**. Choose **Next**.
4. **Create your application.** Choose the **Branch or tag** to deploy and enter an **App name**, up to 80 characters.
5. Wait for detection to finish. Under **What are you deploying?**, keep the **Recommended** choice or pick **Site** or **App**.
6. Choose **Deploy**.

Public and private repositories both work. dply clones a private repository with the account you picked it from. See [Source control](/docs/source-control#private-repositories).

dply opens the new app's **Overview**, which follows the first build as it clones, builds, and publishes. When it finishes, the app is live at its URL.

### What dply detects

While you fill in the last step, dply reads the repository to work out how to build and serve it. It shows the result, such as `astro · Site`, and fills in:

- Whether it's a **Site** or an **App**, marked **Recommended**. See the next section.
- The **build command** and **output directory** for your framework, under **Advanced**. With no match, dply uses `npm ci && npm run build` and `dist`.
- For monorepos, the package to deploy. dply asks **Which package should we deploy?** when it finds more than one. See [Monorepos](/docs/monorepos).

Detection runs automatically; there is no button to rerun it other than changing the branch. To change the build command or output directory, open **Advanced** before you choose **Deploy**, or change them later on the **Build** page. See [Builds](/docs/builds).

### Site or App

| Choice | Runs as | Used for | Details |
|--------|---------|----------|---------|
| **Site** | Static | Static sites, SSGs and single-page apps | [Static & hybrid sites](/docs/static-and-hybrid) |
| **App** | Worker SSR | Server-rendered JavaScript, such as Next.js without `output: 'export'` | [Server rendering (SSR)](/docs/server-rendering) |
| **App** | Container | PHP, Rails, and Node.js servers (Laravel, Symfony, Rails, Sinatra, Nest, Express, Fastify, Koa) | [Container apps](/docs/containers) |
| **Advanced**: hybrid | Hybrid | Static pages on the edge with server routes sent to a server you already run. dply asks for its **Origin URL**. | [Static & hybrid sites](/docs/static-and-hybrid) |

For **App**, dply picks Worker SSR or a container from what it detected. Detection recommends **App** for Next.js server apps and server stacks, and **Site** for everything that builds static files. SvelteKit, Remix and other server-rendered frameworks are recommended as hybrid, because rendering them on the edge needs a Cloudflare adapter in your repository; choose **App** yourself if yours has one. A deploy link can also pick with `runtime_mode=static`, `hybrid`, `ssr` or `container`.

The runtime cannot be changed after creation, except converting a static app to hybrid on **Delivery**.

### Plans and pricing

Apps are unlimited on every plan, subject to fair use, and there's no per-app fee. Container apps and Worker SSR apps bill for the compute and Workers CPU they use, at the usage rates. Preview deployments aren't billed as apps. See [Plans & pricing](/docs/pricing).

## What dply sets up

When you create an app, dply:

- Gives it a URL of the form `<app-name>-<random>.on-dply.live`, with HTTPS. Add your own domains on **Domains**. See [Domains](/docs/domains).
- Stores the repository and branch. Both are fixed: to deploy a different repository or branch, create a new app.
- Queues the first deployment.

- Turns on push-to-deploy when the repository is on GitHub and you have a linked GitHub account: it registers the webhook, so every push to the branch deploys and pull requests get previews. If the webhook cannot be registered, for example because the account lacks admin access to the repository, the app is still created and you can connect it later under **Deploy triggers**. See [Deploy triggers & hooks](/docs/deploy-triggers).

### Other ways to create an app

- **Templates.** Choose **Browse templates** on the dashboard to start from a ready-made project.
- **Import.** Choose **Import a site** to bring an existing site into dply. See [Migrate from Vercel or Netlify](/docs/guides/migrate-from-vercel-netlify).
- **Deploy link.** A link to `/deploy?repo=owner/name` opens the create page with the repository filled in. You can also pass `branch`, `name`, `runtime_mode` (`static`, `hybrid`, `ssr` or `container`), `build_command`, and `output_dir`.

## If the first build fails

The **Overview** shows the error and the build log. Fix the repository or the build settings, then choose **Retry build**.

To give up instead, choose **Cancel build**. For an app that has never been live, cancelling the first build deletes the app.

## Rename an app

Apps cannot be renamed. The name is also part of the app's URL.

## Delete an app

Only organization owners and admins can delete an app.

**From the app:**

1. Open **Danger zone** and choose **Delete Edge site**.
2. Confirm with **Delete Edge site**.

The page shows **This app is being removed** while dply tears it down, then returns you to the dashboard.

**From the dashboard**, open the app card's menu and choose **Delete…**. Under **When to delete**, choose:

- **Delete now**
- **In 30 minutes**
- **Schedule date/time**, then pick a **Deletion date/time** in the future

A scheduled app keeps serving traffic until its deletion time. There is no way to cancel a scheduled deletion from the dashboard.

> [!WARNING]
> Deleting an app cannot be undone. Its production URL, deployment aliases, and custom domains stop serving immediately after teardown. A dply database only this app uses is deleted with it, backups included; the delete dialog names each one. A database other apps also use is only detached. Export a database from its card first if you need the data.

### What deleting removes

- Every deployment and its published files, and the app's routing on the edge
- Server-rendering Workers, containers, and Realtime connections for the app
- Environment variables, deploy hooks, members, access rules, preview comments, and the app's own **Audit log**
- Links to resources and organization secrets
- Its preview deployments
- The GitHub webhook on your repository
- Its custom domains at Cloudflare (hostnames and certificates)
- The app's default key-value store (`env.KV`)

Cleanup is best effort: if one step fails, for example because GitHub rejects the webhook removal, the rest of the teardown still runs and dply logs the failure.

The app stops counting toward your plan once teardown finishes.

### What deleting keeps

Some things belong to your organization or to other services and are not removed. Clean them up yourself if you no longer need them:

| Kept | What to do |
|------|------------|
| DNS records for custom domains at your DNS provider | Delete them at your DNS provider. |
| **Organization resources**: Edge SQL databases, queues, buckets, and key-value stores | Delete them from your organization's resources. See [Resources overview](/docs/resources). |
| Organization secrets | They stay in the vault, unlinked. |
| Your organization's activity log, usage history, and invoices | Kept. |

## Related

- [Source control](/docs/source-control)
- [Builds](/docs/builds)
- [Deployments](/docs/deployments)
- [Plans & pricing](/docs/pricing)
