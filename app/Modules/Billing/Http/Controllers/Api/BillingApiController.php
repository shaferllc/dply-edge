<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Services\BillingAnalytics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingApiController extends Controller
{
    public function show(Request $request, BillingAnalytics $analytics): JsonResponse
    {
        $organization = $this->authorizedOrganization($request);

        return response()->json([
            'data' => $analytics->apiSummary($organization),
        ]);
    }

    public function breakdown(Request $request, BillingAnalytics $analytics): JsonResponse
    {
        $organization = $this->authorizedOrganization($request);

        return response()->json([
            'data' => $analytics->apiBreakdown($organization),
        ]);
    }

    public function invoices(Request $request, BillingAnalytics $analytics): JsonResponse
    {
        $organization = $this->authorizedOrganization($request);

        return response()->json([
            'data' => $analytics->apiInvoices($organization),
        ]);
    }

    protected function authorizedOrganization(Request $request): Organization
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        if (! $organization->hasAdminAccess($user)) {
            throw new AuthorizationException(__('Org admin access is required to view billing.'));
        }

        return $organization;
    }
}
