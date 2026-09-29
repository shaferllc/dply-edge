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

Container apps are checked before they take traffic. Once the app has a live version, each deploy first starts the new version on its own, at your app's address with `--next` added to the first part (for example `shop--next.on-dply.live`), with no visitors and without your queues or scheduled tasks. dply then:

1. Runs your migrations there: `php artisan migrate --force` for Laravel, `rails db:migrate` for Rails. This is the release step, so you don't run them yourself.
2. Requests the new version. If migrations fail, or it answers with a server error, the deploy fails with the error your app logged, and production keeps running the previous version.
3. Only when it works, moves production to the new version and removes the checked copy.

The checked copy runs for a minute or two per deploy and is billed like any container time. Migrations run before the switch, so for a moment the previous version runs against the new schema: add columns and tables in one deploy and remove old ones in a later one. An app's first deploy has nothing to protect, so it migrates and checks in place.

Container apps roll out new instances instead of switching files. By default the rollout is gradual: a new instance starts before an old one stops. After the rollout, dply waits up to three minutes for the new instances to report healthy, then requests the live URL. If an instance fails, the deploy fails. You can change the rollout strategy on **Overview**: select the **App** card, then **Sleep, scaling, region**. See [Container apps](/docs/containers).

## Watch a deploy

While a deploy is running, a bar at the bottom of every page shows it: the app, the step it is on, and how long it has been running. The steps are **Cloning**, **Building**, **Publishing**, and for container apps **Pushing image**, **Rolling out** (with a percentage) and **Checking the app answers**. The bar shows every running deploy in your organization for apps you can open. Preview deploys show only while you are in that app. With more than three running, the rest collapse into **+N more deploying**.

Select a deploy in the bar to see its last few log lines as they arrive, with:

- **Full log**: opens the deploy's complete log on **Build & deploy logs**, which keeps updating while it runs.
- **Open app**: goes to the app.
- **Cancel**: stops the deploy after you confirm. Only people who can deploy the app see it.

**Minimize** shrinks the bar to a dot with a count, until you open it again. Your browser remembers the choice.

When the deploy finishes, the bar turns green and disappears, or red and stays until you choose **Dismiss**. A red bar has **Redeploy**, which queues the same commit again. You get a notification with a link to the app or the log if you started the deploy (from the dashboard, or by pushing to GitHub from a GitHub account linked to dply), or if you are on that app's pages.

A build that is waiting for another build in your organization to finish shows **Queued**. See [Builds](/docs/builds) for concurrency limits.

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
