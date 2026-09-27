<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Models\EdgeDeployment;
use App\Models\EdgeFormSubmission;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveProductAddons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Receives Edge Forms submissions forwarded by the Worker after it has run the
 * honeypot / Turnstile checks. The body is HMAC-signed with a per-app key
 * (keyFor) that only that app's host-map entry carries. The inbox is looked up
 * from the app's own forms config, never taken from the payload, so a leaked
 * key cannot turn this into a mail relay.
 */
final class EdgeFormIngestController
{
    private const MAX_BODY_BYTES = 64 * 1024;

    public static function keyFor(Site $site): string
    {
        return hash_hmac('sha256', 'edge-forms:'.$site->id, (string) config('app.key'));
    }

    public static function ingestUrl(Site $site): ?string
    {
        $base = rtrim((string) (config('edge.log_ingest.base_url') ?: config('dply.public_app_url') ?: config('app.url')), '/');

        return $base !== '' ? $base.'/hooks/edge/'.$site->id.'/forms' : null;
    }

    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (($site->edge_backend ?? '') !== 'dply_edge') {
            return response()->json(['ok' => false, 'error' => 'not_edge'], 404);
        }

        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->json(['ok' => false, 'error' => 'too_large'], 413);
        }

        $signature = (string) $request->header('X-Dply-Edge-Form-Signature', '');
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $raw, self::keyFor($site)), $signature)) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw, true);
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'path' => ['required', 'string', 'max:255'],
            'fields' => ['present', 'array', 'max:50'],
            'fields.*' => ['nullable', 'string', 'max:10000'],
            'submitted_at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 422);
        }

        // Signed bodies are replayable; bound the window.
        $submittedAt = Carbon::parse((string) $payload['submitted_at']);
        if (abs(now()->diffInSeconds($submittedAt)) > 600) {
            return response()->json(['ok' => false, 'error' => 'stale'], 422);
        }

        $path = (string) $payload['path'];
        $toEmail = $this->inboxFor($site, $path);
        if ($toEmail === null) {
            return response()->json(['ok' => false, 'error' => 'unknown_endpoint'], 404);
        }

        /** @var array<string, string> $fields */
        $fields = array_map(fn ($v) => (string) $v, array_filter($payload['fields'], 'is_string', ARRAY_FILTER_USE_KEY));

        EdgeFormSubmission::query()->create([
            'site_id' => $site->id,
            'path' => $path,
            'fields' => $fields,
        ]);

        $siteName = (string) $site->name;
        $siteId = (string) $site->id;
        $lines = ["New form submission on {$siteName}", "Path: {$path}", ''];
        foreach ($fields as $key => $value) {
            $lines[] = $key.': '.$value;
        }
        $body = implode("\n", $lines);

        // Stored already, so mail goes on the queue: SMTP latency must not
        // hold the Worker's request open, and a mail failure loses nothing.
        dispatch(function () use ($body, $toEmail, $siteName, $siteId, $path): void {
            try {
                Mail::raw($body, function ($message) use ($toEmail, $siteName, $path): void {
                    $message->to($toEmail)->subject("[dply Edge] Form: {$siteName} ({$path})");
                });
            } catch (\Throwable $e) {
                Log::warning('edge.form.mail_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);
            }
        });

        return response()->json(['ok' => true]);
    }

    private function inboxFor(Site $site, string $path): ?string
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        $deployment = is_string($activeId) && $activeId !== ''
            ? EdgeDeployment::query()->where('site_id', $site->id)->find($activeId)
            : null;

        $forms = EdgeEffectiveProductAddons::forms($site, $deployment);
        if (! (bool) ($forms['enabled'] ?? false)) {
            return null;
        }

        foreach (is_array($forms['endpoints'] ?? null) ? $forms['endpoints'] : [] as $endpoint) {
            $candidate = trim((string) ($endpoint['path'] ?? ''));
            $candidate = str_starts_with($candidate, '/') ? $candidate : '/'.$candidate;
            $email = trim((string) ($endpoint['to_email'] ?? ''));
            if ($candidate === $path && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }
}
