---
title: "Preview deployments"
description: "Get a live URL for every pull request or any commit, review it with your team, and promote it to production."
---

A preview deployment builds a branch or commit of your app and serves it at its own URL, separate from production. Use previews to review a pull request in a real browser, share work with teammates and clients, and check a change against production traffic before it ships.

dply creates previews two ways:

- **Pull request previews.** With push-to-deploy connected on GitHub, every pull request gets a preview that updates on each push and is removed when the pull request closes.
- **Previews from a commit.** From the dashboard, CLI, or API, you can create a preview of any commit, on any provider.

Previews are managed from your production app's **Previews** page.

> [!NOTE]
> Previews are not billed as apps and do not count toward your plan's fair-use limit on apps. Their builds do count toward your build time and use your build concurrency. See [Builds](/docs/builds).

## Pull request previews

Pull request previews need a repository on GitHub with push-to-deploy turned on. See [Deploy triggers & hooks](/docs/deploy-triggers). Once connected:

- **Opening or reopening a pull request** creates a preview of its branch.
- **Pushing to the pull request** rebuilds the preview at the new commit. The URL stays the same for the life of the pull request.
- **Closing or merging the pull request** tears the preview down.

Pull requests opened from a fork never get a preview, because a preview builds with your app's environment variables and secrets.

On the pull request, dply posts:

- A check run named **dply edge preview**, which shows **Preview deploying**, then **Preview live** or **Preview failed**. Its **Details** link opens the preview.
- One comment headed **dply Edge preview** with the status and URL, edited in place on each push rather than posting a new comment.

GitLab merge requests and Bitbucket pull requests do not create previews. Use a preview from a commit instead.

### Choose which pull requests get previews

By default, every pull request gets a preview. To change that, add a `previews` section to `dply.yaml`:

```yaml
previews:
  enabled: true
  exclude_branches:
    - "renovate/lock-file-maintenance"
```

| Key | Effect |
|-----|--------|
| `enabled` | `false` turns off pull request previews. |
| `exclude_branches` | Branch names that never get a preview. Names must match exactly. |

dply reads this section from the latest **production** deployment, so a change takes effect after it has been deployed to production, not from the pull request that changes it. The **Previews** page summarizes the active policy next to **Auto-deploy** and under **From dply.yaml**.

> [!NOTE]
> `pr_only` and `branches` are also accepted, and the page shows **PRs + branches** when `pr_only` is `false`, but pushes to branches other than production never create previews today. Only pull requests do.

## Create a preview from a commit

1. In your production app, open **Previews**.
2. Under **Create preview from commit**, enter a commit SHA, or choose **Browse** to pick a branch tip, tag, or commit from your repository.
3. Choose **Create preview**.

The button shows **Building…**, then **Propagating…** for about 45 seconds after the build publishes, while the new hostname spreads across the edge. The URL appears in the list when it is safe to open.

Creating a preview of the same commit again returns the existing preview. A different commit gets its own URL.

From the CLI:

```bash
dply edge previews create --commit 3f9c2ab --wait
dply edge previews list
```

## Preview URLs

| Preview | Hostname |
|---------|----------|
| Pull request | `<app>-<id>--pr-<number>.on-dply.live` |
| Branch | `<app>-<id>--<branch>.on-dply.live` |
| Commit | `<app>-<id>--<short sha>.on-dply.live` |

`<app>` is your app's slug and `<id>` is a short identifier for the app. Previews of an app on its own Cloudflare zone use that zone instead of `on-dply.live`.

Each deployment, production or preview, also gets permanent alias URLs. See [Deployments](/docs/deployments).

## Environment variables and resources

Previews build and run with the production app's environment variables and linked secrets, read at each preview build. Change a value on the production app's **Environment** page and the next preview build picks it up. See [Environment variables](/docs/environment-variables).

> [!WARNING]
> Previews inherit production's variables **except** database and Redis connection settings (`DATABASE_URL`, `DB_URL`, `DB_*`, `REDIS_URL`, `REDIS_*`, `MONGODB_URI`, `MONGO_URL`), so a pull request's code never reaches live data. Migrations never run on boot in a preview. To give previews a database, set those keys on the preview with a per-preview override. Other shared services (third-party API keys, mail) are still production's.

To override a value for one preview, set it on the preview through the [HTTP API](/docs/api) (`PUT /api/v1/edge/sites/{preview-id}/env`). A preview's own value wins over production's for that key. The dashboard has no editor for this: a preview's **Environment** page shows **Environment variables are managed on the parent Edge site.**

Previews copy the production app's build settings, repository root, routing, and hybrid origin when they are created.

## Protect previews

By default, anyone with a preview URL can open it. To restrict access, open **Previews** in your production app and find **Protection**:

| Option | Visitors must |
|--------|---------------|
| **Off** | Nothing. The URL is public. |
| **Password** | Enter a password you set. |
| **Dply account** | Sign in to dply with an account that can view the app. Add addresses to **Allowed emails** to limit it further. Leave it empty to allow any member who can view the app. |

Choose **Save protection**. It applies immediately, without a redeploy.

> [!WARNING]
> Protection applies to your live production site as well as to every preview. Turning on **Password** or **Dply account** puts the gate in front of production visitors too.

`dply.yaml` accepts a `previews.protection` block, but it has no effect today: previews always use the protection set on the production app.

## Review and comments

**Comment widget on previews** adds a floating **Comments** button to preview pages, where reviewers leave notes tied to the page they are on. Turn it on under **Previews** and choose **Save**. It applies to previews built after you turn it on. You can also set `comment_widget.enabled: true` in `dply.yaml`.

Choose **Review** on a preview to open the **Preview review hub**. There you can read and reply to threads, resolve them, start a thread on a path, and choose **Approve preview**.

## Promote a preview to production

Promoting makes production serve exactly what the preview built, without rebuilding from your production branch. The preview keeps running.

1. In your production app, open **Previews**.
2. On a live preview, choose **Promote to prod**.
3. Review the checks in the dialog and choose **Promote to production**.

Promote copies the preview's files into a new production deployment, which appears in the history on **Deploys** and can be rolled back like any other. For container apps, promote rebuilds the preview's commit on production with production's environment variables, so it takes as long as a normal deploy.

For static, hybrid, and server-rendered apps, promote reuses the preview's build. The preview was built with production's environment variables, so values baked in at build time, such as `VITE_*`, match production.

> [!IMPORTANT]
> If you set per-preview overrides, promote still ships the preview's build, with the override values baked in. Remove the overrides and rebuild the preview, or deploy the commit to production instead of promoting.

### Checks before promote

Before a preview can be promoted, it must pass its **Deploy contract**. On the preview's row or in the review hub, choose **Run checks**. By default the contract requires:

- **Edge preview build**: the preview built successfully.
- **Preview review**: no unresolved review comments.
- **Shadow replay**: a replay of recent production traffic against the preview matched at least 99% of responses. Choose **Run sample** under **Shadow replay** to run one. It replays up to 20 production `GET` and `HEAD` requests from the last hour. If production had no such traffic in the last hour, as with a new or quiet app, there is nothing to replay and the check passes with the note **No production traffic in the last hour to replay**.
- Origin health checks, for hybrid apps.

If the preview is redeployed, run the checks again. When a check fails and you have verified the change another way, enter a **Waiver reason** and choose **Record waiver**.

To change which checks apply, add a `dply-contract.yaml` to your repository, next to a `dply.yaml`. dply reads it from the preview's own commit, so you can change it in the pull request. For example, to skip the replay requirement:

```yaml
promote:
  requires:
    - edge.preview.build
    - edge.preview.review
```

See [Configuration files](/docs/configuration-files).

## Split traffic

To try a preview on a share of real production traffic before promoting it, enter a percentage from 1 to 99 under **Split** on a live preview and choose **Apply**. That share of visitors to your production URL is served the preview's build. A cookie keeps each visitor on the same version.

- Only one preview can receive split traffic at a time.
- The change applies immediately.
- Choose **Off**, or set `0`, to send all traffic back to production.
- To move all traffic, promote the preview.

## Remove previews

Pull request previews are removed when the pull request closes. To remove any preview yourself, choose **Tear down** on it and confirm. Its files and hostname are deleted and the URL stops responding. Tearing down does not close the pull request, and pushing to the pull request again recreates the preview.

From the CLI, use `dply edge previews rm <preview-id>`.

> [!NOTE]
> Previews from a commit are never removed automatically, and there is no expiry. Tear them down when you are done.

## Related

- [Deploy triggers & hooks](/docs/deploy-triggers)
- [Deployments](/docs/deployments)
- [Access control](/docs/access-control)
- [Environment variables](/docs/environment-variables)
