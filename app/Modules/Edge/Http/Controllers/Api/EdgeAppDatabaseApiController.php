<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\DplyDatabase;
use App\Modules\Edge\Services\DplyDatabaseActions;
use App\Modules\Edge\Services\DplyDatabases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * An app's dply databases (Postgres, MySQL, MongoDB) for API tokens and the
 * CLI: list, show (with export and restore progress), a read-only query,
 * exports, and a point-in-time restore. {database} is its id or name.
 * Creating, attaching, resizing and deleting stay in the dashboard.
 */
class EdgeAppDatabaseApiController extends EdgeApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        return response()->json(['data' => DplyDatabases::for($found)->map(fn (DplyDatabase $db) => $this->payload($db))->values()]);
    }

    public function show(Request $request, string $site, string $database): JsonResponse
    {
        return $this->withDatabase($request, $site, $database, fn (DplyDatabase $db) => response()->json(['data' => $this->payload($db)]));
    }

    public function query(Request $request, string $site, string $database): JsonResponse
    {
        return $this->withDatabase($request, $site, $database, function (DplyDatabase $db) use ($request): JsonResponse {
            $data = $request->validate([
                'sql' => ['nullable', 'string', 'max:100000'],
                'collection' => ['nullable', 'string', 'max:255'],
                'filter' => ['nullable', 'string', 'max:100000'],
            ]);
            if ($db->engine === 'mongodb' ? blank($data['collection'] ?? null) : blank($data['sql'] ?? null)) {
                return response()->json(['message' => $db->engine === 'mongodb' ? 'Pass a collection (and an optional JSON filter).' : 'Pass sql.'], 422);
            }

            return response()->json(['data' => DplyDatabaseActions::query($db, (string) ($data['sql'] ?? ''), (string) ($data['collection'] ?? ''), (string) ($data['filter'] ?? ''))]);
        });
    }

    public function exports(Request $request, string $site, string $database): JsonResponse
    {
        return $this->withDatabase($request, $site, $database, fn (DplyDatabase $db) => response()->json(['data' => DplyDatabaseActions::exports($db)]));
    }

    public function export(Request $request, string $site, string $database): JsonResponse
    {
        return $this->withDatabase($request, $site, $database, function (DplyDatabase $db): JsonResponse {
            DplyDatabaseActions::export($db);

            return response()->json(['data' => $this->payload($db)], 202);
        });
    }

    public function restore(Request $request, string $site, string $database): JsonResponse
    {
        return $this->withDatabase($request, $site, $database, function (DplyDatabase $db) use ($request): JsonResponse {
            $data = $request->validate(['at' => ['required', 'string', 'max:64']]);
            DplyDatabaseActions::restore($db, $data['at']);

            return response()->json(['data' => $this->payload($db)], 202);
        });
    }

    /** @param \Closure(DplyDatabase): JsonResponse $then */
    private function withDatabase(Request $request, string $site, string $database, \Closure $then): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }
        $db = DplyDatabases::find($found, $database);
        if ($db === null) {
            return $this->notFound('Database not found on this app.');
        }
        try {
            return $then($db);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['message' => 'The database did not answer: '.$e->getMessage()], 502);
        }
    }

    /** @return array<string, mixed> */
    private function payload(DplyDatabase $db): array
    {
        $record = DplyDatabases::record($db);

        return [
            'id' => $db->id,
            'name' => $db->name,
            'engine' => $db->engine,
            'primary' => (bool) $db->attached_primary,
            // DB_HOST, DATABASE_URL … for the primary; ANALYTICS_DB_HOST … for the others.
            'env_prefix' => $db->attached_primary ? '' : (string) $db->attached_env_name,
            'host' => $db->host,
            'region' => $db->region,
            'size' => $db->size,
            'suspend_seconds' => $db->suspend,
            'disk_gb' => $db->disk_gb,
            'last_backup_at' => $record['backup']['last_ok_at'] ?? null,
            'transfer' => $record['transfer'] ?? null,
            'restore' => $record['restore'] ?? null,
        ];
    }
}
