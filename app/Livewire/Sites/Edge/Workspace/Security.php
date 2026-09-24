<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeAccessLog;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Per-app security summary: hostname and TLS, whether the Edge controls
 * are on, and recent blocked requests from this app's access log.
 */
class Security extends Component
{
    use ManagesEdgeRedeploy;
    use MountsEdgeWorkspaceSection;

    public bool $confirmRemoveCertificate = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.security', array_merge(
            EdgeSiteViewData::context($this->site, 'security'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'security' => $this->summary(),
                'outboundCertificate' => $this->outboundCertificateId(),
            ],
        ));
    }

    public function enableOutboundCertificate(): void
    {
        $this->authorize('update', $this->site);
        if ($this->outboundCertificateId() === null || $this->outboundCertificateId() !== '') {
            return;
        }

        try {
            $id = EdgeContainerConnections::issueClientCertificate($this->site);
        } catch (\Throwable $e) {
            $message = $e->getMessage() === 'Authentication error'
                ? __('The platform account cannot create certificates yet.')
                : $e->getMessage();
            $this->addError('outboundCertificate', $message);

            return;
        }

        $this->site->mergeEdgeMeta(['client_certificate' => ['id' => $id]]);
        $this->site->save();
        $this->toastSuccess(__('Certificate created. Deploy the app to present it.'));
    }

    public function askRemoveOutboundCertificate(): void
    {
        $this->authorize('update', $this->site);
        $this->confirmRemoveCertificate = true;
    }

    public function removeOutboundCertificate(): void
    {
        $this->authorize('update', $this->site);
        $id = $this->outboundCertificateId() ?? '';
        if ($id !== '') {
            try {
                EdgeCloudflareClient::fromConfig()->deleteMtlsCertificate($id);
            } catch (\Throwable $e) {
                $this->addError('outboundCertificate', $e->getMessage());

                return;
            }
        }

        $this->site->mergeEdgeMeta(['client_certificate' => null]);
        $this->site->save();
        $this->confirmRemoveCertificate = false;
        $this->dispatch('close-modal', 'security-remove-certificate');
        $this->toastSuccess(__('Certificate removed. Deploy the app to stop presenting it.'));
    }

    private function outboundCertificateId(): ?string
    {
        if ((string) ($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            return null;
        }

        return EdgeContainerConnections::clientCertificateId($this->site);
    }

    /**
     * @return array{
     *     hostname: string,
     *     liveUrl: ?string,
     *     tlsLabel: string,
     *     tlsTone: string,
     *     domains: list<array{hostname: string, tlsLabel: string, tlsTone: string}>,
     *     controls: list<array{label: string, detail: string, href: string, on: bool}>,
     *     blocked: int,
     *     limited: int,
     *     recent: list<array{when: string, status: int, method: string, path: string, country: string}>
     * }
     */
    private function summary(): array
    {
        $meta = $this->site->edgeMeta();
        $since = now()->subDays(7);

        return [
            'hostname' => $this->site->edgeHostname(),
            'liveUrl' => $this->site->edgeLiveUrl(),
            'tlsLabel' => $this->site->edgeLiveUrl() !== null ? __('HTTPS') : __('Pending'),
            'tlsTone' => $this->site->edgeLiveUrl() !== null ? 'ok' : 'warn',
            'domains' => $this->domains($meta),
            'controls' => $this->controls($meta),
            'blocked' => $this->countStatus(403, $since),
            'limited' => $this->countStatus(429, $since),
            'recent' => $this->recentBlocks($since),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{hostname: string, tlsLabel: string, tlsTone: string}>
     */
    private function domains(array $meta): array
    {
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $custom = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $rows = [];

        foreach ($custom as $key => $domain) {
            $hostname = is_string($key) && $key !== ''
                ? strtolower(trim($key))
                : strtolower(trim((string) (is_array($domain) ? ($domain['hostname'] ?? '') : '')));
            if ($hostname === '') {
                continue;
            }

            $ssl = is_array($domain) ? (string) ($domain['ssl_status'] ?? '') : '';
            [$label, $tone] = match ($ssl) {
                'active' => [__('TLS active'), 'ok'],
                'failed' => [__('TLS failed'), 'bad'],
                'pending' => [__('Issuing certificate'), 'warn'],
                default => [__('TLS pending'), 'muted'],
            };

            $rows[] = ['hostname' => $hostname, 'tlsLabel' => $label, 'tlsTone' => $tone];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{label: string, detail: string, href: string, on: bool}>
     */
    private function controls(array $meta): array
    {
        $firewall = is_array($meta['firewall'] ?? null) ? $meta['firewall'] : [];
        $mode = strtolower((string) ($firewall['country_mode'] ?? 'off'));
        $countries = is_array($firewall['countries'] ?? null) ? count($firewall['countries']) : 0;
        $firewallOn = in_array($mode, ['allow', 'block'], true);

        $turnstile = is_array($meta['turnstile'] ?? null) ? $meta['turnstile'] : [];
        $botOn = (bool) ($turnstile['enabled'] ?? false)
            && trim((string) ($turnstile['site_key'] ?? '')) !== ''
            && trim((string) ($turnstile['secret_key'] ?? '')) !== '';
        $botMode = (string) ($turnstile['mode'] ?? 'forms');

        $rate = is_array($meta['rate_limit'] ?? null) ? $meta['rate_limit'] : [];
        $rules = is_array($rate['rules'] ?? null) ? count($rate['rules']) : 0;
        $rateOn = (bool) ($rate['enabled'] ?? false);

        return [
            [
                'label' => __('Firewall'),
                'detail' => match (true) {
                    $mode === 'allow' => trans_choice(':count country allowed|:count countries allowed', $countries, ['count' => $countries]),
                    $mode === 'block' => trans_choice(':count country blocked|:count countries blocked', $countries, ['count' => $countries]),
                    default => __('Not checking countries'),
                },
                'href' => $this->sectionUrl('firewall'),
                'on' => $firewallOn,
            ],
            [
                'label' => __('Bot protection'),
                'detail' => $botOn
                    ? ($botMode === 'all' ? __('Every HTML page') : __('Forms only'))
                    : __('No challenge'),
                'href' => $this->sectionUrl('bot-protection'),
                'on' => $botOn,
            ],
            [
                'label' => __('Rate limits'),
                'detail' => $rateOn
                    ? trans_choice(':count rule|:count rules', $rules, ['count' => $rules])
                    : __('Not capping requests'),
                'href' => $this->sectionUrl('rate-limits'),
                'on' => $rateOn,
            ],
        ];
    }

    private function sectionUrl(string $section): string
    {
        return route('sites.show', [
            'server' => $this->server,
            'site' => $this->site,
            'section' => $section,
        ]);
    }

    private function countStatus(int $status, Carbon $since): int
    {
        return EdgeAccessLog::query()
            ->where('site_id', $this->site->id)
            ->where('status_code', $status)
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    /**
     * @return list<array{when: string, status: int, method: string, path: string, country: string}>
     */
    private function recentBlocks(Carbon $since): array
    {
        return EdgeAccessLog::query()
            ->where('site_id', $this->site->id)
            ->whereIn('status_code', [403, 429])
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get()
            ->map(fn (EdgeAccessLog $row): array => [
                'when' => $row->occurred_at?->diffForHumans() ?? '',
                'status' => (int) $row->status_code,
                'method' => $row->method,
                'path' => $row->path,
                'country' => trim((string) $row->country),
            ])
            ->all();
    }
}
