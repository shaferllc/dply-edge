---
title: "CLI"
description: "Install the dply command-line tool, sign in from the browser, and deploy, roll back, and inspect your apps from a terminal or CI."
---

The `dply` CLI drives the dply HTTP API from your terminal. Link a repository to an app, then deploy, roll back, promote previews, manage domains and environment variables, tail request logs, query D1 databases, and route notifications without opening the dashboard. Use it on your own machine and in CI.

The CLI calls the same endpoints as the [HTTP API](/docs/api), so every command is limited by the abilities on your token and by your role in the organization.

## Install

The CLI is served by dply itself, not by the npm registry. You need **Node.js 18 or later** and **npm**. The installer downloads the package from `https://dply.io/cli/dply-cli.tgz` and installs it globally with `npm install -g`.

```bash
curl -fsSL https://dply.io/cli/install.sh | bash -s -- --login
```

`--login` runs `dply login` when the install finishes. The installer accepts these options:

| Option | Description |
| --- | --- |
| `--login` | Run `dply login` after installing. |
| `--no-login` | Skip login (the default). |
| `--base-url <url>` | Download from, and sign in to, a different dply host. |
| `--method <method>` | `tarball` (default), `npm`, or `auto`. |
| `-h`, `--help` | Show help. |

> [!IMPORTANT]
> The installer rejects any option it does not recognize and exits with code `2`. Don't pass `dply` flags such as `--no-shell` to `install.sh`.

Check which version dply is serving, and which version you have:

```bash
curl -fsSL https://dply.io/cli/version.json
dply --version
```

### Update

New commands ship with the platform, so updating means installing whatever build dply is serving right now:

```bash
dply update            # install the build dply is serving
dply update --check    # report only; exits 1 when your build differs
dply update --json     # {installed, serving, up_to_date, base_url}
dply update --force    # reinstall even when up to date
```

The check compares your build with the served build for equality, not "newer than". If the served build is rolled back, `dply update` rolls your CLI back too. If the global install fails with a permission error, re-run with `sudo` or use the install script again.

## Sign in

`dply login` uses a device flow, the same pattern as the GitHub and Stripe CLIs. You approve the terminal in your browser. You never paste a password or token into the terminal.

```bash
dply login
```

1. The CLI asks dply for a code pair and prints a URL and an eight-character code in the form `ABCD-EFGH`. It tries to open the URL in your browser.
2. In the browser, sign in if you need to. Confirm that the code matches the one in your terminal.
3. Choose the **organization** the CLI should act in, then select the scopes (abilities) to grant.
4. Choose **Approve**. The terminal saves the token and opens the interactive shell.

The code expires after 15 minutes. If you deny the request, or the code expires, the CLI exits with code `2` and you run `dply login` again. Press `Ctrl+C` to cancel.

| Flag | Description |
| --- | --- |
| `--base-url <url>`, `-b` | The dply host to sign in to. Defaults to `https://dply.io`. |
| `--token <token>`, `-t` | Skip the browser and save an existing API token (for CI). The CLI verifies it before saving. |
| `--no-open` | Print the approval URL without opening a browser. |
| `--no-shell` | Don't open the interactive shell after signing in. Use this in scripts. |

### Scopes you can grant

The approval page only offers scopes your organization role allows. The server applies the same limit when it issues the token, so a modified request can't exceed it.

| Organization role | Scopes offered |
| --- | --- |
| Owner, Admin | Every ability in the catalog |
| Member | `account.read`, `account.write`, `billing.read`, `edge.read`, `edge.env.read`, `sites.read`, `servers.read` |
| Deployer | `account.read`, `account.write`, `edge.read`, `edge.deploy`, `edge.env.read`, `sites.read`, `servers.read` |

See [Roles & permissions](/docs/roles-and-permissions) for what each role can do. The [HTTP API](/docs/api) page describes each ability.

> [!NOTE]
> Members can't grant `edge.deploy` through the CLI, so a member's CLI token can read apps but can't deploy them. If you need to deploy from a terminal or CI, ask an admin to create an API token with `edge.deploy` for you.

Tokens issued through `dply login` are named `dply CLI` and don't expire. They stop working when someone revokes them, or when you leave the organization.

### Add more scopes later

If a command fails with `403 Forbidden`, your token doesn't carry the ability that command needs. Approve a new token with more scopes:

```bash
dply auth refresh
```

`dply refresh` and `dply account refresh` do the same thing. The CLI shows the scopes that were added and removed, then revokes the previous CLI session. Pass `--keep-old` to keep the old session, `--no-open` to skip opening the browser, or `--base-url` to refresh against another host.

### Sign in from CI

In CI, create an API token under **Profile → API keys** (see [HTTP API](/docs/api)) and pass it with `--token`:

```bash
dply login --token "$DPLY_TOKEN" --base-url https://dply.io --no-shell
dply deploy --site "$DPLY_EDGE_SITE" --wait
```

A GitHub Actions workflow:

```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
      - run: curl -fsSL https://dply.io/cli/install.sh | bash
      - run: dply login --token "${{ secrets.DPLY_TOKEN }}" --base-url https://dply.io --no-shell
      - run: dply deploy --wait --commit "${{ github.sha }}"
        env:
          DPLY_EDGE_SITE: ${{ vars.DPLY_EDGE_SITE }}
```

The token needs `edge.read` and `edge.deploy`. Either commit `.dply/site.json` or set `DPLY_EDGE_SITE`. `--commit` deploys the exact commit that triggered the workflow; leave it out to deploy the head of the app's branch. **Profile → CLI** in the dashboard shows the same workflow with your instance's URLs filled in.

## Verify your setup

```bash
dply whoami     # user, organization, role, token abilities, linked site
dply sites      # apps this token can see
```

## Link a folder to an app

Create the app in the dashboard first. The CLI can't create apps. Then, from your repository:

```bash
dply link              # pick from a list
dply link <site-id>    # or link by id
```

`dply link` writes `.dply/site.json` in the current folder. Commands that act on one app look for that file in the current folder and its parents. The file also records which dply host the app lives on, so a linked repository keeps deploying to the right place after you switch hosts.

Every app-scoped command chooses its app in this order:

1. The `--site <id>` flag.
2. The `DPLY_EDGE_SITE` environment variable.
3. The linked folder (`.dply/site.json`).

## Deploy

```bash
dply deploy --wait
```

`dply deploy` deploys the linked app, or the app that `--site` or `DPLY_EDGE_SITE` names. It's the same as `dply edge deploy`. If the folder isn't linked, the command exits with code `2`. If an older CLI linked the folder to a site type dply no longer hosts, `dply deploy` asks you to re-link it.

| Flag | Description |
| --- | --- |
| `--commit <sha>` | Build this commit. |
| `--branch <name>` | Build this branch. |
| `--wait`, `--follow`, `-w` | Wait until the deployment is `live`, `failed`, or `superseded`. Exits `1` if it fails. |
| `--interval <ms>`, `-i` | How often `--wait` polls, in milliseconds. The default is `2000` and the minimum is `500`. |
| `--prod` | After queueing, print the production URL. This flag doesn't change what gets deployed. |
| `--site <id>` | The app to deploy. |

## Command reference

Run `dply help` for a summary, `dply ls` for a flat list of commands, or `dply edge --help` for the app commands. Every two-word command also works in colon form: `dply edge:status` is the same as `dply edge status`.

Commands that print tables accept `--json` where noted.

### Account

| Command | Description |
| --- | --- |
| `dply whoami` | Same as `dply account show`. Falls back to the saved local config if the API refuses the request. |
| `dply account show [--json]` | Shows your user, current organization and role, the token's id, abilities, last use and expiry, and the linked folder. |
| `dply account orgs [--json]` | Lists the organizations you belong to and your role in each. |
| `dply account sessions [--json]` | Lists `dply CLI` sessions in the organization. Admins see everyone's sessions. |
| `dply account revoke <session-id>` | Revokes a CLI session. You can revoke your own sessions. Owners and admins can revoke anyone's. If you revoke the session you're using, the CLI removes its saved token. |
| `dply account refresh` | Same as `dply auth refresh`. |
| `dply logout` | Removes the saved token for the active host from this machine. It doesn't revoke the token on the server. Also available as `dply account logout`. |

Reading needs `account.read` and revoking needs `account.write`.

### Hosts (`dply use`)

A token works only on the dply host that issued it. The CLI stores a separate sign-in for each host and lets you switch between them:

| Command | Description |
| --- | --- |
| `dply use` | Pick a saved host from a list. |
| `dply use list` | List saved hosts. The active one is marked. |
| `dply use <host>` | Switch to a saved host, for example `dply use dply.io`. |
| `dply use <url>` | Sign in to a new host and keep the others saved. Outside a terminal, pass `--login` or run `dply login --base-url <url>` instead. |
| `dply use live` | Switch to `https://dply.io`. `cloud`, `prod`, `production`, and `hosted` do the same. |
| `dply use forget <host>` | Delete a saved host. `dply use rm <host>` does the same. |

Hosts are keyed by hostname. `dply login --base-url <url>` adds a host without removing the others.

### Apps

| Command | Description |
| --- | --- |
| `dply sites [name] [--json]` | Lists the apps this token can see, with id, name, status, URL, and runtime. A name filters the list. |
| `dply link [site-id]` | Links the current folder to an app. |
| `dply deploy` | Deploys the linked app. See [Deploy](#deploy). |

### App commands (`dply edge`)

Each of these accepts `--site <id>`.

| Command | Description | Ability |
| --- | --- | --- |
| `dply edge deploy` | Queues a deployment. Accepts the same flags as `dply deploy`. | `edge.deploy` |
| `dply edge deployments [--limit N]` | Lists recent deployments with status, commit, branch, publish time, and alias count. The default limit is `20`. | `edge.read` |
| `dply edge status [--wait] [--json]` | Shows the app and its latest deployment, including any failure reason. With `--wait`, blocks until an in-progress deployment finishes. | `edge.read` |
| `dply edge rollback <deployment-id>` | Points production at an earlier deployment. | `edge.deploy` |
| `dply edge previews list` | Lists preview deployments. | `edge.read` |
| `dply edge previews create [--commit <sha>] [--branch <name>] [--wait]` | Creates a preview. Without `--commit`, the CLI resolves the branch head through the public GitHub API, so private repositories need `--commit`. | `edge.deploy` |
| `dply edge previews rm <preview-id>` | Tears down a preview. `destroy` also works. | `edge.deploy` |
| `dply edge promote <preview-id>` | Promotes a preview to production. | `edge.deploy` |
| `dply edge domains list` | Lists custom domains with DNS status and CNAME target. | `edge.read` |
| `dply edge domains add <hostname>` | Attaches a domain and prints the DNS records to create. | `edge.write` |
| `dply edge domains verify <hostname>` | Checks DNS and reports `ready` or the current status. | `edge.write` |
| `dply edge domains rm <hostname>` | Detaches a domain. | `edge.write` |
| `dply edge aliases` | Lists the stable URL for each deployment. | `edge.read` |
| `dply edge purge --tag <tag>` | Purges cached responses with a cache tag. `--tag` is required. | `edge.write` |
| `dply edge usage [--days N]` | Prints traffic and usage as JSON. The default is `30` days. | `edge.read` |
| `dply edge logs` | Tails request logs. See [Tail logs](#tail-logs). | `edge.read` |
| `dply edge open [--dashboard]` | Opens the live URL, or the app's dashboard page with `--dashboard`. | `edge.read` |
| `dply edge lint [--path <file>]` | Validates `dply.yaml`, `dply.yml`, or `dply.json` with the same rules a build uses. Exits `1` if there are errors. | `edge.read` |
| `dply edge env list` | Lists environment variable keys and when each changed. The API never returns values. | `edge.env.read` |
| `dply edge env pull` | Prints every key as `KEY=` with empty values. | `edge.env.read` |
| `dply edge env set KEY=value [KEY=value …]` | Creates or updates one or more variables. | `edge.env.write` |
| `dply edge env rm KEY [KEY …]` | Deletes variables. `remove` and `unset` also work. | `edge.env.write` |
| `dply edge env push --file <path>` | Replaces the app's variables with the contents of a dotenv file. `-f` also works. | `edge.env.write` |

> [!WARNING]
> `dply edge env push` replaces the whole set of variables with the file's contents. Keys missing from the file are removed. To change a few keys, use `dply edge env set`.

`env push` reads `KEY=value` lines. It supports double-quoted values (with `\n` and `\t` escapes), single-quoted literal values, `export` prefixes, and `#` comments. It skips keys that aren't uppercase letters, digits, and underscores.

### Tail logs

```bash
dply edge logs
```

Prints request logs as they arrive: time, method, status, duration, cache status, and path. Press `Ctrl+C` to stop.

| Flag | Description |
| --- | --- |
| `--once` | Print one batch and exit. |
| `--interval <ms>` | Poll interval, from `500` to `60000`. The default is `1000`. |
| `--window <s>` | How far back to start, from `1` to `3600` seconds. The default is `60`. |

See [Logs](/docs/logs) for what gets recorded.

### Databases and queues

These commands work on the organization's D1 databases and queues. See [Edge SQL (D1)](/docs/resources/sql) and [Queues](/docs/resources/queues).

| Command | Description | Ability |
| --- | --- | --- |
| `dply db list [--json]` | Lists databases. | `edge.read` |
| `dply db query <database> "<sql>" [--json]` | Runs SQL and prints each statement's rows, or its change and row counts. | `edge.write` |
| `dply queues list [--json]` | Lists queues. | `edge.read` |
| `dply queues send <queue> '<json>'` | Sends one message. Text that isn't valid JSON is sent as a string. | `edge.write` |

> [!WARNING]
> `dply db query` runs whatever SQL you give it, including writes and `DROP`. That's why it needs `edge.write`.

### Notifications

Routing links a channel, an event, and the app it fires for. See [Notification channels](/docs/notifications).

| Command | Description | Ability |
| --- | --- | --- |
| `dply notifications [site]` | Shows which events are routed to which channels for an app. | `notifications.read` |
| `dply notifications channels [--json]` | Lists the channels you can route to. | `notifications.read` |
| `dply notifications events [--subject site] [--json]` | Lists the event catalog. | `notifications.read` |
| `dply notifications subscribe <event…> --channel <id>` | Routes one or more events to a channel. This adds to the channel's current events and doesn't replace them. | `notifications.write` |
| `dply notifications unsubscribe <event…> --channel <id>` | Stops routing those events. | `notifications.write` |
| `dply notifications test <channel>` | Sends the channel a test message. | `notifications.write` |

`dply notify` is an alias. `--channel` (or `-c`) accepts a channel id or part of its label. `--site` (or `-s`) accepts an app id or name. In a terminal, the CLI shows a picker when either is ambiguous.

### Billing

Available to organization owners and admins, with `billing.read`.

| Command | Description |
| --- | --- |
| `dply billing show [--json]` | Plan, monthly estimate, and subscription status. |
| `dply billing breakdown [--json]` | Estimate by category and line item. |
| `dply billing invoices [--json]` | Recent invoices. |

See [Usage & metering](/docs/usage) and [Invoices & taxes](/docs/invoices).

### Interactive mode

Run `dply` with no arguments in a terminal, or `dply shell`, to open an interactive shell with tab completion. `dply menu` (or `dply m`) shows numbered menus, so you don't have to remember command names.

Shortcuts: `me` and `who` run `whoami`, `orgs` runs `account orgs`, `bill` runs `billing show`, and `r` runs `refresh`.

## Configuration

| Path | Contents |
| --- | --- |
| `~/.dply/config.json` | Saved hosts, a token for each, and which host is active. |
| `.dply/site.json` | The linked app for a repository: app id, name, and host. You can commit it. |

### Environment variables

| Variable | Effect |
| --- | --- |
| `DPLY_EDGE_SITE` | The app id for app-scoped commands, when `--site` isn't passed. |
| `DPLY_SITE` | Also sets the app for `dply notifications`. |
| `DPLY_API_BASE_URL`, `DPLY_BASE_URL` | The dply host to sign in to or use when nothing else sets one. |
| `DPLY_TOKEN`, `DPLY_API_TOKEN` | A token for the `account`, `billing`, `db`, `queues`, and `notifications` commands that isn't read from the config file. The `edge` commands and `dply deploy` don't read it, so in CI run `dply login --token` first. |
| `DPLY_TLS_CA_FILE` | An extra CA certificate to trust, for self-hosted instances behind a private CA. |
| `NO_COLOR` | Turns off colored output. |

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | An API or runtime error: a failed deployment, a failed lint, or `dply update --check` finding a different build. |
| `2` | Bad arguments, no linked app, not signed in, or a denied or expired login. |

Errors go to standard error, prefixed with `dply:`. On a `403`, the CLI suggests `dply auth refresh`.

## Revoke CLI sessions

Each approved device appears as a session. To revoke one:

- From the terminal, run `dply account sessions`, then `dply account revoke <session-id>`.
- In the dashboard, open **Profile → CLI**. Under **CLI authentications**, choose **Revoke** next to the session. Only organization owners and admins can see this list.

Revoking a session invalidates its token right away. That machine must run `dply login` again. Removing someone from the organization also stops every token they hold for it.

## Related

- [HTTP API](/docs/api)
- [API reference](/docs/api/reference)
- [Deployments](/docs/deployments)
- [MCP server](/docs/mcp)
