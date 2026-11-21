<?php

declare(strict_types=1);

namespace MovesOSTests\Fakes;

use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * Sicoob de mentira para os testes (ADR 0633): nenhum byte sai da máquina. Guarda os boletos em memória e responde
 * no formato da Cobrança Bancária v3 e da Conta Corrente usado pelo adaptador. Dados 100% sintéticos.
 */
final class FakeSicoobHttp implements InterHttp
{
    /** @var list<array{method:string, path:string, url:string, headers:array, body:?string, query:array, cert:string}> */
    public array $requests = [];
    /** @var array<string, array> boletos por nosso número */
    public array $boletos = [];
    public ?array $nextResponse = null;
    public int $authStatus = 200;
    public int $rateLimited = 0;
    /** @var array<string, list<array>> transações por "mes/ano" */
    public array $transactions = [];
    public mixed $balance = '1234.56';
    private int $sequence = 1000;

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = ['method' => $method, 'path' => $path, 'url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query, 'cert' => $certificatePem];
        if ($this->authStatus !== 200 || ($headers['Authorization'] ?? '') !== 'Bearer token-sandbox' || ($headers['client_id'] ?? '') === '') {
            return ['status' => $this->authStatus !== 200 ? $this->authStatus : 401, 'body' => '{"message":"Unauthorized"}'];
        }
        if ($this->rateLimited > 0) {
            $this->rateLimited--;
            return ['status' => 429, 'body' => '{}'];
        }
        if ($this->nextResponse !== null) {
            [$response, $this->nextResponse] = [$this->nextResponse, null];
            return $response;
        }
        $base = '/sicoob/sandbox';
        if ($method === 'POST' && $path === $base . '/cobranca-bancaria/v3/boletos') {
            $data = json_decode((string)$body, true);
            $nosso = (string)(++$this->sequence);
            $this->boletos[$nosso] = [
                'numeroCliente' => $data['numeroCliente'], 'codigoModalidade' => $data['codigoModalidade'], 'nossoNumero' => (int)$nosso,
                'seuNumero' => $data['seuNumero'], 'valor' => $data['valor'], 'dataVencimento' => $data['dataVencimento'],
                'linhaDigitavel' => '75691' . str_pad($nosso, 42, '0', STR_PAD_LEFT), 'codigoBarras' => '7569' . str_pad($nosso, 40, '0', STR_PAD_LEFT),
                'qrCode' => '00020101021226950014br.gov.bcb.pix2573pix.sicoob.com.br/qr/payload/v2/cobv/teste' . $nosso,
                'situacaoBoleto' => 'Em Aberto', 'listaHistorico' => [['dataHistorico' => '2026-11-21', 'tipoHistorico' => 1, 'descricaoHistorico' => 'Entrada']],
            ];
            return ['status' => 200, 'body' => json_encode(['resultado' => $this->boletos[$nosso]])];
        }
        if ($method === 'GET' && $path === $base . '/cobranca-bancaria/v3/boletos') {
            $nosso = (string)($query['nossoNumero'] ?? '');
            return isset($this->boletos[$nosso])
                ? ['status' => 200, 'body' => json_encode(['resultado' => $this->boletos[$nosso]])]
                : ['status' => 404, 'body' => '{"mensagens":[{"mensagem":"Boleto não encontrado","codigo":"5002"}]}'];
        }
        if ($method === 'POST' && preg_match('#^' . $base . '/cobranca-bancaria/v3/boletos/(-?\d+)/baixar$#', $path, $m)) {
            if (!isset($this->boletos[$m[1]])) {
                return ['status' => 400, 'body' => '{"mensagens":[{"mensagem":"Boleto não encontrado","codigo":"5002"}]}'];
            }
            if ($this->boletos[$m[1]]['situacaoBoleto'] === 'Liquidado') {
                return ['status' => 400, 'body' => '{"mensagens":[{"mensagem":"Boleto já liquidado","codigo":"5010"}]}'];
            }
            $this->boletos[$m[1]]['situacaoBoleto'] = 'Baixado';
            return ['status' => 204, 'body' => ''];
        }
        if ($method === 'GET' && $path === $base . '/cobranca-bancaria/v3/boletos/segunda-via') {
            return ['status' => 200, 'body' => json_encode(['resultado' => ['pdfBoleto' => base64_encode("%PDF-1.4\n% boleto sintético do Sicoob\n")]])];
        }
        if ($method === 'GET' && preg_match('#^' . $base . '/conta-corrente/v3/extrato/(\d+)/(\d{4})$#', $path, $m)) {
            return ['status' => 200, 'body' => json_encode(['mensagens' => [], 'resultado' => ['saldoAtual' => '10.00', 'saldoAnterior' => '0.00',
                'transacoes' => $this->transactions[$m[1] . '/' . $m[2]] ?? []]])];
        }
        if ($method === 'GET' && $path === $base . '/conta-corrente/v2/saldo') {
            return ['status' => 200, 'body' => json_encode(['mensagens' => [], 'resultado' => ['saldo' => $this->balance, 'saldoLimite' => '0.00', 'saldoBloqueado' => '0.00']])];
        }
        return ['status' => 404, 'body' => '{}'];
    }

    /** Marca o boleto como pago (o sandbox de verdade não simula pagamento). */
    public function pay(string $nossoNumero, string $on = '2026-11-25'): void
    {
        $this->boletos[$nossoNumero]['situacaoBoleto'] = 'Liquidado';
        $this->boletos[$nossoNumero]['listaHistorico'][] = ['dataHistorico' => $on, 'tipoHistorico' => 6, 'descricaoHistorico' => 'Liquidação'];
    }
}
