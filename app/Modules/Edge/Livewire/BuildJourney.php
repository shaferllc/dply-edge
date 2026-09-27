<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgeDeployment;
use App\Modules\Edge\Actions\CancelStuckEdgeDeployment;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Support\AnsiHtml;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Single self-polling Livewire component that owns the full edge build
 * journey card: status header, the 4-step list, AND the live log output
 * tucked under whichever step it belongs to (clone → cloning row,
 * pnpm/build → installing row).
 *
 * Splits the tailed build log by `[dply:step] <name>` markers emitted by
 * {@see EdgeBuildRunner} so each step shows only the
 * lines that belong to it. Stops polling automatically once the
 * deployment leaves BUILDING / PUBLISHING.
 */
class BuildJourney extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    /** Bytes read from the log per tick. */
    private const CHUNK_BYTES = 48_000;

    #[Locked]
    public string $deploymentId = '';

    /** Create-flow log: one scrolling output plus Cancel / Go to app. */
    public bool $logOnly = false;

    /**
     * The log itself never lives in the snapshot: tail() sends only the new
     * lines to the browser (`edge-build-log` event → Alpine store
     * `buildLogs`, see resources/js/app.js), which appends and trims them.
     * The server keeps just the byte offset and which steps have output.
     */
    public int $offset = 0;

    /** @var list<string> step keys (clone/build/publish) that have output */
    public array $logSteps = [];

    /** Step the next log line belongs to (last `[dply:step]` marker seen). */
    public ?string $logStep = null;

    /** Flips to false once the deployment is no longer in flight. */
    public bool $polling = true;

    /**
     * Per-request memo so wire:poll's tail() + render() share one
     * deployment/site/server load instead of querying twice.
     */
    private ?EdgeDeployment $resolvedDeployment = null;

    private bool $resolvedDeploymentLoaded = false;

    private bool $viewAuthorized = false;

    /**
     * Collected during mount() and rendered into the root's x-init, which
     * *replaces* the browser's copy — so a remount never doubles the log and
     * doesn't depend on event timing.
     *
     * @var array<string, string>|null
     */
    private ?array $seed = null;

    public function mount(string $deploymentId): void
    {
        $this->deploymentId = $deploymentId;
        // tail() loads once, authorizes, and seeds the log; render()
        // reuses the same request-memoized deployment row.
        $this->seed = [];
        $this->tail();
    }

    /**
     * Stop the in-flight build from the create page without queueing another.
     */
    public function confirmCancelBuild(): void
    {
        $deployment = $this->deployment();

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('deploy', $deployment->site);

        if (! in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true)) {
            $this->polling = false;

            return;
        }

        $this->openConfirmActionModal(
            'cancelBuild',
            [],
            __('Cancel this build?'),
            __('This deploy will be marked failed and will not be published.'),
            __('Cancel build'),
            true,
        );
    }

    public function cancelBuild(): void
    {
        $this->forgetResolvedDeployment();
        $deployment = $this->deployment();

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('deploy', $deployment->site);

        try {
            app(CancelStuckEdgeDeployment::class)->abandon($deployment->site, $deployment);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->polling = false;
        $this->toastSuccess(__('Build cancelled.'));
    }

    /**
     * Open the shared confirm-action modal before restarting. Two-step
     * flow lets us show a richer "this might race the existing job"
     * warning than the native `wire:confirm` toast can.
     */
    public function confirmRestartFrozenBuild(): void
    {
        $deployment = $this->deployment();

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('deploy', $deployment->site);

        if (! in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true)) {
            $this->polling = false;

            return;
        }

        $details = array_values(array_filter([
            $deployment->git_branch ? [
                'label' => __('Branch'),
                'value' => (string) $deployment->git_branch,
                'mono' => true,
            ] : null,
            $deployment->git_commit ? [
                'label' => __('Commit'),
                'value' => substr((string) $deployment->git_commit, 0, 12),
                'mono' => true,
            ] : null,
            $deployment->created_at ? [
                'label' => __('Started'),
                'value' => $deployment->created_at->diffForHumans(),
            ] : null,
        ]));

        $this->openConfirmActionModal(
            'restartFrozenBuild',
            [],
            __('Restart this build?'),
            __('The in-flight deployment will be marked failed and a fresh build will be queued at the same commit. If the worker is actually still running, both builds may race — superseded ones will be cleaned up automatically.'),
            __('Restart build'),
            true,
            $details === [] ? null : $details,
        );
    }

    /**
     * Operator escape hatch — mark the in-flight deployment failed and
     * queue a fresh build. The parent component polls the deploys list
     * and will swap in a new BuildJourney card for the new deployment
     * on its next tick, so this card just stops polling and bows out.
     */
    public function restartFrozenBuild(): void
    {
        $this->forgetResolvedDeployment();
        $deployment = $this->deployment();

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('deploy', $deployment->site);

        try {
            app(CancelStuckEdgeDeployment::class)->handle($deployment->site, $deployment);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $org = $deployment->site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.deployment.cancelled', $deployment->site, null, [
                'deployment_id' => $deployment->id,
                'reason' => 'stuck',
            ]);
        }

        $this->polling = false;
        $this->toastSuccess(__('Build cancelled — fresh deploy queued.'));
    }

    public function tail(): void
    {
        // Fresh status/log each poll tick — clear the request memo so we
        // don't reuse a stale deployment row from an earlier call in the
        // same request (mount → authorize → tail).
        $this->forgetResolvedDeployment();
        $deployment = $this->deployment();

        if ($deployment === null) {
            $this->polling = false;

            return;
        }

        $this->authorizeView();

        $isInProgress = in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $read = $deployment->readLocalBuildLogSince($this->offset, self::CHUNK_BYTES);
        $body = $read['body'];

        // Consume whole lines only, so a step marker or ANSI sequence is never
        // split across ticks. A finished build flushes the remainder.
        if ($isInProgress && strlen($body) < self::CHUNK_BYTES) {
            $newline = strrpos($body, "\n");
            $body = $newline === false ? '' : substr($body, 0, $newline + 1);
        }

        if ($body !== '') {
            $this->offset += strlen($body);
            $chunks = array_map(AnsiHtml::toHtml(...), $this->routeLines($body));

            if ($this->seed !== null) {
                foreach ($chunks as $key => $html) {
                    $this->seed[$key] = ($this->seed[$key] ?? '').$html;
                }
            } else {
                $this->dispatch('edge-build-log', id: $this->deploymentId, chunks: $chunks);
            }
        }

        // One last read catches the tail end; keep going while a full chunk
        // came back (more is waiting), then stop so the page can settle.
        if (! $isInProgress && strlen($read['body']) < self::CHUNK_BYTES) {
            $this->polling = false;
        }
    }

    /**
     * Route new lines to the step they belong to, on `[dply:step] <name>`
     * markers. Lines before any marker go to `clone`; the old `deploy` step
     * (container image build) folds into `build`. `_all` is the raw text for
     * the create-flow's single log.
     *
     * @return array<string, string>
     */
    private function routeLines(string $body): array
    {
        $out = $this->logOnly ? ['_all' => $body] : [];

        foreach (explode("\n", rtrim($body, "\n")) as $line) {
            if (preg_match('/^\[dply:step\]\s+([a-z0-9_-]+)\s*\r?$/i', $line, $m) === 1) {
                $step = strtolower($m[1]);
                $this->logStep = $step === 'deploy' ? 'build' : $step;

                continue;
            }

            $key = $this->logStep ?? 'clone';
            $out[$key] = ($out[$key] ?? '').$line."\n";
            if (! in_array($key, $this->logSteps, true)) {
                $this->logSteps[] = $key;
            }
        }

        return $out;
    }

    public function render(): View
    {
        $deployment = $this->deployment();

        if ($deployment === null) {
            return view('livewire.edge.build-journey', [
                'missing' => true,
                'journey' => null,
                'seed' => null,
                'deployment' => null,
                'site' => null,
                'server' => null,
            ]);
        }

        $journey = SiteShowViewData::edgeDeploymentJourney($deployment);
        $sections = array_fill_keys($this->logSteps, true);

        // Status flips to BUILDING before clone finishes. Prefer the latest
        // log step marker so "Installing…" doesn't show Waiting while clone
        // output is still streaming under the Done row.
        if (! $journey['hasFailed'] && ! $journey['isDone']) {
            $journey = $this->alignJourneyToLogStep($journey, $sections);
        }

        return view('livewire.edge.build-journey', [
            'missing' => false,
            'journey' => $journey,
            'seed' => $this->seed,
            'deployment' => $deployment,
            'site' => $deployment->site,
            'server' => $deployment->site?->server,
        ]);
    }

    /**
     * @param  array<string, mixed>  $journey
     * @param  array<string, true>  $sections
     * @return array<string, mixed>
     */
    private function alignJourneyToLogStep(array $journey, array $sections): array
    {
        $logToState = [
            'clone' => 'queued',
            'build' => 'building',
            'publish' => 'publishing',
        ];

        $latestLogStep = null;
        foreach (['publish', 'build', 'clone'] as $key) {
            if (isset($sections[$key])) {
                $latestLogStep = $key;
                break;
            }
        }

        if ($latestLogStep === null) {
            return $journey;
        }

        // Only walk the UI back (e.g. BUILDING status + clone-only log →
        // show cloning as current). Never advance past the DB status.
        $desiredState = $logToState[$latestLogStep];

        $stepKeys = $journey['stepKeys'];
        $dbIndex = array_search($journey['state'], $stepKeys, true);
        $logIndex = array_search($desiredState, $stepKeys, true);
        if ($dbIndex === false || $logIndex === false || $logIndex >= $dbIndex) {
            return $journey;
        }

        $statusSteps = $journey['statusSteps'];
        $currentStepIndex = $logIndex;
        $totalSteps = $journey['totalSteps'];
        $completedSteps = max(1, min($totalSteps, $currentStepIndex + 1));

        return array_merge($journey, [
            'state' => $desiredState,
            'currentStepIndex' => $currentStepIndex,
            'completedSteps' => $completedSteps,
            'progressPercent' => $totalSteps > 0
                ? (int) round(($completedSteps / $totalSteps) * 100)
                : 0,
            'currentLabel' => $statusSteps[$desiredState] ?? $journey['currentLabel'],
        ]);
    }

    /**
     * Load the deployment once per request with site+server eager-loaded
     * so SitePolicy::view does not fire a second servers query.
     */
    private function deployment(): ?EdgeDeployment
    {
        if ($this->resolvedDeploymentLoaded) {
            return $this->resolvedDeployment;
        }

        $this->resolvedDeploymentLoaded = true;
        $this->resolvedDeployment = EdgeDeployment::query()
            ->with('site.server')
            ->find($this->deploymentId);

        return $this->resolvedDeployment;
    }

    private function forgetResolvedDeployment(): void
    {
        $this->resolvedDeployment = null;
        $this->resolvedDeploymentLoaded = false;
    }

    private function authorizeView(): void
    {
        if ($this->viewAuthorized) {
            return;
        }

        $deployment = $this->deployment();
        if ($deployment?->site !== null) {
            Gate::authorize('view', $deployment->site);
        }

        $this->viewAuthorized = true;
    }
}
