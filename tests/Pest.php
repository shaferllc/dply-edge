<?php

use Laravel\Pennant\Feature;
use Laravel\Pennant\FeatureManager;
use Tests\Concerns\FakesBackgroundWork;
use Tests\TestCase;

/**
 * Enable Pennant flags for Pest procedural tests. Class-based tests can set
 * WithFeatures::$features instead; Pest files should call this helper.
 */
function usesFeatures(string ...$flags): void
{
    beforeEach(function () use ($flags): void {
        foreach ($flags as $flag) {
            Feature::define($flag, fn (): bool => true);
            // Database store persists the first resolved value; purge any stored
            // false from earlier tests so the new resolver actually wins.
            Feature::purge([$flag]);
        }
        Feature::flushCache();
    });
}

/**
 * Re-bind every config/features.php flag onto the current Pennant store.
 * Needed after switching stores (resolvers are per-driver).
 */
function redefinePennantFeaturesFromConfig(): void
{
    foreach (config('features', []) as $namespace => $flags) {
        if ($namespace === 'beta_bundle' || ! is_array($flags)) {
            continue;
        }

        foreach (array_keys($flags) as $leaf) {
            $name = "{$namespace}.{$leaf}";
            Feature::define($name, fn () => (bool) config("features.{$namespace}.{$leaf}", false));
        }
    }

    Feature::flushCache();
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the application's base TestCase to every test under Feature and Unit
| so converted Pest tests boot the Laravel app exactly as the PHPUnit-class
| tests did. RefreshDatabase and other traits remain opt-in per file.
|
| The `->group()` calls let you slice a run without remembering paths:
| `php artisan test --group=unit`, `--group=feature`, `--group=app`,
| `--group=modules`.
|
*/

pest()->extend(TestCase::class)->group('unit')->in('Unit');

pest()->extend(TestCase::class)
    ->use(FakesBackgroundWork::class)
    ->group('feature')
    ->in('Feature');

/*
| Tests that live next to the code they cover — the Actions framework
| (app/Actions/**\/{Tests,tests}) and each module's own suite
| (app/Modules/<Domain>/Tests), wired into the `App` / `Modules` testsuites
| in phpunit.xml.
|
| Only tagged here, not extended: those files declare their own
| `uses(TestCase::class)` (or extend it as classes), and a second binding
| from this file throws TestCaseAlreadyInUse.
*/

pest()->group('app')->in('../app/Actions');

pest()->group('modules')->in('../app/Modules');

// Arch tests parse app/ instead of booting it, so they bind no TestCase.
pest()->group('arch')->in('Arch');

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| @see https://pestphp.com/docs/tia
|
| The first run records a dependency graph (via pcov, already installed);
| later runs re-execute only the tests your changes touched and replay the
| rest from cache. Replayed runs report coverage identically to a full run,
| which is what makes `--coverage` usable here at all — a cold full-suite
| coverage run over this codebase takes ~40 minutes.
|
| `locally()` keeps it off in CI, per Pest's guidance: the graph is a local
| development accelerator, and .github/workflows/tests.yml must keep running
| the suite in full.
|
| Re-record after a structural change with:  ./vendor/bin/pest --tia --fresh
| Storage directory (~/.pest/tia/<key>):     ./vendor/bin/pest --baseline
*/
pest()->tia()->locally();

/*
| Unit tests often skip RefreshDatabase. Pennant's default database store
| queries the features table before the config resolver — use the in-memory
| array store so Feature::active works without migrations. Feature tests keep
| the database store so per-org activate/deactivate overrides still persist.
|
| forgetInstance is required: Laravel may have already resolved the manager
| against the previous default during app boot / an earlier test.
*/
beforeEach(function (): void {
    config(['pennant.default' => 'array']);
    app()->forgetInstance(FeatureManager::class);
    Feature::clearResolvedInstances();
    redefinePennantFeaturesFromConfig();
})->in('Unit');

beforeEach(function (): void {
    config(['pennant.default' => 'database']);
    app()->forgetInstance(FeatureManager::class);
    Feature::clearResolvedInstances();
    // Boot-time FeatureServiceProvider definitions lived on the previous
    // manager instance — re-bind every config flag or Feature::active() falls
    // through undefined after forgetInstance (flaky page/Livewire aborts).
    redefinePennantFeaturesFromConfig();
    Feature::resolveScopeUsing(fn () => auth()->user()?->currentOrganization());
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Domain Groups
|--------------------------------------------------------------------------
|
| Layer groups (unit / feature / app / modules) answer "which tier?".
| These answer "which part of the product?" so a run can be sliced down to
| the surface being worked on:
|
|   php artisan test --group=servers
|   php artisan test --group=sites --group=deploy      # union of both
|   php artisan test --group=billing --exclude-group=livewire
|
| Groups are additive: a file matching several tokens joins several groups,
| and every file keeps its layer group too. `DOMAIN_GROUPS` below maps a
| group name to filename tokens; `DOMAIN_GROUP_DIRS` adds whole directories
| whose filenames don't carry the token (tests/Feature/Sites/BindingTest.php
| is a `sites` test even though "Site" isn't in the name).
|
| Only 361 of the 632 Feature files live in a subdirectory, so filename
| tokens — not directories — are what actually carry the grouping here.
|
*/

const DOMAIN_GROUPS = [
    'servers' => ['Server', 'Droplet', 'Provision', 'Hetzner'],
    'sites' => ['Site'],
    'deploy' => ['Deploy'],
    'cloud' => ['Cloud'],
    'edge' => ['Edge'],
    'serverless' => ['Serverless'],
    'billing' => ['Billing', 'Subscription', 'Stripe', 'Usage'],
    'taskrunner' => ['TaskRunner', 'RemoteTask', 'RemoteCli'],
    'ssh' => ['Ssh'],
    'queue' => ['Queue', 'Job'],
    'console' => ['Console', 'Cli'],
    'api' => ['Api'],
    'auth' => ['Auth'],
    'admin' => ['Admin'],
    'imports' => ['Import'],
    'insights' => ['Insight', 'Metric'],
    'backups' => ['Backup'],
    'snapshots' => ['Snapshot'],
    'certificates' => ['Certificate', 'Ssl'],
    'logs' => ['Log'],
    'notifications' => ['Notification', 'Telegram', 'Slack', 'Discord'],
    'database' => ['Database'],
    'dns' => ['Dns', 'Domain'],
    'webserver' => ['Nginx', 'Caddy', 'Php'],
    'containers' => ['Docker', 'Kubernetes', 'Cluster'],
    'env' => ['Env'],
    'webhooks' => ['Webhook'],
    'organizations' => ['Organization'],
    'scaffold' => ['Scaffold'],
    'roadmap' => ['Roadmap'],
    'sourcecontrol' => ['SourceControl'],
    'marketplace' => ['Marketplace'],
    'realtime' => ['Realtime'],
    'remediations' => ['Remediation'],
    'secrets' => ['Secret'],
    'launch' => ['Launch'],
    'opscopilot' => ['Copilot'],
];

const DOMAIN_GROUP_DIRS = [
    'servers' => ['Feature/Servers'],
    'sites' => ['Feature/Sites'],
    'serverless' => ['Feature/Serverless'],
    'billing' => ['Feature/Billing'],
    'taskrunner' => ['Feature/TaskRunner'],
    'ssh' => ['Feature/SshAccess'],
    'queue' => ['Feature/Queue', 'Feature/Jobs'],
    'console' => ['Feature/Console', 'Feature/Cli'],
    'api' => ['Feature/Api'],
    'auth' => ['Feature/Auth'],
    'admin' => ['Feature/Admin'],
    'imports' => ['Feature/Imports'],
    'insights' => ['Feature/Insights'],
    'backups' => ['Feature/Backups'],
    'notifications' => ['Feature/Notifications'],
    'livewire' => ['Feature/Livewire'],
];

/**
 * Resolve each domain group to the concrete test files it covers.
 *
 * One directory walk, then substring matching in memory. The obvious
 * alternative — handing `in()` a glob per token — costs ~10s on *every* pest
 * invocation here: `in()` runs glob() and realpath() per target, patterns
 * overlap heavily, and glob's `*` does not cross a `/`, so each of the four
 * nesting levels under tests/ needs its own pattern. Walking once and passing
 * exact paths keeps the whole block under ~50ms.
 *
 * @return array<string, list<string>> group name => absolute test file paths
 */
function domainGroupPaths(): array
{
    $groups = [];

    foreach (['Feature', 'Unit'] as $suite) {
        $root = __DIR__.DIRECTORY_SEPARATOR.$suite;

        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $path = $file->getPathname();
            $name = $file->getFilename();
            // Relative to tests/, with forward slashes, to compare against
            // the DOMAIN_GROUP_DIRS prefixes.
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(__DIR__) + 1));

            foreach (DOMAIN_GROUPS as $group => $tokens) {
                foreach ($tokens as $token) {
                    if (str_contains($name, $token)) {
                        $groups[$group][] = $path;

                        continue 2;
                    }
                }
            }

            foreach (DOMAIN_GROUP_DIRS as $group => $dirs) {
                foreach ($dirs as $dir) {
                    if (str_starts_with($relative, $dir.'/')) {
                        $groups[$group][] = $path;

                        continue 2;
                    }
                }
            }
        }
    }

    // A module's own suite joins its domain group as a single directory entry,
    // so `--testsuite=Modules --group=taskrunner` reaches both the shell tests
    // above and app/Modules/TaskRunner/Tests.
    foreach ((array) glob(__DIR__.'/../app/Modules/*/Tests') as $moduleTests) {
        if (is_dir($moduleTests)) {
            $groups[strtolower(basename(dirname($moduleTests)))][] = $moduleTests;
        }
    }

    return array_map(array_unique(...), $groups);
}

/**
 * Whether this run actually cares about groups.
 *
 * Registering the domain groups costs real time on *every* pest invocation:
 * Pest's TestRepository::make() walks the full `uses` map per test case
 * (is_dir() + prefix match per entry), so the ~1,250 file entries below add
 * seconds to a run that never asked for a group. tests/bootstrap.php already
 * reads $argv the same way for --parallel/--coverage.
 *
 * Consequence: `--list-groups` shows only the layer groups unless a group
 * flag is also present. Everything else behaves identically.
 */
function testRunFiltersByGroup(): bool
{
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if (str_starts_with($argument, '--group') || str_starts_with($argument, '--exclude-group')) {
            return true;
        }
    }

    return false;
}

if (testRunFiltersByGroup()) {
    foreach (domainGroupPaths() as $group => $paths) {
        pest()->group($group)->in(...$paths);
    }
}
