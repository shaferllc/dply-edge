<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Coming-soon access'), 'icon' => 'lock-closed'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Coming-soon access')"
        :description="__('IPs (and CIDR ranges) that see the full site while the coming-soon gate is on. Everyone else only sees the coming-soon page. Logged-in users always pass.')"
        icon="heroicon-o-lock-closed"
    >
        <x-slot:actions>
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-2xs font-bold uppercase tracking-wide',
                'bg-emerald-100 text-emerald-800' => $gateOn,
                'bg-brand-ink/[0.06] text-brand-moss' => ! $gateOn,
            ])>{{ $gateOn ? __('Gate active') : __('Gate off') }}</span>
        </x-slot:actions>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 sm:px-4">
            <div class="min-w-0 text-sm">
                <span class="text-brand-moss">{{ __('Your current IP:') }}</span>
                <code class="ml-1 font-mono text-brand-ink">{{ $yourIp }}</code>
            </div>
            <button type="button" wire:click="addMyIp"
                class="shrink-0 rounded-lg border border-brand-forest bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest transition-colors hover:bg-brand-sage/10">
                {{ __('Allow my IP') }}
            </button>
        </div>

        {{-- Add form --}}
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-plus-circle"
                :title="__('Add an address')"
                :note="__('A single IP or a CIDR range.')"
            />
            <div class="flex flex-wrap items-end gap-3 px-3 py-3 sm:px-4">
                <div class="min-w-56 flex-1">
                    <label for="csa-ip" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('IP or CIDR') }}</label>
                    <input id="csa-ip" type="text" wire:model="ip" placeholder="203.0.113.4  ·  2600:…  ·  10.0.0.0/24"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" />
                    @error('ip')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
                </div>
                <div class="w-44">
                    <label for="csa-label" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Label (optional)') }}</label>
                    <input id="csa-label" type="text" wire:model="label" placeholder="{{ __('e.g. office') }}"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" />
                </div>
                <button type="button" wire:click="addIp"
                    class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                    {{ __('Add') }}
                </button>
            </div>
        </section>

        {{-- Managed list --}}
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-list-bullet"
                :title="__('Allowed addresses')"
                :count="count($rows)"
            />
            @forelse ($rows as $row)
                <div class="flex items-center justify-between gap-3 border-b border-brand-ink/5 px-3 py-2.5 last:border-0 sm:px-4" wire:key="ip-{{ $row->id }}">
                    <div class="min-w-0">
                        <code class="font-mono text-sm text-brand-ink">{{ $row->ip }}</code>
                        @if ($row->label)<span class="ml-2 text-xs text-brand-moss">{{ $row->label }}</span>@endif
                    </div>
                    <button type="button" wire:click="remove({{ $row->id }})"
                        wire:confirm="{{ __('Remove :ip from the allow-list?', ['ip' => $row->ip]) }}"
                        class="shrink-0 rounded-md border border-brand-ink/10 px-2 py-1 text-xs font-semibold text-brand-rust transition-colors hover:bg-brand-rust/5">
                        {{ __('Remove') }}
                    </button>
                </div>
            @empty
                <p class="px-3 py-6 text-center text-sm text-brand-moss sm:px-4">{{ __('No managed addresses yet — add one above.') }}</p>
            @endforelse
        </section>

        {{-- Env-provided (read-only) --}}
        @if (! empty($envIps))
            <x-slot:footer>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From COMING_SOON_ALLOWED_IPS (env, read-only)') }}</p>
                <p class="mt-1 font-mono text-xs text-brand-moss">{{ implode('  ·  ', $envIps) }}</p>
            </x-slot:footer>
        @endif
    </x-profile-shell>
</div>
