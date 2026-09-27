---
title: "Roles & permissions"
description: "What owners, admins, members, and deployers can do in an organization, and how seats are counted."
---

Every person in an organization has one organization role: **Owner**, **Admin**, **Member**, or **Deployer**. The role decides what they can do across the organization and its apps. Roles are per organization, so the same person can be an admin in one organization and a deployer in another.

On top of the organization role, you can give someone an app role on a single app. App roles only add access. They never take away access that the organization role grants. See [App members](/docs/app-members).

## Organization roles

| Role | Summary |
|---|---|
| **Owner** | Everything, including deleting the organization. The person who creates an organization is its owner. Ownership cannot be granted by invitation. |
| **Admin** | Everything an owner can do except delete the organization: billing, settings, members, activity log, API tokens. |
| **Member** | Creates, configures, and deploys apps. Cannot manage the organization, billing, or members, and cannot delete apps. |
| **Deployer** | Works on apps like a member, with a narrower API and CLI scope. Cannot change an app's alert subscriptions or see provider credentials. |

Owners and admins together are called *admins* throughout this documentation and the dashboard.

## Permission matrix

### Organization

| Action | Owner | Admin | Member | Deployer |
|---|---|---|---|---|
| See the organization overview, members, and teams | Yes | Yes | Yes | Yes |
| Invite members and cancel invitations | Yes | Yes | No | No |
| Create, rename, and delete teams; change team membership | Yes | Yes | No | No |
| Edit **General** settings (name, icon, email defaults, data region) | Yes | Yes | No | No |
| View and revoke any API token in the organization | Yes | Yes | No | No |
| Open **Billing**, change plan and payment method | Yes | Yes | No | No |
| Open **Activity** and download the compliance export (Team and Enterprise) | Yes | Yes | No | No |
| Manage organization notification channels | Yes | Yes | No | No |
| See **Credentials** | Yes | Yes | Yes | No |
| Create, rotate, and delete organization secrets | Yes | Yes | No | No |
| See organization secrets | Yes | Yes | Yes | Yes |
| Delete the organization | Yes | No | No | No |

### Apps

| Action | Owner | Admin | Member | Deployer |
|---|---|---|---|---|
| See every app and its workspace | Yes | Yes | Yes | Yes |
| Create an app (within your plan's fair-use limit) | Yes | Yes | Yes | Yes |
| Deploy, roll back, and promote previews | Yes | Yes | Yes | Yes |
| Change app settings, environment variables, domains, and security rules | Yes | Yes | Yes | Yes |
| Link and unlink organization secrets on an app | Yes | Yes | Yes | Yes |
| Change which channels receive an app's alerts | Yes | Yes | Yes | No |
| Manage an app's members | Yes | Yes | App admins only | App admins only |
| Delete an app | Yes | Yes | No | No |

### Tokens, CLI, and API

| Action | Owner | Admin | Member | Deployer |
|---|---|---|---|---|
| Create API tokens on **Profile → API keys** | Yes | Yes | No | No |
| Sign in with `dply login` | Yes | Yes | Yes | Yes |

A token can never do more than the person who owns it. Every API request checks both the token's abilities and the owner's current role on the app. When someone leaves an organization, their tokens for that organization stop working.

`dply login` offers only the abilities your role allows:

| Ability | Owner / Admin | Member | Deployer |
|---|---|---|---|
| `account.read`, `account.write` | Yes | Yes | Yes |
| `edge.read`, `edge.env.read` | Yes | Yes | Yes |
| `sites.read`, `servers.read` | Yes | Yes | Yes |
| `edge.deploy` | Yes | No | Yes |
| `edge.write`, `edge.env.write` | Yes | No | No |
| `notifications.read`, `notifications.write` | Yes | No | No |
| `billing.read` | Yes | Yes (billing endpoints still return `403` to non-admins) | No |

A deployer's tokens are also capped when they are used. Even an older token that lists broader abilities can only use `account.read`, `account.write`, `edge.read`, `edge.deploy`, `edge.env.read`, `sites.read`, and `servers.read`. See [HTTP API](/docs/api) for what each ability covers.

> [!NOTE]
> A member's `dply login` token cannot deploy, although members can deploy from the dashboard. Deployers can deploy from both. Ask an admin to create a token with `edge.deploy` if a member needs to deploy from CI.

## Invite someone

1. In the organization sidebar, open **Members**.
2. Choose **Invite member**.
3. Enter an **Email address**, pick a **Role** (**Member**, **Admin**, or **Deployer**), and choose **Send invitation**.

dply emails a link that is valid for 7 days. The person must sign in (or sign up) with the invited address to accept. Pending invitations appear on **Members** until they are accepted, expire, or you **Cancel** them. You can also invite someone straight onto a team; see [Teams](/docs/teams).

## Change a role or remove someone

> [!IMPORTANT]
> The dashboard does not yet let you change a member's organization role or remove a member from an organization. Contact [support](/docs/support) to do either. App roles only add access, so they cannot be used to limit someone.

## Seats

A seat is one person in the organization. On plans with a hard seat limit, pending invitations also count, so you cannot have more members plus pending invitations than the plan includes.

<!-- generated: php artisan dply:billing:price-table plans -->
| Plan | Price | Seats | Extra seat | Included usage |
| --- | --- | --- | --- | --- |
| Starter | $5/mo | 1 | — | $5/mo |
| Pro | $20/mo | 3 | — | $20/mo |
| Team | $49/mo | 10 | $5/mo each | $50/mo |
| Enterprise | Contact us | Custom | Custom | Custom |

When an invitation would go past a hard limit, dply refuses it with a message that points you to **Billing**. See [Plans & pricing](/docs/pricing).

## Related

- [App members](/docs/app-members)
- [Teams](/docs/teams)
- [HTTP API](/docs/api)
- [Activity log](/docs/activity-log)
