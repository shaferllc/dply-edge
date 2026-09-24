@props(['class' => 'h-4 w-4'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path fill="#DC382C" d="M12.2 1.6 22 6.1 12.2 10.6 2.4 6.1 12.2 1.6Z" />
    <path fill="#A41E11" d="M12.2 12.2 22 7.7v3.2L12.2 15.4 2.4 10.9V7.7l9.8 4.5Z" />
    <path fill="#DC382C" d="M12.2 17 22 12.5v3.2L12.2 20.2 2.4 15.7v-3.2L12.2 17Z" />
</svg>
