<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeImageUrlSigner;
use App\Modules\Edge\Support\EdgeEffectiveImages;
use App\Modules\Edge\Support\EdgeEffectiveOrigin;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Delivery extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    /** @var array{ok: bool, status?: int, latency_ms?: int, error?: string, sample_path?: string, target?: string, has_auth_header?: bool}|null */
    public ?array $originProbe = null;

    /** @var array{ok: bool, status?: int, latency_ms?: int, target?: string, error?: string, signed_url?: string, hint?: string}|null */
    public ?array $imageProbe = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->mountEdgeBuildSettings($site);
    }

    public function requestConvertToHybrid(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'buildForm.edge_convert_origin_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ], [
            'buildForm.edge_convert_origin_url.url' => __('Origin URL must be a valid http(s) URL.'),
        ]);

        $this->openConfirmActionModal(
            'convertEdgeStaticToHybrid',
            [],
            __('Convert to hybrid'),
            __('Convert to hybrid mode? The next deploy will healthcheck the origin before going live.'),
            __('Convert'),
            false,
        );
    }

    public function testOrigin(): void
    {
        $this->authorize('update', $this->site);

        $latest = $this->latestConfiguredDeployment();
        $effOrigin = EdgeEffectiveOrigin::for($this->site, $latest);
        $url = $effOrigin['url'];
        if (! is_string($url) || $url === '') {
            $this->originProbe = ['ok' => false, 'error' => __('No origin URL configured. Set one in dply.yaml or via the SSR origin form first.')];

            return;
        }

        $samplePath = $effOrigin['routes'][0] ?? '/';
        $samplePath = '/'.ltrim(str_replace('*', '', $samplePath), '/');
        $target = rtrim($url, '/').$samplePath;

        $headers = ['User-Agent' => 'dply-edge-origin-probe/1.0'];
        if (is_string($effOrigin['auth_secret']) && $effOrigin['auth_secret'] !== '') {
            $headers['X-Dply-Origin-Auth'] = $effOrigin['auth_secret'];
        }
        // Send the Access token too, or the probe reports a failure the Worker
        // would not actually hit against an Access-protected origin.
        if (is_string($effOrigin['access_client_id']) && $effOrigin['access_client_id'] !== ''
            && is_string($effOrigin['access_client_secret']) && $effOrigin['access_client_secret'] !== '') {
            $headers['CF-Access-Client-Id'] = $effOrigin['access_client_id'];
            $headers['CF-Access-Client-Secret'] = $effOrigin['access_client_secret'];
        }

        try {
            $safe = PublicOutboundUrl::parse($target);
        } catch (UnsafeOutboundUrlException) {
            $this->originProbe = [
                'ok' => false,
                'error' => __('Origin URL is not allowed.'),
                'sample_path' => $samplePath,
                'target' => $target,
                'has_auth_header' => isset($headers['X-Dply-Origin-Auth']),
            ];

            return;
        }

        $startedAt = microtime(true);
        try {
            $response = Http::withHeaders($headers)
                ->timeout(8)
                ->withOptions($safe->httpClientOptions())
                ->get($safe->url);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->originProbe = [
                'ok' => $response->status() < 500,
                'status' => $response->status(),
                'latency_ms' => $latencyMs,
                'sample_path' => $samplePath,
                'target' => $target,
                'has_auth_header' => isset($headers['X-Dply-Origin-Auth']),
            ];
        } catch (\Throwable $e) {
            $this->originProbe = [
                'ok' => false,
                'error' => $e->getMessage(),
                'sample_path' => $samplePath,
                'target' => $target,
                'has_auth_header' => isset($headers['X-Dply-Origin-Auth']),
            ];
        }
    }

    public function testImage(): void
    {
        $this->authorize('update', $this->site);

        $latest = $this->latestConfiguredDeployment();
        $effImages = EdgeEffectiveImages::for($this->site, $latest);

        if (! $effImages['enabled']) {
            $this->imageProbe = [
                'ok' => false,
                'error' => __('Image optimization is not enabled — set a signing secret in the dashboard form below first.'),
            ];

            return;
        }

        // Pick the first allowed host (so the test passes the safelist
        // check). Fall back to a small public test image when nothing
        // is on the safelist yet.
        $sampleHost = $effImages['allowed_hosts'][0] ?? 'images.unsplash.com';
        $samplePath = $sampleHost === 'images.unsplash.com'
            ? '/photo-1503023345310-bd7c1de61c7d?w=320'
            : '/dply-test.jpg';
        $sourceUrl = 'https://'.$sampleHost.$samplePath;

        try {
            $signedUrl = app(EdgeImageUrlSigner::class)->urlFor($this->site, $sourceUrl, width: 320, quality: 75);
        } catch (\Throwable $e) {
            $this->imageProbe = ['ok' => false, 'error' => $e->getMessage()];

            return;
        }

        $startedAt = microtime(true);
        try {
            $response = Http::timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get($signedUrl);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->imageProbe = [
                'ok' => $response->status() < 400,
                'status' => $response->status(),
                'latency_ms' => $latencyMs,
                'target' => $signedUrl,
                'hint' => $effImages['allowed_hosts'] === []
                    ? __('No allowed_hosts declared yet — used images.unsplash.com as a sample. Add the real host(s) below or in dply.yaml.')
                    : null,
            ];
        } catch (\Throwable $e) {
            $this->imageProbe = [
                'ok' => false,
                'target' => $signedUrl,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function render(): View
    {
        $latest = $this->latestConfiguredDeployment();

        $repoOrigin = [];
        $repoImages = [];
        $sourcePath = 'dply.yaml';
        if ($latest !== null && is_array($latest->repo_config)) {
            $repoOrigin = is_array($latest->repo_config['origin'] ?? null) ? $latest->repo_config['origin'] : [];
            $repoImages = is_array($latest->repo_config['images'] ?? null) ? $latest->repo_config['images'] : [];
            $sourcePath = is_string($latest->repo_config['source_path'] ?? null)
                ? (string) $latest->repo_config['source_path']
                : 'dply.yaml';
        }

        return view('livewire.sites.edge.workspace.delivery', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-delivery'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoOrigin' => $repoOrigin,
                'repoImages' => $repoImages,
                'sourcePath' => $sourcePath,
                'originProbe' => $this->originProbe,
                'imageProbe' => $this->imageProbe,
                'effectiveImages' => EdgeEffectiveImages::for($this->site, $latest),
                // Whether one is stored, never the value itself — the secret
                // field is write-only and must not round-trip to the browser.
                'edgeOriginHasAccessSecret' => $this->site->edgeOriginSecret('access_client_secret') !== null,
            ],
        ));
    }

    private function latestConfiguredDeployment(): ?EdgeDeployment
    {
        return EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }
}
