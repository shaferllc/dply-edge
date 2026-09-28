{{-- External stores tab. The add form lives in the `add-store` modal. --}}
@php
    $driverLabels = ['vault' => __('HashiCorp Vault'), 'aws_sm' => __('AWS Secrets Manager'), 'doppler' => __('Doppler')];
@endphp

<section class="dply-card overflow-hidden p-0" aria-label="{{ __('External stores') }}">
    @if ($stores->isEmpty())
        <div class="px-5 py-8 text-center sm:px-6">
            <p class="text-sm font-semibold text-brand-ink">{{ __('No external stores connected') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Connect Vault, AWS Secrets Manager or Doppler to reference secrets without copying them into dply.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($stores as $store)
                <li class="flex flex-wrap items-center gap-4 px-5 py-3.5 sm:px-6" wire:key="store-{{ $store->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ $store->name }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ $driverLabels[$store->driver] ?? $store->driver }} ·
                            {{ $store->resolution === \App\Models\ExternalSecretStore::RESOLUTION_ONBOX ? __('resolved outside dply — not supported on Edge yet, deploys that use it fail') : __('dply fetches at deploy') }}
                        </p>
                    </div>
                    @can('update', $organization)
                        <button
                            type="button"
                            class="inline-flex h-8 items-center px-2 text-xs font-semibold text-rose-700 transition hover:text-rose-900"
                            wire:click="openConfirmActionModal('deleteStore', ['{{ $store->id }}'], @js(__('Remove secret store')), @js(__('Remove this store? Apps referencing it will fail to resolve.')), @js(__('Remove')), true)"
                        >
                            {{ __('Remove') }}
                        </button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>
