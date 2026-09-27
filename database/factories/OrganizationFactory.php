<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'email' => fake()->optional()->companyEmail(),
            'deploy_email_notifications_enabled' => true,
            // A working org is on its trial or paid: there is no Free plan
            // (ruling r-f17p5zgeh120cm5t). noPlan() for one whose trial ended.
            'trial_ends_at' => now()->addDays(5),
        ];
    }

    /** The trial is over and nothing is paid: billingTier() is `none`. */
    public function noPlan(): static
    {
        return $this->state(['trial_ends_at' => now()->subDay()]);
    }

    /** Never started a trial (a fresh signup). */
    public function newSignup(): static
    {
        return $this->state(['trial_ends_at' => null]);
    }
}
