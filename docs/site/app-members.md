---
title: "App members"
description: "Give an organization member a Viewer, Deployer, or Admin role on a single app."
---

App members are per-app roles. You pick someone who is already in the organization and give them a role on one app. For a member or deployer, the app role **replaces** their organization role on that app: it can narrow their access (a member who should only look) or widen it (a deployer who should configure one app). Organization owners and admins are not affected; they always have full control of every app.

## Add an app member

1. Open the app and choose **Members** in the app sidebar (under **Protect**).
2. Choose **Give someone access**.
3. Pick an organization member or deployer who doesn't have a role on this app yet.
4. Choose **Viewer**, **Deployer**, or **Admin**. Each option says what it allows. Then choose **Save**.

The page opens with a sentence that counts who can reach the app and what they can do, for example "5 people can reach this app. 2 can do anything, 2 can deploy and 1 can only look." Below it, everyone in the organization is listed with what they can do here and why ("Org owner", "Deployer on this app").

Only organization owners and admins, and people who hold the **Admin** role on this app, can add, change, or remove app members. The person must already be an organization member. To add someone new, invite them from the organization's **Members** page first (the app's Members page links to it under **Invite to organization**); they appear here once they accept. See [Roles & permissions](/docs/roles-and-permissions#invite-someone).

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

Click an organization member or deployer in the list to open their access. Pick a different role and choose **Save**. To take the app role away, choose **Their org role** or **Remove app role**. The person goes back to what their organization role allows on this app. Owners, admins and organization viewers can't be clicked; change their organization role instead.

App roles also go away when the person leaves or is removed from the organization, or becomes an organization viewer.

Every addition, role change, and removal is recorded in the app's audit log and the organization [activity log](/docs/activity-log).

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [Organizations](/docs/organizations)
- [Activity log](/docs/activity-log)
