<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Normalizes a failed source (ConsoleAction)
 * into a single {@see ErrorEvent} row. Shared by the model listeners and the
 * backfill command so live capture and historical seeding produce identical rows.
 *
 * Capture is idempotent: keyed on (source_type, source_id), so a listener
 * firing twice — or a backfill re-running — updates in place rather than
 * duplicating.
 */
class ErrorEventRecorder
{
    /** Record a failed ConsoleAction. No-op if it isn't actually failed. */
    public function recordConsoleAction(ConsoleAction $action): ?ErrorEvent
    {
        if ($action->status !== ConsoleAction::STATUS_FAILED) {
            return null;
        }

        $subject = $action->subject;
        [$organizationId, $serverId, $siteId, $link] = $this->resolveOwners($subject);

        // Point "Open" at the section where you can actually act on this kind of
        // error — e.g. a failed connectivity fix lands on the site's Environment
        // section, where the unreachable binding badge + its Fix button live.
        $section = $this->actionSectionForCategory((string) $action->kind);
        if ($section !== null && $serverId && $siteId) {
            $link = route('sites.show', ['server' => $serverId, 'site' => $siteId, 'section' => $section]);
        }

        $detail = trim((string) ($action->error ?? ''));
        if ($detail === '') {
            $detail = $this->lastErrorLine($action);
        }

        return $this->upsert($action, [
            'organization_id' => $organizationId,
            'server_id' => $serverId,
            'site_id' => $siteId,
            'category' => (string) $action->kind,
            'remediation_code' => null,
            'title' => $this->humanTitle((string) ($action->label ?: ''), (string) $action->kind),
            'detail' => $detail !== '' ? Str::limit($detail, 2000, '') : null,
            'link_url' => $link,
            'occurred_at' => $action->finished_at ?? $action->updated_at ?? now(),
        ]);
    }

    /**
     * Record one HTTP 5xx hit swept from a site's PHP-FPM access log (Tier-2 of
     * the server-error-reference feature). Idempotent on the reference: a later
     * sweep that re-sees the same 5xx line refreshes the row rather than
     * duplicating, so an overlapping lookback window is safe.
     *
     * No remediation is matched here — the access log carries the request, not
     * the exception text. The row's link deep-links into the Tier-1 reference
     * resolver, where the operator pulls the actual trace on demand.
     *
     * @param  array{reference: string, status: int, method: string, uri: string, occurred_at: \DateTimeInterface}  $hit
     */
    public function recordHttp5xx(Site $site, array $hit): ?ErrorEvent
    {
        $reference = trim((string) $hit['reference']);
        if ($reference === '' || $site->server_id === null) {
            return null;
        }

        $status = (int) $hit['status'];
        $request = trim($hit['method'].' '.$hit['uri']);
        $link = route('sites.show', [
            'server' => $site->server_id,
            'site' => $site->id,
            'reference' => $reference,
        ]);

        return ErrorEvent::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'category' => 'http_5xx',
                'reference' => $reference,
            ],
            [
                // http_5xx rows have no Eloquent source model (they're swept from
                // the access log), but source_type/source_id are NOT NULL and
                // carry a unique index. Key them on the globally-unique reference
                // so the insert satisfies the constraint and stays idempotent.
                'source_type' => 'http_5xx',
                'source_id' => $reference,
                'organization_id' => $site->organization_id,
                'server_id' => $site->server_id,
                'title' => __('HTTP :status — :request', ['status' => $status, 'request' => Str::limit($request, 120, '')]),
                'detail' => __(':request returned HTTP :status. Reference :ref — resolve it for the trace.', [
                    'request' => Str::limit($request, 300, ''),
                    'status' => $status,
                    'ref' => $reference,
                ]),
                'link_url' => $link,
                'occurred_at' => $hit['occurred_at'],
            ],
        );
    }

    /**
     * Resolve [organization_id, server_id, site_id, link_url] from a
     * ConsoleAction subject. Site-owned subjects carry both site_id and the
     * site's server_id (so they roll up to the server view); infra subjects
     * carry server_id only.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function resolveOwners(?Model $subject): array
    {
        return match (true) {
            $subject instanceof Site => [
                $subject->organization_id,
                $subject->server_id,
                $subject->id,
                $subject->server_id ? route('sites.show', ['server' => $subject->server_id, 'site' => $subject->id, 'section' => 'general']) : null,
            ],
            $subject instanceof Server => [
                $subject->organization_id,
                $subject->id,
                null,
                null,
            ],
            $subject instanceof SiteBinding => [
                $subject->site?->organization_id,
                $subject->site?->server_id,
                $subject->site_id,
                ($subject->site && $subject->site->server_id)
                    ? route('sites.show', ['server' => $subject->site->server_id, 'site' => $subject->site_id, 'section' => 'environment'])
                    : null,
            ],
            default => [null, null, null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(Model $source, array $attributes): ErrorEvent
    {
        return ErrorEvent::query()->updateOrCreate(
            ['source_type' => $source->getMorphClass(), 'source_id' => (string) $source->getKey()],
            $attributes,
        );
    }

    /**
     * The site Settings section where a given error category can be acted on,
     * so "Open" lands on the fix rather than a generic page. Null = keep the
     * subject's default link.
     */
    private function actionSectionForCategory(string $category): ?string
    {
        return match ($category) {
            'binding_connectivity_fix' => 'environment',
            'env_sync', 'env_push', 'env_scan' => 'environment',
            'ssl' => 'certificates',
            'basic_auth_sync' => 'basic-auth',
            default => null,
        };
    }

    /** A clean title: prefer the run's label (strip trailing ellipsis), else humanize the kind. */
    private function humanTitle(string $label, string $kind): string
    {
        $label = trim(preg_replace('/\s*…$/u', '', $label) ?? '');
        if ($label !== '') {
            return Str::limit($label, 160, '');
        }

        return Str::headline(str_replace([':', '.'], ' ', $kind)) ?: __('Operation failed');
    }

    /** Newest error-level line from a ConsoleAction's output, if any. */
    private function lastErrorLine(ConsoleAction $action): string
    {
        $lines = $action->lines();
        foreach (array_reverse($lines) as $line) {
            if ($line['level'] === ConsoleAction::LEVEL_ERROR && trim($line['line']) !== '') {
                return trim($line['line']);
            }
        }

        return '';
    }
}
