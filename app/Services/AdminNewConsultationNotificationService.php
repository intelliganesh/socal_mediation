<?php

namespace App\Services;

use App\Mail\AdminNewConsultationRequestMail;
use App\Models\AppSetting;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class AdminNewConsultationNotificationService
{
    private const SETTING_KEY = 'admin_new_consultation_notifications';

    public function settings(): array
    {
        $value = AppSetting::query()->where('key', self::SETTING_KEY)->value('value') ?? [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'emails' => collect($value['emails'] ?? [])
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->values()
                ->all(),
        ];
    }

    public function save(bool $enabled, array $emails): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => [
                'enabled' => $enabled,
                'emails' => collect($emails)
                    ->map(fn ($email) => strtolower(trim((string) $email)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]]
        );
    }

    public function sendForNewRequest(Consultation $consultation): int
    {
        if ($consultation->status === 'draft') {
            return 0;
        }

        $settings = $this->settings();
        if (! $settings['enabled'] || $settings['emails'] === []) {
            return 0;
        }

        $consultation->loadMissing(['type', 'professional', 'participants']);

        foreach ($settings['emails'] as $email) {
            Mail::to($email)->send(new AdminNewConsultationRequestMail($consultation));
        }

        $consultation->integrationLogs()->create([
            'provider' => 'mail',
            'action' => 'admin_new_consultation_request',
            'status' => 'sent',
            'message' => 'New consultation request notification sent to configured admin recipients.',
            'response_payload' => [
                'recipient_count' => count($settings['emails']),
                'recipients' => $settings['emails'],
            ],
        ]);

        return count($settings['emails']);
    }
}
