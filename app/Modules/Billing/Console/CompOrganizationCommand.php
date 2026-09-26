<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Comp an org: it runs as Team with nothing billed until --until (default:
 * indefinitely). For dply's own orgs and hand-picked testers (ruling
 * r-f17p5zgeh120cm5t). --off ends the comp; the org then needs a plan.
 *
 *   php artisan dply:billing:comp {org id or slug} [--until=2026-12-31] [--off]
 */
class CompOrganizationCommand extends Command
{
    protected $signature = 'dply:billing:comp {organization : Organization id or slug} {--until= : End date (default: indefinitely)} {--off : End the comp}';

    protected $description = 'Comp an organization (Team, no bill), or end a comp.';

    public function handle(): int
    {
        $key = (string) $this->argument('organization');
        $org = Organization::query()->whereKey($key)->orWhere('slug', $key)->first();
        if ($org === null) {
            $this->error('No organization '.$key);

            return self::FAILURE;
        }
        $until = $this->option('off') ? null : ($this->option('until') ? Carbon::parse((string) $this->option('until'))->endOfDay() : Carbon::parse('2099-12-31'));
        $org->forceFill(['comped_until' => $until])->save();
        $this->info($until === null ? $org->name.': comp ended.' : $org->name.': comped until '.$until->toDateString().'.');
        app(\App\Modules\Billing\Services\OrganizationBillingEnforcer::class)->enforce($org, false, fn (string $line) => $this->line($line));

        return self::SUCCESS;
    }
}
