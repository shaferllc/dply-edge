<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocsSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Head\Enums\OgType;
use Laravel\Head\Facades\Head;
use Laravel\Head\Facades\Schema;

/**
 * Public docs (/docs). Content and nav live in docs/site/; see DocsSite.
 */
class DocsController extends Controller
{
    public function __construct(private readonly DocsSite $docs) {}

    public function show(string $slug): View
    {
        $page = $this->docs->page($slug) ?? abort(404);
        $section = collect($this->docs->nav())->firstWhere('title', $page['section']);

        Head::title($page['title'].' · '.__('Docs'))
            ->description($page['description'] ?: (Str::limit($page['text'], 155) ?: __('dply documentation: :title.', ['title' => $page['title']])))
            ->canonical(route('docs.show', $page['slug']))
            ->og(type: OgType::Article)
            ->when(! $page['exists'], fn ($head) => $head->robots('noindex'))
            ->schema(Schema::breadcrumbs()->items(array_filter([
                __('Docs') => route('docs.index'),
                // A section has no page of its own: link its first page.
                $page['section'] => isset($section['pages'][0]) ? route('docs.show', $section['pages'][0]['slug']) : null,
                $page['title'] => route('docs.show', $page['slug']),
            ])));

        return view('docs.show', [
            'page' => $page,
            'nav' => $this->docs->nav(),
            'neighbours' => $this->docs->neighbours($slug),
        ]);
    }

    public function markdown(string $slug): Response
    {
        $markdown = $this->docs->markdown($slug) ?? abort(404);

        return response($markdown, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    public function llms(): Response
    {
        return response($this->docs->llmsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function search(): JsonResponse
    {
        return response()->json($this->docs->searchIndex())
            ->header('Cache-Control', 'public, max-age=300');
    }
}
