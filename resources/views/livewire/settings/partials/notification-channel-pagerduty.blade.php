{{-- Expects: $p (field prefix).
     Split out of notification-channel-fields for the same reason as the Intercom
     partial: too many fields to inline without burying the surrounding branches.
     The credential is an Events API v2 *integration* key, which belongs to one
     PagerDuty service — so choosing the key IS choosing what gets paged. --}}
<div class="space-y-4">
    <div class="rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] px-3 py-3">
        <p class="text-xs font-medium text-brand-ink/80">{{ __('Where to get the integration key') }}</p>
        <ol class="mt-2 space-y-1 text-xs text-brand-moss">
            <li>{{ __('1. In PagerDuty, open the service you want dply to page — Services → Service Directory → your service.') }}</li>
            <li>{{ __('2. Go to the Integrations tab and choose Add an integration.') }}</li>
            <li>{{ __('3. Pick Events API v2. dply does not support the older Events API v1.') }}</li>
            <li>{{ __('4. Copy the Integration Key it generates and paste it below.') }}</li>
        </ol>
        <p class="mt-2 text-xs text-brand-moss">
            {{ __('The key is tied to that one service, so its escalation policy decides who gets woken. A wrong-region key is rejected with a message about the key rather than about the region.') }}
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <x-input-label for="{{ $p }}pagerduty_routing_key" :value="__('Integration key')" />
            <input
                id="{{ $p }}pagerduty_routing_key"
                type="password"
                wire:model="{{ $p }}pagerduty_routing_key"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="new-password"
            />
            @error($p.'pagerduty_routing_key')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}pagerduty_region" :value="__('Region')" />
            <select
                id="{{ $p }}pagerduty_region"
                wire:model="{{ $p }}pagerduty_region"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                @foreach (\App\Modules\Notifications\Services\PagerDutyClient::regions() as $region)
                    <option value="{{ $region }}">{{ \App\Modules\Notifications\Services\PagerDutyClient::labelForRegion($region) }}</option>
                @endforeach
            </select>
            @error($p.'pagerduty_region')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}pagerduty_default_severity" :value="__('Default severity')" />
            <select
                id="{{ $p }}pagerduty_default_severity"
                wire:model="{{ $p }}pagerduty_default_severity"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                @foreach (\App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage::severities() as $severity)
                    <option value="{{ $severity }}">{{ ucfirst($severity) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Only used for alerts that carry no severity of their own — subscribed events bring theirs.') }}
            </p>
            @error($p.'pagerduty_default_severity')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}pagerduty_source" :value="__('Source (optional)')" />
            <input
                id="{{ $p }}pagerduty_source"
                type="text"
                wire:model="{{ $p }}pagerduty_source"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Overrides the affected server or site as the incident source. Usually best left blank.') }}
            </p>
            @error($p.'pagerduty_source')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}pagerduty_component" :value="__('Component (optional)')" />
            <input
                id="{{ $p }}pagerduty_component"
                type="text"
                wire:model="{{ $p }}pagerduty_component"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pagerduty_component')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}pagerduty_group" :value="__('Group (optional)')" />
            <input
                id="{{ $p }}pagerduty_group"
                type="text"
                wire:model="{{ $p }}pagerduty_group"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pagerduty_group')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <p class="text-xs text-brand-moss">
        {{ __('Sending a test raises an info-level incident so it proves the wiring without waking whoever is on call.') }}
    </p>
</div>
