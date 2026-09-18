<?php

namespace App\Mail\Transports;

use App\Services\Integrations\MicrosoftGraphTokenProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private MicrosoftGraphTokenProvider $tokenProvider,
        private string $mailboxId,
        private string $fromAddress,
        private string $baseUrl,
        LoggerInterface $logger,
    ) {
        parent::__construct(null, $logger);
    }

    public function __toString(): string
    {
        return 'microsoft-graph://'.rawurlencode($this->mailboxId);
    }

    protected function doSend(SentMessage $message): void
    {
        $this->assertConfigured();

        try {
            $email = MessageConverter::toEmail($message->getOriginalMessage());
        } catch (Throwable $exception) {
            throw new TransportException('Microsoft Graph could not convert the email to MIME.', 0, $exception);
        }

        $from = $email->getFrom();
        if (count($from) !== 1 || strcasecmp($from[0]->getAddress(), $this->fromAddress) !== 0) {
            throw new TransportException(
                'Microsoft Graph email From address must match the configured MAIL_FROM_ADDRESS.'
            );
        }

        $recipientCount = count($message->getEnvelope()->getRecipients());

        try {
            $response = Http::withToken($this->tokenProvider->accessToken())
                ->acceptJson()
                ->timeout(30)
                ->withBody(base64_encode($this->mimeMessage($email)), 'text/plain')
                ->post($this->sendMailUrl());
        } catch (ConnectionException $exception) {
            $this->logFailure('connection_error', null, null, $recipientCount);

            throw new TransportException('Microsoft Graph mail delivery could not connect.', 0, $exception);
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('authentication_or_request_error', null, null, $recipientCount);

            throw new TransportException('Microsoft Graph mail delivery failed before submission.', 0, $exception);
        }

        if ($response->status() !== 202) {
            $errorCode = $response->json('error.code');
            $requestId = $response->header('request-id') ?: $response->header('client-request-id');
            $this->logFailure(
                is_string($errorCode) ? $errorCode : 'unexpected_response',
                $response->status(),
                $requestId,
                $recipientCount,
            );

            $details = array_filter([
                'HTTP '.$response->status(),
                is_string($errorCode) && $errorCode !== '' ? $errorCode : null,
                is_string($requestId) && $requestId !== '' ? 'request '.$requestId : null,
            ]);

            throw new TransportException(
                'Microsoft Graph mail delivery failed ('.implode(', ', $details).').'
            );
        }
    }

    private function assertConfigured(): void
    {
        if ($this->mailboxId === '') {
            throw new TransportException('Microsoft Graph requires OUTLOOK_MAILBOX_ID to be configured.');
        }

        if ($this->fromAddress === '') {
            throw new TransportException('Microsoft Graph requires MAIL_FROM_ADDRESS to be configured.');
        }
    }

    private function sendMailUrl(): string
    {
        return rtrim($this->baseUrl, '/')
            .'/users/'.rawurlencode($this->mailboxId)
            .'/sendMail';
    }

    private function mimeMessage(Email $email): string
    {
        $mime = $email->toString();
        $bcc = $email->getHeaders()->get('Bcc');

        if ($bcc === null) {
            return $mime;
        }

        return preg_replace(
            "/\r\n\r\n/",
            "\r\n".$bcc->toString()."\r\n\r\n",
            $mime,
            1,
        ) ?? $mime;
    }

    private function logFailure(
        string $errorCode,
        ?int $status,
        ?string $requestId,
        int $recipientCount,
    ): void {
        $this->getLogger()->error('Microsoft Graph mail delivery failed.', array_filter([
            'http_status' => $status,
            'graph_error_code' => $errorCode,
            'graph_request_id' => $requestId,
            'recipient_count' => $recipientCount,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
