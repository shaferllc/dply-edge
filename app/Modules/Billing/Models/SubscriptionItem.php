<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * @property string $id
 * @property string $stripe_price
 * @property ?int $quantity
 *                          Cashier's SubscriptionItem, adapted to dply's ULID-keyed schema. See
 *                          {@see Subscription} for the same fix applied to the parent table.
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use HasUlids;
}
