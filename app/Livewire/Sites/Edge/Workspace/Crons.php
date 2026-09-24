<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Cron triggers editor (P54 follow-up). Repo-declared crons (from
 * dply.yaml) are read-only in the UI — committing the file is the
 * source of truth. Dashboard rows are stored on edgeMeta and merge
 * additively at deploy time via EdgeEffectiveCrons; the worker
 * uploaders read the merged list when pushing to Cloudflare cron
 * triggers.
 */
class Crons extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    /** @var list<array{schedule: string, handler: string}> */
    public array $dashboard_crons = [];

    public string $new_schedule = '';

    public string $new_handler = '';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshFromMeta();
    }

    private function refreshFromMeta(): void
    {
        $overrides = is_array($this->site->edgeMeta()['crons_overrides'] ?? null) ? $this->site->edgeMeta()['crons_overrides'] : [];
        $this->dashboard_crons = array_values(array_map(
            static fn ($e): array => [
                'schedule' => (string) $e['schedule'],
                'handler' => (string) ($e['handler'] ?? ''),
            ],
            array_filter($overrides, static fn ($e): bool => is_array($e) && is_string($e['schedule'] ?? null) && $e['schedule'] !== ''),
        ));
    }

    public function addCron(): void
    {
        $this->authorize('update', $this->site);

        $schedule = trim($this->new_schedule);
        $handler = trim($this->new_handler);

        if ($schedule === '') {
            $this->addError('new_schedule', __('Schedule is required.'));

            return;
        }
        if (! $this->looksLikeCron($schedule)) {
            $this->addError('new_schedule', __('Schedule must be a 5-field cron expression (e.g. */5 * * * *).'));

            return;
        }

        $this->dashboard_crons[] = ['schedule' => $schedule, 'handler' => $handler];
        $this->persist();
        $this->new_schedule = '';
        $this->new_handler = '';
        $this->toastSuccess(__('Schedule added — applied on the next deploy.'));
    }

    public function removeCron(int $index): void
    {
        $this->authorize('update', $this->site);

        if (! isset($this->dashboard_crons[$index])) {
            return;
        }
        array_splice($this->dashboard_crons, $index, 1);
        $this->persist();
        $this->toastSuccess(__('Cron removed.'));
    }

    private function persist(): void
    {
        $previous = is_array($this->site->edgeMeta()['crons_overrides'] ?? null) ? $this->site->edgeMeta()['crons_overrides'] : [];

        $this->site->mergeEdgeMeta([
            'crons_overrides' => array_map(
                static fn (array $e): array => [
                    'schedule' => $e['schedule'],
                    'handler' => $e['handler'] !== '' ? $e['handler'] : null,
                ],
                $this->dashboard_crons,
            ),
        ]);
        $this->site->save();

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.crons.updated',
            $this->site,
            ['crons_overrides' => $previous],
            ['crons_overrides' => $this->dashboard_crons],
        );
    }

    private function looksLikeCron(string $expr): bool
    {
        $fields = preg_split('/\s+/', trim($expr));

        return is_array($fields) && count($fields) === 5;
    }

    public function render(): View
    {
        $latestLive = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $effective = EdgeEffectiveCrons::for($this->site, $latestLive);
        $repoCrons = array_values(array_filter($effective, static fn (array $e): bool => $e['source'] === 'repo'));

        return view('livewire.sites.edge.workspace.crons', array_merge(
            EdgeSiteViewData::context($this->site, 'crons'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoCrons' => $repoCrons,
                'sourcePath' => is_array($latestLive?->repo_config) && is_string($latestLive->repo_config['source_path'] ?? null)
                    ? $latestLive->repo_config['source_path']
                    : 'dply.yaml',
            ],
        ));
    }
}
