<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAccessGate;
use App\Modules\Edge\Services\EdgeAccessTokenIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EdgePreviewAccessController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        EdgeAccessGate $gate,
        EdgeAccessTokenIssuer $issuer,
    ): RedirectResponse {
        Gate::authorize('view', $site);

        $parent = $gate->parentSiteForGate($site);
        $rule = $gate->ruleForSite($parent);
        if ($rule === null || $rule->mode !== EdgeSiteAccessRule::MODE_DPLY_ACCOUNT) {
            abort(404);
        }

        $user = $request->user();
        if ($user === null) {
            return redirect()->guest(route('login', [
                'return' => $request->fullUrl(),
            ]));
        }

        $email = (string) $user->email;
        if (! $gate->userMayAccess($parent, $email)) {
            abort(403, __('Your account is not allowed to view previews for this site.'));
        }

        $hostname = strtolower(trim((string) $request->query('hostname', '')));
        if ($hostname === '') {
            abort(422, __('Missing preview hostname.'));
        }

        $issued = $issuer->issue($parent, $hostname, $user, $rule);
        $completeUrl = 'https://'.$hostname.'/__dply/access/complete?token='.rawurlencode($issued['token']);

        return redirect()->away($completeUrl);
    }
}
