---
title: "Activity log"
description: "See who changed what in your organization and its apps, search the history, and export it for audits."
---

dply records every meaningful change made through the dashboard, CLI, and API: invitations, token changes, app settings, domains, firewall rules, members, billing changes, and more. Each entry says who made the change, when, what it touched, and, where it applies, the values before and after. Use it after an incident, during a security review, or whenever you need to answer "who changed this?".

There are two views of the same record:

- **Activity** covers the whole organization.
- **Audit log** in an app's sidebar covers one app.

> [!NOTE]
> Browsing the organization **Activity** page and exporting either log are available on Team and Enterprise. Not included on Starter, Pro or the trial. Changes are recorded on every plan, so upgrading gives you the full history, not just what happens afterwards. See [Plans & pricing](/docs/pricing).

## Organization activity

In the organization sidebar, open **Activity**. Only owners and admins can open it.

Each row shows the action, the person who made it (or **System** for automatic changes), what it applied to, and when. Choose a row to expand it and see the recorded **Before** and **After** values.

### Filter by family

The buttons above the list group actions into families. Each shows how many events it holds, and families with no events are disabled.

| Family | What it covers |
|---|---|
| **Apps** | App-level changes outside the Edge settings, such as app deletion and deploy contracts |
| **Edge** | App changes: settings, domains, security rules, bindings, members, and similar |
| **Resources** | Databases and queues created, deleted, or changed |
| **Team** | Teams created, renamed, and deleted, and people added or removed |
| **Billing** | Checkout, billing portal visits, and subscription cancellations |
| **Security** | API tokens, invitations, notification channels, provider credentials, impersonation, and account changes such as passwords, 2FA, and passkeys |
| **Organization** | Organization settings, icon, data region, and email defaults |
| **Other** | Everything else, including entries from older dply product lines (servers, backups, imports) if your organization has any |

### Search

The search box (**Search action, subject, or values…**) matches, case-insensitively, against:

- the action name, such as `invitation.sent` or `site.edge.member.added`
- the type of thing that changed
- the recorded before and after values, so you can search for a domain name, an email address, or an environment variable key

Search combines with the family filter. Choose **Clear filters** to reset both.

Use **Rows per page** to show 10, 25, 50, or 100 entries. The filter, search, and page size are kept in the URL, so you can share a link to a filtered view.

### Compliance export

Choose **Compliance export** at the top of **Activity** to download a ZIP file for auditors. It contains:

| File | Contents |
|---|---|
| `README.txt` | Organization, when the export was generated, and by whom |
| `audit_log.csv` | Every retained event: time, actor name and email, action, subject type and ID, before and after values as JSON, and IP address |
| `edge_access_rules.csv` | Each app's access-control mode and allowed emails. See [Access control](/docs/access-control) |

Timestamps are ISO 8601 in UTC. Passwords, secret values, and key material are never included.

## App audit log

Open an app and choose **Audit log** in the app sidebar (under **Manage**). It opens with a summary of recent activity, such as how many changes were made in the last 7 days, by whom, and what the most recent one was. Below that, the last 100 events for that app are grouped by day (**Today**, **Yesterday**, then the date, in your organization's time zone), newest first, each as one sentence worded the same way as on the organization's **Activity** page. Anyone who can see the app can see its audit log, and it is read-only.

Choose an event to see its details: the raw action code, who made the change (name and email), when (full date, relative time, and ISO 8601 timestamp), the IP address, a **What changed** table of before and after values, and the full recorded before and after values.

To download the app's full retained history, choose **Download CSV** or **Download JSON** at the bottom of the list. Downloads are available on Team and Enterprise.

Deploy history is not part of the audit log. See [Deployments](/docs/deployments).

## Retention

dply keeps activity for 365 days, then deletes older entries. Deleting an organization deletes its activity log. Download a compliance export first if you need to keep it.

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [App members](/docs/app-members)
- [Plans & pricing](/docs/pricing)
- [Account security](/docs/account-security)
