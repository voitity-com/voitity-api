© {{ date('Y') }} {{ config('mail.from.name') ?: config('app.name') }}. All rights reserved.

@if (config('mail.branding.support_url'))
Support: {{ config('mail.branding.support_url') }}
@elseif (config('mail.branding.support_email'))
Support: {{ config('mail.branding.support_email') }}
@endif
