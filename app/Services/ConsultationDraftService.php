<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\LegalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsultationDraftService
{
    public function createDraft(ConsultationType $type, array $data = []): Consultation
    {
        return DB::transaction(function () use ($type, $data) {
            $consultation = Consultation::create([
                'uuid' => (string) Str::uuid(),
                'booking_number' => $this->bookingNumber($type->application),
                'consultation_type_id' => $type->id,
                'application' => $type->application,
                'status' => 'draft',
                'payment_status' => $type->price_cents === 0 ? 'not_required' : 'not_started',
                'timezone' => config('app.booking_timezone', 'America/Los_Angeles'),
                'total_amount_cents' => $type->price_cents,
                'currency' => $type->currency,
            ]);

            if ($this->hasDraftFormFields($data)) {
                $this->applyDraftFormFields($consultation, $data);
            }

            return $consultation->refresh()->load(['type', 'legalService', 'participants']);
        });
    }

    public function updateDetails(Consultation $consultation, array $data): Consultation
    {
        return DB::transaction(function () use ($consultation, $data) {
            $primary = $data['primary_client'];
            $legalService = $this->resolveLegalService($consultation, $data['legal_service_name'] ?? null);

            $consultation->update([
                'legal_service_id' => $legalService?->id,
                'consultation_mode' => $data['consultation_mode'],
                'description' => $data['description'] ?? null,
                'referral_source' => $data['referral_source'] ?? null,
                'primary_first_name' => $primary['first_name'],
                'primary_last_name' => $primary['last_name'] ?? null,
                'primary_email' => $primary['email'],
                'primary_phone_country' => $primary['phone_country'] ?? null,
                'primary_phone' => $primary['phone'] ?? null,
                'status' => 'details_complete',
            ]);

            $consultation->participants()->delete();
            $participants = $data['participants'] ?? [];
            array_unshift($participants, $primary + ['is_primary' => true]);

            foreach ($participants as $participant) {
                $consultation->participants()->create([
                    'first_name' => $participant['first_name'],
                    'last_name' => $participant['last_name'] ?? null,
                    'email' => $participant['email'] ?? null,
                    'phone_country' => $participant['phone_country'] ?? null,
                    'phone' => $participant['phone'] ?? null,
                    'is_primary' => (bool) ($participant['is_primary'] ?? false),
                ]);
            }

            return $consultation->refresh()->load(['type', 'legalService', 'participants']);
        });
    }

    private function resolveLegalService(Consultation $consultation, ?string $name): ?LegalService
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = Str::of($name)->squish()->lower()->toString();
        $service = LegalService::query()
            ->where('application', $consultation->application)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if (! $service) {
            throw new \DomainException('Selected legal service name is not available for this application.');
        }

        return $service;
    }

    private function hasDraftFormFields(array $data): bool
    {
        return collect([
            'legal_service_name',
            'consultation_mode',
            'description',
            'referral_source',
            'primary_client',
            'participants',
        ])->contains(fn (string $field) => array_key_exists($field, $data));
    }

    private function applyDraftFormFields(Consultation $consultation, array $data): void
    {
        $primary = $data['primary_client'] ?? [];
        $legalService = $this->resolveLegalService($consultation, $data['legal_service_name'] ?? null);

        $consultation->update([
            'legal_service_id' => $legalService?->id,
            'consultation_mode' => $data['consultation_mode'] ?? null,
            'description' => $data['description'] ?? null,
            'referral_source' => $data['referral_source'] ?? null,
            'primary_first_name' => $primary['first_name'] ?? null,
            'primary_last_name' => $primary['last_name'] ?? null,
            'primary_email' => $primary['email'] ?? null,
            'primary_phone_country' => $primary['phone_country'] ?? null,
            'primary_phone' => $primary['phone'] ?? null,
            'status' => 'draft',
        ]);

        $participants = $data['participants'] ?? [];
        $hasPrimaryParticipant = array_filter($primary) !== [];

        $consultation->participants()->delete();

        if ($hasPrimaryParticipant) {
            array_unshift($participants, $primary + ['is_primary' => true]);
        }

        foreach ($participants as $participant) {
            if (empty($participant['first_name'])) {
                continue;
            }

            $consultation->participants()->create([
                'first_name' => $participant['first_name'],
                'last_name' => $participant['last_name'] ?? null,
                'email' => $participant['email'] ?? null,
                'phone_country' => $participant['phone_country'] ?? null,
                'phone' => $participant['phone'] ?? null,
                'is_primary' => (bool) ($participant['is_primary'] ?? false),
            ]);
        }
    }

    private function bookingNumber(string $application): string
    {
        $prefix = $application === 'legal' ? 'SCM' : 'LX';

        do {
            $number = sprintf('%s-%s', $prefix, random_int(10000, 99999));
        } while (Consultation::where('booking_number', $number)->exists());

        return $number;
    }
}
