<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Services\StripeBillingProvisioner;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * One-shot provisioning command that creates the Stripe products and prices
 * backing Edge billing (per-site Edge fees, Edge delivery usage, Enterprise).
 * Idempotent — re-running after a partial failure picks up where it left off,
 * and re-running after success is a no-op (looks up existing objects by
 * `metadata.dply_role` before creating).
 *
 * After it succeeds, paste the printed env vars into your .env (or your
 * secrets manager) and restart the app so config('subscription.standard.*')
 * picks up the new IDs.
 *
 * Examples:
 *   php artisan dply:billing:provision-stripe --dry-run
 *   php artisan dply:billing:provision-stripe
 */
class ProvisionStripeBillingCommand extends Command
{
    protected $signature = 'dply:billing:provision-stripe
                            {--dry-run : Inspect what would be created without calling Stripe}';

    protected $description = 'Create the Stripe products and prices for Edge billing (idempotent).';

    public function handle(): int
    {
        if (! is_string($secret = config('cashier.secret')) || $secret === '') {
            $this->error('cashier.secret is not configured. Set STRIPE_SECRET in .env first.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun();
        }

        try {
            $stripe = Organization::stripe();
        } catch (\Throwable $e) {
            $this->error('Could not initialise Stripe client: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Provisioning Stripe billing objects… (idempotent, safe to re-run)');

        try {
            $provisioner = new StripeBillingProvisioner($stripe);
            $result = $provisioner->provision();
        } catch (ApiErrorException $e) {
            $this->error('Stripe API error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Created or reused the following:');
        foreach ($result as $role => $id) {
            $this->line(sprintf('  %-30s %s', $role, $id));
        }

        $this->newLine();
        $this->info('Paste these env vars into your .env (then restart the app):');
        $this->newLine();
        $this->line(StripeBillingProvisioner::formatEnv($result));
        $this->newLine();

        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        $standard = (array) config('subscription.standard', []);
        $annualPct = (int) ($standard['annual_discount_pct'] ?? 0);
        $yearlyOf = fn (int $cents): int => (int) round($cents * 12 * (100 - $annualPct) / 100);

        $this->info('Dry-run — these objects would be created or matched in Stripe:');
        $this->newLine();
        $edge = (int) ($standard['edge_cents'] ?? 0);
        if ($edge > 0) {
            $this->line('  Product: dply Edge site (static / hybrid)');
            $this->line(sprintf(
                '    Per site $%s/mo   $%s/yr',
                number_format($edge / 100, 2),
                number_format($yearlyOf($edge) / 100, 2),
            ));
        }
        $edgeSsr = (int) ($standard['edge_ssr_cents'] ?? 0);
        if ($edgeSsr > 0) {
            $this->line('  Product: dply Edge SSR site');
            $this->line(sprintf(
                '    Per site $%s/mo   $%s/yr',
                number_format($edgeSsr / 100, 2),
                number_format($yearlyOf($edgeSsr) / 100, 2),
            ));
        }
        $edgeUsageUnit = (int) ($standard['edge_usage_unit_cents'] ?? 1);
        if ($edgeUsageUnit > 0) {
            $this->line('  Product: dply Edge delivery usage');
            $this->line(sprintf(
                '    Metered $%s/unit (monthly, quantity = cents)',
                number_format($edgeUsageUnit / 100, 2),
            ));
        }
        $this->line('  Product: dply Enterprise (no prices — sales-led)');
        $this->newLine();
        $this->info('Re-run without --dry-run to actually create these in Stripe.');

        return self::SUCCESS;
    }
}
