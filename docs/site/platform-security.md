---
title: "Platform security & isolation"
description: "How dply keeps each organization's apps, builds, data and secrets separate, and how it protects outbound requests and credentials."
---

dply runs many customers' apps on shared infrastructure, so separating tenants is central to how it's built. This page covers how your resources, builds, secrets and outbound requests are isolated, and where your data lives. For compliance documents and security contacts, see [Compliance & security](/docs/compliance).

## Tenant isolation

### Apps and hostnames

- Each app is served only on its own hostnames. A hostname can belong to only one app across all dply organizations, and dply checks this when you attach a domain and again when you verify it. See [Domains](/docs/domains).
- Proving you own a custom domain takes either a DNS record in a Cloudflare zone your organization controls (**active** zones only), or a per-app TXT token. When your domain points at dply's shared target, a CNAME alone isn't enough to claim it. See [Domain verification](/docs/domain-verification).
- Each deploy's files are stored under their own storage path. Requests for paths containing `..` are rejected.
- Edge-cached responses are stored under keys scoped to the app, and purges affect only that app.

### Code execution

- **SSR apps** run as their own Cloudflare Worker script, in Cloudflare's isolate sandbox. Your code doesn't share memory with other customers' code.
- **Container apps** run in their own containers on Cloudflare Containers. See [Container apps](/docs/containers).
- **Edge middleware** runs as its own Worker script per app. See [Edge middleware](/docs/edge-middleware).

### Resources

Databases, queues, key-value stores, object storage and other [resources](/docs/resources) belong to the organization that created them:

- An app can only bind resources its own organization owns. Bindings declared in your repository (`wrangler.toml` or `dply.yaml`) are resolved inside your organization's namespace, so a binding name can't reach another customer's resource or dply's own storage.
- Plan limits and payment requirements are checked on every path that creates a resource: dashboard, repository config, CLI and API.
- dply-managed Valkey checks a connection's credentials before it reaches your store.

## Build isolation

Builds run your repository's scripts, including package install hooks, so dply treats every build as untrusted:

- Each build runs in its own container, as a non-root user, with all Linux capabilities dropped and privilege escalation disabled.
- Each build has memory, CPU and process limits.
- Builds run on a dedicated network where one build can't connect to another.
- Package caches (npm, pnpm, Yarn) are kept per organization, so one organization's build can't poison another's cache.
- Only your checkout and your organization's caches are mounted into the build. Nothing else from the build host is.
- Container app images are built in an isolated builder with the same kind of limits. Cache mounts are scoped per organization, and no deploy credentials are present during the build.

Builds can reach the public internet so they can download packages. Don't rely on a build being unable to make outbound requests.

## Secrets

- [Environment variables](/docs/environment-variables) and [secrets](/docs/secrets) are encrypted at rest.
- Git provider tokens, Cloudflare credentials, notification channel settings (including webhook URLs) and resource connection strings are encrypted at rest too.
- Some settings, such as the bot protection secret key, are only shown to members who can edit the app.
- Passwords for [access control](/docs/access-control) are stored hashed, never in plain text.
- Container image builds don't receive your deploy credentials.

## Outbound requests

When dply's control plane makes HTTP requests to URLs you provide (webhook notification channels, hybrid origin URLs and health checks, and external Redis endpoints), it guards against server-side request forgery:

- Only `http` and `https` URLs are accepted, without embedded credentials.
- The hostname is resolved first, and the request is refused if any resolved address is private, loopback, link-local or a cloud metadata address.
- The request is pinned to the checked address, with redirects disabled, so a later DNS change or a redirect can't send it to an internal address.

## Access to your organization

- Every dashboard, API and CLI action is authorized against your role in the organization or app. See [Roles & permissions](/docs/roles-and-permissions).
- API tokens are limited to the abilities you grant and to their owner's role, and stop working when the owner leaves the organization. See [HTTP API](/docs/api).
- Organization changes are recorded in the [activity log](/docs/activity-log).
- Two-factor authentication and passkeys are available for every account. See [Account security](/docs/account-security).

## Where your data lives

- Static files and edge-cached responses are stored on Cloudflare's network and served from the location nearest each visitor.
- Databases and Valkey stores created by dply are hosted on DigitalOcean in New York.
- Container apps are placed by Cloudflare region. Apps that use dply data default to eastern North America.

See [Data regions](/docs/data-regions) for details and options.

## Reporting a vulnerability

If you find a security issue, report it privately. See [Compliance & security](/docs/compliance). To report abuse hosted on dply, see [Report abuse](/docs/abuse).

## Related

- [Access control](/docs/access-control)
- [Firewall](/docs/firewall)
- [Edge network](/docs/edge-network)
- [Secrets](/docs/secrets)
