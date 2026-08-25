@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $engineBadgeClasses = [
        'nginx' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'apache' => 'border-red-200 bg-red-50 text-red-700',
        'caddy' => 'border-sky-200 bg-sky-50 text-sky-700',
        'openlitespeed' => 'border-amber-200 bg-amber-50 text-amber-900',
        'traefik' => 'border-violet-200 bg-violet-50 text-violet-700',
        'lighttpd' => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            :section="$orgShellSection ?? 'webserver'"
            :title="__('Webserver templates')"
            :description="__('Reusable config snippets for nginx, Apache, Caddy and friends — with before / inside / after slots so upstreams and sibling server blocks have a real home.')"
            icon="heroicon-o-server-stack"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Webserver templates'), 'icon' => 'server-stack'],
            ]"
        >
            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-3" aria-label="{{ __('Templates at a glance') }}">
                    <x-infrastructure-stat :label="__('Templates')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $templates->count() }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('saved|saved', $templates->count()) }}</span>
                        </p>
                    </x-infrastructure-stat>
                    <x-infrastructure-stat :label="__('Engines')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $templates->pluck('engine')->unique()->count() }}</span>
                            <span class="text-xs text-brand-moss">{{ __('in use') }}</span>
                        </p>
                        <p class="mt-1 text-xs text-brand-mist">{{ count($engines) }} {{ __('supported') }}</p>
                    </x-infrastructure-stat>
                    <x-infrastructure-stat :label="__('Editor')">
                        <p class="mt-2 flex items-center gap-1.5">
                            @if ($editingId)
                                <x-heroicon-m-pencil-square class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                <span class="text-sm font-semibold text-brand-ink">{{ __('Editing') }}</span>
                            @else
                                <x-heroicon-m-plus-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                <span class="text-sm font-semibold text-brand-ink">{{ __('New') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 truncate text-xs text-brand-mist">{{ $editingId ? $label : __('Ready') }}</p>
                    </x-infrastructure-stat>
                </dl>
            </x-slot:stats>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif


            @if ($testMessage)
                <div
                    @class([
                        'border-b border-brand-ink/10 px-5 py-4 text-sm sm:px-6',
                        'bg-emerald-50 text-emerald-900' => $testOk,
                        'bg-red-50 text-red-900' => ! $testOk,
                    ])
                    role="status"
                >
                    <p class="inline-flex items-center gap-2 font-semibold">
                        @if ($testOk)
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Syntax check passed') }}
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Syntax check failed') }}
                        @endif
                    </p>
                    <pre class="mt-2 whitespace-pre-wrap font-mono text-xs opacity-90">{{ $testMessage }}</pre>
                </div>
            @endif

            {{-- Editor section --}}
                <section class="border-b border-brand-ink/10">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                        <x-icon-badge>
                            <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $editingId ? __('Edit') : __('New') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $editingId ? __('Edit webserver template') : __('Create webserver template') }}
                            </h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick an engine, name the template, then fill in any of the three slots — placeholders are substituted at apply time.') }}</p>
                        </div>
                    </div>
                    <div class="grid gap-6 px-5 py-5 sm:px-6 lg:grid-cols-12 lg:gap-8">
                        <div class="lg:col-span-4">
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Placeholders') }}</p>
                                <ul class="mt-2 space-y-1.5 text-xs leading-relaxed text-brand-moss">
                                    @foreach ($placeholders as $key => $description)
                                        <li>
                                            <code class="rounded bg-brand-sand/80 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ '{'.$key.'}' }}</code>
                                            <span class="ms-1">{{ __($description) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @php $banner = config('webserver_templates.required_banner_line'); @endphp
                                @if ($banner)
                                    <p class="mt-3 border-t border-brand-ink/10 pt-3 text-xs text-brand-mist">
                                        <span class="font-semibold text-brand-moss">{{ __('Required nginx banner:') }}</span>
                                        <code class="ms-1 rounded bg-brand-sand/60 px-1 py-0.5 font-mono text-2xs text-brand-ink">{{ $banner }}</code>
                                    </p>
                                @endif
                            </div>

                            <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Section slots') }}</p>
                                <ul class="mt-2 space-y-2 text-xs leading-relaxed text-brand-moss">
                                    <li>
                                        <span class="inline-flex items-center gap-1 font-semibold text-brand-ink">
                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>
                                            {{ __('Before') }}
                                        </span>
                                        — {{ __('upstream / map / limit_req_zone, etc.') }}
                                    </li>
                                    <li>
                                        <span class="inline-flex items-center gap-1 font-semibold text-brand-ink">
                                            <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                            {{ __('Inside') }}
                                        </span>
                                        — {{ __('the main server block body. Required.') }}
                                    </li>
                                    <li>
                                        <span class="inline-flex items-center gap-1 font-semibold text-brand-ink">
                                            <span class="h-1.5 w-1.5 rounded-full bg-violet-500" aria-hidden="true"></span>
                                            {{ __('After') }}
                                        </span>
                                        — {{ __('sibling redirects, healthchecks, etc.') }}
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="space-y-4 lg:col-span-8">
                            @if (! $canManage)
                                <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss">{{ __('Only organization admins can create or edit templates.') }}</p>
                            @else
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="tpl-engine" :value="__('Engine')" />
                                        <select id="tpl-engine" wire:model.live="engine" class="dply-input mt-1 block w-full">
                                            @foreach ($engines as $slug => $name)
                                                <option value="{{ $slug }}">{{ $name }}</option>
                                            @endforeach
                                        </select>
                                        @error('engine') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="tpl-label" :value="__('Label')" />
                                        <x-text-input id="tpl-label" wire:model="label" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. WordPress with rate-limiting') }}" />
                                        @error('label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                @if ($engine !== 'nginx')
                                    <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs text-sky-900">
                                        <span class="inline-flex items-center gap-1.5 font-semibold">
                                            <x-heroicon-m-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Engine notes') }}
                                        </span>
                                        <p class="mt-1 leading-relaxed">
                                            {{ __(':engine templates are validated by the engine\'s own config-test binary when it\'s installed on the dply host. If it isn\'t, a structural check (balanced braces) runs as a fallback.', ['engine' => $engines[$engine] ?? ucfirst($engine)]) }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Before / Inside / After section editor.
                                     Each section labelled with the same dot
                                     used in the legend so the editor reads
                                     as a single concept. --}}
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <label for="tpl-before" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                                <span class="h-1.5 w-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>
                                                {{ __('Before server block') }}
                                                <span class="text-xs font-normal text-brand-mist">{{ __('optional') }}</span>
                                            </label>
                                            <span class="text-xs text-brand-mist">{{ __('Upstreams, maps, rate-limit zones') }}</span>
                                        </div>
                                        <textarea
                                            id="tpl-before"
                                            wire:model="content_before"
                                            rows="6"
                                            class="mt-2 block w-full rounded-xl border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            spellcheck="false"
                                            placeholder="{{ __('# Optional: upstream and map blocks go here') }}"
                                        ></textarea>
                                        @error('content_before') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <label for="tpl-content" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                                <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                                {{ __('Inside server block') }}
                                                <span class="rounded-md bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Required') }}</span>
                                            </label>
                                            <span class="text-xs text-brand-mist">{{ __('The main server block body') }}</span>
                                        </div>
                                        <textarea
                                            id="tpl-content"
                                            wire:model="content"
                                            rows="16"
                                            class="mt-2 block w-full rounded-xl border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            spellcheck="false"
                                        ></textarea>
                                        @error('content') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <label for="tpl-after" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                                <span class="h-1.5 w-1.5 rounded-full bg-violet-500" aria-hidden="true"></span>
                                                {{ __('After server block') }}
                                                <span class="text-xs font-normal text-brand-mist">{{ __('optional') }}</span>
                                            </label>
                                            <span class="text-xs text-brand-mist">{{ __('HTTP→HTTPS, healthchecks, sibling servers') }}</span>
                                        </div>
                                        <textarea
                                            id="tpl-after"
                                            wire:model="content_after"
                                            rows="6"
                                            class="mt-2 block w-full rounded-xl border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            spellcheck="false"
                                            placeholder="{{ __('# Optional: sibling server blocks go here') }}"
                                        ></textarea>
                                        @error('content_after') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                    @if ($editingId)
                                        <button type="button" wire:click="cancelEdit" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                            {{ __('Cancel edit') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="testDraft"
                                        wire:loading.attr="disabled"
                                        wire:target="testDraft"
                                        class="inline-flex min-w-[8rem] items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="testDraft" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-beaker class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                            {{ __('Test template') }}
                                        </span>
                                        <span wire:loading wire:target="testDraft" class="inline-flex items-center gap-2">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ __('Testing…') }}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="save"
                                        wire:loading.attr="disabled"
                                        wire:target="save"
                                        class="inline-flex min-w-[9rem] items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ $editingId ? __('Save changes') : __('Create template') }}
                                        </span>
                                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                            <x-spinner variant="cream" size="sm" />
                                            {{ __('Saving…') }}
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Saved templates list --}}
                <section class="border-b border-brand-ink/10 last:border-b-0">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                        <x-icon-badge>
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Saved templates') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Reuse these snippets when configuring sites in your organization.') }}</p>
                        </div>
                    </div>
                    @if ($templates->isEmpty())
                        <div class="px-5 py-12 text-center sm:px-6">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No templates yet.') }}</p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Create your first template above to reuse across sites.') }}</p>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($templates as $template)
                                @php
                                    $engineSlug = $template->engine ?: 'nginx';
                                    $engineClasses = $engineBadgeClasses[$engineSlug] ?? 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss';
                                @endphp
                                <li class="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                            <span class="text-sm font-semibold text-brand-ink">{{ $template->label }}</span>
                                            <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $engineClasses }}">
                                                {{ $engines[$engineSlug] ?? ucfirst($engineSlug) }}
                                            </span>
                                            @if (! empty($template->content_before))
                                                <span class="inline-flex items-center gap-1 text-2xs font-medium text-brand-moss" title="{{ __('Has Before section') }}">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>
                                                </span>
                                            @endif
                                            <span class="inline-flex items-center gap-1 text-2xs font-medium text-brand-moss" title="{{ __('Has Inside section') }}">
                                                <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                            </span>
                                            @if (! empty($template->content_after))
                                                <span class="inline-flex items-center gap-1 text-2xs font-medium text-brand-moss" title="{{ __('Has After section') }}">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-violet-500" aria-hidden="true"></span>
                                                </span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 text-xs text-brand-mist">
                                            {{ __('Created :time', ['time' => $template->created_at->diffForHumans()]) }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            wire:click="testSaved({{ $template->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="testSaved"
                                            class="inline-flex min-w-[6.5rem] items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="testSaved" class="inline-flex items-center gap-1.5">
                                                <x-heroicon-o-beaker class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Test') }}
                                            </span>
                                            <span wire:loading wire:target="testSaved" class="inline-flex items-center gap-1.5">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Testing…') }}
                                            </span>
                                        </button>
                                        @if ($canManage)
                                            <button
                                                type="button"
                                                wire:click="startEdit({{ $template->id }})"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                            >
                                                <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('delete', [{{ $template->id }}], @js(__('Delete template')), @js(__('Delete this template?')), @js(__('Delete')), true)"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Delete') }}
                                            </button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
        </x-organization-shell>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
