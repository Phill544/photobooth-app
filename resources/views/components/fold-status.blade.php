@props(['fold'])

{{-- The one line a POST left behind, rendered only in the fold it came back to.
     Repeated in every fold it would claim four things happened when one did,
     and the flashed name is the only thing that can tell them apart: the
     fragment that carried the host here never reaches the server. --}}
@if (session('fold') === $fold && session('status'))
    <p class="status" role="status">{{ session('status') }}</p>
@endif
