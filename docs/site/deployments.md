---
title: "Deployments"
description: "How a deploy goes live, how to follow it, and how to redeploy, deploy a specific commit, roll back, and apply settings changes."
---

A deployment is one build of your app, published to the edge. Every deploy, whether it starts from a push, a deploy hook, the CLI, or the dashboard, creates a new deployment with its own build log and its own permanent URLs. The **Deploys** page lists them and is where you redeploy, deploy a specific commit, or roll back.

## How a deploy goes live

1. **Build.** dply clones the commit and builds it. See [Builds](/docs/builds).
2. **Publish.** dply uploads the output and, for server-rendered apps, the Worker.
3. **Switch.** dply points your production hostname and custom domains at the new deployment in one step. There is no window where the site is down, but the switch can take up to about a minute to reach every edge location, so some visitors briefly still get the previous deployment.

If a build or publish fails, nothing switches: the previous deployment keeps serving traffic, and the failed deployment shows its error.

Starting a new deploy cancels any deploy of the same app that is still building or publishing. The cancelled one shows `Cancelled — a newer deploy (…) replaced it.`

### Container apps

Container apps roll out new instances instead of switching files. By default the rollout is gradual: a new instance starts before an old one stops. After the rollout, dply waits up to three minutes for the new instances to report healthy, then requests the live URL. If an instance fails, the deploy fails. You can change the rollout strategy on the app's **Container** page. See [Container apps](/docs/containers).

## Watch a deploy

While a deploy is running, **Deploys** shows a live card that streams the log by step: cloning, installing and building, and publishing. Choose **Open full build log** to see everything.

A build that is waiting for another build in your organization to finish shows `Queued — waiting for an earlier build in this organization to finish.` See [Builds](/docs/builds) for concurrency limits.

To stop a build and start it again, choose **Restart build** on the live card and confirm. The build is marked failed and the same commit is queued again.

Builds that run past your plan's build timeout are stopped automatically.

## Deploy history

**Deploys** opens with a sentence such as "3f2a91c has been live for 2 hours. The deploy after it failed. 4 earlier builds are ready to roll back to." Below it, **History** lists the 20 most recent deployments as rows, such as "3f2a91c is live · Fix header spacing" or "8bd04e2 failed", with their status and when.

Click a deployment to open it in a dialog: its commit, branch, author and build time, the failure reason if it failed, its alias URLs, and **Roll back to this** or **Rebuild from this commit** when they apply. **Details** and **Build log** open its full page.

The full page has three tabs:

- **Overview**: the full commit SHA and message.
- **Aliases**: the deployment's permanent URLs.
- **Build log**: the full log. After the deploy finishes, this is the archived log.

### Deployment aliases

Every published deployment gets alias hostnames that always serve that deployment, even after production moves on:

```bash
<app>--d-<deployment id suffix>.on-dply.live
<app>--<short sha>.on-dply.live
```

Use them to compare versions side by side or to link to an exact build. An alias serves files only while the deployment's files are kept. See Retention below.

## Redeploy

In your app, open **Deploys** and choose **Redeploy the latest** (your production branch). dply builds the latest commit on your production branch with your current settings and environment variables. Use it after changing settings that take effect on the next deploy.

The first time, when an app has no deployments, the button reads **Trigger first deploy**.

From the CLI, run `dply deploy`.

### Changes that need a redeploy

Many pages say **Redeploy to apply** after you save. Those settings are stored immediately but reach your running app only with the next build, so redeploy after saving:

- Environment variables and linked secrets
- Build settings: build command, output directory, repository root
- Resources you add, remove, or reconfigure
- Container settings
- Cron schedules
- Anything in `dply.yaml` or `wrangler.toml`, which needs a commit and a deploy

Other settings apply as soon as you save, without a redeploy: SPA fallback, preview protection, split traffic, the deploy footer, image optimization, hybrid origin, and dashboard settings for routing, caching, firewall, rate limits, bot protection, waiting room, error pages, forms, snippets, and tags.

> [!IMPORTANT]
> Rolling back, or deploying a specific commit that is already in your history, reuses the earlier build. It does not pick up new environment variables or settings. Redeploy to apply changes.

## Deploy a specific commit, branch, or tag

1. In your app, open **Deploys** and choose **Deploy a specific commit, branch or tag**.
2. Enter a commit SHA, or choose **Browse** to pick a branch tip, tag, or commit from your repository.
3. Choose **Deploy**.

The ref deploys to production. If you picked a branch, the deployment records that branch, but the app's production branch does not change: the next push or redeploy builds the production branch again.

If that commit was already built and its files are still kept, dply switches production back to that build instead of rebuilding. If the commit is already live, you see `That commit is already live.`

Browsing refs needs a linked account for your Git provider. Without one, **Deploys** shows **Connect GitHub** (or GitLab, or Bitbucket). You can still type a SHA.

From the CLI, run `dply edge deploy --commit <sha>`.

## Roll back

To make an earlier deployment live again:

1. In your app, open **Deploys** and click an earlier deployment marked **Roll back**.
2. Choose **Roll back to this**, then confirm.

Rolling back re-points production at that deployment's files. It is immediate and does not rebuild, so the app runs with the environment variables and settings that deployment was built with. The rolled-back deployment becomes `live` and the one you left becomes `superseded`.

For container apps, rolling back rebuilds that deployment's commit, so it takes as long as a normal deploy and uses your current environment variables.

From the CLI, run `dply edge rollback <deployment-id>`.

### Retention

Each app keeps the files of its most recent deployments so you can roll back to them. By default the last 10 are kept. To change it, open **Build** and click the releases-kept row; choose from 1 to 50.

Older deployments stay in the history marked **Files removed**. Their dialog offers **Rebuild from this commit** instead of rolling back, which opens **Deploy a specific commit** with that commit filled in.

## Skew protection

When a deploy goes live, visitors with the previous version still open in their browser may request JavaScript or CSS files that no longer exist in the new build. dply handles this automatically: if a request for an asset returns `404` in the live deployment, dply serves it from one of the five previous deployments instead, newest first. HTML pages always come from the live deployment. This works only while those deployments' files are kept.

## Deploy notifications

dply sends these events to your organization's notification channels:

| Event | When |
|-------|------|
| **Edge deploy succeeded** | A deployment goes live. |
| **Edge deploy failed (action required)** | A build or publish fails, or a container app is unhealthy after deploying. |
| **Edge deploy got noticeably slower** | A deploy took more than 1.5 times the median of recent deploys. |

Preview deployments send these events too. See [Notification channels](/docs/notifications).

## Related

- [Builds](/docs/builds)
- [Deploy triggers & hooks](/docs/deploy-triggers)
- [Preview deployments](/docs/preview-deployments)
- [Logs](/docs/logs)
