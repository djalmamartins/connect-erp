<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/** GraphTransport com cURL (ADR 0373). */
final class CurlGraphTransport implements GraphTransport
{
    public function __construct(private int $timeoutSeconds = 60)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            $list[] = $name . ': ' . $value;
        }
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $list,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Microsoft Graph não respondeu: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$response, 'location' => $location];
    }
}
