<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Resources;

use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-API representation of a site's preview-protection rule.
 *
 * Constructed with both the {@see Site} (the URL context) and the
 * loaded {@see EdgeSiteAccessRule} (which may be null when no rule
 * has been set) so the payload can stay site-scoped without an
 * extra DB lookup in the controller.
 */
final class EdgeAccessRuleResource extends JsonResource
{
    public function __construct(
        private readonly Site $site,
        ?EdgeSiteAccessRule $rule,
    ) {
        parent::__construct($rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ?EdgeSiteAccessRule $rule */
        $rule = $this->resource;
        $mode = is_string($rule?->mode) ? $rule->mode : EdgeSiteAccessRule::MODE_OFF;
        $enabled = $rule !== null && $rule->isEnabled();
        $appUrl = rtrim((string) config('app.url'), '/');

        $payload = [
            'site_id' => (string) $this->site->id,
            'mode' => $mode,
            'enabled' => $enabled,
            'password_set' => $mode === EdgeSiteAccessRule::MODE_PASSWORD
                && is_string($rule?->password_verifier) && $rule->password_verifier !== '',
            'allowed_emails' => $rule?->normalizedAllowedEmails() ?? [],
        ];

        if ($enabled && $mode === EdgeSiteAccessRule::MODE_DPLY_ACCOUNT && $appUrl !== '') {
            $payload['account_login_url'] = $appUrl.'/projects/sites/'.$this->site->id.'/preview-access';
        }

        return $payload;
    }
}
