<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MicrosoftGraphTokenProvider
{
    public function __construct(private LoggerInterface $logger) {}

    public function accessToken(): string
    {
        $this->assertConfigured();

        $cacheKey = $this->cacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        return Cache::lock($cacheKey.'.lock', 10)->block(5, function () use ($cacheKey): string {
            $cachedToken = Cache::get($cacheKey);

            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }

            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(30)
                    ->post($this->tokenUrl(), [
                        'client_id' => config('services.outlook.client_id'),
                        'client_secret' => config('services.outlook.client_secret'),
                        'grant_type' => 'client_credentials',
                        'scope' => 'https://graph.microsoft.com/.default',
                    ]);
            } catch (ConnectionException $exception) {
                $this->logger->error('Microsoft Graph access token request could not connect.', [
                    'graph_error_code' => 'connection_error',
                ]);

                throw new RuntimeException('Microsoft Graph access token request could not connect.', 0, $exception);
            }

            if ($response->failed()) {
                $errorCode = $response->json('error');
                $requestId = $response->header('request-id') ?: $response->header('client-request-id');
                $this->logger->error('Microsoft Graph access token request failed.', array_filter([
                    'http_status' => $response->status(),
                    'graph_error_code' => is_string($errorCode) ? $errorCode : 'unexpected_response',
                    'graph_request_id' => $requestId,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
                $suffix = is_string($errorCode) && $errorCode !== '' ? ' ('.$errorCode.')' : '';

                throw new RuntimeException(
                    'Microsoft Graph access token request failed with HTTP '.$response->status().$suffix.'.'
                );
            }

            $accessToken = $response->json('access_token');

            if (! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Microsoft Graph access token response did not contain an access token.');
            }

            $expiresIn = max(1, (int) $response->json('expires_in', 3600) - 60);
            Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));

            return $accessToken;
        });
    }

    private function assertConfigured(): void
    {
        foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
            if (blank(config('services.outlook.'.$key))) {
                throw new RuntimeException('Microsoft Graph requires OUTLOOK_'.Str::upper($key).' to be configured.');
            }
        }
    }

    private function cacheKey(): string
    {
        return 'microsoft-graph:access-token:'.hash('sha256', implode('|', [
            (string) config('services.outlook.tenant_id'),
            (string) config('services.outlook.client_id'),
        ]));
    }

    private function tokenUrl(): string
    {
        return rtrim((string) config('services.outlook.login_base_url'), '/')
            .'/'.rawurlencode((string) config('services.outlook.tenant_id'))
            .'/oauth2/v2.0/token';
    }
}
