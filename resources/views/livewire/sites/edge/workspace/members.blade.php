@php
    $canManage = auth()->user()?->can('manageMembers', $site);
    $does = [
        'all' => __('can do anything'),
        'admin' => __('can configure, deploy and manage members'),
        'configure' => __('can configure and deploy'),
        'deploy' => __('can deploy'),
        'view' => __('can only look'),
    ];
    $orgRoleLabel = fn (string $r): string => match ($r) {
        'owner' => __('Org owner'),
        'admin' => __('Org admin'),
        'member' => __('Org member'),
        'deployer' => __('Org deployer'),
        default => __('Org viewer'),
    };
    $why = fn (array $p): string => $p['app_role'] !== null && $p['editable']
        ? __(':role on this app', ['role' => $roleOptions[$p['app_role']] ?? $p['app_role']])
        : $orgRoleLabel($p['org_role']);
    $counts = $people->countBy('access');
    $parts = collect([
        'all' => __(':n can do anything'),
        'admin' => __(':n can configure, deploy and manage members'),
        'configure' => __(':n can configure and deploy'),
        'deploy' => __(':n can deploy'),
        'view' => __(':n can only look'),
    ])->filter(fn ($_, $k) => ($counts[$k] ?? 0) > 0)->map(fn ($t, $k) => str_replace(':n', (string) $counts[$k], $t))->values();
    $choices = [
        '' => [__('Their org role'), null],
        'viewer' => [__('Viewer'), __('Sees deploys, logs and settings. Can’t change anything.')],
        'deployer' => [__('Deployer'), __('Deploys, redeploys and rolls back. Can’t change settings or environment variables.')],
        'admin' => [__('Admin'), __('Everything on this app: settings, environment, domains, deploys and members.')],
    ];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'app-members',
            'what' => __('Who can reach this app and what they can do. For org members and deployers, an app role replaces their org role here. Org owners and admins always have full access.'),
            'steps' => [
                __('Click a person to give them an app role, change it, or go back to their org role.'),
                __('Give someone access to add an org member or deployer with an app role.'),
            ],
            'tips' => [
                __('Use Viewer to make this app read-only for someone, or Admin to let a deployer configure it.'),
                __('Org viewers stay read-only and can’t be given an app role.'),
            ],
        ])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Members') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                <span class="text-brand-sage">{{ trans_choice(':count person|:count people', $people->count()) }}</span>
                {{ trans_choice('can reach this app.|can reach this app.', $people->count()) }}
                {{ $parts->count() > 1 ? $parts->slice(0, -1)->implode(', ').' '.__('and').' '.$parts->last() : $parts->first() }}.
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Who has access') }}</p>
                <span class="flex items-center gap-4">
                    <a href="{{ route('organizations.members', $site->organization_id) }}" wire:navigate class="text-sm text-brand-moss hover:text-brand-ink hover:underline">{{ __('Invite to organization') }}</a>
                    @if ($canManage && $eligibleUsers->isNotEmpty())
                        <button type="button" wire:click="openAdd" class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline">
                            <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Give someone access') }}
                        </button>
                    @endif
                </span>
            </div>
            <ul>
                @foreach ($people as $p)
                    <li class="border-b border-brand-ink/10" wire:key="person-{{ $p['id'] }}">
                        @if ($canManage && $p['editable'])
                            <button type="button" wire:click="editPerson('{{ $p['id'] }}')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $p['name'] }} {{ $does[$p['access']] }}</span>
                                <span class="shrink-0 text-xs text-brand-moss">{{ $why($p) }}</span>
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                            </button>
                        @else
                            <div class="flex min-h-12 items-center gap-3 py-3">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $p['name'] }} {{ $does[$p['access']] }}</span>
                                <span class="shrink-0 text-xs text-brand-moss">{{ $why($p) }}</span>
                                <span class="h-4 w-4 shrink-0" aria-hidden="true"></span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-brand-moss">
                {{ __('Org owners and admins always have full access.') }}
                {{ __('To add someone new, invite them to the organization first; they show up here once they accept.') }}
                <a href="{{ route('organizations.members', $site->organization_id) }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Organization members') }}</a>
            </p>
        </div>
    </section>

    <x-modal name="edge-member" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($adding || $editing)
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $adding ? __('Give someone access') : $editing['name'] }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">
                            {{ $adding ? __('Pick an org member or deployer.') : __(':role · an app role replaces it on this app only', ['role' => $orgRoleLabel($editing['org_role'])]) }}
                        </p>
                    </div>
                    <button type="button" wire:click="closePerson" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if ($adding)
                    <div>
                        <x-input-label for="member-person" :value="__('Person')" />
                        <select id="member-person" wire:model="member_user_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900">
                            <option value="">{{ __('Select…') }}</option>
                            @foreach ($eligibleUsers as $u)
                                <option value="{{ $u['id'] }}">{{ $u['name'] }} ({{ $u['email'] }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('member_user_id')" class="mt-1" />
                    </div>
                @endif

                <fieldset class="space-y-2">
                    <legend class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('On this app') }}</legend>
                    @foreach ($choices as $value => [$label, $desc])
                        @continue($adding && $value === '')
                        @php
                            $desc ??= $editing ? __('Back to their org role: :does.', ['does' => $does[$editing['org_role'] === 'member' ? 'configure' : 'deploy']]) : '';
                        @endphp
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3', 'border-brand-sage bg-brand-sage/5' => $member_role === $value, 'border-brand-ink/10 hover:border-brand-ink/25' => $member_role !== $value])>
                            <input type="radio" wire:model.live="member_role" value="{{ $value }}" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>
                                <span class="block text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                <span class="block text-xs text-brand-moss">{{ $desc }}</span>
                            </span>
                        </label>
                    @endforeach
                    <x-input-error :messages="$errors->get('member_role')" class="mt-1" />
                </fieldset>

                <div class="flex items-center justify-between gap-2">
                    @if (! $adding && $editing['app_role'] !== null)
                        <button type="button" wire:click="removePerson" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove app role') }}</button>
                    @else
                        <span></span>
                    @endif
                    <span class="flex gap-2">
                        <button type="button" wire:click="closePerson" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="savePerson" wire:loading.attr="disabled" wire:target="savePerson">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            </div>
        @endif
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
</div>
