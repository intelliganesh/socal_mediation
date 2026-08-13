<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\LegalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsultationDraftService
{
    public function __construct(private readonly PaymentReconciliationService $payments) {}

    public function createDraft(ConsultationType $type, array $data = []): Consultation
    {
        $this->payments->syncLightweight();

        return DB::transaction(function () use ($type, $data) {
            $consultation = Consultation::create([
                'id' => (string) Str::uuid(),
                'booking_number' => $this->bookingNumber($type->application),
                'consultation_type_id' => $type->id,
                'application' => $type->application,
                'status' => 'draft',
                'payment_status' => 'pending',
                'timezone' => config('app.booking_timezone'),
                'total_amount_cents' => $type->price_cents,
                'currency' => $type->currency,
            ]);

            if ($this->hasDraftFormFields($data)) {
                $this->applyDraftFormFields($consultation, $data);
            }

            return $consultation->refresh()->load(['type', 'participants']);
        });
    }

    public function updateDetails(Consultation $consultation, array $data): Consultation
    {
        return DB::transaction(function () use ($consultation, $data) {
            $primary = $data['primary_client'];
            $legalServiceName = $this->resolveLegalServiceName($consultation, $data['legal_service_name'] ?? null);

            $consultation->update([
                'legal_service_name' => $legalServiceName,
                'consultation_mode' => $data['consultation_mode'],
                'description' => $data['description'] ?? null,
                'referral_source' => $data['referral_source'] ?? null,
                'referral_source_others' => $this->otherReferralSource($data),
                'primary_first_name' => $primary['first_name'],
                'primary_last_name' => $primary['last_name'] ?? null,
                'primary_email' => $primary['email'],
                'primary_phone_country' => $primary['phone_country'] ?? null,
                'primary_phone' => $primary['phone'] ?? null,
                'status' => 'draft',
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

            return $consultation->refresh()->load(['type', 'participants']);
        });
    }

    private function resolveLegalServiceName(Consultation $consultation, ?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $candidate = Str::of($name)->squish()->toString();
        $normalized = Str::of($candidate)->lower()->toString();
        $service = LegalService::query()
            ->where('application', $consultation->application)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if (! $service) {
            throw new \DomainException('Selected legal service name is not available for this application.');
        }

        return $service->name;
    }

    private function hasDraftFormFields(array $data): bool
    {
        return collect([
            'legal_service_name',
            'consultation_mode',
            'description',
            'referral_source',
            'referral_source_others',
            'primary_client',
            'participants',
        ])->contains(fn (string $field) => array_key_exists($field, $data));
    }

    private function applyDraftFormFields(Consultation $consultation, array $data): void
    {
        $primary = $data['primary_client'] ?? [];
        $legalServiceName = $this->resolveLegalServiceName($consultation, $data['legal_service_name'] ?? null);

        $consultation->update([
            'legal_service_name' => $legalServiceName,
            'consultation_mode' => $data['consultation_mode'] ?? null,
            'description' => $data['description'] ?? null,
            'referral_source' => $data['referral_source'] ?? null,
            'referral_source_others' => $this->otherReferralSource($data),
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

    private function otherReferralSource(array $data): ?string
    {
        if (strcasecmp((string) ($data['referral_source'] ?? ''), 'Other Referral') !== 0) {
            return null;
        }

        return $data['referral_source_others'] ?? null;
    }
}
