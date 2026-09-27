{{-- Sheet content: a single column of groups with even spacing. --}}
<div {{ $attributes->merge(['class' => 'grid content-start gap-5 px-5 py-4']) }}>{{ $slot }}</div>
