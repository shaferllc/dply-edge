@props([
    'slug' => null,
    'docRoute' => null,
    'docSlug' => null,
    'label' => null,
    'size' => 'sm',
])

{{-- The in-app docs panel left with the Docs module. The affordance renders
     nothing rather than a button that opens an empty slide-over; the call
     sites keep their props so docs can come back without touching them. --}}
