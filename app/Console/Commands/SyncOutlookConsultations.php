<?php

namespace App\Console\Commands;

use App\Services\Integrations\OutlookCalendarClient;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;

class SyncOutlookConsultations extends Command
{
    protected $signature = 'outlook:sync-consultations';

    protected $description = 'Refresh Outlook busy events and sync future application consultations to Outlook.';

    public function handle(OutlookCalendarClient $outlook, PaymentReconciliationService $payments): int
    {
        try {
            $payments->syncLightweight();
            $sync = $outlook->syncAllConsultations();
        } catch (\DomainException|\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Outlook sync completed. {$sync['busy']} busy event(s) refreshed, {$sync['synced']} future consultation(s) synced, {$sync['deleted']} stale consultation event(s) deleted."
        );

        return self::SUCCESS;
    }
}
