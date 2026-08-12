<?php
namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CompleteConsultationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'legal_service_name'           => ['nullable', 'string', 'max:160'],
            'consultation_mode'            => ['nullable', 'in:online,offline,phone,in_person'],
            'description'                  => ['nullable', 'string', 'max:1000'],
            'referral_source'              => ['nullable', 'string', 'max:120'],
            'primary_client'               => ['nullable', 'array'],
            'primary_client.first_name'    => ['nullable', 'string', 'max:80'],
            'primary_client.last_name'     => ['nullable', 'string', 'max:80'],
            'primary_client.email'         => ['nullable', 'email', 'max:160'],
            'primary_client.phone_country' => ['nullable', 'string', 'max:8'],
            'primary_client.phone'         => ['nullable', 'string', 'max:40'],
            'participants'                 => ['nullable', 'array', 'max:4'],
            'participants.*.first_name'    => ['required_with:participants', 'string', 'max:80'],
            'participants.*.last_name'     => ['nullable', 'string', 'max:80'],
            'participants.*.email'         => ['nullable', 'email', 'max:160'],
            'participants.*.phone_country' => ['nullable', 'string', 'max:8'],
            'participants.*.phone'         => ['nullable', 'string', 'max:40'],
            'starts_at'                    => ['required', 'date'],
            'professional_id'              => ['nullable', 'integer', 'exists:professionals,id'],
            'timezone'                     => ['nullable', 'timezone:all'],
            'payment_mode'                 => ['required', 'in:full,split'],
            'payment_method'               => ['nullable', 'in:card,ach'],
            'payment_participant_emails'   => ['nullable', 'array'],
            'payment_participant_emails.*' => ['email', 'max:160'],
        ];
    }
}
