{{-- Payment & details tab: invoice email, VAT, currency, legal details printed on Stripe invoices. Action: Show::saveBillingDetails. --}}
@if ($this->canManageBilling)
    @php $currencies = config('profile_options.currencies', []); @endphp
    <section class="dply-card overflow-hidden p-0">
        <x-workspace-panel-head dense icon="heroicon-o-identification" :title="__('Billing details')" :note="__('Printed on every Stripe invoice for this organization.')" />
        <form wire:submit="saveBillingDetails" class="space-y-3 px-3 py-3 sm:px-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="org_invoice_email" :value="__('Invoice email')" />
                    <x-text-input id="org_invoice_email" wire:model="invoice_email" type="email" class="mt-1 block w-full" autocomplete="email" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Where invoices land — defaults to the org owner\'s email when blank.') }}</p>
                    <x-input-error :messages="$errors->get('invoice_email')" />
                </div>
                <div>
                    <x-input-label for="org_vat_number" :value="__('VAT number')" />
                    <x-text-input id="org_vat_number" wire:model="vat_number" type="text" class="mt-1 block w-full" placeholder="NL123456789B01" autocomplete="off" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Include the country code. EU businesses may receive a VAT exemption notice when valid.') }}</p>
                    <x-input-error :messages="$errors->get('vat_number')" />
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="org_billing_currency" :value="__('Currency')" />
                    <select id="org_billing_currency" wire:model="billing_currency"
                            class="dply-input">
                        <option value="">{{ __('Select a currency') }}</option>
                        @foreach ($currencies as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Preferred currency for invoices and payment references.') }}</p>
                    <x-input-error :messages="$errors->get('billing_currency')" />
                </div>
                <div>
                    <x-input-label for="org_billing_details" :value="__('Legal details')" />
                    <textarea id="org_billing_details" wire:model="billing_details" rows="2"
                              class="dply-input"
                              placeholder="{{ __('Legal name, address, and other details to show on invoices') }}"></textarea>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Printed on newly created invoices when provided.') }}</p>
                    <x-input-error :messages="$errors->get('billing_details')" />
                </div>
            </div>
            <div class="flex items-center justify-end border-t border-brand-ink/10 pt-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="saveBillingDetails"
                        class="inline-flex h-8 items-center gap-1 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-70">
                    <span wire:loading.remove wire:target="saveBillingDetails" class="inline-flex items-center gap-1">
                        <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Save billing details') }}
                    </span>
                    <span wire:loading wire:target="saveBillingDetails" class="inline-flex items-center gap-1">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                </button>
            </div>
        </form>
    </section>
@else
    <section class="dply-card px-5 py-4 text-sm text-brand-moss sm:px-6">
        {{ __('Billing details (invoice email, VAT number, currency, legal details) can be set once the organization has a subscription.') }}
    </section>
@endif
