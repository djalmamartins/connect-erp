<?php
declare(strict_types=1);

namespace Source\Services\Talk\Http;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 15)
    {
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function postFile(string $url, array $fields, string $fileField, string $filePath, string $mime, string $fileName, array $headers = []): array
    {
        $h = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => $fields + [$fileField => new \CURLFile($filePath, $mime, $fileName)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max($this->timeoutSeconds, 60),
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP falhou: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }

    public function request(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $h = ['Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP falhou: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }
}
