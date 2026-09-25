# ADR: Splitting dply into four independent products

Status: proposed (2026-08-22)

Supersedes the scope of `modular-monolith-structure.md` — not its layout decision,
which survives inside each fork (see *Fork layout*).

## Context

dply is one Laravel app on one PostgreSQL database selling four site products —
`vm`, `cloud`, `edge`, `serverless` — plus billable add-ons (managed Database,
Queue, Realtime, Logs). The decision is to split it into four separately owned,
separately operated products.

The measurements that shaped every decision below:

| | |
|---|---|
| `app/` PHP files | 3,818 |
| inside `app/Modules/*` | 1,365 (36%) |
| shared kernel | ~2,450 (64%) |
| migrations carrying `organization_id` | 61 of 197 |
| `Site` referenced by | 24 modules, 444 files |
| `Server` | 22 modules, 186 files |
| `User` / `Organization` | 25 / 19 modules |
| shared workspace UI | 365 files (`Livewire/Sites` 132 + `Livewire/Servers` 233) |
| shell files referencing each product | Serverless 14 · Edge 43 · Deploy 76 · Cloud 86 |

Two structural facts drive most of the difficulty:

**`Site` and `Server` are the shared spine, not the VM product's models.**
`Server::HOST_KIND` has nine values (`vm`, `docker`, `kubernetes`,
`digitalocean_functions`, `digitalocean_app_platform`, `aws_lambda`,
`aws_app_runner`, `dply_cloud`, `dply_edge_delivery`) and Edge, Cloud *and*
Serverless all create `Server` rows — a function's "server" is its DO Functions
namespace. `Site`'s status enum spans `nginx_active`, `docker_active`,
`kubernetes_active`, `functions_*`, `container_*`, `edge_*`.

**The shared engines are a web, not a tree.** Notifications is referenced by 12
modules; Deploy by 6, and it reaches Insights, which reaches TaskRunner (245
files, the largest module); Billing by 4 while itself depending on Deploy, Logs
and Realtime. Backups and Notifications reference each other.

## Decisions

### Separation

1. **Four fully separate products** — own repo, own database, own schema.
2. **Each product owns its own accounts.** No shared identity service and no
   shared identity database. A customer has four logins, four cards on file and
   four invoices, and one organization's usage can never be summed across
   products. This is accepted as a customer-visible consequence.
3. **Shared engines are copied and allowed to diverge.** TaskRunner, Deploy,
   Notifications, Billing, Secrets, Certificates, Backups, Logs and Insights are
   hard-forked per product rather than extracted as packages or services. A
   security fix in SSH key handling is four patches.
4. **Add-ons fork with their consumers.** Managed Database, Queue, Realtime and
   Logs are billable products today that attach to more than one kind; each of
   the four apps forks the ones it needs and sells its own. Two independent
   implementations of the Neon and PlanetScale integrations is the accepted cost.
   *(2026-09-25: dply-edge removed Neon and PlanetScale; its databases run on
   its own cluster.)*
5. **Cross-product features die or fork.** Launch (a wizard referencing `edge`
   51×, `cloud` 49×) is deleted — it cannot span four products with four
   accounts. Insights, OpsCopilot and Projects fork to single-product versions.
   `dply sites` becomes four lists.

### Shape of each fork

6. **Four full clones of the monolith**, each deleting the modules it does not
   own in a first commit. History and blame survive for the ~2,450 kernel files
   every app keeps; no hashes are rewritten.
7. **`Site` and `Server` fork wholesale, then prune.** Each app takes the full
   tables and models and deletes unused host kinds, statuses and columns in
   follow-up PRs. The cutover stays a data partition rather than a partition
   *plus* a model redesign across 444 files.
8. **`app/Modules/*` survives inside each fork.** The existing ADR's rationale
   was findability, not coupling — and Deploy, Notifications, Secrets and
   TaskRunner remain distinct capabilities inside a single-product app.
9. **One provider account, per-app scoped tokens and zones.** Each app gets its
   own zones (serverless keeps `*.dply-serverless.cloud`, edge keeps its apexes),
   a Cloudflare token scoped to only those, and its own Spaces buckets. Provider
   accounts are not code, so sharing one does not re-couple the codebases, and no
   live customer hostname has to move.
10. **One CLI binary, four instances.** The instance store, `dply use`, and the
    link-file-names-its-instance behaviour already shipped; `dply init` drops its
    kind menu because the active instance implies the kind.

### Execution

11. **Sequence: Serverless → Edge → Cloud. VM is the residue.** Ascending
    coupling order, so the cheapest fork teaches the recipe. VM is not extracted
    at all — it is the monolith with three products removed.
12. **Big-bang partition at a cutover date.** The live database is divided four
    ways; every customer is issued accounts per product.
13. **Stripe customers are cloned per product.** *Open risk: cloning does not
    move a payment mandate. Depending on region and network this may require
    re-authorisation — confirm with Stripe before the date is fixed.*
14. **Cut over on the pruned UI; rewrite after.** Each product's workspace is
    rewritten from scratch, but only once the app is independently deployable —
    the data partition and the billing migration are the irreversible steps and
    do not get a UI rewrite tied to them.
15. **Done bar per fork:** boots standalone with the other products deleted; its
    suite plus phpstan plus the Livewire alias guard green; deploys to its own
    infrastructure with its own scoped tokens; and can sign up an org, create its
    product, deploy it and bill it without reading the monolith's database.
16. **No rollback.** At cutover the monolith database is frozen read-only and
    kept for months as the authoritative pre-split copy. Everything is fixed
    forward; the frozen copy exists to reconstruct records, not to revert to.

## Step 1 executed (2026-08-22): extract the provider layer

The first fork was going to be "clone, then delete the other products." Measuring
the dependency closure first showed that does not work: **Serverless transitively
required 18 of 33 modules, including Edge and Cloud**, through cycles
(`Cloud → Edge → Billing → Queue → Serverless → Cloud`). Deleting the other
products would have broken Serverless.

The cause was misfiling, not real coupling. `app/Modules/Cloud` held two things:
the container-app product, and a provider layer everything depends on —
15 provider SDK wrappers (AWS EC2/EKS/AppRunner, Azure, GCP, Hetzner, Linode,
Vultr, UpCloud, OVH, Oracle, Route53, DigitalOcean), `Cloudflare/` and
`Namecheap/`. Hetzner and Linode are *VM* providers; `DigitalOceanService` is a
114-line wrapper over `api.digitalocean.com/v2` that the **kernel itself** used
(`app/Jobs`, `app/Actions/Servers`, `app/Services/Servers`, `app/Livewire/Servers`)
— the ADR's own `feature → platform → kernel, never upward` rule, violated in
reverse. `Edge\Services\EdgeCloudflareClient` was the same mistake one module over.

So step 1 was not a fork at all. `App\Modules\Providers` now holds that layer
(22 files moved, 153 files rewritten, behaviour-zero):

| edge | before | after |
|---|---|---|
| Serverless → Cloud (real code) | 10 files | **0** |
| Serverless → Edge (real code) | 11 files | **2** |

The two that remain are `EdgeTestingDomains` (shared testing-domain logic, wants
to be kernel or platform) and `EdgeSiteCanceller` (genuine product-logic reuse —
a real decision, not a misfiling). Everything else that looked like
cross-product coupling was docblock `{@see}` references, which carry no runtime
dependency and which `ModuleBoundaryTest` already ignores.

**Lesson for the remaining forks:** measure the transitive closure before
deleting anything, and expect most apparent coupling to be platform code filed
under a product. Verified by: app boots, `ModuleBoundaryTest` green, and
`--group=serverless` at its pre-existing baseline (3 failures, 570 passing).

## Known tensions

These are unresolved conflicts between decisions, recorded rather than smoothed
over.

- **The done bar requires a green suite; the test-debt decision defers it.**
  Decision 15 makes "suite green" a gate. The chosen approach to the 463 failing
  module-local tests is to fork first and let each app fix its own copy — but 73
  of those files are TaskRunner's, and TaskRunner is copied into all four apps.
  So either the gate slips for the first fork, or the shared-engine tests get
  fixed before it. **This has to be settled before the Serverless fork starts.**
- **"Each product owns its accounts" and "one CLI binary" pull apart.** The CLI
  can hide four instances behind `dply use`, but it cannot hide four sign-ups,
  four cards and four invoices. The CLI makes the split tolerable for engineers;
  it does nothing for the buyer.
- **Forked engines and a single security response.** Decision 3 accepts four
  copies of the SSH engine. There is no decision yet about how a CVE in it gets
  patched across four repos on a deadline.
- **No rollback plus a big bang concentrates all safety in pre-cutover
  verification.** Nothing here yet specifies that verification beyond the done
  bar — rehearsing the full migration against a production clone, repeatedly and
  including Stripe in test mode, is the missing piece.

## Consequences

- Five things exist during the transition (four products plus the monolith),
  reducing to four when VM becomes the residue.
- `ModuleBoundaryTest` keeps working but guards less, since each fork has fewer
  modules to violate anything.
- The org-wide cost observatory and "deploy my whole stack" stop being possible
  features, permanently, absent shared identity.
- Coupling that today is checked by the compiler (imports across modules) becomes
  coupling checked by nothing (four schemas, four Stripe customers, one provider
  account).
