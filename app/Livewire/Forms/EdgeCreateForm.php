<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Modules\Edge\Livewire\Create;
use App\Modules\Edge\Support\EdgeContainerPlans;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeRepoRoot;
use Livewire\Form;

class EdgeCreateForm extends Form
{
    public const DEFAULT_BUILD_COMMAND = 'npm ci && npm run build';

    public const DEFAULT_OUTPUT_DIR = 'dist';

    public string $name = '';

    /**
     * Git reference type the user is targeting — 'branch' (default), 'tag'
     * (clone via --branch <tag>), or 'commit' (full clone + checkout SHA).
     * The single ref text input writes to {@see Create::$branch}
     * regardless; the deploy step interprets the value based on this kind.
     */
    public string $ref_kind = 'branch';

    public string $build_command = '';

    public string $output_dir = '';

    public bool $spa_fallback = true;

    // Off by default — operators have to opt in on the create form so a
    // freshly connected repo doesn't silently auto-deploy on every push
    // (especially noisy for forks, scratch repos, and `main`-pushed work).
    public bool $deploy_on_push = false;

    public string $runtime_mode = 'static';

    /** Container bundle chosen before the first deploy. Ignored for other runtimes. */
    public string $container_plan = 'flex';

    public string $container_instance_type = 'basic';

    public int $container_max_instances = 1;

    public string $container_sleep_after = '10m';

    public bool $container_scheduler = false;

    public bool $container_migrate_on_boot = false;

    public string $container_jurisdiction = '';

    public string $origin_url = '';

    public string $origin_cloud_site_id = '';

    /** managed = dply platform; byo = org Cloudflare credential */
    public string $repo_root = '';

    public string $delivery_mode = 'managed';

    public string $edge_provider_credential_id = '';

    /** @var string Vercel / Netlify / cloudflare_pages when handed off from import wizard */
    public string $imported_from = '';

    public string $imported_id = '';

    public string $imported_dashboard_url = '';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'ref_kind' => ['required', 'in:branch,tag,commit'],
            'build_command' => ['nullable', 'string', 'max:500'],
            'output_dir' => ['nullable', 'string', 'max:200'],
            'spa_fallback' => ['boolean'],
            'deploy_on_push' => ['boolean'],
            'repo_root' => ['nullable', 'string', 'max:255'],
            'runtime_mode' => ['required', 'in:static,hybrid,ssr,container'],
            'container_plan' => ['nullable', 'in:flex,small,medium'],
            'container_instance_type' => ['nullable', 'in:'.implode(',', array_keys(EdgeContainerSettings::INSTANCE_TYPES))],
            'container_max_instances' => ['nullable', 'integer', 'between:1,'.EdgeContainerSettings::MAX_INSTANCES],
            'container_sleep_after' => ['nullable', 'in:'.implode(',', EdgeContainerSettings::SLEEP_AFTER)],
            'container_scheduler' => ['boolean'],
            'container_migrate_on_boot' => ['boolean'],
            'container_jurisdiction' => ['nullable', 'in:,eu,fedramp'],
            'origin_url' => ['nullable', 'string', 'max:500'],
            'delivery_mode' => ['required', 'in:managed,byo'],
            'edge_provider_credential_id' => ['required_if:delivery_mode,byo', 'nullable', 'string'],
        ];
    }

    public function resolvedBuildCommand(): string
    {
        $buildCommand = trim($this->build_command);

        return $buildCommand !== '' ? $buildCommand : self::DEFAULT_BUILD_COMMAND;
    }

    public function applyContainerPlan(): void
    {
        $settings = EdgeContainerPlans::settings($this->container_plan);
        $this->container_instance_type = $settings['instance_type'];
        $this->container_max_instances = $settings['max_instances'];
        $this->container_sleep_after = $settings['sleep_after'];
        $this->container_scheduler = $settings['scheduler'];
        $this->container_migrate_on_boot = $settings['migrate_on_boot'];
    }

    /**
     * @return array{plan: string, instance_type: string, max_instances: int, sleep_after: string, scheduler: bool, migrate_on_boot: bool, jurisdiction: string}
     */
    public function containerSettings(): array
    {
        return EdgeContainerPlans::settings($this->container_plan, [
            'instance_type' => $this->container_instance_type,
            'max_instances' => $this->container_max_instances,
            'sleep_after' => $this->container_sleep_after,
            'scheduler' => $this->container_scheduler,
            'migrate_on_boot' => $this->container_migrate_on_boot,
            'jurisdiction' => $this->container_jurisdiction,
        ]);
    }

    public function resolvedOutputDir(): string
    {
        $outputDir = trim($this->output_dir);

        return $outputDir !== '' ? $outputDir : self::DEFAULT_OUTPUT_DIR;
    }

    /**
     * @return array<string, mixed>
     */
    public function createEdgeSitePayload(string $framework, string $repo, string $branch, string $sourceControlAccountId = ''): array
    {
        // ref_kind=commit means $branch was actually a SHA — promote it to
        // git_commit and reset branch to the conventional default so the
        // deployment record + UI labels stay sensible. The build runner
        // does a full clone + checkout the SHA when git_commit is set.
        $gitCommit = null;
        $effectiveBranch = $branch;
        if ($this->ref_kind === 'commit') {
            $gitCommit = trim($branch);
            $effectiveBranch = 'main';
        }

        return [
            'name' => $this->name,
            'repo' => $repo,
            'branch' => $effectiveBranch,
            'ref_kind' => $this->ref_kind,
            'git_commit' => $gitCommit,
            'build_command' => $this->resolvedBuildCommand(),
            'output_dir' => $this->resolvedOutputDir(),
            'spa_fallback' => $this->spa_fallback,
            'deploy_on_push' => $this->deploy_on_push,
            'repo_root' => EdgeRepoRoot::normalize($this->repo_root) ?: null,
            'framework' => $framework,
            'runtime_mode' => $this->runtime_mode,
            'container_plan' => $this->container_plan,
            'container' => $this->containerSettings(),
            'origin_url' => trim($this->origin_url),
            'cloud_site_id' => $this->origin_cloud_site_id !== '' ? $this->origin_cloud_site_id : null,
            'origin_routes' => ['/_next/*', '/api/*'],
            'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
            'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
            'imported_from' => trim($this->imported_from) !== '' ? trim($this->imported_from) : null,
            'imported_id' => trim($this->imported_id) !== '' ? trim($this->imported_id) : null,
            'imported_dashboard_url' => trim($this->imported_dashboard_url) !== '' ? trim($this->imported_dashboard_url) : null,
            'git_source_control_account_id' => trim($sourceControlAccountId) !== '' ? trim($sourceControlAccountId) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $detectedPlan
     * @return array<string, mixed>
     */
    public function hybridStackPayload(array $detectedPlan, string $repo, string $branch): array
    {
        return [
            'name' => $this->name,
            'repo' => $repo,
            'branch' => $branch,
            'build_command' => $this->resolvedBuildCommand(),
            'output_dir' => $this->resolvedOutputDir(),
            'spa_fallback' => $this->spa_fallback,
            'deploy_on_push' => $this->deploy_on_push,
            'repo_root' => EdgeRepoRoot::normalize($this->repo_root) ?: null,
            'detected_plan' => $detectedPlan,
            'origin_routes' => ['/_next/*', '/api/*'],
            'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
            'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
        ];
    }

    /**
     * Canonicalize a user-pasted repo reference.
     *
     * GitHub collapses to `owner/name` shorthand — that's unambiguous
     * because GitHub doesn't support nested groups, and the shorthand is
     * what the rest of the create flow (and {@see EdgeRepoBindingTranslator})
     * expects to see persisted.
     *
     * GitLab and Bitbucket normalize to a *cleaned full URL* — host kept,
     * `.git` suffix and `/-/tree/<branch>` (GitLab) or `/src/<branch>`
     * (Bitbucket) tail segments dropped, trailing slash dropped. We
     * don't shorten them because GitLab supports nested groups
     * (`group/sub/project`) so `owner/name` shorthand is ambiguous off
     * GitHub.
     *
     * Accepted shapes for each host:
     *   GitHub
     *     - https://github.com/owner/name(.git)?
     *     - https://github.com/owner/name/tree|blob|commit(s)/<branch>[/...]
     *     - github.com/owner/name(.git)?
     *     - git@github.com:owner/name(.git)?
     *     - owner/name                              (passthrough)
     *   GitLab (nested subgroups supported — any depth of `group/.../project`)
     *     - https://gitlab.com/group/[...subgroups/]project(.git)?
     *     - https://gitlab.com/group/[...subgroups/]project/-/tree|blob|commit(s)/<branch>[/...]
     *     - git@gitlab.com:group/[...subgroups/]project(.git)?
     *   Bitbucket
     *     - https://bitbucket.org/owner/name(.git)?
     *     - https://bitbucket.org/owner/name/src|branch|commits/<branch>[/...]
     *     - git@bitbucket.org:owner/name(.git)?
     */
    public static function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // ── GitHub ─────────────────────────────────────────────────────────
        if (preg_match('#^git@github\.com:([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^https?://github\.com/([^/]+)/([^/.]+(?:\.[^/]+)*?)(?:\.git)?(?:/(?:tree|blob|commits?)/[^/]+(?:/.*)?)?/?$#i', $value, $m) === 1) {
            return $m[1].'/'.$m[2];
        }
        if (preg_match('#^github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        // ── GitLab (nested groups allowed) ─────────────────────────────────
        if (preg_match('#^git@gitlab\.com:([^:].*?)(?:\.git)?/?$#i', $value, $m) === 1 && str_contains($m[1], '/')) {
            return 'https://gitlab.com/'.trim($m[1], '/');
        }
        if (preg_match('#^https?://gitlab\.com/(.+?)(?:\.git)?(?:/-/(?:tree|blob|commits?)/[^/]+(?:/.*)?)?/?$#i', $value, $m) === 1 && str_contains($m[1], '/')) {
            return 'https://gitlab.com/'.trim($m[1], '/');
        }

        // ── Bitbucket ──────────────────────────────────────────────────────
        if (preg_match('#^git@bitbucket\.org:([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return 'https://bitbucket.org/'.$m[1];
        }
        if (preg_match('#^https?://bitbucket\.org/([^/]+/[^/.]+(?:\.[^/]+)*?)(?:\.git)?(?:/(?:src|branch|commits?)/[^/]+(?:/.*)?)?/?$#i', $value, $m) === 1) {
            return 'https://bitbucket.org/'.$m[1];
        }

        return trim($value, '/');
    }

    /**
     * Pull a branch hint out of a host's "view a branch" URL so pasting
     * a URL with a branch in the path can prefill the branch field too.
     *
     *   GitHub:    /tree|blob|commit(s)/<branch>
     *   GitLab:    /-/tree|blob|commit(s)/<branch>
     *   Bitbucket: /src|branch|commits/<branch>
     */
    public static function branchHintFromUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://github\.com/[^/]+/[^/]+?(?:\.git)?/(?:tree|blob|commits?)/([^/?#]+)~i', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~^https?://gitlab\.com/.+?(?:\.git)?/-/(?:tree|blob|commits?)/([^/?#]+)~i', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~^https?://bitbucket\.org/[^/]+/[^/]+?(?:\.git)?/(?:src|branch|commits?)/([^/?#]+)~i', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
