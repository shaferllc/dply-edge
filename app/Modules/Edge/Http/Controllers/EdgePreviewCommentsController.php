<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgePreviewComment;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read/write endpoints for the dply preview-comment widget. Called
 * cross-origin from the preview hostname; auth via the per-parent
 * `widget_token` injected into the script tag. There are no Laravel
 * sessions involved — every visitor of a preview can use the widget.
 *
 * Token check resolves through the *parent* site's
 * `meta.edge.comment_widget.token`; a request can target any preview
 * descended from that parent.
 */
class EdgePreviewCommentsController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        if (! $this->authorizePreviewToken($request, $site)) {
            return $this->corsJson($request, ['error' => 'unauthorized'], 401);
        }

        $comments = EdgePreviewComment::query()
            ->where('site_id', $site->id)
            ->whereNull('parent_id')
            ->with(['replies' => fn ($q) => $q->orderBy('created_at')])
            ->with(['createdBy:id,name,email', 'resolvedBy:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->flatMap(function (EdgePreviewComment $thread) {
                $rows = [$this->serializeComment($thread)];
                foreach ($thread->replies as $reply) {
                    $rows[] = $this->serializeComment($reply, $thread->id);
                }

                return $rows;
            })
            ->values()
            ->all();

        return $this->corsJson($request, ['comments' => $comments]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        if (! $this->authorizePreviewToken($request, $site)) {
            return $this->corsJson($request, ['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'url_path' => ['required', 'string', 'max:2048', 'regex:#^/[^\s]*$#'],
            'selector' => ['nullable', 'string', 'max:500'],
            'viewport_width' => ['nullable', 'integer', 'min:1', 'max:8192'],
            'author_label' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'string', 'ulid'],
        ]);

        $parentId = isset($data['parent_id']) ? (string) $data['parent_id'] : null;
        if ($parentId !== null && $parentId !== '') {
            $parent = EdgePreviewComment::query()
                ->where('site_id', $site->id)
                ->whereNull('parent_id')
                ->whereKey($parentId)
                ->first();
            if ($parent === null) {
                return $this->corsJson($request, ['error' => 'invalid_parent'], 422);
            }
        } else {
            $parentId = null;
        }

        $comment = EdgePreviewComment::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'parent_id' => $parentId,
            'created_by_user_id' => null,
            'author_label' => trim((string) ($data['author_label'] ?? '')) ?: 'Guest',
            'selector' => $data['selector'] ?? null,
            'viewport_width' => $data['viewport_width'] ?? null,
            'url_path' => $data['url_path'],
            'body' => $data['body'],
        ]);

        return $this->corsJson($request, [
            'comment' => $this->serializeComment($comment),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(EdgePreviewComment $c, ?string $parentId = null): array
    {
        return [
            'id' => $c->id,
            'parent_id' => $parentId ?? $c->parent_id,
            'author' => $c->authorDisplayName(),
            'body' => $c->body,
            'url_path' => $c->url_path,
            'selector' => $c->selector,
            'viewport_width' => $c->viewport_width,
            'resolved' => $c->resolved_at !== null,
            'created_at' => $c->created_at->toIso8601String(),
        ];
    }

    /**
     * CORS preflight responder. Mirrors the request's Origin so the
     * widget can talk to dply from any preview hostname without us
     * needing to enumerate them at config time.
     */
    public function options(Request $request, Site $site): Response
    {
        return $this->corsResponse($request, new Response('', 204));
    }

    private function authorizePreviewToken(Request $request, Site $site): bool
    {
        $token = trim((string) $request->header('X-Dply-Preview-Widget'));
        if ($token === '') {
            return false;
        }

        // The widget token lives on the *parent* site's meta. For a
        // request hitting a preview's site id, look up the parent and
        // compare. For a request hitting the parent directly (rare —
        // the widget only runs on previews) we compare directly.
        $widgetMeta = is_array($site->edgeMeta()['comment_widget'] ?? null)
            ? $site->edgeMeta()['comment_widget']
            : [];
        if (hash_equals((string) ($widgetMeta['token'] ?? ''), $token)) {
            return (bool) ($widgetMeta['enabled'] ?? false);
        }

        $parentId = $site->edgeMeta()['preview_parent_site_id'] ?? null;
        if (is_string($parentId)) {
            $parent = Site::query()->find($parentId);
            if ($parent !== null) {
                $parentMeta = is_array($parent->edgeMeta()['comment_widget'] ?? null)
                    ? $parent->edgeMeta()['comment_widget']
                    : [];

                return hash_equals((string) ($parentMeta['token'] ?? ''), $token)
                    && (bool) ($parentMeta['enabled'] ?? false);
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function corsJson(Request $request, array $data, int $status = 200): JsonResponse
    {
        $response = response()->json($data, $status);

        return $this->corsResponse($request, $response);
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function corsResponse(Request $request, Response $response): Response
    {
        $origin = (string) $request->header('Origin');
        // Echo the Origin so credentials-bearing requests work, but
        // never wildcard — preview hostnames are well-known per
        // installation (e.g. *.dply.host) and echoing keeps the
        // browser strict-mode check happy.
        if ($origin !== '' && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Dply-Preview-Widget');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        foreach ((array) config('edge.testing_domains', []) as $apex) {
            $apex = strtolower((string) $apex);
            if ($apex !== '' && (str_ends_with($host, '.'.$apex) || $host === $apex)) {
                return true;
            }
        }

        return false;
    }
}
