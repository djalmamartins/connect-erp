<?php

declare(strict_types=1);

namespace MovesOSTests\Fakes;

use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * CondoConta de mentira para os testes (ADR 0649): nenhum byte sai da máquina. Guarda as cobranças em memória e
 * responde no formato da CondoConta Open API 2.37.0 usado pelo adaptador. Dados 100% sintéticos.
 */
final class FakeCondoContaHttp implements InterHttp
{
    public const CLIENT_ID = 'cid-teste';
    public const CLIENT_SECRET = 'secret-teste';

    /** @var list<array{method:string, path:string, url:string, headers:array, body:?string, query:array, cert:string}> */
    public array $requests = [];
    /** @var array<string, array> cobranças por our_number */
    public array $slips = [];
    public ?array $nextResponse = null;
    public int $rateLimited = 0;
    /** Quantos pedidos autenticados seguintes recebem 401 (token vencido antes da hora). */
    public int $expireToken = 0;
    public int $tokensIssued = 0;
    /** @var list<array> dias do extrato (items da balance/sheet) */
    public array $sheet = [];
    public mixed $balance = 1234.56;
    private int $sequence = 5000;
    private string $token = '';

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = ['method' => $method, 'path' => $path, 'url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query, 'cert' => $certificatePem];
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($method === 'POST' && $path === '/token') {
            parse_str((string)$body, $form);
            if (($form['grant_type'] ?? '') !== 'client_credentials' || ($form['client_id'] ?? '') !== self::CLIENT_ID || ($form['client_secret'] ?? '') !== self::CLIENT_SECRET) {
                return ['status' => 401, 'body' => '{"detail":"Invalid client credentials"}'];
            }
            $this->token = 'tok-' . (++$this->tokensIssued);
            return ['status' => 200, 'body' => json_encode(['access_token' => $this->token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $form['scope'] ?? ''])];
        }
        if ($this->token === '' || ($headers['Authorization'] ?? '') !== 'Bearer ' . $this->token) {
            return ['status' => 401, 'body' => '{"detail":"Not authenticated"}'];
        }
        if ($this->expireToken > 0) {
            $this->expireToken--;
            $this->token = '';
            return ['status' => 401, 'body' => '{"detail":"Token expired"}'];
        }
        if ($this->rateLimited > 0) {
            $this->rateLimited--;
            return ['status' => 429, 'body' => '{"detail":"Too many requests"}'];
        }
        if ($this->nextResponse !== null) {
            [$response, $this->nextResponse] = [$this->nextResponse, null];
            return $response;
        }
        if ($host === 'files.condoconta.net' && $method === 'GET' && preg_match('#^/slip/(\d+)\.pdf$#', $path, $m)) {
            return isset($this->slips[$m[1]]) ? ['status' => 200, 'body' => "%PDF-1.4\n% boleto sintético da CondoConta\n"] : ['status' => 404, 'body' => ''];
        }
        if ($method === 'POST' && $path === '/slip') {
            $data = json_decode((string)$body, true);
            foreach ($this->slips as $slip) {
                if ($slip['your_number'] === $data['your_number'] && $slip['value'] === $data['value']) {
                    return ['status' => 200, 'body' => json_encode($slip)];   // idempotente pelo your_number
                }
            }
            $our = (string)(++$this->sequence);
            $this->slips[$our] = [
                'our_number' => $our, 'your_number' => $data['your_number'], 'value' => $data['value'], 'due_date' => $data['due_date'],
                'type' => $data['type'], 'payer' => $data['payer'],
                'digitable_line' => '00190000090' . str_pad($our, 36, '0', STR_PAD_LEFT), 'bar_code' => '0019' . str_pad($our, 40, '0', STR_PAD_LEFT),
                'pdf' => 'https://files.condoconta.net/slip/' . $our . '.pdf',
                'qr_code' => '00020101021226900014br.gov.bcb.pix2568qrcode.condoconta.net/cobv/teste' . $our,
                'status' => 'CREATED',
            ];
            return ['status' => 200, 'body' => json_encode($this->slips[$our])];
        }
        if ($method === 'GET' && preg_match('#^/slip/(\d+)$#', $path, $m)) {
            return isset($this->slips[$m[1]]) ? ['status' => 200, 'body' => json_encode($this->slips[$m[1]])] : ['status' => 404, 'body' => '{"detail":"Slip not found"}'];
        }
        if ($method === 'POST' && preg_match('#^/slip/(\d+)/cancel$#', $path, $m)) {
            if (!isset($this->slips[$m[1]])) {
                return ['status' => 404, 'body' => '{"detail":"Slip not found"}'];
            }
            if ($this->slips[$m[1]]['status'] === 'PAID') {
                return ['status' => 422, 'body' => '{"detail":"Slip already paid"}'];
            }
            $this->slips[$m[1]]['status'] = 'CANCELED';
            $this->slips[$m[1]]['status_date'] = '2026-11-23';
            return ['status' => 204, 'body' => ''];
        }
        if ($method === 'GET' && $path === '/account/balance') {
            return ['status' => 200, 'body' => json_encode(['balance' => $this->balance, 'blocked_balance' => 0])];
        }
        if ($method === 'GET' && $path === '/account/balance/sheet') {
            $from = (string)($query['initial_date'] ?? '');
            $to = (string)($query['final_date'] ?? '');
            $items = array_values(array_filter($this->sheet, static fn (array $d): bool => $d['date'] >= $from && $d['date'] <= $to));
            return ['status' => 200, 'body' => json_encode(['items' => $items])];
        }
        return ['status' => 404, 'body' => '{"detail":"Not Found"}'];
    }

    /** Marca a cobrança como paga (a documentação não traz simulação de pagamento no sandbox). */
    public function pay(string $ourNumber, string $on = '2026-11-25', ?string $received = null): void
    {
        $this->slips[$ourNumber]['status'] = 'PAID';
        $this->slips[$ourNumber]['payment_date'] = $on;
        $this->slips[$ourNumber]['received_value'] = $received ?? $this->slips[$ourNumber]['value'];
    }
}
