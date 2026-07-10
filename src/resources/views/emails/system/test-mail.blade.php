<x-mail::message>
# Email configuration ready

This test confirms that {{ config('mail.from.name') }} can render and send branded emails.

<x-mail::panel>
Recipient: {{ $recipientEmail }}

Mailer: {{ $mailer }}
</x-mail::panel>

<x-mail::button :url="$homeUrl">
Open bigmelo
</x-mail::button>

Thanks,<br>
{{ config('mail.from.name') }}
</x-mail::message>
