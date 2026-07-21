<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Socal Mediation Center API',
    description: 'REST APIs for Socal mediation and legal consultation booking flows.'
)]
#[OA\Server(url: '/api', description: 'Application API')]
#[OA\Tag(name: 'Catalog')]
#[OA\Tag(name: 'Consultations')]
#[OA\Tag(name: 'Payments')]
#[OA\Schema(
    schema: 'ApiEnvelope',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', type: 'object', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ConsultationType',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'application', type: 'string', enum: ['socal', 'legal'], example: 'socal'),
        new OA\Property(property: 'name', type: 'string', example: 'Full Day Mediation'),
        new OA\Property(property: 'slug', type: 'string', example: 'socal-full-day-mediation'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'duration_minutes', type: 'integer', example: 480),
        new OA\Property(property: 'price_cents', type: 'integer', example: 520000),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'max_participants', type: 'integer', example: 5),
        new OA\Property(property: 'allows_split_payment', type: 'boolean', example: true),
        new OA\Property(property: 'allows_phone', type: 'boolean', example: false),
        new OA\Property(property: 'allows_online', type: 'boolean', example: true),
        new OA\Property(property: 'allows_offline', type: 'boolean', example: true),
    ]
)]
#[OA\Schema(
    schema: 'LegalService',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'application', type: 'string', enum: ['socal', 'legal'], example: 'socal'),
        new OA\Property(property: 'name', type: 'string', example: 'Business, Payment & Contract Disputes'),
        new OA\Property(property: 'slug', type: 'string', example: 'business-payment-contract-disputes'),
    ]
)]
#[OA\Schema(
    schema: 'ParticipantPayload',
    required: ['first_name'],
    properties: [
        new OA\Property(property: 'first_name', type: 'string', example: 'Jonathan'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true, example: 'Miller'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'jonathan@example.com'),
        new OA\Property(property: 'phone_country', type: 'string', nullable: true, example: '+1'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '(495) 060-0000'),
    ]
)]
#[OA\Schema(
    schema: 'Consultation',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'booking_number', type: 'string', example: 'LX-49201'),
        new OA\Property(property: 'application', type: 'string', enum: ['socal', 'legal'], example: 'socal'),
        new OA\Property(property: 'status', type: 'string', example: 'pending_payment'),
        new OA\Property(property: 'payment_status', type: 'string', example: 'pending'),
        new OA\Property(property: 'consultation_mode', type: 'string', nullable: true, enum: ['online', 'offline', 'phone']),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, example: '2026-08-11T09:00:00-07:00'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true, example: '2026-08-11T17:00:00-07:00'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Los_Angeles'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Need help reviewing a dispute before mediation.'),
        new OA\Property(property: 'referral_source', type: 'string', nullable: true, example: 'Google'),
        new OA\Property(property: 'total_amount_cents', type: 'integer', example: 520000),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'payment_mode', type: 'string', nullable: true, enum: ['full', 'split']),
        new OA\Property(property: 'zoom_join_url', type: 'string', nullable: true),
        new OA\Property(property: 'type', ref: '#/components/schemas/ConsultationType'),
        new OA\Property(property: 'legal_service', ref: '#/components/schemas/LegalService', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AvailabilityDay',
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-08-14'),
        new OA\Property(property: 'slots', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'time', type: 'string', example: '09:00'),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'available', type: 'boolean', example: true),
        ])),
    ]
)]
class OpenApi
{
}
