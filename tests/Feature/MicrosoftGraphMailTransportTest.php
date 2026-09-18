<?php

namespace Tests\Feature;

use App\Mail\Transports\MicrosoftGraphTransport;
use App\Services\Integrations\MicrosoftGraphTokenProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MicrosoftGraphMailTransportTest extends TestCase
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
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.mailbox_id' => 'sender@example.com',
            'mail.from.address' => 'sender@example.com',
            'mail.from.name' => 'Shared Office',
        ]);
    }

    public function test_laravel_registers_the_microsoft_graph_mail_transport(): void
    {
        Mail::purge('microsoft-graph');

        $this->assertInstanceOf(
            MicrosoftGraphTransport::class,
            Mail::mailer('microsoft-graph')->getSymfonyTransport(),
        );
    }

    public function test_it_sends_complete_mime_with_recipients_and_attachment(): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/sender%40example.com/sendMail' => Http::response(null, 202),
        ]);

        $email = (new Email)
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->cc('copy@example.com')
            ->bcc('blind@example.com')
            ->replyTo('reply@example.com')
            ->subject('Graph transport test')
            ->text('Plain text body')
            ->html('<strong>HTML body</strong>')
            ->attach('PDF-CONTENT', 'questionnaire.pdf', 'application/pdf');

        $this->transport()->send($email);

        $recorded = collect(Http::recorded())->first(
            fn (array $entry): bool => str_ends_with($entry[0]->url(), '/users/sender%40example.com/sendMail')
        );

        $this->assertNotNull($recorded);
        $request = $recorded[0];
        $mime = base64_decode($request->body(), true);

        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer graph-token'));
        $this->assertTrue($request->hasHeader('Content-Type', 'text/plain'));
        $this->assertIsString($mime);
        $this->assertStringContainsString('Subject: Graph transport test', $mime);
        $this->assertStringContainsString('recipient@example.com', $mime);
        $this->assertStringContainsString('copy@example.com', $mime);
        $this->assertStringContainsString('blind@example.com', $mime);
        $this->assertStringContainsString('reply@example.com', $mime);
        $this->assertStringContainsString('HTML body', $mime);
        $this->assertStringContainsString('questionnaire.pdf', $mime);
        $this->assertStringContainsString(base64_encode('PDF-CONTENT'), $mime);
    }

    public function test_it_rejects_a_from_address_that_does_not_match_configuration(): void
    {
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('must match the configured MAIL_FROM_ADDRESS');

        $this->transport()->send(
            (new Email)
                ->from('different@example.com')
                ->to('recipient@example.com')
                ->subject('Wrong sender')
                ->text('Body')
        );
    }

    #[DataProvider('graphErrorResponses')]
    public function test_it_logs_safe_graph_error_details_and_throws(int $status, string $errorCode): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/sender%40example.com/sendMail' => Http::response([
                'error' => [
                    'code' => $errorCode,
                    'message' => 'Sensitive Graph response detail',
                ],
            ], $status, ['request-id' => 'graph-request-id']),
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('Microsoft Graph mail delivery failed.', Mockery::on(
                fn (array $context): bool => $context === [
                    'http_status' => $status,
                    'graph_error_code' => $errorCode,
                    'graph_request_id' => 'graph-request-id',
                    'recipient_count' => 1,
                ]
            ));

        try {
            $this->transport($logger)->send(
                (new Email)
                    ->from('sender@example.com')
                    ->to('recipient@example.com')
                    ->subject('Denied message')
                    ->text('Body')
            );
            $this->fail('Expected Graph delivery to fail.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('HTTP '.$status, $exception->getMessage());
            $this->assertStringContainsString($errorCode, $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive Graph response detail', $exception->getMessage());
        }
    }

    public static function graphErrorResponses(): array
    {
        return [
            'unauthorized' => [401, 'InvalidAuthenticationToken'],
            'forbidden' => [403, 'ErrorAccessDenied'],
            'throttled' => [429, 'TooManyRequests'],
            'server error' => [500, 'InternalServerError'],
        ];
    }

    public function test_it_throws_on_a_graph_connection_failure(): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/sender%40example.com/sendMail' => Http::failedConnection(),
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('could not connect');

        $this->transport($logger)->send(
            (new Email)
                ->from('sender@example.com')
                ->to('recipient@example.com')
                ->subject('Connection failure')
                ->text('Body')
        );
    }

    private function transport(?LoggerInterface $logger = null): MicrosoftGraphTransport
    {
        return new MicrosoftGraphTransport(
            app(MicrosoftGraphTokenProvider::class),
            'sender@example.com',
            'sender@example.com',
            'https://graph.microsoft.com/v1.0',
            $logger ?? new NullLogger,
        );
    }
}
