<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Services\StripeBillingProvisioner;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * One-shot provisioning command that creates the Stripe products and prices
 * backing billing (Starter / Pro / Team plans, Team seat, Enterprise).
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

    protected $description = 'Create the Stripe products and prices for the plans (idempotent).';

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
        $tiers = (array) config('subscription.standard.tiers', []);

        $this->info('Dry-run — these objects would be created or matched in Stripe:');
        $this->newLine();
        foreach (['starter', 'pro', 'team'] as $key) {
            $tier = (array) ($tiers[$key] ?? []);
            if ((int) ($tier['price_cents'] ?? 0) > 0) {
                $this->line(sprintf('  Product: dply %s — $%s/mo', $tier['label'], number_format((int) $tier['price_cents'] / 100, 2)));
            }
        }
        $seat = (int) ($tiers['team']['extra_seat_cents'] ?? 0);
        if ($seat > 0) {
            $this->line(sprintf('  Product: dply Team seat — $%s/mo', number_format($seat / 100, 2)));
        }
        $this->line('  Product: dply Enterprise (no prices — sales-led)');
        $this->newLine();
        $this->info('Re-run without --dry-run to actually create these in Stripe.');

        return self::SUCCESS;
    }
}
