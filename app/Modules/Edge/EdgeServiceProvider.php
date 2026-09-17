<?php

declare(strict_types=1);

namespace App\Modules\Edge;

use App\Modules\Edge\Console\CheckEdgeRumAlertsCommand;
use App\Modules\Edge\Console\CollectEdgeContainerUsageCommand;
use App\Modules\Edge\Console\CollectEdgeUsageCommand;
use App\Modules\Edge\Console\EdgeDoctorCommand;
use App\Modules\Edge\Console\EdgeEnsureBuildDockerCommand;
use App\Modules\Edge\Console\EdgeEnsureDeliveryFeaturesCommand;
use App\Modules\Edge\Console\EdgeEnsureGithubPreviewsCommand;
use App\Modules\Edge\Console\EdgeEnsureHybridOriginsCommand;
use App\Modules\Edge\Console\EdgeInfraBootstrapCommand;
use App\Modules\Edge\Console\EdgeInfraBootstrapOrgCommand;
use App\Modules\Edge\Console\EdgeWorkerDeployCommand;
use App\Modules\Edge\Console\EnsureEdgeLogpushCommand;
use App\Modules\Edge\Console\EvaluateEdgeGuardrailsCommand;
use App\Modules\Edge\Console\MigrateEdgeHostnamesCommand;
use App\Modules\Edge\Console\PruneEdgeAnalyticsCommand;
use App\Modules\Edge\Console\RollupEdgeAnalyticsEngineCommand;
use App\Modules\Edge\Console\WarmEdgeBuildImagesCommand;
use App\Modules\Edge\Livewire\BuildJourney;
use App\Modules\Edge\Livewire\BuildLogStream;
use App\Modules\Edge\Livewire\Create;
use App\Modules\Edge\Livewire\Import;
use App\Modules\Edge\Livewire\Index;
use App\Modules\Edge\Livewire\Templates;
use App\Modules\Edge\Livewire\Usage;
use Illuminate\Support\ServiceProvider;
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
                CollectEdgeUsageCommand::class,
                EdgeDoctorCommand::class,
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
                RollupEdgeAnalyticsEngineCommand::class,
                WarmEdgeBuildImagesCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('edge.index', Index::class);
        Livewire::component('edge.create', Create::class);
        Livewire::component('edge.import', Import::class);
        Livewire::component('edge.templates', Templates::class);
        Livewire::component('edge.usage', Usage::class);
        Livewire::component('edge.build-journey', BuildJourney::class);
        Livewire::component('edge.build-log-stream', BuildLogStream::class);
    }
}
