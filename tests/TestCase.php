<?php

namespace Tests;

use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\Sites\LinkedOrganizationSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop Postgres composite types alongside tables when RefreshDatabase
     * triggers `migrate:fresh`. Without this, composite types auto-created
     * with each table linger after wipe and the *next* test run collides
     * with "duplicate key value violates unique constraint pg_type_typname_nsp_index".
     */
    protected bool $dropTypes = true;

    /**
     * Roll back the dply Queue data plane between tests, not just the primary
     * connection.
     *
     * `RefreshDatabase` transacts only the default connection, so rows written
     * to `dply_queue` (jobs, locks, failed jobs) survived into the next test.
     * Namespace-scoped queries hid it; anything counting rows outright did
     * not. Listing the connection here restores per-test isolation for every
     * current and future queue test, rather than each one remembering to
     * clean up after itself.
     *
     * `null` is the default connection and must stay first.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [null, 'dply_queue'];

    protected function setUp(): void
    {
        $this->guardAgainstDestructiveDatabaseTarget();

        parent::setUp();

        // Production Livewire actions (e.g. server log tailing) call set_time_limit()
        // with request budgets. That applies to the whole PHPUnit worker, so one
        // test can poison the remaining batch with a 90s cap.
        set_time_limit(0);

        $this->withoutVite();

        // Avoid blocking Livewire tests on SSH; tests that assert queued manage jobs opt in explicitly.
        config(['server_manage.queue_remote_tasks' => false]);

        // Server workspace tabs are #[Lazy]: the first request returns a skeleton
        // placeholder, then a follow-up request hydrates the real body. Feature
        // tests GET these routes and assert on the hydrated content, so disable
        // lazy loading for the whole test process. withoutLazyLoading() resets on
        // each flush-state (per request), so re-arm it once via a flush-state
        // listener — keeps multi-request tests eager too. Production is unaffected.
        Livewire::withoutLazyLoading();
        static $reArmLazyDisable = false;
        if (! $reArmLazyDisable) {
            $reArmLazyDisable = true;
            \Livewire\on('flush-state', static fn () => Livewire::withoutLazyLoading());
        }

        foreach (class_uses_recursive(static::class) as $trait) {
            $hook = 'setUp'.class_basename($trait);
            if (method_exists($this, $hook)) {
                $this->{$hook}();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            $hook = 'tearDown'.class_basename($trait);
            if (method_exists($this, $hook)) {
                $this->{$hook}();
            }
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        try {
            DB::disconnect(config('database.default'));
        } catch (\Throwable) {
            // Best-effort cleanup only.
        }

        LinkedOrganizationSecrets::flushMemo();
        OrganizationBillingStateComputer::flushMemo();
        EdgeOrganizationUsageReader::flushMemo();
        AssignableNotificationChannels::flushMemo();

        parent::tearDown();
    }

    /**
     * RefreshDatabase runs migrate:fresh — refuse to run it against a non-test DB.
     */
    protected function guardAgainstDestructiveDatabaseTarget(): void
    {
        if (! $this->usesRefreshDatabase()) {
            return;
        }

        // Runs before parent::setUp() — use env vars, not config().
        $connection = (string) (getenv('DB_CONNECTION') ?: $_ENV['DB_CONNECTION'] ?? 'pgsql');
        $database = (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? '');

        if ($database === '' && $connection === 'pgsql') {
            $database = 'dply_testing';
        }

        $allowed = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) (getenv('DPLY_TESTING_DATABASES') ?: $_ENV['DPLY_TESTING_DATABASES'] ?? 'dply_testing')),
        )));

        if ($allowed === []) {
            $allowed = ['dply_testing'];
        }

        if (! in_array($database, $allowed, true)) {
            throw new \RuntimeException(sprintf(
                'Refusing to run RefreshDatabase tests against [%s] on connection [%s]. '
                .'Use a dedicated test database (default: dply_testing). '
                .'phpunit.xml sets DB_DATABASE=dply_testing — check .env DB_URL / DB_DATABASE overrides.',
                $database,
                $connection,
            ));
        }
    }

    protected function usesRefreshDatabase(): bool
    {
        return in_array(
            RefreshDatabase::class,
            class_uses_recursive(static::class),
            true,
        );
    }
}
