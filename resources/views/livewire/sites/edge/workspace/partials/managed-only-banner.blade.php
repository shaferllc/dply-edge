@unless ($managedDelivery ?? false)
    <div class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-brand-ink dark:text-brand-ink">
        {{ __('These add-ons run on Dply-hosted Edge delivery. Switch this site to managed hosting to enable them.') }}
    </div>
@endunless
