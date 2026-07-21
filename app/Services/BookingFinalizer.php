<?php

namespace App\Services;

use App\Models\Consultation;
use App\Services\Integrations\ZoomClient;

class BookingFinalizer
{
    public function __construct(private readonly ZoomClient $zoom)
    {
    }

    public function syncPaymentStatus(Consultation $consultation): Consultation
    {
        $total = $consultation->paymentRequests()->count();
        $paid = $consultation->paymentRequests()->where('status', 'paid')->count();

        if ($total > 0 && $paid === $total) {
            $updates = ['payment_status' => 'paid', 'status' => 'paid', 'confirmed_at' => now()];

            if ($consultation->consultation_mode === 'online' && ! $consultation->zoom_join_url) {
                $meeting = $this->zoom->createMeeting($consultation);
                $updates['zoom_meeting_id'] = $meeting['id'];
                $updates['zoom_join_url'] = $meeting['join_url'];
            }

            $consultation->update($updates);
        } elseif ($paid > 0) {
            $consultation->update(['payment_status' => 'partially_paid', 'status' => 'partially_paid']);
        }

        return $consultation->refresh();
    }
}
