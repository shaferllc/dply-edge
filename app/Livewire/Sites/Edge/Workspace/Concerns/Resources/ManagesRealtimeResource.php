<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeRealtimeApp;
use App\Models\EdgeRealtimeUsage;
use App\Modules\Billing\Services\EdgeRealtimeCost;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;

/**
 * Resources: Realtime (Reverb-compatible WebSockets, docs/edge-realtime.md).
 * Mixed into {@see Resources}. This part creates the app from the Add a
 * resource builder; the open connection is $this->openResourceConnection().
 */
trait ManagesRealtimeResource
{
    /** Add a resource → Realtime: optional display name, size, and origins. */
    public string $realtimeName = '';

    public int $realtimeMaxConnections = 200;

    /** One Origin per line (or comma separated). Empty allows any. */
    public string $realtimeAllowedOrigins = '';

    /** The sheet: relay counters, read on opening it (never during render). Null until loaded. */
    public ?array $realtimeStats = null;

    public ?string $realtimeError = null;

    /** The host realtimeLoad() last loaded, so opening another app starts clean. */
    public string $realtimeLoadedHost = '';

    /** Settings tab drafts, filled from the row by realtimeLoad(). */
    public int $realtimeEditMax = 200;

    public string $realtimeEditOrigins = '';

    public bool $realtimeEditClientEvents = false;

    /** Opening the sheet: fill the settings drafts, then read the relay's counters. */
    public function realtimeLoad(): void
    {
        $this->authorize('view', $this->site);
        $app = $this->realtimeApp();
        if ($app === null) {
            $this->realtimeError = __('This Realtime app is no longer attached.');

            return;
        }
        if ($this->realtimeLoadedHost !== $this->resourceHost) {
            $this->realtimeLoadedHost = $this->resourceHost;
            $this->realtimeEditMax = (int) $app->max_connections;
            $this->realtimeEditOrigins = implode("\n", array_values($app->allowed_origins ?? []));
            $this->realtimeEditClientEvents = (bool) $app->client_events;
        }
        $this->realtimeStats = null;
        $this->realtimeError = null;

        try {
            $this->realtimeStats = app(EdgeRealtimeApps::class)->stats($app);
        } catch (\Throwable) {
            $this->realtimeError = __('The realtime relay isn’t reachable yet, so there are no live numbers. Try again in a moment.');
        }
    }

    /** Settings live in the relay's KV record, so saving applies at once with no redeploy. */
    public function realtimeSaveSettings(): void
    {
        $this->authorize('update', $this->site);
        $app = $this->realtimeApp();
        if ($app === null) {
            return;
        }
        if (! in_array($this->realtimeEditMax, $this->realtimeSizes(), true)) {
            $this->addError('realtimeSettings', __('Pick one of the offered sizes.'));

            return;
        }
        $origins = $this->realtimeParseOrigins($this->realtimeEditOrigins, 'realtimeSettings');
        if ($origins === null) {
            return;
        }

        $app->forceFill([
            'max_connections' => $this->realtimeEditMax,
            'allowed_origins' => $origins,
            'client_events' => $this->realtimeEditClientEvents,
        ])->save();
        try {
            app(EdgeRealtimeApps::class)->sync($app);
        } catch (\Throwable $e) {
            $this->addError('realtimeSettings', __('Saved, but the relay could not be updated: :message', ['message' => $e->getMessage()]));

            return;
        }
        $this->realtimeEditOrigins = implode("\n", $origins);
        $this->toastSuccess(__('Saved. The relay uses these settings now; no redeploy needed.'));
    }

    /**
     * New signing secret. Browsers keep working (the key is unchanged), but
     * the running app signs with the old secret until it is redeployed.
     */
    public function realtimeRotateSecret(): void
    {
        $this->authorize('update', $this->site);
        $app = $this->realtimeApp();
        if ($app === null) {
            return;
        }
        try {
            app(EdgeRealtimeApps::class)->rotateSecret($app);
        } catch (\Throwable $e) {
            $this->addError('realtimeSecret', $e->getMessage());

            return;
        }
        $this->site->mergeEdgeMeta(['settings_saved_at' => now()->toIso8601String()]);
        $this->site->save();
        $this->toastSuccess(__('New secret saved. Redeploy so the app signs with it; until then its broadcasts are refused.'));
    }

    /**
     * Try it: publish dply.test to the browser's throwaway channel, signed
     * here so the secret never reaches the page. The nonce comes back in the
     * frame so the browser can time the round trip on its own clock.
     *
     * @return array{ok: bool, error: ?string}
     */
    #[Renderless]
    public function realtimeSendTest(string $channel, string $nonce): array
    {
        $this->authorize('update', $this->site);
        if (preg_match('/^dply-test\.[a-z0-9]{8,32}$/', $channel) !== 1 || preg_match('/^[a-z0-9]{1,32}$/', $nonce) !== 1) {
            return ['ok' => false, 'error' => __('That is not a test channel.')];
        }
        $app = $this->realtimeApp();
        if ($app === null) {
            return ['ok' => false, 'error' => __('This Realtime app is no longer attached.')];
        }
        if (! RateLimiter::attempt('edge-realtime-test:'.$this->site->id, 20, static fn (): bool => true, 60)) {
            return ['ok' => false, 'error' => __('Too many tries. Wait a minute and send again.')];
        }

        try {
            app(EdgeRealtimeApps::class)->publish($app, $channel, 'dply.test', ['nonce' => $nonce, 'sent_at' => now()->toIso8601String()]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => __('The relay did not take the event. It may not be deployed yet, or this app is asleep.')];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Sizes this organization's plan allows.
     *
     * @return list<int>
     */
    public function realtimeSizes(): array
    {
        $cap = $this->site->organization === null ? 0 : EdgeRealtimeApps::maxConnectionsFor($this->site->organization);

        return array_values(array_filter(EdgeRealtimeApps::MAX_CONNECTION_SIZES, static fn (int $size): bool => $size <= $cap));
    }

    /**
     * This month's collected usage for one app, priced as if it had the
     * organization's allowance to itself (the bill applies it once per org).
     *
     * @return array{messages: int, cents: int, org_minutes: int, org_messages: int}
     */
    public function realtimeMonth(string $appId): array
    {
        $month = static fn ($q) => $q->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(connection_seconds), 0) AS seconds, COALESCE(SUM(messages), 0) AS messages')
            ->first();
        $org = $this->site->organization;
        $app = $month(EdgeRealtimeUsage::query()->where('organization_id', $org->id)->where('realtime_app_id', $appId));
        $all = $month(EdgeRealtimeUsage::query()->where('organization_id', $org->id));

        return [
            'messages' => (int) ($app->messages ?? 0),
            'cents' => app(EdgeRealtimeCost::class)->cents((int) ($app->seconds ?? 0), (int) ($app->messages ?? 0)),
            'org_minutes' => intdiv((int) ($all->seconds ?? 0), 60),
            'org_messages' => (int) ($all->messages ?? 0),
        ];
    }

    /**
     * Origins from a textarea: one per line or comma separated, scheme and
     * host only. Null (with an error on $errorKey) when one is not an origin.
     *
     * @return list<string>|null
     */
    private function realtimeParseOrigins(string $text, string $errorKey): ?array
    {
        $origins = [];
        foreach (preg_split('/[\s,]+/', $text) ?: [] as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') {
                continue;
            }
            if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin) !== 1) {
                $this->addError($errorKey, __(':origin is not an origin. Use the scheme and host, like https://example.com.', ['origin' => $origin]));

                return null;
            }
            $origins[] = strtolower($origin);
        }

        return array_values(array_unique($origins));
    }

    /** The Realtime app behind the open connection, when this organization owns it. */
    protected function realtimeApp(): ?EdgeRealtimeApp
    {
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'realtime' || $connection['target'] === '') {
            return null;
        }

        return EdgeRealtimeApp::query()
            ->whereKey($connection['target'])
            ->where('organization_id', $this->site->organization_id)
            ->first();
    }

    /** Called from saveConnection() for kind realtime: one per app, card required. */
    protected function saveRealtimeConnection(): void
    {
        if (collect(EdgeContainerConnections::for($this->site))->contains('kind', 'realtime')) {
            $this->addError('connection', __('This app already has Realtime.'));

            return;
        }
        if (! $this->cardOnFile()) {
            $this->addError('connection', __('Add a card before starting Realtime. Connections and messages are billed to that card.'));

            return;
        }
        if (! in_array($this->realtimeMaxConnections, $this->realtimeSizes(), true)) {
            $cap = EdgeRealtimeApps::maxConnectionsFor($this->site->organization);
            $this->addError('connection', match (true) {
                ! in_array($this->realtimeMaxConnections, EdgeRealtimeApps::MAX_CONNECTION_SIZES, true) => __('Pick one of the offered sizes.'),
                $cap === 0 => __('Your plan does not include Realtime. Choose a plan to add it.'),
                default => __('Your plan allows up to :cap connections per Realtime app. Pick a smaller size or upgrade.', ['cap' => number_format($cap)]),
            });

            return;
        }
        $origins = $this->realtimeParseOrigins($this->realtimeAllowedOrigins, 'connection');
        if ($origins === null) {
            return;
        }

        $service = app(EdgeRealtimeApps::class);
        try {
            $app = $service->provision($this->site, mb_substr(trim($this->realtimeName), 0, 100), [
                'max_connections' => $this->realtimeMaxConnections,
                'allowed_origins' => $origins,
            ]);
        } catch (\Throwable $e) {
            $this->addError('connection', $e->getMessage());

            return;
        }

        $this->storeConnection('REALTIME', EdgeContainerConnections::resourceHost($this->site, 'realtime'), $app->id);
        if ($this->getErrorBag()->has('connection')) {
            try {
                $service->destroy($app);
            } catch (\Throwable) {
                $app->delete();
            }

            return;
        }
        $this->reset('realtimeName', 'realtimeMaxConnections', 'realtimeAllowedOrigins');
    }
}
