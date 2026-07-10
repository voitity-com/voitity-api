@php
    $brandName = config('mail.from.name') ?: config('app.name');
    $homeUrl = config('mail.branding.home_url') ?: config('app.url');
    $supportEmail = config('mail.branding.support_email');
    $supportUrl = config('mail.branding.support_url');
@endphp

<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p style="color: #6b7280; font-size: 12px; line-height: 1.5; margin: 0 0 8px;">
© {{ date('Y') }} {{ $brandName }}. All rights reserved.
</p>
<p style="color: #9ca3af; font-size: 12px; line-height: 1.5; margin: 0;">
<a href="{{ $homeUrl }}" style="color: #6b7280;">Website</a>
@if ($supportUrl)
    <span style="color: #d1d5db;"> · </span><a href="{{ $supportUrl }}" style="color: #6b7280;">Support</a>
@elseif ($supportEmail)
    <span style="color: #d1d5db;"> · </span><a href="mailto:{{ $supportEmail }}" style="color: #6b7280;">Support</a>
@endif
</p>
</td>
</tr>
</table>
</td>
</tr>
