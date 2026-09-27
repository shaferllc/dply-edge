---
title: "App members"
description: "Give an organization member a Viewer, Deployer, or Admin role on a single app."
---

App members are per-app role grants. You pick someone who is already in the organization and give them a role on one app. App roles add access on top of the person's organization role. They never restrict it, so an organization owner or admin always has full control of every app.

## Add an app member

1. Open the app and choose **Members** in the app sidebar (under **Protect**).
2. Under **Member**, pick a person from the organization who does not already have a role on this app.
3. Under **Role**, choose **Viewer**, **Deployer**, or **Admin**.
4. Choose **Add**.

Only organization owners and admins, and people who hold the **Admin** role on this app, can add, change, or remove app members. The person must already be an organization member; invite them from **Members** first. See [Roles & permissions](/docs/roles-and-permissions#invite-someone).

## App roles

| App role | Grants on this app |
|---|---|
| **Viewer** | See the app and its workspace |
| **Deployer** | Everything a viewer can do, plus deploy and change settings and environment variables |
| **Admin** | Everything a deployer can do, plus manage this app's members |

App roles do not let anyone delete the app. Deleting an app always needs an organization owner or admin.

> [!NOTE]
> Every organization member, including deployers, can already see, configure, and deploy every app in the organization. Today, **Viewer** and **Deployer** app roles therefore add nothing beyond what an organization member already has. The **Admin** app role is the one that changes something: it lets a member or deployer manage this app's members.

## Change or remove a role

In the member list, pick a new role to change it. Choose **Remove** to take away the app role. The person keeps their organization role and whatever access it grants.

Every addition, role change, and removal is recorded in the app's audit log and the organization [activity log](/docs/activity-log).

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [Organizations](/docs/organizations)
- [Activity log](/docs/activity-log)
