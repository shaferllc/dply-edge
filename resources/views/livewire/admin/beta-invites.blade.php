@php
    $statusPill = function (\App\Models\BetaInvitation $i): array {
        if ($i->isRevoked()) {
            return ['bg-brand-ink/[0.06] text-brand-moss', __('Revoked')];
        }
        if ($i->isRedeemed()) {
            return ['bg-emerald-50 text-emerald-800', __('Redeemed')];
        }
        if ($i->isExpired()) {
            return ['bg-amber-50 text-amber-800', __('Expired')];
        }
        return ['bg-sky-50 text-sky-800', __('Pending')];
    };
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Beta invites'), 'icon' => 'envelope'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Beta invites')"
        :description="__('Issue closed-beta invites by email. Invitees get the platform free (BYO servers) plus one free dply-managed server. Admin-only — no peer invites.')"
        icon="heroicon-o-envelope"
    >
        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2">
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Issued') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $invitations->count() }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Waitlist') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $waitlist->count() }}</dd>
                </div>
            </dl>
        </x-slot:stats>

    {{-- Issue invites --}}
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-paper-airplane"
            :title="__('Send invites')"
            :note="__('One address or many — separate with commas, spaces, or new lines. Already-registered addresses are skipped.')"
        />
        <form wire:submit="sendInvites" class="space-y-3 px-3 py-3 sm:px-4">
            <textarea wire:model="emails" rows="3"
                      placeholder="alex@example.com, sam@example.com"
                      class="block w-full rounded-lg border-brand-ink/15 text-sm focus:border-brand-gold focus:ring-brand-gold/40"></textarea>
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" wire:target="sendInvites"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                    <span wire:loading.remove wire:target="sendInvites">{{ __('Send invites') }}</span>
                    <span wire:loading wire:target="sendInvites">{{ __('Sending…') }}</span>
                </button>
            </div>
        </form>
    </section>

    {{-- Waitlist funnel --}}
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-clock"
            :title="__('Waitlist')"
            :count="$waitlist->count()"
            :note="__('Coming-soon signups not yet invited. Pick who to let in.')"
        />
        @if ($waitlist->isEmpty())
            <p class="px-3 py-6 text-sm text-brand-mist sm:px-4">{{ __('No un-invited waitlist signups.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/5">
                @foreach ($waitlist as $signup)
                    <li class="flex items-center justify-between gap-3 px-3 py-2 sm:px-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-brand-ink">{{ $signup->email }}</p>
                            <p class="text-xs text-brand-mist">{{ __('Joined :when', ['when' => $signup->created_at?->diffForHumans()]) }}{{ $signup->source ? ' · '.$signup->source : '' }}</p>
                        </div>
                        <button type="button" wire:click="inviteFromWaitlist('{{ $signup->email }}')" wire:loading.attr="disabled"
                                class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Invite') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Issued invites --}}
    <section>
        <x-workspace-panel-head
            dense
            icon="heroicon-o-ticket"
            :title="__('Issued invites')"
            :count="$invitations->count()"
        />
        @if ($invitations->isEmpty())
            <p class="px-3 py-6 text-sm text-brand-mist sm:px-4">{{ __('No invites issued yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <th class="px-3 py-2 sm:px-4">{{ __('Email') }}</th>
                            <th class="px-3 py-2">{{ __('Status') }}</th>
                            <th class="px-3 py-2">{{ __('Source') }}</th>
                            <th class="px-3 py-2">{{ __('Expires') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($invitations as $invitation)
                            @php([$pillClass, $pillLabel] = $statusPill($invitation))
                            <tr>
                                <td class="px-3 py-2 text-brand-ink sm:px-4">{{ $invitation->email }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $pillClass }}">{{ $pillLabel }}</span>
                                </td>
                                <td class="px-3 py-2 text-brand-moss">{{ $invitation->source }}</td>
                                <td class="px-3 py-2 text-brand-moss">{{ $invitation->expires_at?->diffForHumans() }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($invitation->isRedeemable())
                                        <button type="button" wire:click="resend('{{ $invitation->id }}')"
                                                class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Resend') }}</button>
                                        <button type="button" wire:click="revoke('{{ $invitation->id }}')"
                                                wire:confirm="{{ __('Revoke this invite? It can no longer be redeemed.') }}"
                                                class="ml-3 text-xs font-semibold text-red-700 hover:underline">{{ __('Revoke') }}</button>
                                    @else
                                        <span class="text-xs text-brand-mist">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    </x-profile-shell>
</div>
