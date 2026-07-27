<?php

namespace Database\Seeders;

use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\LegalService;
use App\Models\PaymentRequest;
use App\Models\Professional;
use App\Models\User;
use Carbon\CarbonImmutable;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate([
            'email' => 'admin@socal.test',
        ], [
            'name' => 'Socal Admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $types = [
            ['application' => 'socal', 'name' => 'Free 15-Min Intro Call', 'slug' => 'socal-free-intro-call', 'duration_minutes' => 15, 'price_cents' => 0, 'max_participants' => 5, 'allows_split_payment' => false, 'allows_phone' => true, 'allows_online' => false, 'allows_offline' => false],
            ['application' => 'socal', 'name' => '1/2 Day Mediation', 'slug' => 'socal-half-day-mediation', 'duration_minutes' => 240, 'price_cents' => 260000, 'max_participants' => 5, 'allows_split_payment' => true, 'allows_phone' => false, 'allows_online' => true, 'allows_offline' => true],
            ['application' => 'socal', 'name' => 'Full Day Mediation', 'slug' => 'socal-full-day-mediation', 'duration_minutes' => 480, 'price_cents' => 520000, 'max_participants' => 5, 'allows_split_payment' => true, 'allows_phone' => false, 'allows_online' => true, 'allows_offline' => true],
            ['application' => 'legal', 'name' => 'Professional Consultation', 'slug' => 'legal-professional-consultation', 'duration_minutes' => 60, 'price_cents' => 19500, 'max_participants' => 1, 'allows_split_payment' => false, 'allows_phone' => true, 'allows_online' => true, 'allows_offline' => true],
        ];

        foreach ($types as $type) {
            ConsultationType::updateOrCreate(['slug' => $type['slug']], $type + ['currency' => 'USD', 'is_active' => true]);
        }

        foreach ([
            'Business, Payment & Contract Disputes',
            'Construction & Home Repair Disputes',
            'Consumer Complaints',
            'Debt or Money Owed Matters',
            'Divorce & Family Matters',
            'HOA & Community Disputes',
            'Landlord & Tenant Disputes',
            'Neighbor & Property Disputes',
            'Real Estate & Escrow Disputes',
            'Workplace or Employment Issues',
            'Other Civil Matter',
        ] as $name) {
            LegalService::updateOrCreate([
                'slug' => str($name)->slug()->toString(),
            ], [
                'application' => 'socal',
                'name' => $name,
                'is_active' => true,
            ]);
        }

        LegalService::updateOrCreate([
            'slug' => 'professional-legal-consultation',
        ], [
            'application' => 'legal',
            'name' => 'Professional Legal Consultation',
            'is_active' => true,
        ]);

        Professional::updateOrCreate([
            'email' => 'sarah@socal.test',
        ], [
            'name' => 'Sarah Thompson',
            'title' => 'Senior Legal Partner',
            'timezone' => 'America/Los_Angeles',
            'applications' => ['socal'],
            'is_active' => true,
        ]);

        Professional::updateOrCreate([
            'email' => 'steve@socal.test',
        ], [
            'name' => 'Steve Lopez',
            'title' => 'Senior Mediator',
            'timezone' => 'America/Los_Angeles',
            'applications' => ['legal'],
            'is_active' => true,
        ]);

        $this->seedSampleConsultations();
    }

    private function seedSampleConsultations(): void
    {
        $sampleNumbers = collect(range(1, 10))->map(fn (int $index) => sprintf('SAMPLE-%02d', $index))->all();
        Consultation::withTrashed()->whereIn('booking_number', $sampleNumbers)->forceDelete();

        $types = ConsultationType::pluck('id', 'slug');
        $services = LegalService::pluck('name', 'slug');
        $professionals = Professional::pluck('id', 'email');
        $base = CarbonImmutable::now('America/Los_Angeles')->startOfMonth()->addMonth()->setTime(9, 0);
        $sharedCalendarDate = $base->addDays(10);

        $samples = [
            [
                'number' => 'SAMPLE-01',
                'type' => 'socal-free-intro-call',
                'service' => 'business-payment-contract-disputes',
                'professional' => 'sarah@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'paid',
                'mode' => 'phone',
                'client' => ['Avery', 'Stone', 'avery.stone@example.com'],
                'starts_at' => $base,
                'participants' => [],
                'payment_mode' => null,
                'payments' => [],
            ],
            [
                'number' => 'SAMPLE-02',
                'type' => 'socal-half-day-mediation',
                'service' => 'construction-home-repair-disputes',
                'professional' => 'sarah@socal.test',
                'status' => 'payment_pending',
                'payment_status' => 'pending',
                'mode' => 'online',
                'client' => ['Blake', 'Reed', 'blake.reed@example.com'],
                'starts_at' => $sharedCalendarDate->setTime(9, 0),
                'participants' => [['Casey', 'Reed', 'casey.reed@example.com']],
                'payment_mode' => 'full',
                'payments' => ['pending'],
            ],
            [
                'number' => 'SAMPLE-03',
                'type' => 'socal-half-day-mediation',
                'service' => 'landlord-tenant-disputes',
                'professional' => 'sarah@socal.test',
                'status' => 'payment_pending',
                'payment_status' => 'partially_paid',
                'mode' => 'offline',
                'client' => ['Camila', 'Nguyen', 'camila.nguyen@example.com'],
                'starts_at' => $sharedCalendarDate->setTime(10, 30),
                'participants' => [['Diego', 'Nguyen', 'diego.nguyen@example.com'], ['Elena', 'Park', 'elena.park@example.com']],
                'payment_mode' => 'split',
                'payments' => ['paid', 'pending', 'pending'],
            ],
            [
                'number' => 'SAMPLE-04',
                'type' => 'socal-full-day-mediation',
                'service' => 'divorce-family-matters',
                'professional' => 'sarah@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'paid',
                'mode' => 'online',
                'client' => ['Elliot', 'Harper', 'elliot.harper@example.com'],
                'starts_at' => $sharedCalendarDate->setTime(13, 0),
                'participants' => [['Finley', 'Harper', 'finley.harper@example.com'], ['Gray', 'Lopez', 'gray.lopez@example.com'], ['Hayden', 'Lopez', 'hayden.lopez@example.com']],
                'payment_mode' => 'split',
                'payments' => ['paid', 'paid', 'paid', 'paid'],
                'zoom' => true,
            ],
            [
                'number' => 'SAMPLE-05',
                'type' => 'socal-full-day-mediation',
                'service' => 'real-estate-escrow-disputes',
                'professional' => 'sarah@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'pending',
                'mode' => 'offline',
                'client' => ['Iris', 'Cole', 'iris.cole@example.com'],
                'starts_at' => $sharedCalendarDate->setTime(15, 30),
                'participants' => [['Jordan', 'Cole', 'jordan.cole@example.com']],
                'payment_mode' => null,
                'payments' => [],
            ],
            [
                'number' => 'SAMPLE-06',
                'type' => 'socal-full-day-mediation',
                'service' => 'workplace-or-employment-issues',
                'professional' => 'sarah@socal.test',
                'status' => 'draft',
                'payment_status' => 'pending',
                'mode' => null,
                'client' => ['Kai', 'Morgan', 'kai.morgan@example.com'],
                'starts_at' => null,
                'participants' => [],
                'payment_mode' => null,
                'payments' => [],
            ],
            [
                'number' => 'SAMPLE-07',
                'type' => 'legal-professional-consultation',
                'service' => 'professional-legal-consultation',
                'professional' => 'steve@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'paid',
                'mode' => 'online',
                'client' => ['Lena', 'Ortiz', 'lena.ortiz@example.com'],
                'starts_at' => $sharedCalendarDate->setTime(16, 30),
                'participants' => [],
                'payment_mode' => 'full',
                'payments' => ['paid'],
                'zoom' => true,
            ],
            [
                'number' => 'SAMPLE-08',
                'type' => 'legal-professional-consultation',
                'service' => 'professional-legal-consultation',
                'professional' => 'steve@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'pending',
                'mode' => 'offline',
                'client' => ['Miles', 'Patel', 'miles.patel@example.com'],
                'starts_at' => $base->addDays(6)->setTime(13, 0),
                'participants' => [],
                'payment_mode' => 'full',
                'payments' => ['pending'],
            ],
            [
                'number' => 'SAMPLE-09',
                'type' => 'legal-professional-consultation',
                'service' => 'professional-legal-consultation',
                'professional' => 'steve@socal.test',
                'status' => 'draft',
                'payment_status' => 'pending',
                'mode' => 'phone',
                'client' => ['Nora', 'Singh', 'nora.singh@example.com'],
                'starts_at' => null,
                'participants' => [],
                'payment_mode' => null,
                'payments' => [],
            ],
            [
                'number' => 'SAMPLE-10',
                'type' => 'socal-half-day-mediation',
                'service' => 'neighbor-property-disputes',
                'professional' => 'sarah@socal.test',
                'status' => 'scheduled',
                'payment_status' => 'paid',
                'mode' => 'online',
                'client' => ['Owen', 'Kim', 'owen.kim@example.com'],
                'starts_at' => $base->addDays(8),
                'participants' => [['Priya', 'Kim', 'priya.kim@example.com'], ['Quinn', 'Diaz', 'quinn.diaz@example.com'], ['Riley', 'Diaz', 'riley.diaz@example.com'], ['Sage', 'Fox', 'sage.fox@example.com']],
                'payment_mode' => 'split',
                'payments' => ['paid', 'paid', 'paid', 'paid', 'paid'],
                'zoom' => true,
            ],
        ];

        foreach ($samples as $sample) {
            $type = ConsultationType::find($types[$sample['type']]);
            $startsAt = $sample['starts_at'];
            $endsAt = $startsAt?->addMinutes($type->duration_minutes);
            [$firstName, $lastName, $email] = $sample['client'];

            $consultation = Consultation::create([
                'id' => (string) Str::uuid(),
                'booking_number' => $sample['number'],
                'consultation_type_id' => $type->id,
                'legal_service_name' => $services[$sample['service']] ?? null,
                'professional_id' => $professionals[$sample['professional']] ?? null,
                'application' => $type->application,
                'status' => $sample['status'],
                'payment_status' => $sample['payment_status'],
                'consultation_mode' => $sample['mode'],
                'timezone' => 'America/Los_Angeles',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'description' => 'Seeded sample consultation for admin review and API testing.',
                'referral_source' => 'Sample data',
                'primary_first_name' => $firstName,
                'primary_last_name' => $lastName,
                'primary_email' => $email,
                'primary_phone_country' => '+1',
                'primary_phone' => '(555) 010-'.substr($sample['number'], -2).'00',
                'total_amount_cents' => $type->price_cents,
                'currency' => 'USD',
                'payment_mode' => $sample['payment_mode'],
                'zoom_meeting_id' => ($sample['zoom'] ?? false) ? random_int(1000000000, 9999999999) : null,
                'zoom_join_url' => ($sample['zoom'] ?? false) ? 'https://zoom.us/j/'.random_int(1000000000, 9999999999) : null,
                'confirmed_at' => $sample['payment_status'] === 'paid' ? now() : null,
                'metadata' => ['sample_seed' => true],
            ]);

            $participants = collect([[$firstName, $lastName, $email]])->merge($sample['participants']);
            $shareCount = max(1, count($sample['payments']));
            $share = $shareCount > 0 ? intdiv($type->price_cents, $shareCount) : 0;
            $remainder = $shareCount > 0 ? $type->price_cents % $shareCount : 0;

            $participants->values()->each(function (array $participant, int $index) use ($consultation, $sample, $share, $remainder) {
                $hasPayment = array_key_exists($index, $sample['payments']);
                $consultation->participants()->create([
                    'first_name' => $participant[0],
                    'last_name' => $participant[1],
                    'email' => $participant[2],
                    'phone_country' => '+1',
                    'phone' => '(555) 010-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'is_primary' => $index === 0,
                    'should_pay' => $hasPayment,
                    'share_amount_cents' => $hasPayment ? $share + ($index === 0 ? $remainder : 0) : 0,
                ]);
            });

            foreach ($consultation->participants()->where('should_pay', true)->get()->values() as $index => $participant) {
                $status = $sample['payments'][$index] ?? 'pending';
                PaymentRequest::create([
                    'consultation_id' => $consultation->id,
                    'participant_id' => $participant->id,
                    'id' => (string) Str::uuid(),
                    'provider' => 'converge',
                    'status' => $status,
                    'amount_cents' => $participant->share_amount_cents,
                    'currency' => 'USD',
                    'payment_method' => $index % 2 === 0 ? 'card' : 'ach',
                    'provider_reference' => 'sample_'.Str::lower(Str::random(12)),
                    'payment_url' => 'https://pay.demo.convergepay.com/pay/sample-'.$sample['number'].'-'.$participant->id,
                    'sent_at' => now(),
                    'paid_at' => $status === 'paid' ? now() : null,
                    'metadata' => ['sample_seed' => true],
                ]);
            }
        }
    }
}
