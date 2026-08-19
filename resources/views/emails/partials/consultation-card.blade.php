@php
    $statusLabel = $statusLabel ?? null;
    $amountCents = $amountCents ?? null;
    $buttonUrl = $buttonUrl ?? null;
    $buttonLabel = $buttonLabel ?? null;
    $secondaryButtonUrl = $secondaryButtonUrl ?? null;
    $secondaryButtonLabel = $secondaryButtonLabel ?? null;
    $rescheduleButtonUrl = $rescheduleButtonUrl ?? null;
    $rescheduleButtonLabel = $rescheduleButtonLabel ?? null;
    $zoomUrl = $zoomUrl ?? null;
    $showSchedule = $showSchedule ?? true;
    $isLegalApplication = ($consultation?->application ?? null) === 'legal';
    $iconPrefix = $isLegalApplication ? 'legal' : 'socal';
    $brandColor = $isLegalApplication ? '#75172E' : '#082BC3';
    $brandSoftColor = $isLegalApplication ? '#E8DDE1' : '#F1F6FE';
    $iconPath = function (array $names): string {
        foreach ($names as $name) {
            $path = 'admin-icons/'.$name.'.svg';
            if (file_exists(public_path($path))) {
                return $path;
            }
        }

        return 'admin-icons/link-svgrepo-com.svg';
    };
    $isPaymentPending = str_contains(strtolower((string) $statusLabel), 'pending');
    $paymentIconName = $isPaymentPending ? 'payment_pending' : 'payment_check';
    $heroIcon = $iconPath([$paymentIconName, $paymentIconName]);
    $professionalIcon = $iconPath([$iconPrefix, $iconPrefix]);
    $statusIcon = $iconPath([$iconPrefix.'_'.$paymentIconName, $paymentIconName, 'check_white']);
    $serviceIcon = $iconPath([$iconPrefix.'_law', $iconPrefix, 'law']);
    $calendarIcon = $iconPath([$iconPrefix.'_calendar', 'calendar', 'socal_calendar']);
    $consultationTypeIcon = 'admin-icons/consultation_type'.($consultation?->consultation_type_id ?: $consultation?->type?->id).'.svg';
    if (! file_exists(public_path($consultationTypeIcon))) {
        $consultationTypeIcon = $serviceIcon;
    }
    $timezone = $consultation?->timezone ?: config('app.booking_timezone', 'America/Los_Angeles');
    $scheduledAt = $showSchedule ? (($scheduledAt ?? $consultation?->starts_at)?->timezone($timezone)) : null;
    $professional = $consultation?->professional?->name ?: 'Not assigned yet';
    $mode = match ($consultation?->consultation_mode) {
        'online' => 'Video Consultation'.($zoomUrl ? ' (Zoom)' : ''),
        'offline' => 'In-Person Consultation',
        'in_person' => 'In-Person Consultation',
        'phone' => 'Phone Consultation',
        default => 'Not selected',
    };
    $modeIconSuffix = match ($consultation?->consultation_mode) {
        'online' => 'video',
        'offline' => 'inperson',
        'in_person' => 'inperson',
        'phone' => 'phone',
        default => 'video',
    };

    $modeIcon=$iconPath([$iconPrefix.'_'.$modeIconSuffix, $iconPrefix, 'video']);
    $modeDetail = match ($consultation?->consultation_mode) {
        'phone' => [
            'label' => 'Phone Number',
            'value' => config('app.consultation_contact.phone.'.($consultation?->application ?? 'socal')),
            'icon' => $iconPath([$iconPrefix.'_phone', 'phone']),
        ],
        'offline', 'in_person' => [
            'label' => 'Location Address',
            'value' => config('app.consultation_contact.location_address.'.($consultation?->application ?? 'socal')),
            'icon' => $iconPath([$iconPrefix.'_inperson', 'inperson']),
        ],
        default => null,
    };
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:#f7f5fb;color:#111827;font-family:Georgia,'Times New Roman',serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7f5fb;margin:0;padding:0;">
        <tr>
            <td style="background:#ffffff;padding:14px 32px;border-bottom:1px solid #e5e7eb;">
                <img src="{{ asset('admin-icons/'.$consultation->application.'.png') }}" width="190" alt="SoCal Mediation Center" style="display:block;max-width:190px;height:auto;  margin: 0 auto;">
            </td>
        </tr>
        <tr>
            <td align="center" style="padding:32px 16px 42px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;">
                    <tr>
                        <td align="center">
                            <div style="width:70px;height:70px;border-radius:999px;background:{{ $brandColor }};text-align:center;line-height:70px;">
                                <img src="{{ asset($heroIcon) }}" width="34" height="34" alt="" style="display:inline-block;width:34px;height:34px;vertical-align:middle;">
                            </div>
                            <h1 style="margin:22px 0 10px;font-size:34px;line-height:1.15;color:#111827;font-weight:700;">{{ $title }}</h1>
                            <p style="margin:0 auto 28px;max-width:440px;color:#4b5563;font-size:14px;line-height:1.55;">{!! $intro !!}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #d7dce8;border-radius:8px;overflow:hidden;background:#ffffff;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="background:{{ $brandColor }};color:#ffffff;padding:12px 26px;font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:.02em;">
                                        BOOKING ID: {{ $consultation?->booking_number }}
                                    </td>
                                    @if($statusLabel)
                                        <td align="right" style="background:{{ $brandColor }};padding:12px 26px;">
                                            <span style="display:inline-block;border-radius:999px;background:#ffffff;color:{{ $brandColor }};padding:5px 12px;font-family:Arial,sans-serif;font-size:12px;font-weight:700;">
                                                <img src="{{ asset($statusIcon) }}" width="13" height="13" alt="" style="display:inline-block;width:13px;height:13px;vertical-align:-2px;margin-right:5px;">
                                                {{ $statusLabel }}
                                            </span>
                                        </td>
                                    @endif
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:26px 26px 12px;">
                                <tr>
                                    <td width="50%" valign="top" style="padding:0 18px 22px 0;">
                                        <div style="font-size:12px;color:#374151;margin-bottom:7px;">Service Type</div>
                                        <div style="font-size:14px;line-height:1.4;">
                                            <img src="{{ asset($serviceIcon) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                            {{ $consultation?->legal_service_name ?: 'Legal Consultation' }}
                                        </div>
                                    </td>
                                    <td width="50%" valign="top" style="padding:0 0 22px 18px;">
                                        <div style="font-size:12px;color:#374151;margin-bottom:7px;">{{ $showSchedule ? 'Time & Date' : 'Slot Selection' }}</div>
                                        <div style="font-size:14px;line-height:1.4;">
                                            <img src="{{ asset($calendarIcon) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                            {{ $showSchedule ? ($scheduledAt ? $scheduledAt->format('M d, Y - g:i A').' '.$timezone : 'Not scheduled') : 'Please choose your preferred available slot.' }}
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="50%" valign="top" style="padding:0 18px 22px 0;">
                                        <div style="font-size:12px;color:#374151;margin-bottom:7px;">Professional</div>
                                       {{--  <div style="font-size:14px;line-height:1.4;">
                                            <span style="display:inline-block;width:20px;height:20px;border-radius:999px;background:{{ $brandSoftColor }};text-align:center;vertical-align:-5px;margin-right:7px;">
                                                <img src="{{ asset($professionalIcon) }}" width="12" height="12" alt="" style="display:inline-block;width:12px;height:12px;vertical-align:middle;margin-top:4px;">
                                            </span>
                                            {{ $professional }}
                                        </div> --}}

                                        <div style="font-size:14px;line-height:1.4;">
                                            <img src="{{ asset($professionalIcon) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                            {{ $professional }}
                                        </div>
                                    </td>
                                    <td width="50%" valign="top" style="padding:0 0 22px 18px;">
                                        <div style="font-size:12px;color:#374151;margin-bottom:7px;">Consultation mode</div>
                                        <div style="font-size:14px;line-height:1.4;">
                                            <img src="{{ asset($modeIcon) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                            {{ $mode }}
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="50%" valign="top" style="padding:0 18px 22px 0;">
                                        <div style="font-size:12px;color:#374151;margin-bottom:7px;">Consultation Type</div>
                                        {{-- <div style="font-size:14px;line-height:1.4;">
                                            <span style="display:inline-block;width:20px;height:20px;border-radius:999px;background:{{ $brandSoftColor }};text-align:center;vertical-align:-5px;margin-right:7px;">
                                                <img src="{{ asset($consultationTypeIcon) }}" width="12" height="12" alt="" style="display:inline-block;width:12px;height:12px;vertical-align:middle;margin-top:4px;">
                                            </span>
                                            {{ $consultation?->type?->name }}
                                        </div> --}}
                                        <div style="font-size:14px;line-height:1.4;">
                                            <img src="{{ asset($consultationTypeIcon) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                            {{ $consultation?->type?->name }}
                                        </div>
                                    </td>
                                    <td width="50%" valign="top" style="padding:0 0 22px 18px;">
                                        @if($zoomUrl)
                                            <div style="font-size:12px;color:#374151;margin-bottom:7px;">Zoom Meeting</div>
                                            <a href="{{ $zoomUrl }}" style="color:{{ $brandColor }};font-size:14px;font-weight:700;text-decoration:none;">
                                                <img src="{{ asset('admin-icons/video.svg') }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                                Click here to join zoom meeting
                                                <p style="font-size:8px">{{ $zoomUrl }}</p>
                                            </a>
                                        @elseif($modeDetail && filled($modeDetail['value']))
                                            <div style="font-size:12px;color:#374151;margin-bottom:7px;">{{ $modeDetail['label'] }}</div>
                                            <div style="font-size:14px;line-height:1.4;">
                                                <img src="{{ asset($modeDetail['icon']) }}" width="14" height="14" alt="" style="display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:7px;">
                                                {{ $modeDetail['value'] }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="border-top:1px solid #d7dce8;padding:24px 0 2px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="font-size:14px;color:#374151;">Consultation Fee</td>
                                                <td align="right" style="font-size:18px;font-weight:700;color:#111827;">{{ ($consultation?->currency=='USD') ? '$' : $consultation?->currency ?? '-' }} {{ number_format(($amountCents ?? $consultation?->total_amount_cents ?? 0) / 100, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if(($buttonUrl && $buttonLabel) || ($secondaryButtonUrl && $secondaryButtonLabel) || ($rescheduleButtonUrl && $rescheduleButtonLabel))
                        <tr>
                            <td align="center" style="padding-top:26px;">
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        @if($buttonUrl && $buttonLabel)
                                            <td align="center" bgcolor="{{ $brandColor }}" style="border:1px solid {{ $brandColor }};border-radius:8px;">
                                                <a href="{{ $buttonUrl }}" style="display:inline-block;color:#ffffff;text-decoration:none;padding:14px 30px;font-family:Arial,sans-serif;font-size:14px;line-height:18px;font-weight:700;">{{ $buttonLabel }}</a>
                                            </td>
                                        @endif
                                        @if($buttonUrl && $buttonLabel && (($secondaryButtonUrl && $secondaryButtonLabel) || ($rescheduleButtonUrl && $rescheduleButtonLabel)))
                                            <td width="16" style="width:16px;font-size:1px;line-height:1px;">&nbsp;</td>
                                        @endif
                                        @if($secondaryButtonUrl && $secondaryButtonLabel)
                                            <td align="center" bgcolor="#ffffff" style="border:1px solid {{ $brandColor }};border-radius:8px;">
                                                <a href="{{ $secondaryButtonUrl }}" style="display:inline-block;color:{{ $brandColor }};text-decoration:none;padding:14px 30px;font-family:Arial,sans-serif;font-size:14px;line-height:18px;font-weight:700;">{{ $secondaryButtonLabel }}</a>
                                            </td>
                                        @endif
                                        @if($secondaryButtonUrl && $secondaryButtonLabel && $rescheduleButtonUrl && $rescheduleButtonLabel)
                                            <td width="16" style="width:16px;font-size:1px;line-height:1px;">&nbsp;</td>
                                        @endif
                                        @if($rescheduleButtonUrl && $rescheduleButtonLabel)
                                            <td align="center" bgcolor="#ffffff" style="border:1px solid {{ $brandColor }};border-radius:8px;">
                                                <a href="{{ $rescheduleButtonUrl }}" style="display:inline-block;color:{{ $brandColor }};text-decoration:none;padding:14px 30px;font-family:Arial,sans-serif;font-size:14px;line-height:18px;font-weight:700;">{{ $rescheduleButtonLabel }}</a>
                                            </td>
                                        @endif
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
