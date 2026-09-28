<?php

namespace App\Livewire\Organizations;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org overview, "needs attention first" (redesign 2026-09-27, option B).
 * Every check is a real query: failing it lists an attention item, passing
 * it lists an "all clear" line. Nothing is shown that isn't backed by data.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    /** Invites older than this many days are flagged. */
    private const STALE_INVITE_DAYS = 3;

    /** API tokens unused for this many days are flagged. */
    private const IDLE_TOKEN_DAYS = 60;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        Head::title($organization->name);
        $this->organization = $organization->load([
            'users',
            'teams',
            'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
            'apiTokens',
            'notificationChannels',
        ]);
    }

    public function render(): View
    {
        $isAdmin = $this->organization->hasAdminAccess(auth()->user());
        $apps = $this->apps();
        [$attention, $clear] = $this->checks($apps, $isAdmin);

        return view('livewire.organizations.show', [
            'isAdmin' => $isAdmin,
            'attention' => $attention,
            'clear' => $clear,
            'appCount' => $apps->count(),
            'usage' => $isAdmin ? $this->usage() : null,
        ]);
    }

    /** @return Collection<int, Site> live, non-preview edge apps */
    private function apps(): Collection
    {
        return $this->organization->sites()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge')
            ->get()
            ->reject(fn (Site $site): bool => $site->isEdgePreview())
            ->values();
    }

    /**
     * @param  Collection<int, Site>  $apps
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string>>}
     */
    private function checks(Collection $apps, bool $isAdmin): array
    {
        $org = $this->organization;
        $attention = [];
        $clear = [];

        // Apps whose most recent deploy failed.
        $latest = EdgeDeployment::query()
            ->whereIn('site_id', $apps->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->get(['id', 'site_id', 'status', 'created_at'])
            ->unique('site_id');
        $failed = $latest->where('status', EdgeDeployment::STATUS_FAILED);
        foreach ($failed as $deploy) {
            $site = $apps->firstWhere('id', $deploy->site_id);
            if ($site === null) {
                continue;
            }
            $attention[] = [
                'tone' => 'warn',
                'title' => __('Deploy failed on :app', ['app' => $site->name]),
                'detail' => __(':when · the previous deploy is still live', ['when' => $deploy->created_at?->diffForHumans()]),
                'action' => __('View deploy'),
                'href' => route('sites.show', ['site' => $site->id, 'section' => 'deploys']),
            ];
        }
        if ($apps->isNotEmpty() && $failed->isEmpty()) {
            $clear[] = ['label' => __('Deploys healthy'), 'sub' => trans_choice(':n app|:n apps', $apps->count(), ['n' => $apps->count()])];
        }

        if ($isAdmin) {
            if ($org->notificationChannels->isEmpty()) {
                $attention[] = [
                    'tone' => 'warn',
                    'title' => __('Nobody hears about failures'),
                    'detail' => __('No notification channels. Deploy failures and billing alerts only show here.'),
                    'action' => __('Add a channel'),
                    'href' => route('organizations.notification-channels', $org),
                ];
            } else {
                $clear[] = ['label' => __('Alerts routed'), 'sub' => trans_choice(':n channel|:n channels', $org->notificationChannels->count(), ['n' => $org->notificationChannels->count()])];
            }

            $staleInvites = $org->invitations->filter(fn ($i) => $i->created_at?->lt(now()->subDays(self::STALE_INVITE_DAYS)));
            if ($staleInvites->isNotEmpty()) {
                $first = $staleInvites->first();
                $attention[] = [
                    'tone' => 'muted',
                    'title' => $staleInvites->count() === 1
                        ? __('Invite to :email pending for :days days', ['email' => $first->email, 'days' => (int) $first->created_at->diffInDays(now())])
                        : __(':n invites pending for more than :days days', ['n' => $staleInvites->count(), 'days' => self::STALE_INVITE_DAYS]),
                    'detail' => __('Invites expire after 7 days.'),
                    'action' => __('Review invites'),
                    'href' => route('organizations.members', ['organization' => $org, 'filter' => 'pending']),
                ];
            }

            $idle = $org->apiTokens->whereNull('revoked_at')->filter(
                fn ($t) => ($t->last_used_at ?? $t->created_at)?->lt(now()->subDays(self::IDLE_TOKEN_DAYS))
            );
            if ($idle->isNotEmpty()) {
                $attention[] = [
                    'tone' => 'muted',
                    'title' => trans_choice(':n API token unused for :days days|:n API tokens unused for :days days', $idle->count(), ['n' => $idle->count(), 'days' => self::IDLE_TOKEN_DAYS]),
                    'detail' => $idle->pluck('name')->unique()->take(3)->implode(', '),
                    'action' => __('Review tokens'),
                    'href' => route('organizations.settings', $org).'#api-tokens',
                ];
            } elseif ($org->apiTokens->whereNull('revoked_at')->isNotEmpty()) {
                $clear[] = ['label' => __('API tokens in use'), 'sub' => (string) $org->apiTokens->whereNull('revoked_at')->count()];
            }

            if (! $org->hasPlan()) {
                $attention[] = [
                    'tone' => 'warn',
                    'title' => __('No plan — apps are paused'),
                    'detail' => __('Choose a plan to bring them back.'),
                    'action' => __('Choose a plan'),
                    'href' => route('billing.show', $org),
                ];
            } elseif ($org->onTrialPlan() && $org->trial_ends_at !== null && $org->trial_ends_at->lt(now()->addDays(3))) {
                $attention[] = [
                    'tone' => 'warn',
                    'title' => __('Trial ends :when', ['when' => $org->trial_ends_at->diffForHumans()]),
                    'detail' => __('Add a card to keep your apps running.'),
                    'action' => __('Open billing'),
                    'href' => route('billing.show', $org),
                ];
            } else {
                $clear[] = ['label' => __('Billing in good standing'), 'sub' => $org->planTierLabel()];
            }
        }

        return [$attention, $clear];
    }

    /** @return array{cents: int, credit_cents: int}|null */
    private function usage(): ?array
    {
        $tier = $this->organization->billingTier();
        if ($tier === 'none') {
            return null;
        }
        $state = app(OrganizationBillingStateComputer::class)->computeForTier($this->organization, $tier);

        return [
            'cents' => $state->usageLineCents(),
            'credit_cents' => (int) config('subscription.standard.tiers.'.$tier.'.usage_credit_cents', 0),
        ];
    }
}
