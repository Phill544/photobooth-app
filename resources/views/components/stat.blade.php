{{-- A big serif figure over a mono caption. The two halves are one phrase for
     screen readers, which would otherwise read them as unrelated fragments. --}}
@props(['figure', 'label', 'say' => null])
<div {{ $attributes->class('stat') }}>
    <p class="figure" aria-hidden="true">{{ $figure }}</p>
    <p class="label" aria-hidden="true">{{ $label }}</p>
    <span class="sr-only">{{ $say ?? $figure.' '.$label }}</span>
</div>
