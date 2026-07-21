<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateDraftConsultationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'consultation_type_id' => ['required', 'integer', 'exists:consultation_types,id'],
            'legal_service_name' => ['nullable', 'string', 'max:160'],
            'consultation_mode' => ['nullable', 'in:online,offline,phone'],
            'description' => ['nullable', 'string', 'max:1000'],
            'referral_source' => ['nullable', 'string', 'max:120'],
            'primary_client' => ['nullable', 'array'],
            'primary_client.first_name' => ['nullable', 'string', 'max:80'],
            'primary_client.last_name' => ['nullable', 'string', 'max:80'],
            'primary_client.email' => ['nullable', 'email', 'max:160'],
            'primary_client.phone_country' => ['nullable', 'string', 'max:8'],
            'primary_client.phone' => ['nullable', 'string', 'max:40'],
            'participants' => ['nullable', 'array', 'max:4'],
            'participants.*.first_name' => ['required_with:participants', 'string', 'max:80'],
            'participants.*.last_name' => ['nullable', 'string', 'max:80'],
            'participants.*.email' => ['nullable', 'email', 'max:160'],
            'participants.*.phone_country' => ['nullable', 'string', 'max:8'],
            'participants.*.phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
