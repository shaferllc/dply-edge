<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Manifest;

/**
 * Parsed, validated representation of a `dply.yaml` file.
 *
 * The manifest is intentionally minimal — code-shape only. It captures
 * what changes with the code (runtime version, build/release commands,
 * process definitions) and deliberately *omits* deployment-shape fields
 * (env vars, domains, server selection, database engine) which stay in
 * the dply dashboard because they vary per environment and may contain
 * secrets.
 *
 * All fields are optional. A repo with no `dply.yaml` deploys via auto-
 * detection; a repo with a one-line manifest overrides only that line.
 *
 * Precedence on deploy: manifest → auto-detection → dashboard.
 */
final readonly class DplyManifest
{
    /**
     * Allowed values for the top-level `runtime:` key.
     *
     * Static is included even though it has no runtime per se — it's the
     * "I'm a static site" marker that lets the manifest opt out of build/
     * runtime detection entirely.
     */
    public const ALLOWED_RUNTIMES = ['php', 'node', 'python', 'ruby', 'go', 'static'];

    /**
     * Every top-level key the unified manifest recognizes across all site
     * kinds. This DTO only *stores* the code-shape subset (runtime, version,
     * build, release, processes, healthcheck) — the remaining keys are owned by
     * sibling loaders (routing/crons/hooks/env via ByoRepoConfigLoader, and the
     * `edge:` block via EdgeRepoConfigLoader). They are listed here so the
     * code-shape parser does NOT emit "unknown key" warnings when it reads a
     * real unified file that also carries those sections.
     *
     * Genuinely unknown keys still produce a forward-compat warning (newer
     * manifests may add fields older clients can safely skip).
     */
    public const KNOWN_TOP_LEVEL_KEYS = [
        // code-shape (stored on this DTO)
        'runtime', 'version', 'build', 'release', 'processes', 'healthcheck',
        // which dply product this repo wants to be — read by `dply init` when
        // creating the site. Purely declarative: it never changes an existing
        // site's kind, because a site's kind is not something a deploy may
        // silently migrate.
        'kind',
        // control-plane supervisor templates (SelfSupervisorSync; not code-shape)
        'supervisor',
        // repeatable config (owned by ByoRepoConfigLoader / EdgeRepoConfig)
        'crons', 'server_crons', 'redirects', 'rewrites', 'headers',
        'deploy_hooks', 'env', 'env_declarations', 'domains',
        // Cloudflare-only block (owned by EdgeRepoConfigLoader)
        'edge', 'bindings', 'firewall', 'origin', 'previews', 'error_pages',
        'maintenance', 'images', 'comment_widget', 'env_files',
    ];

    /**
     * @param  list<string>  $build
     * @param  list<string>  $release
     * @param  array<string, mixed>  $processes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $runtime,
        public ?string $version,
        public array $build,
        public array $release,
        public array $processes,
        public array $warnings,
        public ?string $healthcheck = null,
        public ?string $kind = null,
    ) {}

    public static function empty(): self
    {
        return new self(
            runtime: null,
            version: null,
            build: [],
            release: [],
            processes: [],
            warnings: [],
            healthcheck: null,
            kind: null,
        );
    }

    /**
     * True when the manifest declares at least one code-shape field — i.e. it
     * has an opinion the deploy pipeline should honor (authoritative).
     */
    public function hasCodeShape(): bool
    {
        return $this->runtime !== null
            || $this->version !== null
            || $this->build !== []
            || $this->release !== []
            || $this->processes !== []
            || $this->healthcheck !== null;
    }
}
