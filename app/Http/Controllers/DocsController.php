<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocsSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public docs (/docs). Content and nav live in docs/site/; see DocsSite.
 */
class DocsController extends Controller
{
    public function __construct(private readonly DocsSite $docs) {}

    public function show(string $slug): View
    {
        $page = $this->docs->page($slug) ?? abort(404);

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
