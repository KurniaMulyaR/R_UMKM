<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Xendit QR Codes (api-version 2022-07-31) client.
 */
class XenditClient
{
    public function __construct(private readonly string $secretKey, private readonly string $baseUrl = 'https://api.xendit.co')
    {
    }

    /**
     * Create a dynamic QRIS code.
     *
     * @return array{qr_id: ?string, qr_string: string, expires_at: ?string}
     */
    public function createDynamicQr(string $referenceId, float $amount, string $expiresAtIso): array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')->withHeaders(['api-version' => '2022-07-31'])->acceptJson()->asJson()->timeout(15)
                ->post($this->baseUrl.'/qr_codes', [
                    'reference_id' => $referenceId, 'type' => 'DYNAMIC', 'currency' => 'IDR', 'amount' => (int) round($amount), 'expires_at' => $expiresAtIso,
                ])->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Xendit menolak permintaan QR: '.($exception->response->json('message') ?? $exception->response->status()), previous: $exception);
        }
        $qrString = $response['qr_string'] ?? null;
        if (! $qrString) {
            throw new RuntimeException('Respons Xendit tidak berisi qr_string.');
        }

        return ['qr_id' => $response['qr_id'] ?? $response['id'] ?? null, 'qr_string' => $qrString, 'expires_at' => $response['expires_at'] ?? null];
    }
}
