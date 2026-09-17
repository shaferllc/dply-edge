<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire\Concerns;

use App\Enums\QuotaSurface;
use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Actions\CreateEdgeSite;
use App\Modules\Edge\Support\EdgeEligibility;
use App\Modules\Edge\Support\EdgeSsrAvailability;
use App\Modules\Edge\Support\EdgeSsrDetection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesEdgeDeploy
{
    private function validateCreateForm(): void
    {
        $this->validate([
            'repo' => ['required', 'string', 'max:200'],
            'branch' => ['required', 'string', 'max:120'],
        ]);

        $this->form->validate();
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        if (! $org->canCreateOnSurface(QuotaSurface::Edge)) {
            $this->toastError($org->quotaLimitMessage(QuotaSurface::Edge));

            return;
        }

        if ($this->form->runtime_mode === 'container' && ! EdgeSsrAvailability::isAvailable()) {
            $this->toastError(__('Container delivery isn’t set up on this install yet: it needs the Edge platform Cloudflare API token (with Containers access) and a dispatch namespace.'));

            return;
        }

        if ($this->form->runtime_mode === 'container' && ! ($org->tierAllowances()['containers'] ?? false) && ! $org->isBeta()) {
            $this->toastError(__('Container apps (PHP, Rails) are on Pro and Team. Choose a plan on the billing page.'));

            return;
        }

        if ($this->detectedPlan !== [] && EdgeEligibility::needsContainer($this->detectedPlan) && $this->form->runtime_mode !== 'container') {
            $this->toastError(__('This looks like a PHP or Rails app. Choose "Container" delivery to run it on Edge.'));

            return;
        }

        if ($this->form->runtime_mode === 'ssr' && ! ($org->tierAllowances()['ssr'] ?? false) && ! $org->isBeta()) {
            $this->toastError(__('Worker-native SSR sites are on Pro and Team. Choose a plan on the billing page, or deploy as static or hybrid.'));

            return;
        }

        $eligibility = EdgeEligibility::evaluate($this->detectedPlan);
        if (! $eligibility['eligible']) {
            $this->toastError((string) $eligibility['message']);

            return;
        }

        if ($this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)
            && ! in_array($this->form->runtime_mode, ['hybrid', 'ssr'], true)) {
            $this->toastError(__('This repository looks like an SSR app. Pick "Worker-native SSR" (Next.js via OpenNext), hybrid mode with an origin URL, or run it on a server.'));

            return;
        }

        if ($this->form->runtime_mode === 'hybrid' && trim($this->form->origin_url) === '') {
            $this->toastError(__('Enter the SSR origin URL for hybrid delivery.'));

            return;
        }

        try {
            $site = (new CreateEdgeSite)->handle(
                auth()->user(),
                $org,
                $this->form->createEdgeSitePayload(
                    (string) ($this->detectedPlan['framework'] ?? ''),
                    $this->repo,
                    $this->branch,
                ),
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        audit_log($org, auth()->user(), 'site.edge.created', $site, null, [
            'site_id' => (string) $site->id,
            'site_name' => $site->name,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'framework' => (string) ($this->detectedPlan['framework'] ?? ''),
            'runtime_mode' => $this->form->runtime_mode,
        ]);

        $importedCount = $this->persistImportedEnvVars($site);
        if ($importedCount > 0) {
            $this->toastSuccess(__('Edge app build queued — :count env var(s) imported.', ['count' => $importedCount]));
        } else {
            $this->toastSuccess(__('Edge app build queued. We\'ll keep the site workspace updated as it goes live.'));
        }
        $this->redirect(route('sites.show', ['server' => $site->server, 'site' => $site]), navigate: true);
    }

    /**
     * Flush imported env vars onto the freshly-created site as
     * production-scope EdgeSiteEnvVar rows. Skips keys that conflict
     * with platform-reserved names (HOST_MAP, ASSETS, etc) or aren't
     * shaped as ALL_CAPS_WITH_UNDERSCORES. Returns the count actually
     * persisted so the success toast can report it.
     */
    private function persistImportedEnvVars(Site $site): int
    {
        if ($this->pendingImportedEnvVars === []) {
            return 0;
        }

        $count = 0;
        foreach ($this->pendingImportedEnvVars as $key => $value) {
            if (! EdgeSiteEnvVar::keyIsValid($key)) {
                continue;
            }
            $stringValue = is_string($value) ? $value : ((string) $value);
            if ($stringValue === '') {
                // Importer signals secret-redacted values with an
                // empty string (Cloudflare Pages); skip — the user
                // re-enters them via the dashboard env panel.
                continue;
            }
            (new EdgeSiteEnvVar([
                'site_id' => $site->id,
                'key' => $key,
                'value' => $stringValue,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                'created_by_user_id' => auth()->id(),
            ]))->save();
            $count++;
        }
        $this->pendingImportedEnvVars = [];

        return $count;
    }
}
