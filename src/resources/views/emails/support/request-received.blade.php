<x-mail::message>
# Nueva solicitud de soporte

Un usuario autenticado envió una solicitud desde el administrador de Bigmelo.

<x-mail::panel>
Solicitud: #{{ $supportRequest->id }}

Usuario: #{{ $supportRequest->user_id }}

Correo: {{ $supportRequest->email }}

Perfil: @if($supportRequest->profile_id){{ $supportRequest->profile_alias ?? 'Sin alias' }} (#{{ $supportRequest->profile_id }})@else Sin perfil relacionado @endif
</x-mail::panel>

## Descripción

{{ $supportRequest->description }}

Enviada: {{ $supportRequest->created_at?->toIso8601String() }}

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
