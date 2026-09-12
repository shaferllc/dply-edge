<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\Site;
use App\Support\GitCloneUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property string $dns_zone
 * @property string $logo_path
 * @property string $git_repository_url
 * @property string $ssl_status
 * @property string $edge_backend
 */
trait ResolvesSiteUrls
{
    /**
     * Naive apex guess from the primary site hostname (last two labels), e.g. app.example.com → example.com.
     */
    public function guessDnsZoneFromPrimaryHostname(): ?string
    {
        $this->loadMissing('domains');
        $host = strtolower(trim((string) optional($this->primaryDomain())->hostname));

        return self::apexGuessForHostname($host);
    }

    /**
     * Apex extraction helper for an arbitrary hostname — same rule as
     * {@see guessDnsZoneFromPrimaryHostname()} but doesn't read from the
     * site's current domain. Used by the rename-cascade planner to decide
     * whether the saved `dns_zone` was the operator's choice or matched
     * what dply would have auto-suggested from the *old* hostname.
     */
    public static function apexGuessForHostname(string $hostname): ?string
    {
        $host = strtolower(trim($hostname));
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
    }

    /**
     * True when the saved `dns_zone` equals the apex dply would auto-guess
     * from the supplied hostname. Treats an empty saved zone as "no operator
     * value" — also auto-derived for cascade purposes.
     */
    public function dnsZoneMatchesAutoGuessForHostname(string $hostname): bool
    {
        $saved = strtolower(trim((string) ($this->dns_zone ?? '')));
        if ($saved === '') {
            return true;
        }

        $guess = self::apexGuessForHostname($hostname);

        return $guess !== null && strtolower($guess) === $saved;
    }

    /**
     * What the value of {@see $git_branch} represents — a branch name, a
     * tag name, or a commit SHA. Deployers branch on this to pick clone
     * flags: branch/tag use `--branch`, commit needs a full clone + checkout.
     * Defaults to 'branch' for legacy sites with no meta value.
     *
     * @return 'branch'|'tag'|'commit'
     */
    public function gitRefKind(): string
    {
        $meta = $this->meta ?? [];
        $value = $meta['git_ref_kind'] ?? null;

        return in_array($value, ['branch', 'tag', 'commit'], true) ? $value : 'branch';
    }

    public function visitUrl(): ?string
    {
        if ($this->provisionedUrl() !== null) {
            return $this->provisionedUrl();
        }

        if (! $this->isReadyForTraffic()) {
            return null;
        }

        $hostname = $this->testingHostname();
        if ($hostname !== '') {
            return $this->urlSchemeForHostname($hostname).'://'.$hostname;
        }

        $primary = $this->primaryDomain()?->hostname;

        return $primary
            ? $this->urlSchemeForHostname($primary).'://'.$primary
            : null;
    }

    /**
     * Scheme to advertise for a public site hostname. dply provisions SSL for
     * every managed hostname and forces an HTTP→HTTPS redirect, so a provisioned
     * site's canonical URL is https. Hardcoding http:// made "visit"/preview
     * links land on the redirect — and an http:// preview opened from the https
     * console reads as broken (mixed-content block / bare nginx error). Fall back
     * to http only while SSL isn't active yet.
     */
    protected function urlSchemeForHostname(?string $hostname): string
    {
        $hostname = strtolower(trim((string) $hostname));
        if ($hostname === '') {
            return 'http';
        }

        // Managed preview hostname (e.g. *.on-dply.cc) — the preview-domain row
        // tracks SSL + redirect state for that exact host.
        $preview = $this->primaryPreviewDomain();
        if ($preview !== null && strtolower((string) $preview->hostname) === $hostname) {
            return ((bool) $preview->https_redirect || (string) $preview->ssl_status === 'active')
                ? 'https'
                : 'http';
        }

        // Custom domain — SSL is tracked at the site level (site_domains rows
        // carry no per-host cert state).
        return (string) $this->ssl_status === 'active' ? 'https' : 'http';
    }

    /**
     * Public URL of the site's custom logo (uploaded or pulled from its
     * favicon), or null when none is set — callers fall back to the generated
     * gradient + initials avatar. Stored on the durable `site_assets` disk so it
     * survives a redeploy.
     */
    public function logoUrl(): ?string
    {
        $path = $this->logo_path;
        if (blank($path)) {
            return null;
        }

        return Storage::disk('site_assets')->url($path);
    }

    public function hasLogo(): bool
    {
        return filled($this->logo_path);
    }

    /**
     * Git remote URL for source-control API browsing (BYO git_repository_url or Edge source.repo).
     *
     * Always a URL, never the bare `owner/name` shorthand. Serverless Create
     * persists the shorthand into git_repository_url (see {@see GitCloneUrl}),
     * which the clone path expands but every reader here does not: the commits
     * list, README, branches, file browser, and commitWebUrl() all run the
     * value through a URL parser, so shorthand read as "no repository
     * configured" on a site that plainly had one. Normalizing at the accessor
     * fixes all of them at once, and matches what the Edge branch below was
     * already doing inline.
     */
    public function sourceControlRepositoryUrl(): ?string
    {
        $direct = trim((string) $this->git_repository_url);
        if ($direct !== '') {
            return GitCloneUrl::normalize($direct);
        }

        if (! $this->usesEdgeRuntime()) {
            return null;
        }

        $source = is_array($this->edgeMeta()['source'] ?? null) ? $this->edgeMeta()['source'] : [];
        $repo = trim((string) ($source['repo'] ?? ''));
        if ($repo === '') {
            return null;
        }

        return GitCloneUrl::normalize($repo);
    }

    /**
     * Web URL to view a commit on the site's git provider, derived from its
     * remote ({@see sourceControlRepositoryUrl()}). Handles SSH and HTTPS
     * remotes and the path quirks of GitHub (/commit), GitLab (/-/commit) and
     * Bitbucket (/commits). Null when there's no remote or sha.
     */
    public function commitWebUrl(?string $sha): ?string
    {
        $sha = trim((string) $sha);
        $remote = trim((string) $this->sourceControlRepositoryUrl());
        if ($sha === '' || $remote === '') {
            return null;
        }

        // git@host:owner/repo(.git) → host + owner/repo
        if (preg_match('#^[\w.-]+@([^:]+):(.+?)(?:\.git)?/?$#', $remote, $m) === 1) {
            [$host, $path] = [$m[1], $m[2]];
            // scheme://[user@]host/owner/repo(.git)
        } elseif (preg_match('#^[a-z]+://(?:[^@/]+@)?([^/]+)/(.+?)(?:\.git)?/?$#i', $remote, $m) === 1) {
            [$host, $path] = [$m[1], $m[2]];
        } else {
            return null;
        }

        $base = 'https://'.$host.'/'.$path;

        return match (true) {
            str_contains($host, 'gitlab') => $base.'/-/commit/'.$sha,
            str_contains($host, 'bitbucket') => $base.'/commits/'.$sha,
            default => $base.'/commit/'.$sha, // GitHub + generic
        };
    }



    /**
     * @return array<string, mixed>
     */
    public function repositoryMeta(): array
    {
        $meta = $this->meta ?? [];

        return is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeRepositoryMeta(array $patch): void
    {
        $meta = $this->meta ?? [];
        $current = is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
        $meta['repository'] = array_merge($current, $patch);
        $this->meta = $meta;
    }
}
