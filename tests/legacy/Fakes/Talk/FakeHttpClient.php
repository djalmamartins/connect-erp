<?php
declare(strict_types=1);

namespace MovesOSTests\Fakes\Talk;

use Source\Services\Talk\Http\HttpClient;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url:string, body:array, headers:array}> */
    public array $calls = [];
    /** @var list<array{status:int, body:string}|\Throwable> */
    public array $responses = [];
    private int $seq = 0;

    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function postFile(string $url, array $fields, string $fileField, string $filePath, string $mime, string $fileName, array $headers = []): array
    {
        return $this->request('POST', $url, $fields + [$fileField => "@$fileName ($mime)"], $headers);
    }

    public function request(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $this->calls[] = compact('method', 'url', 'body', 'headers');
        $r = array_shift($this->responses);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r ?? ['status' => 201, 'body' => json_encode(['key' => ['id' => 'OUT' . (++$this->seq)]])];
    }
}
