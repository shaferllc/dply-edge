<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveAlerts;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeEffectiveErrorPages;
use App\Modules\Edge\Support\EdgeEffectiveFirewall;
use App\Modules\Edge\Support\EdgeEffectiveImages;
use App\Modules\Edge\Support\EdgeEffectiveOrigin;
use App\Modules\Edge\Support\EdgeEffectiveProductAddons;
use App\Modules\Edge\Support\EdgeEffectiveRouting;
use App\Modules\Edge\Support\EdgeTagVendors;

/**
 * Generates a `dply.yaml` snippet from a site's most recent live
 * deployment's repo_config (plus dashboard overrides via the
 * EdgeEffective* mergers). Lets a user who set things up manually
 * export the current state to a file they can commit as the source
 * of truth.
 */
final class EdgeRepoConfigYamlGenerator
{
    public function forSite(Site $site): string
    {
        $deployment = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repo = is_array($deployment?->repo_config) ? $deployment->repo_config : [];

        $sections = [];

        $spaFallback = $repo['spa_fallback'] ?? null;
        if (is_bool($spaFallback)) {
            $sections[] = sprintf('spa_fallback: %s', $spaFallback ? 'true' : 'false');
        }

        // Routing — merge repo + dashboard overrides via
        // EdgeEffectiveRouting so dashboard-added rules round-trip
        // into the generated file. Source labels are stripped since
        // the YAML is the canonical record once committed.
        $effRouting = EdgeEffectiveRouting::for($site, $deployment);
        $stripSource = static fn (array $r): array => array_diff_key($r, ['source' => true]);
        if ($effRouting['redirects'] !== []) {
            $sections[] = $this->renderRedirects(array_map($stripSource, $effRouting['redirects']));
        }
        if ($effRouting['rewrites'] !== []) {
            $sections[] = $this->renderRewrites(array_map($stripSource, $effRouting['rewrites']));
        }
        if ($effRouting['headers'] !== []) {
            $sections[] = $this->renderHeaders(array_map($stripSource, $effRouting['headers']));
        }

        // Crons come through the effective-crons merger so dashboard
        // overrides round-trip into the generated YAML alongside the
        // repo-declared ones. Source labels are stripped here since
        // the file is meant to be the canonical record once committed.
        $effective = EdgeEffectiveCrons::for($site, $deployment);
        $crons = array_map(
            static fn (array $e): array => array_filter([
                'schedule' => $e['schedule'],
                'handler' => $e['handler'],
            ], static fn ($v): bool => $v !== null),
            $effective,
        );
        if ($crons !== []) {
            $sections[] = $this->renderCrons($crons);
        }

        // Firewall: same merge model as crons (repo + dashboard). Skip
        // when mode is off OR countries empty so we don't emit a stub
        // section that adds nothing.
        $effFirewall = EdgeEffectiveFirewall::for($site, $deployment);
        if ($effFirewall['country_mode'] !== 'off' && $effFirewall['countries'] !== []) {
            $sections[] = $this->renderFirewall($effFirewall);
        }

        // RUM alerts — emit when any metric is enabled (dashboard wins
        // per metric over the repo declaration).
        $effAlerts = EdgeEffectiveAlerts::for($site, $deployment);
        if (EdgeEffectiveAlerts::anyEnabled($effAlerts)) {
            $sections[] = $this->renderAlerts($effAlerts);
        }

        // Hybrid origin (P55-followup): emit a `origin:` block when the
        // site has a non-empty merged origin url. Skip auth_secret —
        // that's environment-sensitive and should be set via the
        // dashboard, not committed to the repo.
        $effOrigin = EdgeEffectiveOrigin::for($site, $deployment);
        if (is_string($effOrigin['url']) && $effOrigin['url'] !== '') {
            $sections[] = $this->renderOrigin($effOrigin);
        }

        // Image optimization (P55-followup): emit allowed_hosts only —
        // signing_secret never round-trips into the repo file.
        $effImages = EdgeEffectiveImages::for($site, $deployment);
        if ($effImages['allowed_hosts'] !== []) {
            $sections[] = $this->renderImages($effImages['allowed_hosts']);
        }

        // Bindings (kv / r2 / d1 / queues) are intentionally NOT
        // emitted into dply.yaml — they live in wrangler.toml. dply
        // auto-creates any missing CF resources on deploy.

        // Custom domains — emit the repo's declared list verbatim. The
        // dashboard's attached-hostnames list is intentionally NOT
        // emitted here (those are runtime state, not declarative
        // intent). Users who want everything in the file can copy the
        // hostnames they want over from Edge → Domains.
        $domains = is_array($repo['domains'] ?? null) ? $repo['domains'] : [];
        if ($domains !== []) {
            $sections[] = $this->renderDomains($domains);
        }

        // Preview-deploy policy — `enabled` + branch lists + per-PR
        // protection mode. Only emit when the repo declared something
        // (defaults are permissive).
        $previews = is_array($repo['previews'] ?? null) ? $repo['previews'] : [];
        if ($previews !== []) {
            $sections[] = $this->renderPreviews($previews);
        }

        // Preview comment widget — boolean toggle. Tokens stay
        // dashboard-only (never round-trip).
        $commentWidget = is_array($repo['comment_widget'] ?? null) ? $repo['comment_widget'] : [];
        if (isset($commentWidget['enabled'])) {
            $sections[] = "comment_widget:\n  enabled: ".((bool) $commentWidget['enabled'] ? 'true' : 'false');
        }

        // env.public + env.secret. Public values ARE emitted (they're
        // intended to be commit-safe). Secret VALUES are never emitted
        // — only the name list, so reviewers can see what the site
        // expects without leaking the actual secret.
        $envCfg = is_array($repo['env'] ?? null) ? $repo['env'] : [];
        if ($envCfg !== []) {
            $sections[] = $this->renderEnv($envCfg);
        }

        // Error pages + maintenance — merge repo + dashboard via the
        // effective resolver (dashboard wins on conflict). HTML bodies
        // are inlined when present; we keep them ≤ a sane size to keep
        // the generated file readable.
        $effErrors = EdgeEffectiveErrorPages::for($site, $deployment);
        if (is_string($effErrors['html_404']) || is_string($effErrors['html_500'])) {
            $sections[] = $this->renderErrorPages($effErrors['html_404'], $effErrors['html_500']);
        }
        if ($effErrors['maintenance_enabled'] || is_string($effErrors['maintenance_html'])) {
            $sections[] = $this->renderMaintenance($effErrors['maintenance_enabled'], $effErrors['maintenance_html']);
        }

        // Tags / snippets / forms — dashboard wins when the section was
        // saved in the UI; otherwise emit the repo declaration.
        $effTags = EdgeEffectiveProductAddons::tags($site, $deployment);
        if ($effTags !== [] && ((bool) ($effTags['enabled'] ?? false) || ! empty($effTags['tools']))) {
            $sections[] = $this->renderTags($effTags);
        }
        $effSnippets = EdgeEffectiveProductAddons::snippets($site, $deployment);
        if ($effSnippets !== [] && ((bool) ($effSnippets['enabled'] ?? false) || ! empty($effSnippets['items']))) {
            $sections[] = $this->renderSnippets($effSnippets);
        }
        $effForms = EdgeEffectiveProductAddons::forms($site, $deployment);
        if ($effForms !== [] && ((bool) ($effForms['enabled'] ?? false) || ! empty($effForms['endpoints']))) {
            $sections[] = $this->renderForms($effForms);
        }

        if ($sections === []) {
            return "# dply.yaml — generated from {$site->name}\n# No declarative config (redirects / rewrites / headers / crons) on the latest deploy.\n# Add sections below; the worker reads this file on every deploy.\n";
        }

        return "# dply.yaml — generated for {$site->name} ({$site->id})\n# Commit at the repo root. The platform picks it up automatically on the next deploy.\n\n"
            .implode("\n\n", $sections)
            ."\n";
    }

    /** @param array{url: ?string, routes: list<string>, failover_html: ?string, auth_secret: ?string, sources: array{repo: bool, dashboard: bool}} $origin */
    private function renderOrigin(array $origin): string
    {
        $lines = ['origin:'];
        if (is_string($origin['url'])) {
            $lines[] = '  url: '.$this->quote($origin['url']);
        }
        if ($origin['routes'] !== []) {
            $lines[] = '  routes:';
            foreach ($origin['routes'] as $route) {
                $lines[] = '    - '.$this->quote($route);
            }
        }
        if (is_string($origin['failover_html']) && $origin['failover_html'] !== '') {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $origin['failover_html']);
            $lines[] = '  failover_html: "'.$escaped.'"';
        }

        return implode("\n", $lines);
    }

    private function renderErrorPages(?string $html404, ?string $html500): string
    {
        $lines = ['error_pages:'];
        if (is_string($html404) && $html404 !== '') {
            $lines[] = '  html_404: '.$this->quote($html404);
        }
        if (is_string($html500) && $html500 !== '') {
            $lines[] = '  html_500: '.$this->quote($html500);
        }

        return implode("\n", $lines);
    }

    private function renderMaintenance(bool $enabled, ?string $html): string
    {
        $lines = ['maintenance:'];
        $lines[] = '  enabled: '.($enabled ? 'true' : 'false');
        if (is_string($html) && $html !== '') {
            $lines[] = '  html: '.$this->quote($html);
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $tags */
    private function renderTags(array $tags): string
    {
        $lines = ['tags:'];
        $lines[] = '  enabled: '.(((bool) ($tags['enabled'] ?? false)) ? 'true' : 'false');
        if (isset($tags['consent_required'])) {
            $lines[] = '  consent_required: '.(((bool) $tags['consent_required']) ? 'true' : 'false');
        }
        $tools = is_array($tags['tools'] ?? null) ? $tags['tools'] : [];
        if ($tools !== []) {
            $lines[] = '  tools:';
            foreach ($tools as $tool) {
                $tool = is_array($tool) ? EdgeTagVendors::normalize($tool) : null;
                if ($tool === null) {
                    continue;
                }
                $lines[] = '    - name: '.$this->quote($tool['name']);
                if ($tool['vendor'] === 'custom') {
                    $lines[] = '      src: '.$this->quote($tool['src']);
                    $lines[] = '      async: '.($tool['async'] ? 'true' : 'false');
                } else {
                    $lines[] = '      vendor: '.$tool['vendor'];
                    $lines[] = '      id: '.$this->quote($tool['id']);
                }
                $lines[] = '      purpose: '.$tool['purpose'];
                $lines[] = '      path: '.$this->quote($tool['path']);
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $snippets */
    private function renderSnippets(array $snippets): string
    {
        $lines = ['snippets:'];
        $lines[] = '  enabled: '.(((bool) ($snippets['enabled'] ?? false)) ? 'true' : 'false');
        $items = is_array($snippets['items'] ?? null) ? $snippets['items'] : [];
        if ($items !== []) {
            $lines[] = '  items:';
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $lines[] = '    - name: '.$this->quote((string) ($item['name'] ?? 'snippet'));
                $lines[] = '      phase: '.$this->quote((string) ($item['phase'] ?? 'head'));
                $lines[] = '      path: '.$this->quote((string) ($item['path'] ?? '/*'));
                $lines[] = '      html: '.$this->quote((string) ($item['html'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $forms */
    private function renderForms(array $forms): string
    {
        $lines = ['forms:'];
        $lines[] = '  enabled: '.(((bool) ($forms['enabled'] ?? false)) ? 'true' : 'false');
        $endpoints = is_array($forms['endpoints'] ?? null) ? $forms['endpoints'] : [];
        if ($endpoints !== []) {
            $lines[] = '  endpoints:';
            foreach ($endpoints as $endpoint) {
                if (! is_array($endpoint)) {
                    continue;
                }
                $lines[] = '    - path: '.$this->quote((string) ($endpoint['path'] ?? ''));
                $lines[] = '      to_email: '.$this->quote((string) ($endpoint['to_email'] ?? ''));
                $lines[] = '      honeypot: '.$this->quote((string) ($endpoint['honeypot'] ?? 'company'));
                $lines[] = '      require_turnstile: '.(((bool) ($endpoint['require_turnstile'] ?? true)) ? 'true' : 'false');
            }
        }

        return implode("\n", $lines);
    }

    /** @param  list<string> $allowedHosts */
    private function renderImages(array $allowedHosts): string
    {
        $lines = ['images:', '  allowed_hosts:'];
        foreach ($allowedHosts as $host) {
            $lines[] = '    - '.$this->quote($host);
        }

        return implode("\n", $lines);
    }

    /** @param array{public?: array<string, string>, secret?: list<string>} $env */
    private function renderEnv(array $env): string
    {
        $lines = ['env:'];
        $public = is_array($env['public'] ?? null) ? $env['public'] : [];
        if ($public !== []) {
            $lines[] = '  public:';
            foreach ($public as $name => $value) {
                $lines[] = '    '.$name.': '.$this->quote($value);
            }
        }
        $secret = is_array($env['secret'] ?? null) ? $env['secret'] : [];
        if ($secret !== []) {
            $lines[] = '  secret:';
            foreach ($secret as $name) {
                if ($name) {
                    $lines[] = '    - '.$this->quote($name);
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed> $previews */
    private function renderPreviews(array $previews): string
    {
        $lines = ['previews:'];
        if (isset($previews['enabled'])) {
            $lines[] = '  enabled: '.($previews['enabled'] ? 'true' : 'false');
        }
        if (isset($previews['pr_only'])) {
            $lines[] = '  pr_only: '.($previews['pr_only'] ? 'true' : 'false');
        }
        foreach (['branches', 'exclude_branches'] as $key) {
            if (! is_array($previews[$key] ?? null) || $previews[$key] === []) {
                continue;
            }
            $lines[] = '  '.$key.':';
            foreach ($previews[$key] as $branch) {
                if (is_string($branch) && $branch !== '') {
                    $lines[] = '    - '.$this->quote($branch);
                }
            }
        }
        $protection = is_array($previews['protection'] ?? null) ? $previews['protection'] : [];
        if ($protection !== []) {
            $lines[] = '  protection:';
            if (isset($protection['mode'])) {
                $lines[] = '    mode: '.$this->quote((string) $protection['mode']);
            }
            if (is_array($protection['allowed_emails'] ?? null) && $protection['allowed_emails'] !== []) {
                $lines[] = '    allowed_emails:';
                foreach ($protection['allowed_emails'] as $email) {
                    if (is_string($email) && $email !== '') {
                        $lines[] = '      - '.$this->quote($email);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed> $domains */
    private function renderDomains(array $domains): string
    {
        $lines = ['domains:'];
        foreach ($domains as $host) {
            if (! is_string($host) || $host === '') {
                continue;
            }
            $lines[] = '  - '.$this->quote($host);
        }

        return implode("\n", $lines);
    }

    /** @param array{country_mode: string, countries: list<string>} $firewall */
    private function renderFirewall(array $firewall): string
    {
        $lines = ['firewall:'];
        $lines[] = '  country_mode: '.$this->quote($firewall['country_mode']);
        $lines[] = '  countries:';
        foreach ($firewall['countries'] as $code) {
            $lines[] = '    - '.$this->quote($code);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{
     *     lcp_p75_ms: array{enabled: bool, threshold: int},
     *     error_rate: array{enabled: bool, threshold: float},
     *     five_xx_count: array{enabled: bool, threshold: int}
     * }  $alerts
     */
    private function renderAlerts(array $alerts): string
    {
        $lines = ['alerts:'];
        foreach (EdgeEffectiveAlerts::KEYS as $key) {
            $metric = $alerts[$key];
            if (! $metric['enabled']) {
                continue;
            }
            $threshold = $metric['threshold'];
            $lines[] = '  '.$key.':';
            $lines[] = '    enabled: true';
            $lines[] = '    threshold: '.($key === 'error_rate' ? (string) $threshold : (string) (int) $threshold);
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $rules */
    private function renderRedirects(array $rules): string
    {
        $lines = ['redirects:'];
        foreach ($rules as $rule) {
            $from = $this->quote((string) ($rule['from'] ?? ''));
            $to = $this->quote((string) ($rule['to'] ?? ''));
            $status = (int) ($rule['status'] ?? 301);
            $lines[] = "  - from: {$from}";
            $lines[] = "    to: {$to}";
            $lines[] = "    status: {$status}";
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $rules */
    private function renderRewrites(array $rules): string
    {
        $lines = ['rewrites:'];
        foreach ($rules as $rule) {
            $from = $this->quote((string) ($rule['from'] ?? ''));
            $to = $this->quote((string) ($rule['to'] ?? ''));
            $lines[] = "  - from: {$from}";
            $lines[] = "    to: {$to}";
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $rules */
    private function renderHeaders(array $rules): string
    {
        $lines = ['headers:'];
        foreach ($rules as $rule) {
            $for = $this->quote((string) ($rule['for'] ?? ''));
            $lines[] = "  - for: {$for}";
            $values = is_array($rule['values'] ?? null) ? $rule['values'] : [];
            if ($values !== []) {
                $lines[] = '    values:';
                foreach ($values as $headerName => $headerValue) {
                    if (! is_string($headerName) || $headerName === '') {
                        continue;
                    }
                    $lines[] = "      {$headerName}: ".$this->quote((string) $headerValue);
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $rules */
    private function renderCrons(array $rules): string
    {
        $lines = ['crons:'];
        foreach ($rules as $rule) {
            $schedule = $this->quote((string) ($rule['schedule'] ?? ''));
            $lines[] = "  - schedule: {$schedule}";
            $handler = is_string($rule['handler'] ?? null) ? trim((string) $rule['handler']) : '';
            if ($handler !== '') {
                $lines[] = '    handler: '.$this->quote($handler);
            }
        }

        return implode("\n", $lines);
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        // Always double-quote so we don't have to second-guess YAML
        // parsing of strings that happen to look like booleans, numbers,
        // or contain special characters.
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
