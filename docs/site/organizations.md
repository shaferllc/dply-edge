---
title: "Organizations"
description: "Create organizations, switch between them, and manage their settings, members, and deletion."
---

An organization is the top-level container in dply. Every app, domain, secret, notification channel, API token, and subscription belongs to one organization. You can belong to several organizations and hold a different role in each, which makes organizations the natural boundary between companies, clients, or personal and work projects.

## Your first organization

When you sign up, dply creates an organization for you and makes you its **owner**. It also creates a team called **General** and adds you to it as a team admin. See [Teams](/docs/teams).

## Create an organization

1. Open the organization switcher and choose **All organizations**, or go to `/organizations`.
2. Choose **Create organization**.
3. Enter a **Name** and save.

You become the owner of the new organization, and dply switches you into it. You must verify your email address before you can create an organization.

> [!NOTE]
> Each organization has its own plan and billing. An organization gets the trial described in [Free trial](/docs/free-trial) only if it has never had a trial or subscription and none of its owners has had one on another organization. Otherwise choose a plan in **Billing**. See [Plans & pricing](/docs/pricing).

## Switch organizations

Everything in the dashboard applies to your *current* organization, including the app list, usage, and the organization your API tokens and CLI sessions are created for. To change organizations, open **All organizations**, find the one you want (you can search by name), and switch to it. dply returns you to the page you were on.

## Organization pages

The organization sidebar holds these sections. Which ones you see depends on your role; see [Roles & permissions](/docs/roles-and-permissions).

| Section | What it holds | Who sees it |
|---|---|---|
| **Overview** | Plan, app count, people, API token count, and links to each section | All members |
| **Activity** | The organization audit trail. See [Activity log](/docs/activity-log) | Owners and admins |
| **Billing** | Plan, payment method, usage, invoices. See [Plans & pricing](/docs/pricing) | Owners and admins |
| **Credentials** | Encrypted tokens dply holds for third-party providers | Everyone except deployers |
| **General** | Name, icon, contact details, email defaults, data region, API tokens, and deletion | Owners and admins |
| **Members** | People, roles, and pending invitations. Owners and admins change roles and remove people here; anyone can leave. See [Change a role or remove someone](/docs/roles-and-permissions#change-a-role-or-remove-someone). Each member and pending invitation takes a seat on your plan, except view-only members, who are free. See [Seats](/docs/roles-and-permissions#seats) | All members |
| **Notification channels** | Organization-owned alert destinations. See [Notification channels](/docs/notifications) | Owners and admins |
| **Secrets** | The shared secret vault you link onto apps. See [Secrets](/docs/secrets) | All members |
| **Teams** | Named groups of members. See [Teams](/docs/teams) | All members |

## General settings

Open **General** in the organization sidebar. Only owners and admins can open this page.

### Identity

- **Organization icon**: a PNG, JPG, WEBP, GIF, or ICO file up to 1 MB. dply shows it beside the organization name across the dashboard. Without an icon, dply shows the organization's initials.
- **Name**: how the organization appears in the dashboard and on invitations.
- **Handle**: lowercase letters, numbers, and dashes. It must be unique across dply. Dashboard URLs use the organization ID, not the handle.
- **Contact email**, **Timezone**, and **Description** (up to 500 characters).

Choose **Save changes** to apply. Every change is recorded in the activity log with its before and after values.

### Email defaults

**Deploy-finish emails** sends the person who started a deploy an email when it completes or fails. To route alerts to Slack, Discord, webhooks, and other destinations, use [Notification channels](/docs/notifications) instead.

### Edge data region

Choose the preferred storage region for buckets dply creates for this organization:

| Option | Meaning |
|---|---|
| **Default** | dply picks the region |
| **EU** | Strict EU jurisdiction. Data is stored in the EU and the EU jurisdiction header is set on every request |
| **Western Europe (weur)**, **Eastern Europe (eeur)**, **Western North America (wnam)**, **Eastern North America (enam)**, **Asia-Pacific (apac)**, **Oceania (oc)** | Location hint for new buckets |

The setting applies only to buckets created after you change it. Existing buckets stay where they are. See [Data regions](/docs/data-regions).

### API tokens

The **API tokens** list shows every token issued for this organization, across all members, with its prefix, when it was last used, and when it expires. An owner or admin can **Revoke** any token here. Revocation takes effect immediately and is recorded in the activity log.

You create tokens on your own **Profile → API keys** page, not here. See [HTTP API](/docs/api).

## Leave an organization

Open **Members** and choose **Leave** in your own row. You lose access to the organization's apps, and your API tokens for it are revoked. You cannot leave if you are the organization's only owner; make another member owner first (**Make owner** in their row).

## Delete an organization

Only the **owner** can delete an organization. Deleting it permanently removes its settings, invitations, API tokens, notification channels, teams, and activity log.

Before you can delete it:

- Delete every app in the organization.
- Cancel the organization's subscription.
- Belong to at least one other organization, so you have somewhere to land.

Then, in **General**, scroll to **Delete organization**, type the organization name exactly, and confirm.

> [!WARNING]
> Deleting an organization cannot be undone. The activity log is deleted with it. If you need a record, download a compliance export first (Team and Enterprise). See [Activity log](/docs/activity-log).

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [Teams](/docs/teams)
- [Activity log](/docs/activity-log)
- [Plans & pricing](/docs/pricing)
