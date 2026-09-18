<?php

namespace App\Providers;

use App\Mail\Transports\MicrosoftGraphTransport;
use App\Services\Integrations\MicrosoftGraphTokenProvider;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('microsoft-graph', function (array $config): MicrosoftGraphTransport {
            return new MicrosoftGraphTransport(
                app(MicrosoftGraphTokenProvider::class),
                (string) config('services.outlook.mailbox_id'),
                (string) config('mail.from.address'),
                (string) config('services.outlook.base_url'),
                app(LoggerInterface::class),
            );
        });
    }
}
