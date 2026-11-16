<?php

declare(strict_types=1);

namespace MovesOSTests\Fakes;

use RuntimeException;
use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * Cora de mentira para os testes (ADR 0322): nenhum byte sai da máquina. Guarda os boletos em memória e
 * responde no formato da Integração Direta usado pelo adaptador. Dados 100% sintéticos.
 */
final class FakeCoraHttp implements InterHttp
{
    /** @var list<array{method:string, path:string, url:string, headers:array, body:?string}> */
    public array $requests = [];
    /** @var array<string, array> boletos por id */
    public array $invoices = [];
    /** @var array<string, string> Idempotency-Key => id */
    public array $keys = [];
    public ?array $nextResponse = null;
    public ?string $tokenError = null;
    public int $tokenStatus = 200;
    /** Quantas respostas 429 seguidas antes de atender. */
    public int $rateLimited = 0;
    public array $entries = [];
    public mixed $balance = 123456;
    public string $pdfUrl = 'https://boletos.stage.cora.com.br/pdf/teste.pdf';
    private int $sequence = 0;

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($certificatePem === '' || $privateKeyPem === '') {
            throw new RuntimeException('sem mTLS');
        }
        if ($path === '/token') {
            if ($this->tokenError !== null) {
                throw new RuntimeException('Sem resposta do banco: ' . $this->tokenError);
            }
            if ($this->tokenStatus !== 200) {
                return ['status' => $this->tokenStatus, 'body' => '{"error":"invalid_client"}'];
            }
            return ['status' => 200, 'body' => json_encode(['access_token' => 'jwt-' . (++$this->sequence), 'expires_in' => 86400, 'refresh_expires_in' => 0])];
        }
        if ($url === $this->pdfUrl) {
            return ['status' => 200, 'body' => "%PDF-1.4\n% boleto sintético da Cora\n"];
        }
        if ($this->nextResponse !== null) {
            [$response, $this->nextResponse] = [$this->nextResponse, null];
            return $response;
        }
        if (!str_starts_with((string)($headers['Authorization'] ?? ''), 'Bearer jwt-')) {
            return ['status' => 401, 'body' => '{}'];
        }
        if ($this->rateLimited > 0) {
            $this->rateLimited--;
            return ['status' => 429, 'body' => ''];
        }
        if ($method === 'POST' && $path === '/v2/invoices/') {
            $key = (string)($headers['Idempotency-Key'] ?? '');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
                return ['status' => 400, 'body' => '{"code":"BAD_REQUEST","message":"Idempotency-Key inválida"}'];
            }
            if (isset($this->keys[$key])) {
                return ['status' => 200, 'body' => json_encode($this->view($this->keys[$key]))];
            }
            $data = json_decode((string)$body, true);
            $id = 'inv_teste' . str_pad((string)(++$this->sequence), 8, '0', STR_PAD_LEFT);
            $this->keys[$key] = $id;
            $this->invoices[$id] = ['code' => $data['code'], 'amount' => $data['services'][0]['amount'], 'status' => 'OPEN', 'total_paid' => 0,
                'occurrence_date' => null, 'request' => $data];
            return ['status' => 200, 'body' => json_encode($this->view($id))];
        }
        if ($method === 'POST' && $path === '/v2/invoices/pay') {
            if (!isset($headers['Idempotency-Key'])) {
                return ['status' => 400, 'body' => '{"message":"Idempotency-Key obrigatória"}'];
            }
            $id = (string)(json_decode((string)$body, true)['id'] ?? '');
            if (!isset($this->invoices[$id])) {
                return ['status' => 404, 'body' => '{}'];
            }
            $this->set($id, 'PAID');
            return ['status' => 200, 'body' => json_encode(['id' => $id, 'status' => 'IN_PAYMENT'])];
        }
        if (preg_match('#^/v2/invoices/(inv_[A-Za-z0-9]+)$#', $path, $m)) {
            $id = $m[1];
            if (!isset($this->invoices[$id])) {
                return ['status' => 404, 'body' => '{}'];
            }
            if ($method === 'DELETE') {
                if ($this->invoices[$id]['status'] === 'PAID') {
                    return ['status' => 422, 'body' => '{"code":"REC-0006","message":"message"}'];
                }
                $this->invoices[$id]['status'] = 'CANCELLED';
                return ['status' => 204, 'body' => ''];
            }
            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode($this->view($id))];
            }
        }
        if ($method === 'GET' && $path === '/bank-statement/statement') {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            $per = max(1, (int)($q['perPage'] ?? 50));
            $page = max(1, (int)($q['page'] ?? 1));
            $in = array_values(array_filter($this->entries, static fn (array $e): bool => substr((string)$e['createdAt'], 0, 10) >= ($q['start'] ?? '')
                && substr((string)$e['createdAt'], 0, 10) <= ($q['end'] ?? '')));
            return ['status' => 200, 'body' => json_encode(['entries' => array_slice($in, ($page - 1) * $per, $per), 'start' => [], 'end' => []])];
        }
        if ($method === 'GET' && $path === '/third-party/account/balance') {
            return ['status' => 200, 'body' => json_encode(['balance' => $this->balance])];
        }
        return ['status' => 404, 'body' => '{}'];
    }

    public function set(string $id, string $status, string $on = '2026-11-12'): void
    {
        $this->invoices[$id]['status'] = $status;
        if ($status === 'PAID') {
            $this->invoices[$id]['total_paid'] = $this->invoices[$id]['amount'];
            $this->invoices[$id]['occurrence_date'] = $on;
        }
    }

    public function count(string $method, string $prefix): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with($r['path'], $prefix)));
    }

    private function view(string $id): array
    {
        $i = $this->invoices[$id];
        $digits = str_pad(substr((string)$i['code'], -10), 10, '0', STR_PAD_LEFT);
        return [
            'id' => $id, 'status' => $i['status'], 'code' => $i['code'], 'total_amount' => $i['amount'], 'total_paid' => $i['total_paid'],
            'occurrence_date' => $i['occurrence_date'], 'payments' => [],
            'payment_options' => ['bank_slip' => ['barcode' => '40399' . str_repeat('0', 29) . $digits, 'digitable' => '40390.00009 ' . $digits . ' TESTE',
                'our_number' => '7' . $digits, 'url' => $this->pdfUrl]],
            'pix' => ['emv' => '00020101021226CORATESTE' . $digits],
        ];
    }
}
