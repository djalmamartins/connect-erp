<?php
declare(strict_types=1);

namespace Source\Services\Talk\Http;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function postJson(string $url, array $body, array $headers = []): array;

    /**
     * Requisição genérica (GET/POST/PATCH...). $body null = sem corpo.
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, ?array $body = null, array $headers = []): array;

    /**
     * POST multipart/form-data com um arquivo (upload de mídia na API oficial).
     * @return array{status:int, body:string}
     */
    public function postFile(string $url, array $fields, string $fileField, string $filePath, string $mime, string $fileName, array $headers = []): array;
}
