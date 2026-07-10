@props(['url'])

@php
    $brandName = config('mail.from.name') ?: config('app.name');
    $homeUrl = config('mail.branding.home_url') ?: $url;
    $logoUrl = config('mail.branding.logo_url');
@endphp

<tr>
<td class="header" style="padding: 28px 0 18px; text-align: center;">
<a href="{{ $homeUrl }}" style="display: inline-block; text-decoration: none;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" class="logo" alt="{{ $brandName }} logo" style="border: 0; max-height: 56px; max-width: 180px;">
@else
<span style="color: #111827; font-size: 22px; font-weight: 800; letter-spacing: 0;">{{ $brandName }}</span>
@endif
</a>
</td>
</tr>
