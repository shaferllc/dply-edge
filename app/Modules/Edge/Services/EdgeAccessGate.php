<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EdgeAccessGate
{
    /**
     * @param  list<string>  $allowedEmails
     */
    public function sync(
        Site $site,
        string $mode,
        ?string $password = null,
        array $allowedEmails = [],
    ): EdgeSiteAccessRule {
        $parent = $this->parentSiteForGate($site);
        if ($site->isEdgePreview()) {
            throw ValidationException::withMessages([
                'edge_preview_protection_mode' => __('Configure preview protection on the parent Edge site.'),
            ]);
        }

        if (! in_array($mode, [
            EdgeSiteAccessRule::MODE_OFF,
            EdgeSiteAccessRule::MODE_PASSWORD,
            EdgeSiteAccessRule::MODE_DPLY_ACCOUNT,
        ], true)) {
            throw ValidationException::withMessages([
                'edge_preview_protection_mode' => __('Invalid preview protection mode.'),
            ]);
        }

        $rule = EdgeSiteAccessRule::query()->firstOrNew(['site_id' => $parent->id]);
        if (! $rule->exists) {
            $rule->cookie_secret = Str::random(48);
        }

        $previousMode = (string) ($rule->mode ?? EdgeSiteAccessRule::MODE_OFF);
        $rule->mode = $mode;
        $rule->allowed_emails = $this->normalizeEmails($allowedEmails);

        if ($mode === EdgeSiteAccessRule::MODE_PASSWORD) {
            if ($password !== null && trim($password) !== '') {
                $salt = bin2hex(random_bytes(16));
                $rule->password_salt = $salt;
                $rule->password_verifier = hash('sha256', $salt.trim($password));
                $rule->password_hash = $password;
                $rule->cookie_secret = Str::random(48);
            } elseif (! $rule->exists || $previousMode !== EdgeSiteAccessRule::MODE_PASSWORD) {
                throw ValidationException::withMessages([
                    'edge_preview_protection_password' => __('Enter a password to enable preview protection.'),
                ]);
            }
        } else {
            $rule->password_hash = null;
            $rule->password_salt = null;
            $rule->password_verifier = null;
        }

        if ($mode === EdgeSiteAccessRule::MODE_OFF) {
            $rule->password_hash = null;
            $rule->password_salt = null;
            $rule->password_verifier = null;
            $rule->allowed_emails = null;
        }

        $rule->save();

        $this->republish($parent);

        return $rule->refresh();
    }

    public function ruleForSite(Site $site): ?EdgeSiteAccessRule
    {
        $parent = $this->parentSiteForGate($site);
        $rule = EdgeSiteAccessRule::query()->where('site_id', $parent->id)->first();

        return $rule !== null && $rule->isEnabled() ? $rule : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function kvPayloadForSite(Site $site): ?array
    {
        $rule = $this->ruleForSite($site);
        if ($rule === null) {
            return null;
        }

        $parent = $this->parentSiteForGate($site);
        $appUrl = rtrim((string) config('app.url'), '/');

        $payload = [
            'mode' => $rule->mode,
            'site_id' => (string) $parent->id,
            'cookie_secret' => $rule->cookie_secret,
            'app_url' => $appUrl,
        ];

        if ($rule->mode === EdgeSiteAccessRule::MODE_PASSWORD) {
            $payload['password_salt'] = $rule->password_salt;
            $payload['password_verifier'] = $rule->password_verifier;
        }

        if ($rule->mode === EdgeSiteAccessRule::MODE_DPLY_ACCOUNT) {
            $emails = $rule->normalizedAllowedEmails();
            if ($emails !== []) {
                $payload['allowed_emails'] = $emails;
            }
            if ($appUrl !== '') {
                $payload['account_login_url'] = $appUrl.'/projects/sites/'.$parent->id.'/preview-access';
            }
        }

        return $payload;
    }

    public function republish(Site $site): void
    {
        $parent = $this->parentSiteForGate($site);
        if ($parent->isEdgePreview()) {
            return;
        }

        $this->republishSiteHostMap($parent);

        foreach (CreateEdgePreviewSite::listForParent($parent) as $preview) {
            $this->republishSiteHostMap($preview);
        }
    }

    /**
     * Re-publish KV host maps for every parent Edge site with preview
     * protection enabled. Used after worker deploy so access_gate
     * payloads reach every preview hostname and deploy alias.
     */
    public function republishAllProtectedSites(): int
    {
        $count = 0;

        EdgeSiteAccessRule::query()
            ->where('mode', '!=', EdgeSiteAccessRule::MODE_OFF)
            ->with('site')
            ->each(function (EdgeSiteAccessRule $rule) use (&$count): void {
                $site = $rule->site;
                if ($site === null || ! $site->usesEdgeRuntime() || $site->isEdgePreview()) {
                    return;
                }

                $this->republish($site);
                $count++;
            });

        return $count;
    }

    public function userMayAccess(Site $site, string $email): bool
    {
        $rule = $this->ruleForSite($site);
        if ($rule === null || $rule->mode !== EdgeSiteAccessRule::MODE_DPLY_ACCOUNT) {
            return true;
        }

        $allowed = $rule->normalizedAllowedEmails();
        if ($allowed === []) {
            return true;
        }

        return in_array(strtolower(trim($email)), $allowed, true);
    }

    public function parentSiteForGate(Site $site): Site
    {
        if (! $site->isEdgePreview()) {
            return $site;
        }

        $parentId = $site->edgeMeta()['preview_parent_site_id'] ?? null;
        if (! is_string($parentId) || $parentId === '') {
            return $site;
        }

        $parent = Site::query()->find($parentId);

        return $parent ?? $site;
    }

    private function republishSiteHostMap(Site $site): void
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            $deployment = EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('published_at')
                ->first();
        } else {
            $deployment = EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->find($activeId);
        }

        if ($deployment === null || $deployment->status !== EdgeDeployment::STATUS_LIVE) {
            return;
        }

        app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
    }

    /**
     * @param  list<string>  $allowedEmails
     * @return list<string>
     */
    private function normalizeEmails(array $allowedEmails): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($email): string => (strtolower(trim($email))),
            $allowedEmails,
        ))));
    }
}
