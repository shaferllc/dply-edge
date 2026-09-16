<?php

namespace App\Modules\Billing\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * @property string $id
 * @property string $stripe_status
 * @property ?Carbon $ends_at
 * @property ?Carbon $trial_ends_at
 *                                  Cashier's Subscription model, adapted to dply's ULID-keyed schema. The
 *                                  subscriptions table stores `id` as character(26) (see pgsql-schema.sql),
 *                                  and Cashier's default Subscription is incrementing-int — which causes
 *                                  the eager-loaded items relation to compare ULID strings against an int
 *                                  cast of the parent key. {@see HasUlids} fixes the key type and disables
 *                                  incrementing in one move.
 */
class Subscription extends CashierSubscription
{
    use HasUlids;

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
