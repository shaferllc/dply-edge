<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Edge\EdgeServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Secrets\SecretVaultServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LookoutDebugPageServiceProvider;

return [
    BillingServiceProvider::class,
    EdgeServiceProvider::class,
    NotificationsServiceProvider::class,
    AppServiceProvider::class,
    LookoutDebugPageServiceProvider::class,
    HorizonServiceProvider::class,
    SecretVaultServiceProvider::class,
];
