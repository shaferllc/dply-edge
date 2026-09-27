---
title: "Teams"
description: "Group organization members into teams and give each team its own notification channels."
---

A team is a named group of people inside an organization, such as "Platform", "On-call", or "Customer success". Teams let you route alerts to the group that owns an app instead of to everyone. A member can belong to several teams.

> [!IMPORTANT]
> Teams group people. They do not grant or restrict access. What a person can do comes from their organization role and any per-app role. See [Roles & permissions](/docs/roles-and-permissions) and [App members](/docs/app-members).

## What teams are for

Each team owns its own [notification channels](/docs/notifications). An alert sent to a team channel reaches that team's Slack channel, webhook, or inbox rather than the whole organization's.

Every organization starts with a team called **General**. The person who created the organization is a team admin of **General**.

## Create a team

1. In the organization sidebar, open **Teams**.
2. Choose **Create team**.
3. Enter a **Team name** and choose **Create team**.

Only organization owners and admins can create, rename, or delete teams.

## Add people to a team

Each team card has two ways to add people:

- **Add**: pick someone who is already an organization member from **Add member…**, then choose **Add**.
- **Invite**: enter an email address and choose an **Organization role**. If the address already belongs to a member, dply adds them to the team at once. Otherwise dply emails an invitation. Accepting it joins the organization with that role and the team in one step.

The role you choose in **Invite** applies to the organization, not to the team. Invitations expire after 7 days, and a team invitation uses an organization seat like any other invitation. See [Roles & permissions](/docs/roles-and-permissions#seats).

> [!NOTE]
> Only one pending invitation can exist per email address in an organization. If you already invited someone from **Members**, cancel that invitation before re-sending it from a team.

## Rename, remove, and delete

- **Rename**: edit the team name in place on its card. dply asks you to confirm when you leave the field.
- **Remove a person**: choose **Remove** next to their name. They stay in the organization with the same role.
- **Delete a team**: choose **Delete** on the team card and confirm. Members stay in the organization.

## Team notification channels

Choose **Notifications** on a team card to manage the team's channels. Organization owners and admins can manage every team's channels. A team admin can manage the channels of their own team. Any team member can view them.

> [!NOTE]
> The dashboard adds people to teams as regular team members. The only team admin is the organization creator on the **General** team. Organization owners and admins can manage every team, so this rarely matters.

## Who can do what

| Action | Owner | Admin | Member | Deployer |
|---|---|---|---|---|
| See teams and who is on them | Yes | Yes | Yes | Yes |
| Create, rename, delete teams | Yes | Yes | No | No |
| Add, invite, or remove team members | Yes | Yes | No | No |
| Manage team notification channels | Yes | Yes | Team admins only | Team admins only |

## Related

- [Organizations](/docs/organizations)
- [Roles & permissions](/docs/roles-and-permissions)
- [Notification channels](/docs/notifications)
