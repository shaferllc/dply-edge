---
title: "CLI"
slug: account-cli
category: "Account"
order: 630
description: "Install the dply CLI, sign in once with device-flow login, link a folder to an Edge site, and deploy, roll back and inspect it from the terminal."
group: account
---

# CLI

**Profile → CLI** is where you install the command line, authenticate, and review every CLI session tied to your organizations.

> Managing CLI authentications requires **org admin** access.

## At a glance

- **Sessions** — active devices signed in to the CLI.
- **Organizations** — the organizations you administer.
- **Last used** — your most recent CLI sign-in.

## Install

The CLI is hosted by **this dply instance — not npm**. The install script downloads `/cli/dply-cli.tgz` and installs it globally. **Node 18+** is required. `dply update` installs whatever build your instance is serving (`--check` to compare only).

## Sign in

Run `dply login` — your browser opens here, you approve the device once, and the terminal drops into `dply shell`. Each approval creates a **session** listed on this page. If you need more scopes later, run `dply auth refresh` (same browser approval, new token on that machine).

## Working with more than one instance

A CLI token belongs to the instance that minted it, so pointing the CLI at a
second dply install is a swap of both URL and credential. `dply use` keeps them
side by side:

- `dply use` — pick from the instances you are signed in to.
- `dply use list` — show them, with the active one marked.
- `dply use <host>` — switch to a saved one (`dply use dply.io`).
- `dply use <url>` — add one; it signs you in and leaves the others saved.
- `dply use forget <host>` — drop a saved session.

`dply login --base-url <url>` adds an instance rather than replacing the current
one, and `dply logout` signs out of the active instance only.

## Sessions

Every approved device shows up under **CLI authentications**. **Revoke** a session to immediately invalidate that machine's token.

## Linking a folder

Sites are created in the dashboard (`/edge/create`, or `/edge/import` to bring a
project over from Vercel, Netlify or Cloudflare Pages). Then attach a folder:

- `dply link <site>` — link by id; with no id you get a picker of your Edge sites.
- The link is written to `.dply/site.json`. Commit it, or pass `--site <id>` /
  set `$DPLY_EDGE_SITE` in CI.
- `dply sites` — list your Edge sites (`dply sites <name>` filters).

`dply deploy` in a folder that is not linked tells you to create the site in the
dashboard and `dply link` it. A folder linked by an older CLI to a VM, cloud or
serverless site gets a re-link error — dply no longer hosts those.

## Edge commands

| Command | What it does |
|---------|--------------|
| `dply deploy` / `dply edge deploy` | Queue a deploy (`--commit`, `--branch`, `--prod`, `--wait`) |
| `dply edge deployments` | Recent deployments |
| `dply edge status` | Site + latest deployment (`--wait` to block until it settles) |
| `dply edge lint` | Validate `dply.yaml` in the current folder (`--path`) |
| `dply edge open` | Open the live URL (`--dashboard` for the workspace) |
| `dply edge rollback <deploy>` | Re-point production at an earlier deployment |
| `dply edge promote <deploy>` | Promote a preview to production |
| `dply edge previews` | `list` · `create [--commit\|--branch] [--wait]` · `rm <id>` |
| `dply edge domains` | `list` · `add <host>` · `verify <host>` · `rm <host>` |
| `dply edge aliases` | Per-deploy stable URLs |
| `dply edge purge --tag X` | Purge the edge cache by tag |
| `dply edge usage` | Traffic and billing usage |
| `dply edge logs` | Tail request logs (`--interval`, `--window`, `--once`) |
| `dply edge env` | `list` · `set KEY=val` · `rm KEY` · `push --file .env` · `pull` |

**GitHub Actions:** create an org API token, commit `.dply/site.json` (or pass
`--site`), and run `dply deploy --prod --wait`.

## Notification routing

`dply notifications` shows what fires for a site and where it goes. Reading needs
**`notifications.read`**; routing and test sends need **`notifications.write`**.

- `dply notifications` / `dply sites:notifications <site>` — the routing for a site.
- `dply notifications channels` — channels this token can route to.
- `dply notifications events --subject site` — the event catalog.
- `dply notifications subscribe edge.deploy.failed --channel <id> --site <site>` — route one (or several at once).
- `dply notifications unsubscribe …` — stop routing them.
- `dply notifications test <channel>` — send that channel its test message.

Subscribing **adds** to a channel rather than replacing its selection, so two
people editing different events cannot clobber each other.

## Billing

`dply billing show` (plan + monthly estimate), `dply billing breakdown`
(line items) and `dply billing invoices` — org admins only.

## Scripts

In scripts nothing prompts: a pipe, `--json`, `--no-prompt`, or
`DPLY_NO_PROMPT=1` keeps plain print-and-exit behaviour. Any `dply a b` also
reads as `dply a:b`, and `dply ls` prints every command.

## Related

- [HTTP API](HTTP_API.md) — organization API tokens used for CI and automation.
