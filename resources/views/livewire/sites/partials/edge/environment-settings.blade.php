{{-- Primary Environment strip: one .env block. Repo-managed env lives under Advanced on the parent page. --}}
<section class="border-b border-brand-ink/10">
    @can('update', $site)
        <div class="px-5 py-4 sm:px-6">
            <label class="block">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Environment') }}</span>
                <textarea
                    wire:model="edgeEnvText"
                    rows="14"
                    spellcheck="false"
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs leading-5 text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    placeholder="APP_DEBUG=true"
                ></textarea>
                @error('edgeEnvText')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </label>
            <p class="mt-3 text-xs text-brand-moss">{{ __('One KEY=value per line. Redeploy to apply.') }}</p>
        </div>
    @else
        @php
            $envKeys = $this->edgeEnvVarKeys();
        @endphp
        @if ($envKeys === [])
            <div class="px-5 py-6 text-center text-sm text-brand-moss sm:px-6">
                {{ __('No env vars set yet.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($envKeys as $envRow)
                    <li class="px-5 py-3 sm:px-6" wire:key="edge-env-{{ $envRow['key'] }}">
                        <p class="font-mono text-sm text-brand-ink">{{ $envRow['key'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    @endcan

    @if ($resourceInjections !== [])
        <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('From resources') }}</p>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'resources']) }}" wire:navigate class="text-xs font-semibold text-brand-ink underline">{{ __('Resources') }}</a>
            </div>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Added on the next deploy from the app size and database. A line in the field above replaces the same key.') }}</p>
            <ul class="mt-3 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                @foreach ($resourceInjections as $row)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-3 py-2 font-mono text-xs" wire:key="resource-env-{{ $row['key'] }}">
                        <span class="text-brand-ink">{{ $row['key'] }}={{ $row['value'] }}</span>
                        <span class="font-sans text-brand-moss">
                            {{ $row['from'] }}
                            @if ($row['overridden'])
                                · {{ __('replaced above') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
