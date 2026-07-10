<x-mail::message>
# Prueba local de correo

Este correo confirma que {{ config('mail.from.name') }} puede renderizar y enviar emails con identidad visual desde el entorno local.

<x-mail::panel>
Destinatario: {{ $recipientEmail }}

Mailer: {{ $mailer }}
</x-mail::panel>

<x-mail::button :url="$homeUrl">
Abrir bigmelo
</x-mail::button>

Este es un mensaje de ejemplo para validar el header con logo, el contenido principal y el footer del correo antes de conectar las notificaciones reales.

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
