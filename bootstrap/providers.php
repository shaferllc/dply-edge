<?php

use App\Modules\Backups\BackupsServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Cache\CacheServiceProvider;
use App\Modules\Certificates\CertificatesServiceProvider;
use App\Modules\Database\DatabaseServiceProvider;
use App\Modules\Deploy\DeployServiceProvider;
use App\Modules\Edge\EdgeServiceProvider;
use App\Modules\Insights\InsightsServiceProvider;
use App\Modules\Logs\LogsServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Queue\QueueServiceProvider;
use App\Modules\Realtime\RealtimeServiceProvider;
use App\Modules\Secrets\SecretVaultServiceProvider;
use App\Modules\Serverless\ServerlessServiceProvider;
use App\Modules\TaskRunner\TaskServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BundleSsoServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LookoutDebugPageServiceProvider;

return [
    TaskServiceProvider::class,
    BackupsServiceProvider::class,
    BillingServiceProvider::class,
    CertificatesServiceProvider::class,
    CacheServiceProvider::class,
    CloudServiceProvider::class,
    DatabaseServiceProvider::class,
    DeployServiceProvider::class,
    LogsServiceProvider::class,
    SnapshotsServiceProvider::class,
    BlogServiceProvider::class,
    DocsServiceProvider::class,
    EdgeServiceProvider::class,
    FeedbackServiceProvider::class,
    ImportsServiceProvider::class,
    InsightsServiceProvider::class,
    LaunchServiceProvider::class,
    MarketplaceServiceProvider::class,
    NotificationsServiceProvider::class,
    OpsCopilotServiceProvider::class,
    ProjectsServiceProvider::class,
    QueueServiceProvider::class,
    RealtimeServiceProvider::class,
    ReferralsServiceProvider::class,
    ServerlessServiceProvider::class,
    RoadmapServiceProvider::class,
    AppServiceProvider::class,
    BundleSsoServiceProvider::class,
    LookoutDebugPageServiceProvider::class,
    FeatureServiceProvider::class,
    HorizonServiceProvider::class,
    SecretVaultServiceProvider::class,
];
