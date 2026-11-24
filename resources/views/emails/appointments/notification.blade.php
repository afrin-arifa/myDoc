@php
    $palette = match ($tone) {
        'success' => ['accent' => '#15803d', 'soft' => '#e8f6ef', 'label' => 'Confirmed'],
        'warning' => ['accent' => '#b45309', 'soft' => '#fdf3e7', 'label' => 'Action noted'],
        default => ['accent' => '#167d91', 'soft' => '#e7f4f6', 'label' => 'Appointment update'],
    };
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <title>{{ $subject }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; }
            .email-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .detail-label, .detail-value { display: block !important; width: 100% !important; }
            .detail-label { padding-bottom: 0 !important; }
            .schedule-cell { display: block !important; width: 100% !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f7f7; font-family:Arial, Helvetica, sans-serif; color:#1f2933; -webkit-text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $introduction }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f7f7;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #e1e7ec;">
                    <tr>
                        <td class="email-padding" style="background-color:#167d91; padding:22px 32px;">
                            <span style="font-size:22px; font-weight:bold; color:#ffffff; letter-spacing:0.3px;">{{ $appName }}</span>
                            <span style="font-size:13px; color:#d4eef2; padding-left:8px;">Appointments</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:4px; line-height:4px; font-size:0; background-color:{{ $palette['accent'] }};">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding:32px 32px 8px;">
                            <p style="margin:0 0 12px; display:inline-block; padding:4px 10px; border-radius:999px; background-color:{{ $palette['soft'] }}; color:{{ $palette['accent'] }}; font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:0.6px;">{{ $palette['label'] }}</p>
                            <h1 style="margin:0 0 16px; font-size:24px; line-height:32px; color:#102a33;">{{ $heading }}</h1>
                            @if ($recipientName)
                                <p style="margin:0 0 12px; font-size:16px; line-height:24px;">Hello {{ $recipientName }},</p>
                            @endif
                            <p style="margin:0 0 24px; font-size:16px; line-height:24px;">{{ $introduction }}</p>
                        </td>
                    </tr>

                    @if ($scheduleChange)
                        <tr>
                            <td class="email-padding" style="padding:0 32px 20px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td class="schedule-cell" width="50%" valign="top" style="padding:0 6px 12px 0;">
                                            <div style="border:1px solid #e1e7ec; border-radius:8px; padding:14px 16px; background-color:#f8fafb;">
                                                <p style="margin:0 0 6px; font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:0.6px; color:#5b6b73;">Previous time</p>
                                                <p style="margin:0; font-size:15px; line-height:22px; color:#5b6b73; text-decoration:line-through;">{{ $scheduleChange['previous']['date'] }}<br>{{ $scheduleChange['previous']['time'] }}@isset($scheduleChange['previous']['location'])<br>{{ $scheduleChange['previous']['location'] }}@endisset</p>
                                            </div>
                                        </td>
                                        <td class="schedule-cell" width="50%" valign="top" style="padding:0 0 12px 6px;">
                                            <div style="border:1px solid {{ $palette['accent'] }}; border-radius:8px; padding:14px 16px; background-color:{{ $palette['soft'] }};">
                                                <p style="margin:0 0 6px; font-size:12px; font-weight:bold; text-transform:uppercase; letter-spacing:0.6px; color:{{ $palette['accent'] }};">New time</p>
                                                <p style="margin:0; font-size:15px; line-height:22px; font-weight:bold; color:#102a33;">{{ $scheduleChange['current']['date'] }}<br>{{ $scheduleChange['current']['time'] }}@isset($scheduleChange['current']['location'])<br>{{ $scheduleChange['current']['location'] }}@endisset</p>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="email-padding" style="padding:0 32px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e1e7ec; border-radius:8px;">
                                @foreach ($details as $label => $value)
                                    <tr>
                                        <td class="detail-label" width="35%" valign="top" style="padding:12px 16px; font-size:13px; font-weight:bold; color:#5b6b73; {{ $loop->last ? '' : 'border-bottom:1px solid #eef2f5;' }}">{{ $label }}</td>
                                        <td class="detail-value" valign="top" style="padding:12px 16px; font-size:15px; color:#102a33; {{ $loop->last ? '' : 'border-bottom:1px solid #eef2f5;' }}">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="email-padding" align="center" style="padding:0 32px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="border-radius:10px; background-color:#167d91;">
                                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display:inline-block; padding:14px 28px; font-size:16px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $actionLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0; font-size:12px; line-height:18px; color:#5b6b73; word-break:break-all;">Or open this link: <a href="{{ $actionUrl }}" style="color:#167d91;">{{ $actionUrl }}</a></p>
                        </td>
                    </tr>

                    @if ($closing)
                        <tr>
                            <td class="email-padding" style="padding:0 32px 28px;">
                                <p style="margin:0; font-size:14px; line-height:22px; color:#3e4c55;">{{ $closing }}</p>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="email-padding" style="padding:20px 32px; background-color:#f8fafb; border-top:1px solid #e1e7ec;">
                            <p style="margin:0; font-size:12px; line-height:18px; color:#5b6b73;">{{ $footerNote ?? 'This is an automated message from '.$appName.' about your appointment. For your privacy, it contains scheduling details only. Sign in to '.$appName.' to see everything else.' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
