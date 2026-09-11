# `dply` — command-line interface for dply Edge

Zero-dependency Node CLI for the dply REST API. Link a repo to an Edge site,
deploy, roll back, promote previews, manage domains and env vars, and tail
request logs from your terminal.

## Install

The CLI is **hosted by your dply instance** (not npm). Each install downloads
the package from `/cli/dply-cli.tgz` on the same origin as the web app.

```sh
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --login
```

Requires **Node 18+** and **npm** (npm installs the downloaded tarball globally).

When you pipe from your dply instance, the script already knows your `APP_URL`.
`--login` opens the browser for device-flow authentication when install finishes.

```sh
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --help
curl -fsSL https://your-dply.example/cli/install.sh | bash -s -- --login
```

Check the hosted version:

```sh
curl -fsSL https://your-dply.example/cli/version.json
```

## Update

New commands ship with the instance, not with a registry release — the tarball
is built on demand from the running app. So updating is one command:

```sh
dply update             # install what your instance is serving
dply update --check     # report only; exits 1 when your build differs
dply update --json      # {installed, serving, up_to_date, base_url}
dply --version          # what you are running right now
```

The check is an **equality**, not a greater-than: the instance is the source of
truth, so an instance that has rolled back rolls your CLI back too. `--force`
reinstalls the same version.

If the global install hits a permission wall, re-run with `sudo` or fall back to
the installer (`curl -fsSL https://your-dply.example/cli/install.sh | bash`).

### Developing on the CLI

Point the global `dply` at your checkout once, and every edit is live — no
reinstall, no repack:

```sh
cd packages/dply-cli
npm link                 # global `dply` becomes a symlink to this directory
which dply && ls -la $(which dply)   # confirm it resolves into your repo
```

Undo with `npm unlink -g @dply/cli`, then re-run the installer for a packed build.

While linked, `dply update` refuses and tells you to `git pull` instead —
installing the packed tarball would replace the symlink and quietly end dev
mode. `dply update --force` overrides that if you actually want the packed build.

### Self-hosted config

In `.env` on the dply app:

```env
APP_URL=https://dplyi.test
# Default — download from this app:
DPLY_CLI_INSTALL_METHOD=tarball
DPLY_CLI_NPM_PUBLISHED=false
```

After you publish `@dply/cli` to npm, set `DPLY_CLI_NPM_PUBLISHED=true` and
optionally `DPLY_CLI_INSTALL_METHOD=auto` to try npm first.

## Sign in (seamless device flow)

```sh
dply login --base-url https://your-dply.example
```

1. The CLI prints a short code and opens your browser to the dply instance.
2. Sign in if needed, confirm the code, pick your organization and scopes, click **Approve**.
3. The terminal saves the token and drops you into **`dply shell`** — press **Enter** or run **`menu`** to browse actions without memorizing commands.

Use `dply login --no-shell` in scripts/CI to skip the interactive shell.

Revoke CLI sessions from **Profile → CLI** in the web app. Run `dply auth refresh` (or `dply refresh`) to re-approve scopes when you need more permissions.

## Verify

```sh
dply whoami
dply sites           # Edge sites this token can see
dply menu            # numbered menus — type names or numbers
dply shell           # re-open the interactive shell anytime
```

## Switch instances (`dply use`)

A dply token is minted by, and valid only for, one instance — so moving between
a local install and the hosted one is a swap of URL *and* credential. The CLI
keeps several signed-in instances and switches between them:

```sh
dply use                      # pick from the ones you are signed in to
dply use list                 # show them, active one marked
dply use dply.io             # switch to a saved instance
dply use https://dply.io     # add one — signs you in, keeps the others
dply use live                 # shorthand for the hosted instance
dply use forget dply.test     # drop a saved session
```

Instances are keyed by hostname, so there is no alias to invent or remember.
`dply login --base-url <url>` *adds* an instance instead of replacing the one
you were already using, and `dply logout` signs you out of the active instance
only.

Config lives at `~/.dply/config.json`. A config written by an older CLI is
adopted on first use — it becomes an instance named after its own host and stays
active, so upgrading logs nobody out.

## Link and deploy

Create the site in the dashboard, then from your repo:

```sh
dply link              # interactive picker · or `dply link <site-id>`
dply deploy --wait     # queue a deploy and block until it is live
dply edge status       # site + latest deployment
```

`dply link` writes `.dply/site.json`; every site-scoped command then defaults to
that site. Override per command with `--site <id>` or `$DPLY_EDGE_SITE`. A folder
linked by an older CLI to a non-Edge site is refused by `dply deploy` — re-link it.

CI / GitHub Actions:

```sh
# Install + auth (see Profile → CLI for full workflow YAML)
dply login --token "$DPLY_TOKEN" --no-shell
dply deploy --site "$DPLY_EDGE_SITE" --wait
```

### Sites

```sh
dply sites             # every Edge site this token can see
dply sites checkout    # filter by name
dply sites --json
```

### Edge commands

```sh
dply edge deploy [--commit <sha>] [--branch <b>] [--prod] [--wait]
dply edge deployments [--limit N]
dply edge status [--wait]
dply edge rollback <deployment-id>
dply edge previews list | create [--commit|--branch] [--wait] | rm <id>
dply edge promote <preview-id>
dply edge domains list | add <host> | verify <host> | rm <host>
dply edge aliases
dply edge env list | set KEY=val | rm KEY | push --file .env | pull
dply edge purge --tag <tag>
dply edge logs [--once] [--interval ms] [--window s]
dply edge usage [--days N]
dply edge open [--dashboard]
dply edge lint [--path dply.yaml]
```

### Notifications

One matrix: a **channel** × an **event key** × the **site** it fires for.
Reading needs **`notifications.read`**, routing and tests need
**`notifications.write`**.

```sh
dply notifications                     # what fires for the linked site, and where
dply notifications acme                # by name
dply notifications channels            # what you can route to
dply notifications events --subject site
dply notifications subscribe <event…> --channel <id> --site acme
dply notifications unsubscribe <event…> --channel <id>
dply notifications test <channel>      # send that channel its test message
```

Subscribing **adds** to a channel instead of replacing its selection, so two
people routing different events can't clobber each other.

## Commands

See **Profile → CLI** in the web app. Run `dply help`, `dply ls`, or `dply edge --help`.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | API or runtime error |
| 2 | Bad arguments / not logged in |
