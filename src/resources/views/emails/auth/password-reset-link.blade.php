<x-mail::message>
# {{ $copy['title'] }}

{{ $copy['intro'] }}

<x-mail::button :url="$resetUrl">
{{ $copy['action'] }}
</x-mail::button>

{{ $copy['outro'] }}

{{ $copy['thanks'] }}<br>
{{ config('mail.from.name') }}
</x-mail::message>
