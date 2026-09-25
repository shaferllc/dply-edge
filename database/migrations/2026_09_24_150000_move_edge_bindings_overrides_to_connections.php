<?php

use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * One resource store (ruling r-72p0gkdn9dqwxqha, T-017): dashboard bindings
 * move from `meta.edge.bindings_overrides` into `meta.edge.connections`, the
 * list the Resources page manages. Nothing reads `bindings_overrides` after
 * this.
 *
 * A row that cannot be carried over (a reserved name, or a name the
 * Resources page rejects) is logged and parked under
 * `bindings_overrides_unmigrated`, never silently dropped.
 *
 * Idempotent: a site with no `bindings_overrides` is skipped. `down()` is a
 * no-op because the rows now live in `connections` and may have changed.
 */
return new class extends Migration
{
    private const KINDS = ['kv' => 'key_value', 'r2' => 'object_storage', 'd1' => 'sql', 'queue' => 'queue'];

    public function up(): void
    {
        Site::query()
            ->whereRaw("meta->'edge'->'bindings_overrides' IS NOT NULL")
            ->orderBy('id')
            ->chunkById(200, function ($sites): void {
                foreach ($sites as $site) {
                    $rows = $site->edgeMeta()['bindings_overrides'] ?? null;
                    if (! is_array($rows)) {
                        continue;
                    }
                    $taken = array_column(EdgeContainerConnections::for($site), 'name');
                    $left = [];
                    foreach ($rows as $row) {
                        $kind = self::KINDS[$row['kind'] ?? ''] ?? null;
                        $name = trim((string) ($row['name'] ?? ''));
                        $value = trim((string) ($row['value'] ?? ''));
                        if ($kind === null || $name === '' || $value === '') {
                            continue;
                        }
                        if (in_array($name, $taken, true)
                            || EdgeContainerConnections::attach($site, $kind, $name, $value) !== null) {
                            $left[] = $row;

                            continue;
                        }
                        $taken[] = $name;
                    }
                    if ($left !== []) {
                        Log::warning('Edge bindings not moved to connections', ['site_id' => $site->id, 'rows' => $left]);
                    }
                    $site->refresh();
                    $site->mergeEdgeMeta(['bindings_overrides' => null, 'bindings_overrides_unmigrated' => $left !== [] ? $left : null]);
                    $site->save();
                }
            });
    }

    public function down(): void
    {
        //
    }
};
