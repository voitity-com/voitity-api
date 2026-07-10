<x-mail::message>
# {{ $copy['title'] }}

{{ $copy['intro'] }}

<x-mail::button :url="$homeUrl">
{{ $copy['action'] }}
</x-mail::button>

{{ $copy['thanks'] }}<br>
{{ config('mail.from.name') }}
</x-mail::message>
