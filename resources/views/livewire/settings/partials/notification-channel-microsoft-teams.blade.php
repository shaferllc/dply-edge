{{-- Expects: $p (field prefix).

     Teams needs its own partial for one reason the other chat channels don't:
     Microsoft retired Office 365 connectors between 18 and 22 May 2026, and the
     "Incoming Webhook" most Teams setup guides still describe is that retired
     thing. An operator following a stale guide arrives here with a
     *.webhook.office.com URL that will never deliver, so the form names the
     right flow and detects the wrong URL as it is typed. --}}
@php
    $teamsUrl = $this->{$p.'teams_webhook_url'};
    $teamsUrlKind = \App\Modules\Notifications\Services\MicrosoftTeamsClient::classifyUrl($teamsUrl);
@endphp
<div class="space-y-3">
    <div class="rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] px-3 py-3">
        <p class="text-xs font-medium text-brand-ink/80">{{ __('Where to get the workflow URL') }}</p>
        <ol class="mt-2 space-y-1 text-xs text-brand-moss">
            <li>{{ __('1. In Teams, right-click the channel you want alerts in → Workflows.') }}</li>
            <li>{{ __('2. Choose the template "Post to a channel when a webhook request is received".') }}</li>
            <li>{{ __('3. Sign in when prompted, confirm the team and channel, and create the flow.') }}</li>
            <li>{{ __('4. Copy the URL it shows you at the end and paste it below.') }}</li>
        </ol>
        <p class="mt-2 text-xs text-brand-moss">
            {{ __('This is a Power Automate Workflow, not the older "Incoming Webhook" connector — Microsoft retired connectors in May 2026 and those URLs no longer deliver.') }}
        </p>
    </div>

    <div>
        <x-input-label for="{{ $p }}teams_webhook_url" :value="__('Workflow URL')" />
        <input
            id="{{ $p }}teams_webhook_url"
            type="url"
            wire:model.live.debounce.500ms="{{ $p }}teams_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            placeholder="https://prod-00.westus.logic.azure.com:443/workflows/…"
            autocomplete="off"
        />

        @if ($teamsUrlKind === \App\Modules\Notifications\Services\MicrosoftTeamsClient::KIND_CONNECTOR)
            {{-- Caught before save, because the failure mode otherwise is a
                 channel that saves cleanly and silently never delivers. --}}
            <p class="mt-1.5 flex items-start gap-1.5 rounded-lg bg-rose-50 px-2 py-1.5 text-xs text-rose-700 ring-1 ring-rose-200">
                <x-heroicon-m-exclamation-triangle class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{{ __('That is a retired Office 365 connector URL. Follow the steps above to create a Workflows URL instead — connector URLs stopped delivering in May 2026.') }}</span>
            </p>
        @elseif ($teamsUrlKind === \App\Modules\Notifications\Services\MicrosoftTeamsClient::KIND_WORKFLOW)
            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-brand-sage">
                <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Looks like a Power Automate workflow URL.') }}
            </p>
        @endif

        @error($p.'teams_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <p class="text-xs text-brand-moss">
        {{ __('Alerts arrive as an Adaptive Card with the title, detail, and a button back into dply.') }}
    </p>
</div>
