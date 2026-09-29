{{-- The deploy pill (resources/js/deploy-pill.js): the organization's
     in-flight deploys at the bottom of every page. @persist'd by the layout
     so it survives wire:navigate. z-[90]: sheets and dialogs (z-100+) cover
     it, so it never sits over a Save button. --}}
@php
    $deployPillInitial = \App\Modules\Edge\Support\EdgeDeployProgress::inFlightFor(auth()->user());
    $deployPillInitial = array_map(fn (array $d): array => $d + [
        'can_deploy' => ($s = \App\Models\Site::query()->find($d['site_id'])) !== null && auth()->user()->can('deploy', $s),
    ], $deployPillInitial);
@endphp
<div
    x-data="deployPill({
        initial: @js($deployPillInitial),
        endpoint: @js(route('deploy-pill.index')),
        tailUrl: @js(route('deploy-pill.tail', ['deployment' => '__ID__'])),
        actionUrl: @js(url('deploy-pill/__ID__/__ACTION__')),
        csrf: @js(csrf_token()),
        userId: @js((string) auth()->id()),
    })"
    x-show="visible.length > 0"
    x-cloak
    class="pointer-events-none fixed inset-x-0 bottom-3 z-[90] flex justify-center px-4 sm:bottom-5"
    role="status"
    aria-live="polite"
    aria-label="{{ __('Deploys in progress') }}"
>
    {{-- Minimized: one dot with a count. --}}
    <button
        type="button"
        x-show="minimized"
        x-on:click="toggleMinimized()"
        class="pointer-events-auto flex items-center gap-2 rounded-full bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-lg"
        :aria-label="visible.length + ' {{ __('deploys in progress. Show them.') }}'"
    >
        <span class="h-2 w-2 animate-pulse rounded-full bg-brand-sage" aria-hidden="true"></span>
        <span x-text="visible.length"></span>
    </button>

    <div x-show="! minimized" class="pointer-events-auto flex w-full max-w-md flex-col gap-1.5">
        <template x-for="d in shown" :key="d.id">
            <div
                class="overflow-hidden rounded-2xl border shadow-lg transition"
                :class="{
                    'border-brand-ink/10 bg-brand-ink text-brand-cream dark:border-brand-mist/20': tone(d) === 'running' || tone(d) === 'cancelled',
                    'border-emerald-400/40 bg-emerald-600 text-white': tone(d) === 'live',
                    'border-red-400/40 bg-red-700 text-white': tone(d) === 'failed',
                    'opacity-60': tone(d) === 'cancelled',
                }"
            >
                <button type="button" x-on:click="toggle(d)" class="flex w-full items-center gap-3 px-4 py-2.5 text-left" :aria-expanded="expanded === d.id">
                    <span class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                        <span x-show="tone(d) === 'running'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-sage opacity-60"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="tone(d) === 'running' ? 'bg-brand-sage' : 'bg-white/80'"></span>
                    </span>
                    <span class="min-w-0 flex-1 truncate text-sm">
                        <span class="font-semibold" x-text="d.app_name"></span><span x-show="d.preview" class="opacity-70" x-text="' · Preview ' + d.preview"></span>
                        <span class="opacity-80" x-text="' · ' + d.step"></span>
                    </span>
                    <span class="shrink-0 font-mono text-2xs tabular-nums opacity-70" x-text="elapsed(d)"></span>
                    <span class="shrink-0 text-xs opacity-60 transition" :class="expanded === d.id ? 'rotate-180' : ''" aria-hidden="true">⌃</span>
                </button>

                <div x-show="expanded === d.id" x-collapse class="border-t border-white/10 px-4 pb-3 pt-2">
                    <p x-show="d.failure" class="mb-2 text-xs" x-text="d.failure"></p>
                    <ol x-show="tail.id === d.id && tail.lines.length" class="mb-2 max-h-40 overflow-hidden rounded-lg bg-black/30 p-2 font-mono text-2xs leading-relaxed">
                        <template x-for="(line, i) in tail.lines" :key="i">
                            <li class="truncate" x-text="line"></li>
                        </template>
                    </ol>
                    <p x-show="tail.id === d.id && ! tail.lines.length && tone(d) === 'running'" class="mb-2 text-2xs opacity-60">{{ __('Waiting for output…') }}</p>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold">
                        <a :href="d.log_url" class="underline-offset-2 hover:underline">{{ __('Full log') }}</a>
                        <a :href="d.app_url" wire:navigate class="underline-offset-2 hover:underline">{{ __('Open app') }}</a>
                        <template x-if="tone(d) === 'running' && d.can_deploy">
                            <span class="ms-auto flex items-center gap-3">
                                <button type="button" x-show="confirmCancel !== d.id" x-on:click="confirmCancel = d.id" class="opacity-80 hover:opacity-100">{{ __('Cancel') }}</button>
                                <span x-show="confirmCancel === d.id" class="flex items-center gap-3">
                                    <span class="font-normal opacity-80">{{ __('Cancel this deploy?') }}</span>
                                    <button type="button" x-on:click="act(d, 'cancel')" class="text-red-300 hover:text-red-200">{{ __('Yes, cancel') }}</button>
                                    <button type="button" x-on:click="confirmCancel = null" class="opacity-70">{{ __('No') }}</button>
                                </span>
                            </span>
                        </template>
                        <template x-if="tone(d) === 'failed'">
                            <span class="ms-auto flex items-center gap-3">
                                <button type="button" x-show="d.can_deploy" x-on:click="act(d, 'redeploy')">{{ __('Redeploy') }}</button>
                                <button type="button" x-on:click="forget(d.id)" class="opacity-80">{{ __('Dismiss') }}</button>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <div class="flex items-center justify-center gap-3 text-2xs font-semibold">
            <button type="button" x-show="hidden > 0 && ! showAll" x-on:click="showAll = true" class="rounded-full bg-brand-ink/90 px-3 py-1 text-brand-cream shadow" x-text="'+' + hidden + ' {{ __('more deploying') }}'"></button>
            <button type="button" x-show="showAll && hidden > 0" x-on:click="showAll = false" class="rounded-full bg-brand-ink/90 px-3 py-1 text-brand-cream shadow">{{ __('Show fewer') }}</button>
            <button type="button" x-on:click="toggleMinimized()" class="rounded-full bg-brand-ink/90 px-3 py-1 text-brand-cream shadow">{{ __('Minimize') }}</button>
        </div>
    </div>
</div>
