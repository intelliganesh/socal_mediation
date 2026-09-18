<?php

namespace Tests\Feature;

use App\Services\Integrations\MicrosoftGraphTokenProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MicrosoftGraphTokenProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
        ]);
    }

    public function test_it_fetches_and_caches_a_graph_access_token(): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
        ]);

        $provider = app(MicrosoftGraphTokenProvider::class);

        $this->assertSame('graph-token', $provider->accessToken());
        $this->assertSame('graph-token', $provider->accessToken());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default');
    }

    public function test_it_reports_token_failure_without_exposing_the_response_body(): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Sensitive tenant detail',
            ], 401),
        ]);

        try {
            app(MicrosoftGraphTokenProvider::class)->accessToken();
            $this->fail('Expected the token request to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
            $this->assertStringContainsString('invalid_client', $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive tenant detail', $exception->getMessage());
        }
    }

    public function test_it_refreshes_the_token_after_the_cached_lifetime_expires(): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::sequence()
                ->push(['access_token' => 'first-token', 'expires_in' => 61])
                ->push(['access_token' => 'second-token', 'expires_in' => 3600]),
        ]);

        $provider = app(MicrosoftGraphTokenProvider::class);

        $this->assertSame('first-token', $provider->accessToken());
        $this->travel(2)->seconds();
        $this->assertSame('second-token', $provider->accessToken());

        Http::assertSentCount(2);
    }
}
