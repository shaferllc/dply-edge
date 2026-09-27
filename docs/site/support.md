---
title: "Support"
description: "How to get help with dply, what to include in a request, and where to look first."
---

dply is in private beta, and support is handled directly by the dply team by email. There are no support tiers yet: every plan, including the trial, gets the same help. This page explains how to reach us and how to get an answer quickly.

## Contact support

Email [hello@dply.io](mailto:hello@dply.io). The address is also linked from **Email support** in the account menu and from the footer of the public site. Write from the email address on your dply account, so we can find your organization.

> [!NOTE]
> There is no in-app chat, ticket form or phone support yet, and no guaranteed response time. We answer beta customers as quickly as we can on business days.

## What to include

A request with these details can usually be answered on the first reply:

- **Organization name**, and the **project** (site) involved.
- **What you expected** and **what happened**, including the exact error text.
- **When it happened**, with a time zone.
- **The deployment**, if it's about a build or deploy. Link the deployment from the project's **Deploys** tab.
- **A URL and a request time**, if it's about an error on your site. The `cf-ray` response header helps us find the request.

Never send passwords, API tokens, or the values of secrets or environment variables. We never need them, and we will never ask.

## Look here first

Many problems can be diagnosed without waiting for a reply:

| Problem | Start with |
|---|---|
| A build fails | The build log on the deployment, and [Troubleshooting builds](/docs/guides/troubleshooting-builds) |
| Your site returns errors or 5xx responses | The project's **Logs**, and [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime) |
| Sites show a paused page | Your organization's **Billing** page, and [Paused accounts](/docs/paused-accounts) |
| A charge you don't recognize | [Invoices & taxes](/docs/invoices) and [Usage & metering](/docs/usage) |
| A domain won't verify | [Domain verification](/docs/domain-verification) |
| Who changed a setting | The [activity log](/docs/activity-log) |

## Billing questions

Plan changes, cancellation and card updates are self-serve on your organization's **Billing** page. Email [hello@dply.io](mailto:hello@dply.io) for anything else about a charge, including a charge you believe is wrong. Include the invoice date and amount.

## Report abuse or a security issue

- To report a site hosted on dply that is abusive, see [Report abuse](/docs/abuse).
- To report a vulnerability in dply itself, email [hello@dply.io](mailto:hello@dply.io) with "Security" in the subject line and don't disclose it publicly until we've responded. See [Compliance & security](/docs/compliance).

## Related

- [Private beta](/docs/private-beta)
- [Compliance & security](/docs/compliance)
- [Report abuse](/docs/abuse)
