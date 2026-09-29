{{-- Expects: $p (field prefix).
     Split out of notification-channel-fields because Intercom needs seven fields
     where every other provider needs one or two, and inlining it would bury the
     surrounding branches. Credentials are per-channel on purpose: an Intercom
     access token is scoped to one workspace, so each org brings its own. --}}
@php($messageType = $this->{$p.'intercom_message_type'})
<div class="space-y-4">
    {{-- Operators get here with an Intercom account but no idea which of the
         several things Intercom calls a "key" dply wants. Spelling out the click
         path is cheaper than a support round-trip. --}}
    <div class="rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] px-3 py-3">
        <p class="text-xs font-medium text-brand-ink/80">{{ __('Where to get these') }}</p>
        <ol class="mt-2 space-y-1 text-xs text-brand-moss">
            <li>
                {{ __('1. Open the') }}
                <a
                    href="https://app.intercom.com/a/apps/_/developer-hub"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-brand-sage underline underline-offset-2"
                >{{ __('Intercom Developer Hub') }}</a>
                {{ __('and create an app (or pick an existing one).') }}
            </li>
            <li>{{ __('2. In that app, go to Configure → Authentication. Enable the "Write conversations" permission — without it Intercom accepts the token but refuses to send.') }}</li>
            <li>{{ __('3. Copy the Access Token shown on that same page and paste it below.') }}</li>
            <li>{{ __('4. For the admin ID, go to Settings → Teammates in Intercom and copy the ID of the teammate messages should come from.') }}</li>
        </ol>
        <p class="mt-2 text-xs text-brand-moss">
            {{ __('The token belongs to one workspace and one region — make sure both match the workspace you just took it from.') }}
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <x-input-label for="{{ $p }}intercom_access_token" :value="__('Access token')" />
            <input
                id="{{ $p }}intercom_access_token"
                type="password"
                wire:model="{{ $p }}intercom_access_token"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="new-password"
            />
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Developer Hub → your app → Configure → Authentication.') }}
            </p>
            @error($p.'intercom_access_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}intercom_region" :value="__('Region')" />
            <select
                id="{{ $p }}intercom_region"
                wire:model="{{ $p }}intercom_region"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                @foreach (\App\Modules\Notifications\Services\IntercomClient::regions() as $region)
                    <option value="{{ $region }}">{{ \App\Modules\Notifications\Services\IntercomClient::labelForRegion($region) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('A token issued in one region will not work against another.') }}
            </p>
            @error($p.'intercom_region')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <x-input-label for="{{ $p }}intercom_admin_id" :value="__('Send as (admin ID)')" />
        <input
            id="{{ $p }}intercom_admin_id"
            type="text"
            wire:model="{{ $p }}intercom_admin_id"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            placeholder="394051"
            autocomplete="off"
        />
        <p class="mt-1 text-xs text-brand-moss">
            {{ __('Intercom requires every message to come from a teammate. Copy the ID from Settings → Teammates.') }}
        </p>
        @error($p.'intercom_admin_id')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}intercom_recipient_type" :value="__('Deliver to')" />
            <select
                id="{{ $p }}intercom_recipient_type"
                wire:model.live="{{ $p }}intercom_recipient_type"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                @foreach (\App\Models\NotificationChannel::intercomRecipientTypes() as $recipientType)
                    <option value="{{ $recipientType }}">{{ \App\Models\NotificationChannel::labelForIntercomRecipientType($recipientType) }}</option>
                @endforeach
            </select>
            @error($p.'intercom_recipient_type')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}intercom_recipient" :value="__('Recipient')" />
            <input
                id="{{ $p }}intercom_recipient"
                type="text"
                wire:model="{{ $p }}intercom_recipient"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'intercom_recipient')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}intercom_message_type" :value="__('Message type')" />
            {{-- .live because the subject field below only exists for e-mail, and
                 validation requires a subject the moment this flips. --}}
            <select
                id="{{ $p }}intercom_message_type"
                wire:model.live="{{ $p }}intercom_message_type"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                <option value="{{ \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TYPE_INAPP }}">{{ __('In-app (Messenger)') }}</option>
                <option value="{{ \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TYPE_EMAIL }}">{{ __('E-mail') }}</option>
            </select>
            @error($p.'intercom_message_type')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        @if ($messageType === \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TYPE_EMAIL)
            <div>
                <x-input-label for="{{ $p }}intercom_template" :value="__('Template')" />
                <select
                    id="{{ $p }}intercom_template"
                    wire:model="{{ $p }}intercom_template"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="{{ \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TEMPLATE_PLAIN }}">{{ __('Plain') }}</option>
                    <option value="{{ \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TEMPLATE_PERSONAL }}">{{ __('Personal') }}</option>
                </select>
                @error($p.'intercom_template')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif
    </div>

    @if ($messageType === \App\Modules\Notifications\Channels\Intercom\IntercomMessage::TYPE_EMAIL)
        <div>
            <x-input-label for="{{ $p }}intercom_subject" :value="__('Default subject')" />
            <input
                id="{{ $p }}intercom_subject"
                type="text"
                wire:model="{{ $p }}intercom_subject"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Used when an alert carries no subject of its own. Intercom rejects e-mail messages without one.') }}
            </p>
            @error($p.'intercom_subject')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @endif
</div>
