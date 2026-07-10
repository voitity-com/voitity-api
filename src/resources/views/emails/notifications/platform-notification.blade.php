<x-mail::message>
# {{ $message->title }}

{{ $message->body }}

@if ($message->actionUrl && $message->actionLabel)
<x-mail::button :url="$message->actionUrl">
{{ $message->actionLabel }}
</x-mail::button>
@endif

{{ $user->locale === 'es' ? 'Gracias,' : 'Thanks,' }}<br>
{{ config('mail.from.name') }}
</x-mail::message>
