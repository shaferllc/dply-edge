<?php

declare(strict_types=1);

namespace App\Modules\Edge;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Console\CheckEdgeBuildersCommand;
use App\Modules\Edge\Console\CheckEdgeQueueWorkersCommand;
use App\Modules\Edge\Console\CheckEdgeRealtimeCommand;
use App\Modules\Edge\Console\CheckEdgeRumAlertsCommand;
use App\Modules\Edge\Console\CollectEdgeContainerUsageCommand;
use App\Modules\Edge\Console\CollectEdgeDataUsageCommand;
use App\Modules\Edge\Console\CollectEdgeKvUsageCommand;
use App\Modules\Edge\Console\CollectEdgePlatformUsageCommand;
use App\Modules\Edge\Console\CollectEdgeRealtimeUsageCommand;
use App\Modules\Edge\Console\CollectEdgeUsageCommand;
use App\Modules\Edge\Console\CollectEdgeValkeyUsageCommand;
use App\Modules\Edge\Console\DrainEdgeBuilderCommand;
use App\Modules\Edge\Console\EdgeDoctorCommand;
use App\Modules\Edge\Console\EdgeEnsureBuildDockerCommand;
use App\Modules\Edge\Console\EdgeEnsureDeliveryFeaturesCommand;
use App\Modules\Edge\Console\EdgeEnsureGithubPreviewsCommand;
use App\Modules\Edge\Console\EdgeEnsureHybridOriginsCommand;
use App\Modules\Edge\Console\EdgeInfraBootstrapCommand;
use App\Modules\Edge\Console\EdgeInfraBootstrapOrgCommand;
use App\Modules\Edge\Console\EdgeSpikeWorkerBindingsCommand;
use App\Modules\Edge\Console\EdgeWorkerDeployCommand;
use App\Modules\Edge\Console\EnsureEdgeLogpushCommand;
use App\Modules\Edge\Console\EvaluateEdgeGuardrailsCommand;
use App\Modules\Edge\Console\MigrateEdgeHostnamesCommand;
use App\Modules\Edge\Console\MoveRedisToValkeyCommand;
use App\Modules\Edge\Console\PruneEdgeAnalyticsCommand;
use App\Modules\Edge\Console\PublishEdgeBaseImagesCommand;
use App\Modules\Edge\Console\ReapStuckEdgeBuildsCommand;
use App\Modules\Edge\Console\ResizeEdgeDatabasesCommand;
use App\Modules\Edge\Console\RollupEdgeAnalyticsEngineCommand;
use App\Modules\Edge\Console\MeasureWakeTimeCommand;
use App\Modules\Edge\Console\SampleContainerMemoryCommand;
use App\Modules\Edge\Console\SampleEdgeDatabasesCommand;
use App\Modules\Edge\Console\ScaleEdgeQueueWorkersCommand;
use App\Modules\Edge\Console\SelfBootstrapCommand;
use App\Modules\Edge\Console\SelfDeployCommand;
use App\Modules\Edge\Console\SelfRegisterCommand;
use App\Modules\Edge\Console\WarmEdgeBuildImagesCommand;
use App\Modules\Edge\Console\WarmEdgeContainersCommand;
use App\Modules\Edge\Livewire\BuildJourney;
use App\Modules\Edge\Livewire\BuildLogStream;
use App\Modules\Edge\Livewire\Create;
use App\Modules\Edge\Livewire\Import;
use App\Modules\Edge\Livewire\Index;
use App\Modules\Edge\Livewire\Templates;
use App\Modules\Edge\Livewire\Usage;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/**
 * Edge module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The dply Edge product line, extracted in phases. Re-registers the edge commands
 * here (several are scheduled in DplySchedule via repointed refs). Edge jobs
 * dispatch by class. Full-page/embedded Livewire/Edge components are registered in
 * boot() (phase 2b). Edge controllers/middleware are referenced by ::class in
 * routes/bootstrap (phase 2c). Edge models stay in app/Models; Sites/Edge workspace
 * tabs + edge-support in Sites/Servers namespaces stay in the shell.
 */
class EdgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckEdgeRumAlertsCommand::class,
                CollectEdgeContainerUsageCommand::class,
                CollectEdgeDataUsageCommand::class,
                CollectEdgeKvUsageCommand::class,
                CollectEdgePlatformUsageCommand::class,
                CollectEdgeRealtimeUsageCommand::class,
                CollectEdgeValkeyUsageCommand::class,
                ReapStuckEdgeBuildsCommand::class,
                MoveRedisToValkeyCommand::class,
                CollectEdgeUsageCommand::class,
                EdgeDoctorCommand::class,
                EdgeSpikeWorkerBindingsCommand::class,
                EdgeEnsureBuildDockerCommand::class,
                EdgeEnsureDeliveryFeaturesCommand::class,
                EdgeEnsureGithubPreviewsCommand::class,
                EdgeEnsureHybridOriginsCommand::class,
                EdgeInfraBootstrapCommand::class,
                EdgeInfraBootstrapOrgCommand::class,
                EdgeWorkerDeployCommand::class,
                EnsureEdgeLogpushCommand::class,
                EvaluateEdgeGuardrailsCommand::class,
                MigrateEdgeHostnamesCommand::class,
                PruneEdgeAnalyticsCommand::class,
                PublishEdgeBaseImagesCommand::class,
                RollupEdgeAnalyticsEngineCommand::class,
                WarmEdgeBuildImagesCommand::class,
                WarmEdgeContainersCommand::class,
                ScaleEdgeQueueWorkersCommand::class,
                SampleContainerMemoryCommand::class,
                MeasureWakeTimeCommand::class,
                CheckEdgeQueueWorkersCommand::class,
                CheckEdgeRealtimeCommand::class,
                SampleEdgeDatabasesCommand::class,
                ResizeEdgeDatabasesCommand::class,
                CheckEdgeBuildersCommand::class,
                DrainEdgeBuilderCommand::class,
                SelfBootstrapCommand::class,
                SelfDeployCommand::class,
                SelfRegisterCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Feature flags for resource kinds: off until turned on per organization.
        foreach (EdgeContainerConnections::FLAGGED as $kind) {
            Feature::define(EdgeContainerConnections::flag($kind), static fn (Organization $organization): bool => false);
        }
        Livewire::component('edge.index', Index::class);
        Livewire::component('edge.create', Create::class);
        Livewire::component('edge.import', Import::class);
        Livewire::component('edge.templates', Templates::class);
        Livewire::component('edge.usage', Usage::class);
        Livewire::component('edge.build-journey', BuildJourney::class);
        Livewire::component('edge.build-log-stream', BuildLogStream::class);

        // One report per AI / browser / vector call (EdgeMeter), per app.
        // Workers share egress IPs, so never by IP.
        RateLimiter::for('edge-meter', function (Request $request) {
            $site = $request->route('site');

            return Limit::perMinute(20_000)->by('edge-meter:'.($site instanceof Site ? $site->id : $request->ip()));
        });
    }
}
