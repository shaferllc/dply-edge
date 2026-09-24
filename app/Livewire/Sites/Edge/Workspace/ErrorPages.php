<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Custom error pages + maintenance toggle (P52). HTML bodies persist on
 * the site's edgeMeta and are picked up by EdgeHostMapPublisher on the
 * next deploy. The Maintenance toggle additionally re-publishes the host
 * map immediately so taking a site offline doesn't require a redeploy.
 */
class ErrorPages extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    #[Validate('nullable|string|max:200000')]
    public string $error_404_html = '';

    #[Validate('nullable|string|max:200000')]
    public string $error_500_html = '';

    #[Validate('nullable|string|max:200000')]
    public string $maintenance_html = '';

    public bool $maintenance_enabled = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $meta = $site->edgeMeta();
        $errorPages = is_array($meta['error_pages'] ?? null) ? $meta['error_pages'] : [];
        $maintenance = is_array($meta['maintenance'] ?? null) ? $meta['maintenance'] : [];

        $this->error_404_html = (string) ($errorPages['html_404'] ?? '');
        $this->error_500_html = (string) ($errorPages['html_500'] ?? '');
        $this->maintenance_html = (string) ($maintenance['html'] ?? '');
        $this->maintenance_enabled = (bool) ($maintenance['enabled'] ?? false);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $this->validate();

        $previousMeta = $this->site->edgeMeta();
        $previousMaintenanceOn = (bool) (is_array($previousMeta['maintenance'] ?? null) ? ($previousMeta['maintenance']['enabled'] ?? false) : false);

        $this->site->mergeEdgeMeta([
            'error_pages' => [
                'html_404' => trim($this->error_404_html),
                'html_500' => trim($this->error_500_html),
            ],
            'maintenance' => [
                'enabled' => $this->maintenance_enabled,
                'html' => trim($this->maintenance_html),
            ],
        ]);
        $this->site->save();

        // Maintenance toggle must take effect without a redeploy — push
        // a fresh host-map entry so the Worker picks up the new flag on
        // the next request. Error-page HTML can wait for the next
        // publish since users don't expect instant rollout there.
        if ($previousMaintenanceOn !== $this->maintenance_enabled) {
            try {
                $live = EdgeDeployment::query()
                    ->where('site_id', $this->site->id)
                    ->where('status', EdgeDeployment::STATUS_LIVE)
                    ->latest('id')
                    ->first();
                if ($live !== null) {
                    app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $live);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            audit_log(
                $this->site->organization,
                auth()->user(),
                'site.edge.maintenance.toggled',
                $this->site,
                ['enabled' => $previousMaintenanceOn],
                ['enabled' => $this->maintenance_enabled],
            );
        }

        $this->toastSuccess(__('Saved.'));
    }

    public function applyTemplate(string $kind, string $key): void
    {
        $this->authorize('update', $this->site);

        $tpl = self::templates()[$key] ?? null;
        if ($tpl === null) {
            return;
        }
        $html = (string) ($tpl[$kind] ?? '');
        if ($html === '') {
            return;
        }

        match ($kind) {
            'html_404' => $this->error_404_html = $html,
            'html_500' => $this->error_500_html = $html,
            'maintenance' => $this->maintenance_html = $html,
            default => null,
        };
        $this->toastSuccess(__('Template applied — review and Save.'));
    }

    public function applyAllTemplates(string $key): void
    {
        $this->authorize('update', $this->site);

        $tpl = self::templates()[$key] ?? null;
        if ($tpl === null) {
            return;
        }

        $this->error_404_html = (string) $tpl['html_404'];
        $this->error_500_html = (string) $tpl['html_500'];
        $this->maintenance_html = (string) $tpl['maintenance'];
        $this->toastSuccess(__('Starter applied to 404, 500, and maintenance — review and Save.'));
    }

    public function render(): View
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repoErrors = [];
        $repoMaint = [];
        $sourcePath = 'dply.yaml';
        if ($latest !== null && is_array($latest->repo_config)) {
            $repoErrors = is_array($latest->repo_config['error_pages'] ?? null) ? $latest->repo_config['error_pages'] : [];
            $repoMaint = is_array($latest->repo_config['maintenance'] ?? null) ? $latest->repo_config['maintenance'] : [];
            $sourcePath = is_string($latest->repo_config['source_path'] ?? null)
                ? (string) $latest->repo_config['source_path']
                : 'dply.yaml';
        }

        return view('livewire.sites.edge.workspace.error-pages', array_merge(
            EdgeSiteViewData::context($this->site, 'error-pages'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoErrors' => $repoErrors,
                'repoMaint' => $repoMaint,
                'sourcePath' => $sourcePath,
                'templates' => self::templates(),
            ],
        ));
    }

    /**
     * Bundled starter HTML — same minimal-Tailwind style across all
     * three (404 / 500 / maintenance) so the user gets a consistent
     * brand baseline with one click. Inline CSS = no external assets.
     *
     * @return array<string, array{label: string, hint: string, html_404: string, html_500: string, maintenance: string}>
     */
    private static function templates(): array
    {
        $base = static function (string $title, string $heading, string $body): string {
            return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5ef; color: #1a1a1a; }
  @media (prefers-color-scheme: dark) { body { background: #111; color: #f6f5ef; } }
  main { max-width: 32rem; padding: 2rem; text-align: center; }
  h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
  p { margin: .25rem 0; color: #4b5563; }
  @media (prefers-color-scheme: dark) { p { color: #cbd5e1; } }
  a { color: inherit; }
</style>
</head>
<body>
<main>
  <h1>{$heading}</h1>
  <p>{$body}</p>
</main>
</body>
</html>
HTML;
        };

        return [
            'minimal' => [
                'label' => __('Minimal'),
                'hint' => __('Clean, brand-neutral. System font, light/dark aware. Inline CSS so it ships in one round-trip.'),
                'html_404' => $base('404 — Not found', 'Page not found', 'The link you followed may be broken, or the page may have been removed.'),
                'html_500' => $base('500 — Something went wrong', 'Something went wrong', 'We logged the error and our team is on it. Please try again in a moment.'),
                'maintenance' => $base('503 — Under maintenance', "We'll be right back.", 'This site is temporarily offline for maintenance. Please check back shortly.'),
            ],
            'friendly' => [
                'label' => __('Friendly'),
                'hint' => __('A bit more personality — emoji + softer copy. Same minimal style.'),
                'html_404' => $base('404', '🧭 Lost in space', "We couldn't find that page. The link might have moved — try the homepage."),
                'html_500' => $base('500', '🔧 We hit a snag', "Something on our end isn't cooperating. Give it another shot in a minute."),
                'maintenance' => $base('Maintenance', '🚧 Just a moment', "We're making things better. Back online very shortly."),
            ],
            'enterprise' => [
                'label' => __('Enterprise'),
                'hint' => __('Sharper, technical. Suitable for B2B / dashboards.'),
                'html_404' => $base('Error 404', 'Resource not found', 'The requested URL was not found on this server. If you typed it manually, please verify the spelling.'),
                'html_500' => $base('Error 500', 'Internal server error', 'An unexpected error occurred while processing your request. Our team has been notified.'),
                'maintenance' => $base('Scheduled maintenance', 'Service temporarily unavailable', 'We are performing scheduled maintenance. Expected return: shortly.'),
            ],
        ];
    }
}
