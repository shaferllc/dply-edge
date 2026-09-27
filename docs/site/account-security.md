---
title: "Account security"
description: "Protect your dply account with a password, passkeys, OAuth sign-in, and two-factor authentication."
---

Your account is how you reach every organization you belong to, so it deserves more than a password. dply supports passwords, passkeys, OAuth sign-in with your Git provider, and authenticator-app two-factor authentication. You manage all of them in **Profile → Security**.

## Security posture

The top of **Profile → Security** summarizes your account:

| Posture | Meaning |
|---|---|
| **Hardened** | Two-factor authentication is on, and you have a passkey or a linked OAuth account |
| **Good** | Two-factor authentication is on |
| **Password only** | Two-factor authentication is off. Add it, or a passkey |

The same header shows how many passkeys you have and whether two-factor authentication is **Enabled** or **Off**.

## Change your password

1. Open **Profile → Security**.
2. In **Password**, enter your **Current password**, a **New password**, and **Confirm new password**.
3. Choose **Save password** in the bar that appears.

Use a long, random password stored in a password manager.

## Passkeys

A passkey signs you in with your device PIN, fingerprint, face, or a hardware security key, with no password. Once you have one, choose **Sign in with a passkey** on the sign-in page.

To add a passkey:

1. Open **Profile → Security**.
2. In **Passkeys**, give it a name (for example `Work laptop`).
3. Choose **Add a passkey** and follow your browser's prompt.

You can register several passkeys, for example one per device. Rename a passkey by editing its name in the list. Choose **Remove** to delete one. dply will not let you remove your only passkey if it is your only way to sign in; add a password or link an OAuth account first.

## OAuth sign-in

You can sign in with the same GitHub, GitLab, or Bitbucket account you use for Git. In **OAuth sign-in**, choose **Link account** next to a provider (or **Link another** to link a second account from the same provider). Choose **Unlink** to remove one.

dply will not let you unlink your only sign-in method. Set a password or add a passkey first.

## Two-factor authentication

Two-factor authentication (2FA) asks for a 6-digit code from an authenticator app when you sign in with your email and password. Any time-based one-time password (TOTP) app works, such as 1Password, Bitwarden, Authy, Google Authenticator, or Microsoft Authenticator.

### Turn on 2FA

1. Open **Profile → Security** and, in **Two-factor authentication**, choose **Set up 2FA**.
2. Start setup, then scan the QR code with your authenticator app. If you cannot scan it, enter the setup key by hand.
3. Enter the 6-digit code the app shows to confirm.
4. Save the 8 recovery codes dply shows you.

> [!WARNING]
> Recovery codes are shown only once, right after you confirm. Store them somewhere safe, such as your password manager. They are the only way back in if you lose your authenticator device.

### Use a recovery code

At the two-factor prompt, enter a recovery code instead of the 6-digit code. Each recovery code works once. The **Two-factor authentication** page shows how many you have left. When you run out, disable and re-enable 2FA to get a new set; you need your authenticator app to do this.

### Turn off 2FA

Open **Profile → Security**, choose **Manage or disable**, and enter your password plus a current 6-digit code or a recovery code.

> [!NOTE]
> dply asks for the two-factor code when you sign in with email and password. Signing in with a passkey or a linked OAuth account does not ask for a code, so protect your Git provider account with its own two-factor authentication.

## What gets recorded

Changing your password, turning 2FA on or off, removing a passkey, and unlinking an OAuth account are recorded in the [activity log](/docs/activity-log) of your current organization.

## Tokens and CLI sessions

API tokens and CLI sign-ins act as you. Treat them like passwords:

- Owners and admins create and revoke API tokens in **Profile → API keys**. See [HTTP API](/docs/api).
- Owners and admins review and revoke CLI sessions in **Profile → CLI**. Anyone can revoke their own CLI sessions with `dply account sessions` and `dply account revoke`. `dply logout` only deletes the token from your machine; it does not revoke it. See [CLI](/docs/cli).

## Delete your account

Open **Profile → Delete account** and enter your password. Your account is deleted and you are signed out.

> [!WARNING]
> Deleting your account cannot be undone. Ownership of an organization cannot be transferred from the dashboard, so delete any organization you own first, or contact support. See [Organizations](/docs/organizations#delete-an-organization).

## Related

- [Roles & permissions](/docs/roles-and-permissions)
- [Activity log](/docs/activity-log)
- [CLI](/docs/cli)
