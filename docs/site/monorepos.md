---
title: "Monorepos"
description: "Deploy one package from a monorepo by setting a repository root, and control which pushes redeploy it."
---

A monorepo holds several apps or packages in one Git repository. dply deploys one package per app: you point the app at the package's folder with a **Repository root**, and dply builds from there while still installing dependencies from the workspace root. To deploy several packages from the same repository, create one app for each.

## Set the repository root

When you create an app, dply inspects the repository for workspace markers: `pnpm-workspace.yaml`, `turbo.json`, `nx.json`, or `lerna.json`. When it finds one, it lists the packages it detected. If there is exactly one, it fills in the repository root for you.

To change it later, open **Build**, expand **Advanced**, and set **Repository root** to the package folder relative to the top of the repository, such as `apps/web`.

Choose **Save advanced**, then redeploy. The path cannot contain `..`, and leading or trailing slashes are removed. If the folder does not exist at the deployed commit, the build fails with `Repository root "apps/web" was not found in the checkout.`

## How builds work in a monorepo

With a repository root set:

- **Checkout.** dply checks out only the package folder and the workspace files it needs, not every package in the repository.
- **Configuration.** `dply.yaml` and `wrangler.toml` are read from the repository root folder, not the top of the repository. Put them next to the package's `package.json`.
- **Build command and output directory** run and resolve inside the repository root folder. An output directory of `dist` means `apps/web/dist`.
- **Install.** When the top of the repository is a workspace (it has `pnpm-workspace.yaml`, or a `package.json` with `workspaces`), dply installs from the workspace root using the lockfile there, and filters the install to your package and its dependencies:

| Package manager | Filtered install |
|-----------------|------------------|
| pnpm | `pnpm install --filter ./apps/web...` |
| npm | `npm ci -w <package name>`, falling back to `npm install -w <package name>` |
| Yarn | A full `yarn install` at the workspace root, then `yarn workspaces focus <package name>` |

If the package's `package.json` has no `name`, npm and Yarn repositories fall back to a normal install.

- **Build cache.** For workspace monorepos, the cache is saved and restored at the workspace root, where the installed `node_modules` live.

You do not need to add `cd apps/web` to your build command. dply runs it from the package folder.

## Which pushes trigger a deploy

With push-to-deploy connected, a push to the production branch redeploys the app only when it changes a file under the repository root. A push that only touches other packages is skipped. Changes to a `dply.yaml`, `dply.yml`, or `dply.json` at the top of the repository, or inside the repository root, also trigger a deploy.

With no repository root set, every push to the production branch redeploys the app.

Deploy hooks, **Redeploy now**, and the API always deploy, whatever files changed. See [Deploy triggers & hooks](/docs/deploy-triggers).

## Turborepo and Nx

dply does not run `turbo` or `nx` for you. If your package's build depends on other workspace packages being built first, make the build command do it, for example:

```bash
pnpm turbo run build --filter=web
```

Because dply runs the command from the package folder, use your tool's filter flag rather than relying on the current directory.

## Using `build.root` in `dply.yaml`

`dply.yaml` also accepts a `build.root` key, but it is not a substitute for **Repository root**. It changes where dply looks for the lockfile, Node version, and output directory, while your build command still runs from the folder `dply.yaml` was read from, and it does not affect checkout or which pushes deploy. Use **Repository root** for monorepos.

## Related

- [Builds](/docs/builds)
- [Configuration files](/docs/configuration-files)
- [Deploy triggers & hooks](/docs/deploy-triggers)
