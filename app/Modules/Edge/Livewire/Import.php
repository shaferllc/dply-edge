<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Modules\Edge\Services\Importers\CloudflarePagesImporter;
use App\Modules\Edge\Services\Importers\EdgeImporter;
use App\Modules\Edge\Services\Importers\NetlifyImporter;
use App\Modules\Edge\Services\Importers\VercelImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edge import wizard — pick a source provider, paste a credential,
 * list the projects, then hand a single picked project off to the
 * Create form pre-filled with build settings + env vars + domains.
 *
 * The wizard is intentionally read-only on the dply side: no rows
 * are created until the user lands in the Create flow and hits
 * Deploy. Mistyped credentials or wrong projects can be backed out
 * without leaving orphan sites behind.
 */
class Import extends Component
{
    use DispatchesToastNotifications;

    public string $step = 'provider';

    public string $provider = '';

    public string $apiToken = '';

    /** Optional secondary identifier — Vercel team id, Cloudflare account id. */
    public string $secondaryId = '';

    /** @var array{ok: bool, message: string, principal?: string}|null */
    public ?array $probeResult = null;

    /** @var list<array<string, mixed>> */
    public array $projects = [];

    public string $selectedProjectId = '';

    /** @var array<string, mixed>|null */
    public ?array $projectPreview = null;

    public ?string $loadError = null;

    #[Locked]
    public bool $loading = false;

    public function mount(): void {}

    public function pickProvider(string $provider): void
    {
        if (! in_array($provider, ['vercel', 'netlify', 'cloudflare_pages'], true)) {
            return;
        }
        $this->provider = $provider;
        $this->step = 'credential';
        $this->probeResult = null;
        $this->projects = [];
        $this->projectPreview = null;
        $this->loadError = null;
    }

    public function back(): void
    {
        $this->step = match ($this->step) {
            'preview' => 'projects',
            'projects' => 'credential',
            'credential' => 'provider',
            default => 'provider',
        };

        if ($this->step === 'provider') {
            $this->provider = '';
            $this->apiToken = '';
            $this->secondaryId = '';
            $this->probeResult = null;
            $this->projects = [];
        }
    }

    public function probe(): void
    {
        $token = trim($this->apiToken);
        if ($token === '') {
            $this->probeResult = ['ok' => false, 'message' => __('Paste a token before continuing.')];

            return;
        }
        if ($this->provider === 'cloudflare_pages' && trim($this->secondaryId) === '') {
            $this->probeResult = ['ok' => false, 'message' => __('Cloudflare account id is required.')];

            return;
        }

        try {
            $importer = $this->makeImporter();
            $this->probeResult = $importer->probe();
        } catch (\Throwable $e) {
            $this->probeResult = ['ok' => false, 'message' => $e->getMessage()];

            return;
        }

        if (($this->probeResult['ok'] ?? false) !== true) {
            return;
        }

        $this->loadProjects();
    }

    public function loadProjects(): void
    {
        $this->loading = true;
        try {
            $importer = $this->makeImporter();
            $this->projects = $importer->listProjects();
            $this->step = 'projects';
            $this->loadError = null;
        } catch (\Throwable $e) {
            $this->loadError = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function previewProject(string $projectId): void
    {
        if ($projectId === '') {
            return;
        }
        $this->loading = true;
        try {
            $importer = $this->makeImporter();
            $project = $importer->fetchProject($projectId);
            $this->selectedProjectId = $projectId;
            $this->projectPreview = [
                'name' => $project->name,
                'repo' => $project->repoUrl,
                'branch' => $project->branch,
                'framework' => $project->framework,
                'build_command' => $project->buildCommand,
                'output_dir' => $project->outputDir,
                'runtime_mode' => $project->runtimeMode,
                'env_count' => count($project->envVars),
                'env_keys' => array_keys($project->envVars),
                'custom_domains' => $project->customDomains,
                'source_live_url' => $project->sourceLiveUrl,
                'create_form_prefill' => $project->toCreateFormPrefill(),
            ];
            $this->step = 'preview';
            $this->loadError = null;
        } catch (\Throwable $e) {
            $this->loadError = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function handOffToCreate(): void
    {
        if (! is_array($this->projectPreview)) {
            return;
        }

        $prefill = is_array($this->projectPreview['create_form_prefill'] ?? null)
            ? $this->projectPreview['create_form_prefill']
            : [];

        // Env-var transfer (P-import-env): re-fetch the project to
        // pick up plaintext values (we deliberately don't keep them
        // in component state past previewProject), stash them in
        // session keyed by a ULID, and pass that key through the
        // URL. EdgeCreate::mount pops the session entry and writes
        // EdgeSiteEnvVar rows after CreateEdgeSite::handle().
        if ($this->selectedProjectId !== '') {
            try {
                $project = $this->makeImporter()->fetchProject($this->selectedProjectId);
                if ($project->envVars !== []) {
                    $key = (string) Str::ulid();
                    session()->put('edge.import.envs.'.$key, $project->envVars);
                    $prefill['import_envs'] = $key;
                }
            } catch (\Throwable) {
                // Best-effort — if the re-fetch fails (token revoked,
                // network blip), the user lands on Create without env
                // transfer and can paste vars manually via the panel.
            }
        }

        $this->redirectRoute('edge.create', $prefill, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.edge.import', [
            'providers' => [
                ['key' => 'vercel', 'label' => 'Vercel', 'hint' => __('Personal access token (Account → Tokens). Team accounts also need the team id.')],
                ['key' => 'netlify', 'label' => 'Netlify', 'hint' => __('Personal access token (User settings → Applications → Personal access tokens).')],
                ['key' => 'cloudflare_pages', 'label' => 'Cloudflare Pages', 'hint' => __('API token with Pages:Read + account id from the Cloudflare dashboard.')],
            ],
        ]);
    }

    private function makeImporter(): EdgeImporter
    {
        $token = trim($this->apiToken);

        return match ($this->provider) {
            'vercel' => new VercelImporter($token, trim($this->secondaryId) ?: null),
            'netlify' => new NetlifyImporter($token),
            'cloudflare_pages' => new CloudflarePagesImporter(trim($this->secondaryId), $token),
            default => throw new \InvalidArgumentException('Unsupported provider: '.$this->provider),
        };
    }
}
