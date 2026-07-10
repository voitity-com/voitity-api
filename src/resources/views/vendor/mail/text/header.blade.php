{{ config('mail.from.name') ?: config('app.name') }}: {{ config('mail.branding.admin_url') ?: config('mail.branding.home_url') ?: $url }}
