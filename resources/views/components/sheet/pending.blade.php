@props([
    'name',
    'maxWidth' => 'lg',
])

{{-- A sheet whose body has not loaded: the placeholder of a skipped Livewire
     island (edge Resources). It slides in at once; <x-sheet> loads the
     island the first time it comes to the top, and the body morphs in. --}}
<x-sheet :name="$name" :maxWidth="$maxWidth">
    <div data-sheet-pending class="grid gap-3 p-6" aria-busy="true">
        <span class="h-3 w-24 animate-pulse rounded-full bg-brand-ink/10 dark:bg-brand-mist/15"></span>
        <span class="h-6 w-48 animate-pulse rounded-lg bg-brand-ink/10 dark:bg-brand-mist/15"></span>
        <span class="mt-4 h-24 animate-pulse rounded-2xl bg-brand-ink/5 dark:bg-brand-mist/10"></span>
        <span class="sr-only">{{ __('Loading…') }}</span>
    </div>
</x-sheet>
