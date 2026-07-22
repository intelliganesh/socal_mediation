<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZoomClient
{
    public function createMeeting(Consultation $consultation): array
    {
        if (! config('services.zoom.enabled')) {
            return $this->localMeeting();
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post(rtrim(config('services.zoom.base_url'), '/').'/users/me/meetings', [
                'topic' => $consultation->booking_number.' - '.$consultation->type->name,
                'type' => 2,
                'start_time' => $consultation->starts_at?->toIso8601String(),
                'duration' => $consultation->type->duration_minutes,
                'timezone' => $consultation->timezone ?: config('app.booking_timezone'),
                'agenda' => $consultation->description,
                'settings' => [
                    'join_before_host' => false,
                    'waiting_room' => true,
                    'approval_type' => 0,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Zoom meeting creation failed: '.$response->body());
        }

        $meeting = $response->json();

        return [
            'id' => (string) $meeting['id'],
            'join_url' => $meeting['join_url'],
            'start_url' => $meeting['start_url'] ?? null,
        ];
    }

    public function deleteMeeting(string $meetingId): void
    {
        if (! config('services.zoom.enabled') || str_starts_with($meetingId, 'local-')) {
            return;
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->delete(rtrim(config('services.zoom.base_url'), '/').'/meetings/'.$meetingId);

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Zoom meeting deletion failed: '.$response->body());
        }
    }

    private function localMeeting(): array
    {
        $id = (string) random_int(1000000000, 9999999999);

        return [
            'id' => 'local-'.$id,
            'join_url' => rtrim(config('services.zoom.join_base_url'), '/').'/j/'.$id.'?pwd='.Str::random(12),
        ];
    }

    private function accessToken(): string
    {
        foreach (['account_id', 'client_id', 'client_secret'] as $key) {
            if (blank(config('services.zoom.'.$key))) {
                throw new \RuntimeException('Zoom is enabled but ZOOM_'.strtoupper($key).' is not configured.');
            }
        }

        $response = Http::withBasicAuth(config('services.zoom.client_id'), config('services.zoom.client_secret'))
            ->asForm()
            ->acceptJson()
            ->post(rtrim(config('services.zoom.oauth_base_url'), '/').'/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => config('services.zoom.account_id'),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Zoom access token request failed: '.$response->body());
        }

        return $response->json('access_token');
    }
}
