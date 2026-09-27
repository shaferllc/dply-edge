---
title: "Roles & permissions"
description: "What owners, admins, members, deployers, and viewers can do in an organization, and how seats are counted."
---

Every person in an organization has one organization role: **Owner**, **Admin**, **Member**, **Deployer**, or **Viewer**. The role decides what they can do across the organization and its apps. Roles are per organization, so the same person can be an admin in one organization and a deployer in another.

On top of the organization role, you can give a member or deployer (not a viewer) an app role on a single app: **Viewer**, **Deployer**, or **Admin**. On that app, the app role replaces their organization role, so it can narrow access (a member who is only a Viewer there) or widen it (a deployer who is an Admin there). Owners and admins always keep full access. See [App members](/docs/app-members).

## Organization roles

| Role | Summary |
|---|---|
| **Owner** | Everything, including deleting the organization and managing other owners. The person who creates an organization is its owner. Ownership cannot be granted by invitation; an owner makes another member owner from **Members**. An organization always has at least one owner. |
| **Admin** | Everything an owner can do except delete the organization, grant ownership, or change or remove an owner: billing, settings, members, activity log, API tokens. |
| **Member** | Creates, configures, and deploys apps. Cannot manage the organization, billing, or members, and cannot delete apps. |
| **Deployer** | Sees every app and ships code: deploy, redeploy, roll back, promote and tear down previews, cancel or restart builds, purge the cache, and read logs. Cannot change anything else: environment variables and secrets, domains, resources, security settings (firewall, rate limits, bot protection, access, waiting room), build settings, alerts, members, or billing. Cannot create apps. |
| **Viewer** | Sees every app, its deploys, previews, logs, and settings, and changes nothing. Free: a viewer does not take a seat. An app role never lifts a viewer above view-only. |

Owners and admins together are called *admins* throughout this documentation and the dashboard.

## Permission matrix

### Organization

| Action | Owner | Admin | Member | Deployer | Viewer |
|---|---|---|---|---|---|
| See the organization overview, members, and teams | Yes | Yes | Yes | Yes | Yes |
| Invite members and cancel invitations | Yes | Yes | No | No | No |
| Change a member's role and remove members | Yes | Yes (not owners) | No | No | No |
| Make another member owner | Yes | No | No | No | No |
| Leave the organization (unless you are its only owner) | Yes | Yes | Yes | Yes | Yes |
| Create, rename, and delete teams; change team membership | Yes | Yes | No | No | No |
| Edit **General** settings (name, icon, email defaults, data region) | Yes | Yes | No | No | No |
| View and revoke any API token in the organization | Yes | Yes | No | No | No |
| Open **Billing**, change plan and payment method | Yes | Yes | No | No | No |
| Open **Activity** and download the compliance export (Team and Enterprise) | Yes | Yes | No | No | No |
| Manage organization notification channels | Yes | Yes | No | No | No |
| See **Credentials** | Yes | Yes | Yes | No | No |
| Create, rotate, and delete organization secrets | Yes | Yes | No | No | No |
| See organization secrets | Yes | Yes | Yes | Yes | Yes |
| Delete the organization | Yes | No | No | No | No |

### Apps

| Action | Owner | Admin | Member | Deployer | Viewer |
|---|---|---|---|---|---|
| See every app, its deploys, and its logs | Yes | Yes | Yes | Yes | Yes |
| Create an app (within your plan's fair-use limit) | Yes | Yes | Yes | No | No |
| Deploy, redeploy, roll back, and deploy a specific commit | Yes | Yes | Yes | Yes | No |
| Create, promote, split traffic to, and tear down previews; comment on and approve previews | Yes | Yes | Yes | Yes | No |
| Cancel or restart a build, retry provisioning, purge the cache | Yes | Yes | Yes | Yes | No |
| Change environment variables, and link or unlink organization secrets | Yes | Yes | Yes | No | No |
| Add, verify, and remove domains; change routing and redirects | Yes | Yes | Yes | No | No |
| Add, change, and remove resources (databases, queues, workers, storage) | Yes | Yes | Yes | No | No |
| Change security settings: firewall, rate limits, bot protection, access, waiting room | Yes | Yes | Yes | No | No |
| Change build settings, deploy hooks, cache options, crons, and container settings | Yes | Yes | Yes | No | No |
| Change which channels receive an app's alerts | Yes | Yes | Yes | No | No |
| Manage an app's members | Yes | Yes | App admins only | App admins only | No |
| Delete an app | Yes | Yes | No | No | No |

An app role changes these rows on one app for a member or deployer. It does not change anything for a viewer. See [App members](/docs/app-members#app-roles).

### Tokens, CLI, and API

| Action | Owner | Admin | Member | Deployer | Viewer |
|---|---|---|---|---|---|
| Create API tokens on **Profile → API keys** | Yes | Yes | No | No | No |
| Sign in with `dply login` | Yes | Yes | Yes | Yes | Yes |

A token can never do more than the person who owns it. Every API request checks both the token's abilities and the owner's current role on the app: reads need view access, deployment, preview, and cache-purge requests need deploy access, and every other write needs configure access. Database queries and queue messages need an organization admin. When someone leaves or is removed from an organization, their tokens for that organization are revoked: they stop working at once, show as **Revoked** in the organization's token list, and stay revoked if the person is invited back.

`dply login` offers only the abilities your role allows:

| Ability | Owner / Admin | Member | Deployer | Viewer |
|---|---|---|---|---|
| `account.read`, `account.write` | Yes | Yes | Yes | Yes |
| `edge.read`, `edge.env.read` | Yes | Yes | Yes | Yes |
| `sites.read`, `servers.read` | Yes | Yes | Yes | Yes |
| `edge.deploy` | Yes | Yes | Yes | No |
| `edge.write`, `edge.env.write` | Yes | No | No | No |
| `notifications.read`, `notifications.write` | Yes | No | No | No |
| `billing.read` | Yes | Yes (billing endpoints still return `403` to non-admins) | No | No |

A deployer's tokens are also capped when they are used. Even an older token that lists broader abilities can only use `account.read`, `account.write`, `edge.read`, `edge.deploy`, `edge.env.read`, `sites.read`, and `servers.read`. A viewer's tokens are capped the same way to `account.read`, `account.write`, `edge.read`, `edge.env.read`, `sites.read`, and `servers.read`. See [HTTP API](/docs/api) for what each ability covers.

## Invite someone

1. In the organization sidebar, open **Members**.
2. Choose **Invite member**.
3. Enter an **Email address**, pick a **Role** (**Member**, **Admin**, **Deployer**, or **Viewer**), and choose **Send invitation**. A viewer does not use a seat.

dply emails a link that is valid for 7 days. The person must sign in (or sign up) with the invited address to accept. Pending invitations appear on **Members** until they are accepted, expire, or you **Cancel** them. You can also invite someone straight onto a team; see [Teams](/docs/teams).

## Change a role or remove someone

Owners and admins manage people on **Members**. Everyone else sees the list read-only.

- **Change a role.** Pick a new role in the member's row. A change that takes access away (for example Admin to Member, or anything to Viewer) asks you to confirm first. Admins cannot change an owner's role or make anyone owner. Moving a viewer to any other role takes a seat, so it is refused when your plan's seats are full. Making someone a viewer removes their app roles, since viewers can't hold one.
- **Remove someone.** Choose **Remove** in their row and confirm. Admins cannot remove an owner.
- **Leave.** Choose **Leave** in your own row. You cannot leave if you are the only owner: make someone else owner first.
- **Make someone owner.** Owners see **Make owner** in each member's row. Turn on **Step down to admin** in the confirmation to hand ownership over and stay on as an admin.

An organization always keeps at least one owner, so the last owner can't be demoted, removed, or leave.

When someone is removed or leaves, dply takes them off every team, removes their app roles in the organization, revokes their API tokens for it, and updates your seat count (and, on Team, your bill). The person gets an email when an owner or admin changes their role or removes them. Every role change, removal, departure, and ownership change is recorded in the [activity log](/docs/activity-log).

To limit someone on a single app instead, give them the **Viewer** app role there; see [App members](/docs/app-members).

## Seats

A seat is one person in the organization who can do more than view. View-only members are free: they don't take a seat, aren't billed as extra seats on Team, and inviting one never hits a seat limit. On plans with a hard seat limit, pending invitations also count (again, except view-only ones), so you cannot have more members plus pending invitations than the plan includes.

<!-- generated: php artisan dply:billing:price-table plans -->
| Plan | Price | Seats | Extra seat | Included usage |
| --- | --- | --- | --- | --- |
| Starter | $5/mo | 1 | — | $5/mo |
| Pro | $20/mo | 3 | — | $20/mo |
| Team | $49/mo | 10 | $5/mo each | $49/mo |
| Enterprise | Contact us | Custom | Custom | Custom |

When an invitation would go past a hard limit, dply refuses it with a message that points you to **Billing**. See [Plans & pricing](/docs/pricing).

## Related

- [App members](/docs/app-members)
- [Teams](/docs/teams)
- [HTTP API](/docs/api)
- [Activity log](/docs/activity-log)
