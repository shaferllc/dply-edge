---
title: "Deploy triggers & hooks"
description: "Start deploys automatically on Git push, from a CMS or CI pipeline with a deploy hook URL, or from the CLI and HTTP API."
---

Most deploys start without anyone opening the dashboard. dply can deploy when you push to your production branch, when an external system calls a deploy hook URL, or when your CI pipeline calls the CLI or API. You manage push-to-deploy and deploy hooks in your app under **Deploy triggers**.

The page opens with one sentence saying what starts a deploy, for example "Pushing to main on acme/web deploys to production. Pull requests get a preview." It also says how many deploy hooks you have and when one last fired. Each trigger is a row below it; click a row to manage it in a dialog.

Every trigger creates a normal deployment. It builds, appears in the history on **Deploys**, and counts toward your build minutes. See [Deployments](/docs/deployments).

## Push to deploy

Push to deploy is available for repositories on GitHub. New apps have it on: when you create an app from a GitHub repository with a linked GitHub account, dply registers the webhook right away. The webhook listens for two events:

- **Push** to the production branch builds and deploys that commit to production.
- **Pull request** opened, reopened, or updated creates or updates a preview deployment, and a closed pull request removes it. See [Preview deployments](/docs/preview-deployments).

Pushes to any other branch are ignored.

### Turn it on

If the webhook was not registered at create, or you disconnected it:

1. In your app, open **Deploy triggers** and click the row under **GitHub**.
2. In **Through this GitHub account**, pick an account. It needs permission to manage webhooks on the repository. See [Source control](/docs/source-control).
3. Choose **Connect**.

The row changes to "Pushes to … reach dply" and shows when GitHub last sent an event.

Checking **Deploy on push** on **Build** and saving also connects the webhook, using the account the repository was picked from or your GitHub login.

If dply cannot register the webhook, usually because the account lacks admin access to the repository, the error explains why. You can register it yourself instead: choose **Register the GitHub webhook yourself** under **More**, copy the **Payload URL** and **Secret**, and add a webhook in the repository's GitHub settings with content type `application/json` and the **Pushes** and **Pull requests** events.

### Turn it off

On **Build**, uncheck **Deploy on push** and save. Pushes to the production branch stop deploying, while the webhook stays connected so pull requests still get previews.

To stop previews as well, open the GitHub row under **Deploy triggers** and choose **Disconnect**. dply removes the webhook from GitHub. Choosing **Connect** again turns **Deploy on push** back on.

### Monorepos

When the app has a **Repository root**, a push deploys only if it changes a file inside that folder or a `dply.yaml` file. See [Monorepos](/docs/monorepos).

### Several pushes in a row

A new deploy cancels any build of the same app that is still running. If you push three commits in quick succession, the first two builds are cancelled and only the last commit deploys.

## Deploy hooks

A deploy hook is a secret URL that deploys the latest commit on your production branch when it is called. Use one to redeploy when content changes in a headless CMS, from a GitLab or Bitbucket pipeline, or from any system that can make an HTTP request.

### Create a deploy hook

1. In your app, open **Deploy triggers** and choose **Create a deploy hook**.
2. Enter a **Name**, such as `Sanity publish`, and choose **Create**.
3. Copy the URL from the dialog, which also gives a `curl` command to try it, then choose **I’ve copied it**.

> [!WARNING]
> dply shows the URL once. Anyone with the URL can deploy your app, so store it as a secret in the system that calls it. If you lose it, revoke the hook and create a new one.

### Call a deploy hook

Send a `POST` (or a `GET`, for systems that only send GET requests) to the URL. No body or headers are needed:

```bash
curl -X POST "https://<your-dply-host>/hooks/edge/deploy/<token>"
```

A successful call queues a deploy and responds with `202`:

```json
{
  "message": "Deploy queued.",
  "deployment_id": "01J...",
  "site_id": "01J..."
}
```

| Status | Meaning |
|--------|---------|
| `202` | A deploy was queued. |
| `404` | The token is not valid, or the hook was revoked. |
| `422` | The hook belongs to a preview deployment or an app that is not active. |
| `429` | Too many calls. Hooks accept 60 requests per minute from one IP address. |
| `503` | Deploys are temporarily paused on the platform. |

A deploy hook always deploys the latest commit on the production branch. It does not accept a commit or branch parameter. To deploy a specific commit from CI, use the API or CLI instead.

Each hook's row under **Deploy hooks** says when it last fired, or that it hasn't fired yet.

### Revoke a deploy hook

Click the hook's row, choose **Revoke** and confirm. The URL returns `404` immediately.

Deploy hooks exist only on production apps, not on preview deployments.

## Deploy from the HTTP API

Create an API token with the `edge.deploy` ability, then call:

```http
POST /api/v1/edge/sites/{site}/deployments
Authorization: Bearer <token>
Content-Type: application/json

{
  "commit": "3f9c2ab",
  "branch": "main"
}
```

Both fields are optional:

- With no body, dply deploys the latest commit on the production branch, the same as redeploying from **Deploys**.
- With `commit` (7 to 40 hexadecimal characters), dply deploys that commit. If that commit was already built and its files are still kept, dply switches production back to that build without rebuilding. `branch` records which branch the commit came from.

The API responds `202` with the new deployment. See [HTTP API](/docs/api).

## Deploy from the CLI

The [CLI](/docs/cli) wraps the same API. From a directory linked with `dply link`, or with `--site <id>`:

```bash
dply deploy                                  # deploy the latest commit on the production branch
dply edge deploy --commit 3f9c2ab --wait     # deploy a commit and follow it until it finishes
dply edge deployments --limit 5              # recent deployments
dply edge rollback <deployment-id>           # switch production back to an earlier deployment
```

In CI, sign in with an API token and pass the app id with `--site` or the `DPLY_EDGE_SITE` variable:

```bash
dply login --token "$DPLY_TOKEN" --no-shell
dply edge deploy --site "$DPLY_EDGE_SITE" --commit "$GITHUB_SHA" --wait
```

**Profile** → **CLI** in the dashboard has a complete GitHub Actions workflow.

## Deploy notifications

Deploys that succeed or fail notify your organization's notification channels. To choose where they go, expand **Advanced** under **Deploy triggers** and choose **Manage channels**. See [Notification channels](/docs/notifications).

## Related

- [Deployments](/docs/deployments)
- [Preview deployments](/docs/preview-deployments)
- [Source control](/docs/source-control)
- [CLI](/docs/cli)
