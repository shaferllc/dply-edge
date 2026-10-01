<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Services\OrganizationBillingEnforcer;
use Illuminate\Console\Command;

/**
 * Legal hold: keep an organization's data even when the billing purge would
 * delete it (a dispute, a legal claim, a preservation request). While held,
 * OrganizationBillingEnforcer sends no deletion warnings and
 * OrganizationDataPurger refuses to run. --off releases it; a paused org's
 * 30 days then run again from the release, with both warnings.
 *
 *   php artisan dply:billing:hold {org id or slug} --reason="Preservation request 2026-10" [--off]
 */
class HoldOrganizationCommand extends Command
{
    protected $signature = 'dply:billing:hold {organization : Organization id or slug} {--reason= : Why (shown in the enforce log)} {--off : Release the hold}';

    protected $description = 'Put an organization on legal hold (its data is never purged), or release it.';

    public function handle(): int
    {
        $key = (string) $this->argument('organization');
        $org = Organization::query()->whereKey($key)->orWhere('slug', $key)->first();
        if ($org === null) {
            $this->error('No organization '.$key);

            return self::FAILURE;
        }
        if ($this->option('off')) {
            // Restart the clock: deletion is never sooner than the full keep period after release.
            $org->forceFill([
                'purge_hold_at' => null,
                'purge_hold_reason' => null,
                'billing_paused_at' => $org->billing_paused_at !== null ? now() : null,
                // Warnings sent before the hold go out again before any deletion.
                'billing_notices' => array_diff_key((array) $org->billing_notices, OrganizationBillingEnforcer::DELETE_WARNINGS),
            ])->save();
            $this->info($org->name.': hold released.'.($org->billing_paused_at !== null ? ' Paused: its keep period starts again today.' : ''));

            return self::SUCCESS;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Give a --reason.');

            return self::FAILURE;
        }
        $org->forceFill(['purge_hold_at' => now(), 'purge_hold_reason' => mb_substr($reason, 0, 255)])->save();
        audit_log($org, null, 'organization.purge_hold', $org, null, ['reason' => $reason]);
        $this->info($org->name.': on legal hold. Its data will not be purged until released.');

        return self::SUCCESS;
    }
}
