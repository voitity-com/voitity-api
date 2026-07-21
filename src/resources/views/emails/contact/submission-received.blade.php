<x-mail::message>
# Nuevo contacto desde Bigmelo

Recibiste una nueva solicitud desde el formulario público.

<x-mail::panel>
Nombre: {{ $submission->name }}

Correo: {{ $submission->email }}

Teléfono: {{ $submission->phone() }}

Idioma: {{ strtoupper($submission->locale) }}

Fuente: {{ $submission->source }}
</x-mail::panel>

## Mensaje

{{ $submission->message }}

@if(! empty($submission->metadata['page_url']) || ! empty($submission->metadata['referrer']))
## Contexto

@if(! empty($submission->metadata['page_url']))
Página: {{ $submission->metadata['page_url'] }}
@endif

@if(! empty($submission->metadata['referrer']))
Referidor: {{ $submission->metadata['referrer'] }}
@endif
@endif

IP: {{ $submission->ip_address ?? 'No disponible' }}

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
