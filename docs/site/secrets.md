---
title: "Secrets"
description: "Store a secret once in your organization's vault, link it onto apps, and rotate it in one place."
---

Organization secrets are a shared vault of values, such as a Stripe key or an SMTP password, that you store once and link onto any app in the organization. When you rotate a secret, every app it is linked to picks up the new value on its next deploy. Use them instead of pasting the same value into several apps' environment variables.

Values are write-only: after you save a secret, nobody can read it back in the dashboard, CLI, or API. You can only replace it.

## How secrets reach your app

A linked secret is added to your app's environment on each deploy, exactly like a variable you set on the **Environment** page. It reaches the build and, for server-rendered and container apps, the runtime. See [Environment variables](/docs/environment-variables) for where variables are available.

The order is:

1. Linked secrets.
2. The app's own **Environment** variables, which win over a linked secret with the same key.

Resource variables, such as a database or Realtime, are covered in [Environment variables](/docs/environment-variables).

> [!IMPORTANT]
> Creating, rotating, linking, unlinking, and deleting secrets all take effect on the next deploy. Open **Deploys** and choose **Redeploy the latest** to apply a change right away.

## Create a secret

Only organization owners and admins can create secrets from the organization page.

1. Open your organization and choose **Secrets**.
2. Under **Add a secret**, enter a **Key**, such as `STRIPE_SECRET`, and a **Value**.
3. Add **Notes** if you like. Notes are required when a secret with the same key already exists, so you can tell them apart.
4. Choose **Save secret**.

Keys are converted to uppercase and may contain letters, digits, and underscores. Values can be up to 16,000 characters.

Several secrets can share a key, for example a test and a live `STRIPE_SECRET`. Each is a separate secret, and an app can link only one secret per key.

> [!NOTE]
> A secret whose key starts with an underscore, or uses a platform-reserved name such as `ENVIRONMENT` or `SITE_ID`, saves but is never added to a deploy. Use keys that start with a letter. See [reserved names](/docs/environment-variables).

## Link secrets to an app

Anyone who can update an app can link secrets to it.

1. In your app, open **Environment**.
2. In **Linked secrets**, choose **Paste or link secrets**.
3. Either:
   - Under **Or link an existing vault secret**, search by key or note and choose **Link**. **Key already linked** means the app already has a secret with that key.
   - Under **Bulk import**, paste a `.env` snippet into **Paste .env** and choose **Import secrets**. Each key is saved as a new organization secret and linked to this app. Keys the app already links are skipped.
4. Redeploy the app.

Linked secrets appear in the panel with their values masked. A badge on a secret shows when:

- The app's own variables on **Environment** also set the same key. That value is used.
- A connected resource already provides that key.

> [!NOTE]
> Pasting in **Bulk import** creates organization secrets, even for members who are not organization admins.

To unlink, choose the unlink control next to the secret and confirm. The key drops from the app on its next deploy, and the secret stays in the vault.

## Rotate a secret

Only owners and admins can rotate.

1. Open your organization, choose **Secrets**, then **Rotate** on the secret.
2. Enter the **New value** and choose **Save new value**.
3. Redeploy each app that links it. The **Secrets** list shows which apps each secret is linked to.

## Delete a secret

Only owners and admins can delete. Choose **Delete** on the secret, or select several and choose **Delete selected**. Deleting unlinks the secret from every app, and each app drops the key on its next deploy.

## Security

- Values are encrypted at rest with the application's encryption key.
- dply decrypts values only to inject them into a deploy. dply's systems can therefore decrypt them; the vault is not zero-knowledge.
- Every member of the organization can see secret keys, notes, and which apps they are linked to. No one can see values.
- Linked secrets are not passed to [preview deployments](/docs/preview-deployments).

## The Residency tab

**Secrets** has a second tab, **Residency**, with an **Encryption key** panel (a dply-managed or customer-held `age` key) and an **External secret stores** panel for HashiCorp Vault, AWS Secrets Manager, and Doppler.

> [!IMPORTANT]
> Settings on the **Residency** tab do not affect Edge apps today. Deploys do not read external secret stores or residency keys. Use organization secrets and **Environment** variables for values your apps need.

## Related

- [Environment variables](/docs/environment-variables)
- [Organizations](/docs/organizations)
- [Roles & permissions](/docs/roles-and-permissions)
