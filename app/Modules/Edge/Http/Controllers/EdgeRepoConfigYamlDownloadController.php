<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeRepoConfigYamlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * One-click "Generate dply.yaml" download. Session-authed so the
 * dashboard button works without a minted API token.
 */
class EdgeRepoConfigYamlDownloadController extends Controller
{
    public function __invoke(Request $request, Site $site, EdgeRepoConfigYamlGenerator $generator): Response
    {
        Gate::authorize('view', $site);
        if (! $site->usesEdgeRuntime()) {
            abort(404, 'Not an Edge site.');
        }

        $yaml = $generator->forSite($site);

        $inline = (bool) $request->query('inline');
        $headers = [
            'Content-Type' => 'text/yaml; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
        ];
        if (! $inline) {
            $headers['Content-Disposition'] = 'attachment; filename="dply.yaml"';
        }

        return response($yaml, 200, $headers);
    }
}
