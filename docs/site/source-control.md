---
title: "Source control"
description: "Connect GitHub, GitLab, or Bitbucket to dply, what each provider supports, and how dply uses your credentials."
---

dply deploys apps from Git. You link a GitHub, GitLab, or Bitbucket account to your dply user, then pick a repository from it when you create an app. The linked account is also what dply uses to clone private repositories, browse branches and commits, register push webhooks, and post pull request status.

## What each provider supports

| | GitHub | GitLab | Bitbucket |
|---|---|---|---|
| Pick a repository when creating an app | Yes | Yes | Yes |
| Build and deploy (public and private repositories) | Yes | Yes | Yes |
| Deploy on push | Yes | No | No |
| Preview deployments for pull requests | Yes | No | No |
| Pull request check run and comment | Yes | No | No |
| Deploy a specific branch, commit, or tag from the dashboard | Yes | Yes | Yes |
| Deploy hooks | Yes | Yes | Yes |

For GitLab and Bitbucket, trigger deploys from your CI pipeline with a deploy hook or the API. See [Deploy triggers & hooks](/docs/deploy-triggers).

## Link an account

Git accounts belong to your dply user, not to the organization. Each teammate links their own.

1. Open your profile and choose **Source control**. The first step of creating an app also offers **Connect GitHub, GitLab, or Bitbucket**.
2. Next to the provider, choose **Link GitHub**, **Link GitLab**, or **Link Bitbucket**.
3. Approve the requested access with the provider.

dply requests these scopes:

| Provider | Scopes |
|----------|--------|
| GitHub | `read:user`, `repo`, `admin:repo_hook` |
| GitLab | `read_user`, `api` |
| Bitbucket | `account`, `repository:write`, `webhook` |

The webhook scope lets dply register the push and pull request webhook on GitHub. If you link GitHub and the repository belongs to an organization, that GitHub organization may need to approve dply's OAuth app before its repositories appear.

You can link more than one account per provider, for example a personal account and a work account. Choose **Edit** to give an account a label so you can tell them apart.

## Use a personal access token

A personal access token works for machine users, and for any provider whose OAuth link is not offered. On **Source control**, choose **Add token** next to the provider, paste the token, optionally set a **Label**, and choose **Validate and save**. dply checks the token with the provider before saving it.

| Provider | Token requirements |
|----------|--------------------|
| GitHub | Classic tokens need `repo` and `admin:repo_hook`. Fine-grained tokens need **Contents** (Read), **Metadata** (Read), and **Webhooks** (Read & Write) on the repositories you deploy. |
| GitLab | The `api` scope. A group token covers every project in the group. |
| Bitbucket | An app password or workspace access token with repository read and webhook permissions. |

To replace a token that has expired or been rotated, choose **Edit** on the token and paste the new value. Every app that uses the token keeps working without relinking. **Validate** re-checks a stored token at any time.

## Choose a repository

When you create an app, pick a linked account and then a repository from its list, or paste the repository yourself. dply accepts:

- `owner/name`, treated as a GitHub repository
- A repository URL on `github.com`, `gitlab.com`, or `bitbucket.org`, including GitLab subgroups. A URL that points at a branch, such as `…/tree/develop`, also fills in the branch.
- An SSH remote such as `git@github.com:owner/name.git`. dply converts it and still clones over HTTPS.

Self-hosted GitLab and GitHub Enterprise hosts are not accepted when creating an app.

You also choose the branch or tag to deploy. It becomes the app's production branch, and the repository and branch are fixed after creation. See [Apps](/docs/apps).

## Private repositories

Private repositories build with the account you picked them from when you created the app. If that account can no longer be used, dply falls back to another account you've linked for the same provider. Push deploys and preview deployments use the same account as the app.

dply sends the token to the Git host only while it clones. It never appears in the build log, and it isn't saved in the cloned repository's Git configuration. GitLab and Bitbucket sign-in tokens expire after a few hours; dply renews them automatically before a build.

If the account loses access to the repository, or the token is revoked, the build fails at the clone step with **Reconnect it under Source control**. Link the account again (or replace the token), then choose **Retry build**.

## Unlink an account

On **Source control**, choose **Unlink** next to the account, or **Remove** next to a token. Unlinking does not remove webhooks that dply already registered on your repositories. To stop push deploys for an app, open the app's **Deploy triggers** and choose **Disable** before you unlink. See [Deploy triggers & hooks](/docs/deploy-triggers).

After you unlink, the dashboard can no longer browse that repository's branches and commits for **Deploy ref**, and it prompts you to connect the provider again. Builds of a private repository fail until another linked account can read it.

## Related

- [Apps](/docs/apps)
- [Deploy triggers & hooks](/docs/deploy-triggers)
- [Preview deployments](/docs/preview-deployments)
