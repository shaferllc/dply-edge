---
title: "App members"
description: "Give an organization member a Viewer, Deployer, or Admin role on a single app."
---

App members are per-app roles. You pick someone who is already in the organization and give them a role on one app. For a member or deployer, the app role **replaces** their organization role on that app: it can narrow their access (a member who should only look) or widen it (a deployer who should configure one app). Organization owners and admins are not affected; they always have full control of every app.

## Add an app member

1. Open the app and choose **Members** in the app sidebar (under **Protect**).
2. Under **Member**, pick a person from the organization who does not already have a role on this app.
3. Under **Role**, choose **Viewer**, **Deployer**, or **Admin**.
4. Choose **Add**.

Only organization owners and admins, and people who hold the **Admin** role on this app, can add, change, or remove app members. The person must already be an organization member; invite them from **Members** first. See [Roles & permissions](/docs/roles-and-permissions#invite-someone).

## App roles

| App role | On this app |
|---|---|
| **Viewer** | See the app, its deploys, previews, logs, and settings. Cannot deploy or change anything. |
| **Deployer** | Everything a viewer can do, plus deploy, redeploy, roll back, promote and tear down previews, cancel or restart builds, and purge the cache. Cannot change environment variables, domains, resources, security settings, build settings, alerts, or members. |
| **Admin** | Everything a deployer can do, plus change every setting and manage this app's members. |

| Organization role | No app role | App Viewer | App Deployer | App Admin |
|---|---|---|---|---|
| Owner or Admin | Full | Full | Full | Full |
| Member | Configure and deploy | View only | Deploy and view | Configure, deploy, manage app members |
| Deployer | Deploy and view | View only | Deploy and view | Configure, deploy, manage app members |
| Viewer | View only | Not available | Not available | Not available |

Organization viewers can't be given an app role: they stay view-only everywhere, which is why they don't take a seat. An organization deployer with the app **Admin** role configures that app in the dashboard, but their API tokens stay capped to deploy and read abilities.

App roles do not let anyone delete the app. Deleting an app always needs an organization owner or admin. API tokens follow the same rules; see [Roles & permissions](/docs/roles-and-permissions#tokens-cli-and-api).

## Change or remove a role

In the member list, pick a new role to change it. Choose **Remove** to take away the app role. The person goes back to what their organization role allows on this app.

Every addition, role change, and removal is recorded in the app's audit log and the organization [activity log](/docs/activity-log).

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [Organizations](/docs/organizations)
- [Activity log](/docs/activity-log)
