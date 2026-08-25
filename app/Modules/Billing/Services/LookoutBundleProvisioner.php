<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Enums\BundleTransition;
use App\Models\LookoutProject;
use Illuminate\Support\Facades\Log;

/**
 * In-process bridge from a `bundle.*` transition to Lookout provisioning
 * (Lookout is first-party and provisioned inside dply, so it consumes the
 * transition directly rather than over a webhook — Q5).
 *
 * A bundle project is a normal MANAGED {@see LookoutProject} keyed to the org
 * (no site/binding) and stamped `source = SOURCE_BUNDLE`, which
 * {@see \App\Observers\LookoutProjectBillingObserver} and
 * {@see \App\Modules\Billing\Services\OrganizationBillingStateComputer} skip — so
 * the free perk is never invoiced. Idempotent: one active bundle project per org.
 *
 *   Provisioned/Resumed → ensure ONE active bundle project for the org.
 *   Suspended           → flip it to STATUS_PAUSED (data retained, reversible).
 *   Deleted             → hard-delete the local row (retention elapsed).
 *
 * Best-effort: a provisioning failure (e.g. LOOKOUT_PROVISION_TOKEN unset) logs
 * and leaves no row, so the nightly reconcile retries. See
 * docs/adr/bundled-products-sso.md.
 */
final class LookoutBundleProvisioner
{
    public function __construct(
        private readonly LookoutProvisioner $provisioner,
    ) {}

    public function apply(string $organizationId, BundleTransition $transition): void
    {
        if (! config('bundle.enabled', false)) {
            return;
        }

        match ($transition) {
            BundleTransition::Provisioned, BundleTransition::Resumed => $this->ensureActive($organizationId),
            BundleTransition::Suspended => $this->pause($organizationId),
            BundleTransition::Deleted => $this->purge($organizationId),
        };
    }

    /** Existing bundle project (any status) for the org, or null. */
    private function bundleProject(string $organizationId): ?LookoutProject
    {
        return LookoutProject::query()
            ->where('organization_id', $organizationId)
            ->where('source', LookoutProject::SOURCE_BUNDLE)
            ->first();
    }

    private function ensureActive(string $organizationId): void
    {
        $existing = $this->bundleProject($organizationId);

        // Resume: a suspended/paused row just flips back — no new remote project.
        if ($existing !== null) {
            if ($existing->status !== LookoutProject::STATUS_ACTIVE) {
                $existing->update(['status' => LookoutProject::STATUS_ACTIVE]);
            }

            return;
        }

        // Fresh provision: create the remote managed project under a Lookout org
        // dedicated to this dply org (isolation), then persist the row.
        $tier = (string) config('lookout.default_tier', 'starter');
        $retentionDays = (int) config("lookout.tiers.{$tier}.retention_days", 7);
        $orgName = \App\Models\Organization::query()->whereKey($organizationId)->value('name');

        try {
            $result = $this->provisioner->provisionManaged(
                'dply-bundle-'.$organizationId,
                $retentionDays,
                $organizationId,
                is_string($orgName) ? $orgName : null,
            );
        } catch (\Throwable $e) {
            // No row persisted → the nightly reconcile re-emits Provisioned and retries.
            Log::warning('bundle.lookout.provision_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        LookoutProject::create([
            'organization_id' => $organizationId,
            'lookout_project_id' => $result['project_id'] ?? null,
            'name' => $result['project_name'],
            'tier' => $tier,
            'status' => LookoutProject::STATUS_ACTIVE,
            'source' => LookoutProject::SOURCE_BUNDLE,
            'retention_days' => $retentionDays,
        ]);
    }

    private function pause(string $organizationId): void
    {
        $project = $this->bundleProject($organizationId);
        if ($project !== null && $project->status !== LookoutProject::STATUS_PAUSED) {
            $project->update(['status' => LookoutProject::STATUS_PAUSED]);
        }
    }

    private function purge(string $organizationId): void
    {
        // Remote teardown first (best-effort — never blocks the local delete),
        // then drop the local rows. Retention has elapsed, so this is terminal.
        LookoutProject::query()
            ->where('organization_id', $organizationId)
            ->where('source', LookoutProject::SOURCE_BUNDLE)
            ->get()
            ->each(function (LookoutProject $project): void {
                if (is_string($project->lookout_project_id) && $project->lookout_project_id !== '') {
                    $this->provisioner->deprovision($project->lookout_project_id);
                }
                $project->delete();
            });
    }
}
