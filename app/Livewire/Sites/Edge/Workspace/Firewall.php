<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\CountryList;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Geo-firewall surface (P55). Tag-style country picker backed by an
 * authoritative ISO 3166-1 alpha-2 list. The picker is Alpine-driven
 * so type-to-filter is instant; only add / remove actions hit the
 * server. Selected codes ship in the host map payload so the Worker
 * can reject blocked traffic before any downstream processing.
 */
class Firewall extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    #[Validate('required|in:off,allow,block')]
    public string $country_mode = 'off';

    /** @var list<string> ISO 3166-1 alpha-2, uppercase. */
    public array $selected_codes = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $firewall = is_array($site->edgeMeta()['firewall'] ?? null) ? $site->edgeMeta()['firewall'] : [];
        $mode = strtolower((string) ($firewall['country_mode'] ?? 'off'));
        $this->country_mode = in_array($mode, ['off', 'allow', 'block'], true) ? $mode : 'off';

        $countries = is_array($firewall['countries'] ?? null) ? $firewall['countries'] : [];
        $this->selected_codes = $this->sanitize($countries);
    }

    public function addCountry(string $code): void
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z]{2}$/', $code) || CountryList::name($code) === null) {
            return;
        }
        if (in_array($code, $this->selected_codes, true)) {
            return;
        }
        $this->selected_codes[] = $code;
        sort($this->selected_codes);
    }

    public function removeCountry(string $code): void
    {
        $code = strtoupper(trim($code));
        $this->selected_codes = array_values(array_filter(
            $this->selected_codes,
            fn (string $existing): bool => $existing !== $code,
        ));
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $this->validate();

        $codes = $this->sanitize($this->selected_codes);
        $this->selected_codes = $codes;

        $previousFirewall = is_array($this->site->edgeMeta()['firewall'] ?? null) ? $this->site->edgeMeta()['firewall'] : [];

        $this->site->mergeEdgeMeta([
            'firewall' => [
                'country_mode' => $this->country_mode,
                'countries' => $codes,
            ],
        ]);
        $this->site->save();

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
            'site.edge.firewall.updated',
            $this->site,
            ['firewall' => $previousFirewall],
            ['firewall' => ['country_mode' => $this->country_mode, 'countries' => $codes]],
        );

        $this->toastSuccess(__('Firewall updated.'));
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

        $repoFirewall = [];
        $sourcePath = 'dply.yaml';
        if ($latestLive !== null && is_array($latestLive->repo_config)) {
            $repoFirewall = is_array($latestLive->repo_config['firewall'] ?? null) ? $latestLive->repo_config['firewall'] : [];
            $sourcePath = is_string($latestLive->repo_config['source_path'] ?? null)
                ? (string) $latestLive->repo_config['source_path']
                : 'dply.yaml';
        }

        return view('livewire.sites.edge.workspace.firewall', array_merge(
            EdgeSiteViewData::context($this->site, 'firewall'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'allCountries' => CountryList::all(),
                'repoFirewall' => $repoFirewall,
                'sourcePath' => $sourcePath,
            ],
        ));
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<string>
     */
    private function sanitize(array $codes): array
    {
        $valid = CountryList::all();
        $cleaned = [];
        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }
            $upper = strtoupper(trim($code));
            if ($upper === '' || ! isset($valid[$upper])) {
                continue;
            }
            $cleaned[$upper] = true;
        }
        $codes = array_keys($cleaned);
        sort($codes);

        return $codes;
    }
}
