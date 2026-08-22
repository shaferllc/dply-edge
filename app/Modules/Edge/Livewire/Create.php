<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Jobs\DetectRepositoryRuntimeJob;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Modules\Edge\Livewire\Concerns\ManagesEdgeDeploy;
use App\Modules\Edge\Livewire\Concerns\ManagesEdgeFormPrefills;
use App\Modules\Edge\Livewire\Concerns\ManagesEdgeRefPicker;
use App\Modules\Edge\Livewire\Concerns\ManagesEdgeRepoDetection;
use App\Livewire\Forms\EdgeCreateForm;
use App\Models\EdgeSiteEnvVar;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Billing\Services\ManagedProductCostEstimator;
use App\Modules\Edge\Services\EdgeTemplateRegistry;
use App\Modules\Edge\Services\Frameworks\EdgeFrameworkPresetRegistry;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\Edge\Support\EdgeEligibility;
use App\Modules\Edge\Support\EdgeSsrAvailability;
use App\Modules\Edge\Support\EdgeSsrDetection;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Git-connected create flow for dply Edge — static/SSG builds only in v1.
 */
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;
    use ManagesEdgeDeploy;
    use ManagesEdgeFormPrefills;
    use ManagesEdgeRefPicker;
    use ManagesEdgeRepoDetection;
    use RefreshesLinkedSourceControlAccounts;

    public EdgeCreateForm $form;

    /** 'manual' = type owner/name. 'connected' = pick from linked Git account. */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $repo = '';

    public string $branch = 'main';

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableRepositories = [];

    public bool $runtimeModeTouched = false;

    public bool $originUrlTouched = false;

    public bool $buildOverridesTouched = false;

    public bool $confirmingHybridStack = false;

    private bool $prefillingOrigin = false;

    private bool $prefillingFromDetection = false;

    private string $lastDetectionFingerprint = '';

    /** @var list<array{path: string, label: string}> */
    public array $monorepoPackages = [];

    public bool $monorepoDetected = false;

    /** @var list<string> */
    public array $monorepoMarkers = [];

    public bool $repoRootTouched = false;

    /**
     * Detection-in-flight bookkeeping. Off-thread detection writes its
     * result into Cache; the view polls pollRuntimeDetection() while
     * this flag is true, then flips it off when the cache key resolves.
     */
    public bool $runtimeDetectionPending = false;

    public string $runtimeDetectionKey = '';

    // ── Repo ref picker state (Branch / Tag / Commit search) ──────────────
    // Pops a panel under the ref input so the operator can search the
    // repo's actual branches/tags/commits instead of typing blind.
    // Hits the public GitHub API directly (authed via the user's linked
    // identity if present, anonymous otherwise). GitLab/Bitbucket fall
    // back to the static text input — adding them later is incremental.
    public bool $refPickerOpen = false;

    public string $refPickerTab = 'branches';

    public string $refPickerSearch = '';

    /** @var list<array{label: string, sha: string, meta: ?string}> */
    public array $refPickerResults = [];

    public ?string $refPickerError = null;

    public bool $refPickerLoading = false;

    /**
     * Env vars handed over from the Import wizard, pending persistence
     * until the site is actually created. Keyed by env name, values are
     * the importer's plaintext payload. Filled by {@see applyQueryPrefills}
     * when ?import_envs=<session-key> is present; flushed in {@see deploy}.
     *
     * @var array<string, string|int|float>
     */
    public array $pendingImportedEnvVars = [];

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.edge'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->loadRepositoriesForSelectedAccount();
            $this->repo_source = 'connected';
        }

        $this->applyQueryPrefills(request()->query());
    }

    public function openCloudflareCredentialModal(): void
    {
        $this->dispatch('open-add-provider-credential-modal', provider: 'cloudflare');
    }

    #[On('provider-credential-created')]
    public function applyStoredCloudflareCredential(?string $provider = null, mixed $credentialId = null): void
    {
        if ($provider !== 'cloudflare' || $credentialId === null || $credentialId === '') {
            return;
        }

        $this->form->delivery_mode = 'byo';
        $this->form->edge_provider_credential_id = (string) $credentialId;
    }

    /**
     * Prefill a known public static template so local/sandbox operators can
     * exercise create → detect → deploy without hunting for a repo.
     * Gated to local/testing (and fake-edge sandbox) — never shown in prod.
     */
    public function loadSampleApp(): void
    {
        if (! $this->localSampleAppAvailable()) {
            return;
        }

        $this->loadExampleApp('eleventy-portfolio');
    }

    /**
     * Prefill from a curated public Edge template (Keel, Eleventy, …).
     * Always available — these repos are public, so no Git token is required.
     */
    public function loadExampleApp(string $slug): void
    {
        $slug = trim($slug);
        $template = $slug !== '' ? EdgeTemplateRegistry::find($slug) : null;
        if ($template === null && $slug === 'eleventy-portfolio') {
            $template = EdgeTemplateRegistry::find('static-html');
        }
        if ($template === null) {
            $this->toastError(__('That example app is no longer available.'));

            return;
        }

        $repo = EdgeCreateForm::normalizeRepo((string) ($template['clone_repo'] ?? $template['repo'] ?? ''));
        if ($repo === '') {
            $this->toastError(__('Example app is missing a repository.'));

            return;
        }

        $this->repo_source = 'manual';
        $this->repository_selection = '';
        $this->repo = $repo;
        $this->branch = (string) ($template['branch'] ?? 'main');
        $this->form->name = $this->exampleAppName($template, $repo);
        $this->form->ref_kind = 'branch';
        $this->form->delivery_mode = 'managed';
        $this->form->spa_fallback = true;
        $this->form->deploy_on_push = false;
        $this->form->origin_url = '';
        $this->form->origin_cloud_site_id = '';
        $this->form->repo_root = '';
        $this->runtimeModeTouched = false;
        $this->originUrlTouched = false;
        $this->buildOverridesTouched = false;
        $this->repoRootTouched = false;
        $this->monorepoDetected = false;
        $this->monorepoPackages = [];
        $this->monorepoMarkers = [];

        $runtimeMode = strtolower((string) ($template['runtime_mode'] ?? 'static'));
        $this->form->runtime_mode = in_array($runtimeMode, ['static', 'hybrid', 'ssr'], true)
            ? $runtimeMode
            : 'static';

        // Mark runtime when the template pins hybrid/SSR so detection
        // doesn't quietly flip Keel (etc.) back to static.
        if ($this->form->runtime_mode !== 'static') {
            $this->runtimeModeTouched = true;
        }

        $preset = EdgeFrameworkPresetRegistry::byDetectionPlan([
            'framework' => (string) ($template['framework'] ?? ''),
        ]);
        $this->form->build_command = $preset->buildCommand;
        $this->form->output_dir = $preset->outputDir !== '' ? $preset->outputDir : 'dist';

        $this->toastSuccess(__('Loaded example: :name', ['name' => (string) ($template['name'] ?? $repo)]));
        $this->detectFromRepository();
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function exampleAppName(array $template, string $repo): string
    {
        $slug = trim((string) ($template['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $name = trim((string) ($template['name'] ?? ''));
        if ($name !== '') {
            return \Illuminate\Support\Str::slug($name);
        }

        return $this->extractRepoNameForApp($repo) ?: 'edge-app';
    }

    private function localSampleAppAvailable(): bool
    {
        if (FakeEdgeProvision::enabled()) {
            return true;
        }

        return app()->environment('local', 'testing');
    }

    /**
     * Honor ?repo=…&branch=…&framework=…&runtime_mode=…&build_command=…&output_dir=…&name=…
     * query params on initial mount so the import wizard + template
     * gallery can hand off a ready-to-deploy state without re-asking
     * the user to type anything they've already picked.
     *
     * Only fields the user hasn't manually touched (still empty) get
     * filled — refreshing the page doesn't blow away in-progress
     * edits.
     *
     * @param  array<string, mixed>  $query
     */
    private function applyQueryPrefills(array $query): void
    {
        $stringValue = static function ($v): string {
            return is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : '');
        };

        $repo = $stringValue($query['repo'] ?? null);
        if ($repo !== '') {
            $this->repo = EdgeCreateForm::normalizeRepo($repo);
            $this->repo_source = 'manual';
            $this->maybeSeedAppNameFromRepo();
        }

        $branch = $stringValue($query['branch'] ?? null);
        if ($branch !== '') {
            $this->branch = $branch;
        }

        $name = $stringValue($query['name'] ?? null);
        if ($name !== '' && $this->form->name === '') {
            $this->form->name = $name;
        }

        $runtimeMode = strtolower($stringValue($query['runtime_mode'] ?? null));
        if (in_array($runtimeMode, ['static', 'hybrid', 'ssr'], true)) {
            $this->form->runtime_mode = $runtimeMode;
            $this->runtimeModeTouched = true;
        }

        $build = $stringValue($query['build_command'] ?? null);
        if ($build !== '' && $this->form->build_command === '') {
            $this->form->build_command = $build;
            $this->buildOverridesTouched = true;
        }

        $output = $stringValue($query['output_dir'] ?? null);
        if ($output !== '' && ($this->form->output_dir === '' || $this->form->output_dir === 'dist')) {
            $this->form->output_dir = $output;
            $this->buildOverridesTouched = true;
        }

        // Env-var transfer from the Import wizard. The wizard stashes
        // the importer's plaintext values in session keyed by a ULID
        // and passes the key through ?import_envs=… (values stay out
        // of the URL — too sensitive + too long). On consume we pop
        // the session entry and stash the array on the component;
        // deploy() writes EdgeSiteEnvVar rows after the site lands.
        $importedFrom = $stringValue($query['imported_from'] ?? null);
        if ($importedFrom !== '') {
            $this->form->imported_from = $importedFrom;
        }

        $importedId = $stringValue($query['imported_id'] ?? null);
        if ($importedId !== '') {
            $this->form->imported_id = $importedId;
        }

        $importDashboard = $stringValue($query['imported_dashboard_url'] ?? null);
        if ($importDashboard !== '') {
            $this->form->imported_dashboard_url = $importDashboard;
        }

        $importEnvsKey = $stringValue($query['import_envs'] ?? null);
        if ($importEnvsKey !== '') {
            $envs = session()->pull('edge.import.envs.'.$importEnvsKey);
            if (is_array($envs)) {
                $this->pendingImportedEnvVars = array_filter(
                    $envs,
                    static fn ($v, $k): bool => is_string($k) && (is_string($v) || is_int($v) || is_float($v)),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
        }

        if ($repo !== '') {
            $this->maybeAutoDetectFromRepository();
        }
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }

        $this->loadRepositoriesForSelectedAccount();
        $this->repo_source = 'connected';
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $cloudflareCredentials = $org
            ? ProviderCredential::query()
                ->where('organization_id', $org->id)
                ->where('provider', 'cloudflare')
                ->latest()
                ->get()
            : collect();

        $eligibility = EdgeEligibility::evaluate($this->detectedPlan);
        $ssrAvailable = EdgeSsrAvailability::isAvailable();
        if (! $ssrAvailable && $this->form->runtime_mode === 'ssr') {
            $this->form->runtime_mode = 'hybrid';
        }

        return view('livewire.edge.create', [
            'fakeEdgeActive' => FakeEdgeProvision::enabled(),
            'localSampleAppAvailable' => $this->localSampleAppAvailable(),
            'exampleApps' => EdgeTemplateRegistry::featuredForCreate(),
            'edgeFee' => app(ManagedProductCostEstimator::class)->edgeFee(),
            'edgeSsrFee' => app(ManagedProductCostEstimator::class)->edgeSsrFee(),
            'edgePlatformFee' => app(ManagedProductCostEstimator::class)->edgeFeeForRuntimeMode((string) $this->form->runtime_mode),
            'edgeUsageBillingEnabled' => app(ManagedProductCostEstimator::class)->edgeUsageBillingEnabled(),
            'edgeUsageRates' => app(ManagedProductCostEstimator::class)->edgeUsageRates(),
            'cloudflareCredentials' => $cloudflareCredentials,
            'orgCloudSites' => $this->orgCloudSitesForPicker(),
            'ssrDetected' => $this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan),
            'ssrAvailable' => $ssrAvailable,
            'ssrUnavailableReason' => EdgeSsrAvailability::unavailableReason(),
            'edgeEligible' => $eligibility['eligible'],
            'edgeIneligibleMessage' => $eligibility['message'],
            'edgeAlternativeRoute' => $eligibility['alternative_route'],
            'edgeAlternativeLabel' => $eligibility['alternative_label'],
            'suggestedHybridOriginUrl' => $this->suggestedHybridOriginUrlForName(),
            'showHybridStackCta' => $this->showHybridStackCta(),
            'autoProvisionHybridOrigin' => $this->shouldAutoProvisionHybridOrigin(),
            'canProvisionCloudOrigin' => $this->canProvisionCloudOrigin(),
            'cloudFee' => app(ManagedProductCostEstimator::class)->cloudFee(),
        ])->layout('layouts.app');
    }

    public function runDetection(string $url, string $branch): void
    {
        // Defensive ceiling — anything slow here is a bug we want to
        // see fail loud (queue dispatch + 200ms HTTP fetches are
        // nowhere near this), but if a fall-through to the trait's
        // sync clone ever happens we shouldn't crash at PHP's
        // 30s default. Bumped to 120s gives the worker headroom too.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        // Breadcrumb so operators can confirm which code path is
        // actually running. Grep storage/logs/laravel.log for this
        // string — if it's missing during a slow request, opcache is
        // serving stale code and `valet restart` is required.
        Log::info('[edge.create.runDetection]', [
            'url' => $url, 'branch' => $branch, 'pid' => getmypid(),
        ]);

        $url = trim($url);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if ($url === '') {
            $this->detectedPlan = [];
            $this->runtimeDetectionPending = false;
            $this->runtimeDetectionKey = '';
            $this->applyDetectedRuntimePrefills();

            return;
        }

        // Include repo_root so picking examples/basics doesn't reuse a
        // cached framework-monorepo plan from the repository root.
        $repoRoot = trim((string) ($this->form->repo_root ?? ''));
        $key = 'edge-detect:'.sha1($url.'|'.$branch.'|'.$repoRoot);
        $this->runtimeDetectionKey = $key;
        $cached = Cache::get($key);

        // Already done — surface immediately, no job dispatch.
        if (is_array($cached) && in_array($cached['state'] ?? null, ['done', 'failed'], true)) {
            $this->detectedPlan = (array) ($cached['plan'] ?? []);
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        // Fast path: GitHub repos can be detected by fetching ~25 small
        // files via Contents API in parallel (200-400ms) instead of
        // cloning. Skip the queue entirely when this succeeds. Falls
        // through to the queued clone for non-GitHub repos, private
        // repos without auth, or repos where the fast path errors.
        if ($this->tryFastGitHubDetection($url, $branch, $key)) {
            return;
        }

        $this->detectedPlan = [];
        $this->runtimeDetectionPending = true;

        // Only dispatch if no fresh job is already in flight for this key.
        // "Stuck" entries (queued/running but old) get re-dispatched.
        $shouldDispatch = ! is_array($cached)
            || ! in_array($cached['state'] ?? null, ['queued', 'running'], true)
            || $this->isStaleDetectionEntry($cached);

        if ($shouldDispatch) {
            Cache::put($key, [
                'state' => 'queued',
                'url' => $url,
                'branch' => $branch,
                'dispatched_at' => now()->toIso8601String(),
            ], now()->addHours(24));
            DetectRepositoryRuntimeJob::dispatch($key, $url, $branch);
        }
    }

    protected function applyDetectedRuntimePrefills(): void
    {
        if (! $this->buildOverridesTouched) {
            // Detection ships its own build_command + output_dir when
            // they're confident; we honor those verbatim. When either is
            // missing we fall back to the framework preset registry —
            // single source of truth shared with the build cache + import
            // wizard.
            $preset = EdgeFrameworkPresetRegistry::byDetectionPlan($this->detectedPlan);

            $build = trim((string) ($this->detectedPlan['build_command'] ?? ''));
            if ($build !== '') {
                $this->form->build_command = $build;
            } elseif ($preset->buildCommand !== '') {
                $this->form->build_command = $preset->buildCommand;
            }

            $detectedOutput = trim((string) ($this->detectedPlan['output_dir'] ?? ''));
            if ($detectedOutput !== '') {
                $this->form->output_dir = $detectedOutput;
            } elseif ($this->form->output_dir === '' || $this->form->output_dir === 'dist') {
                $this->form->output_dir = $preset->outputDir !== '' ? $preset->outputDir : 'dist';
            }
        }

        // Always apply delivery-mode prefills (hybrid for SSR, etc.) even
        // when build fields were filled from detection — early-returning
        // after output_dir used to skip this and leave mode stuck on static.
        $this->applyDetectedDeliveryPrefills();
    }
}
