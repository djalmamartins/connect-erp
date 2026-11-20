<?php

declare(strict_types=1);

namespace MovesOSTests\Fakes;

use RuntimeException;
use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * Inter de mentira para os testes (ADR 0156): nenhum byte sai da máquina. Guarda as cobranças em
 * memória e responde no formato usado pelo adaptador. Dados 100% sintéticos.
 */
final class FakeInterHttp implements InterHttp
{
    /** @var list<array{method:string, path:string, headers:array, body:?string}> */
    public array $requests = [];
    /** @var array<string, array> cobranças por codigoSolicitacao */
    public array $charges = [];
    /** Situação inicial depois da emissão. */
    public string $initialSituation = 'A_RECEBER';
    /** Próxima resposta forçada (status, body) ou exceção de rede. */
    public ?array $nextResponse = null;
    public bool $failNext = false;
    /** A busca por seuNumero (GET /cobranca/v3/cobrancas) responde 500. */
    public bool $listFails = false;
    /** A emissão grava a cobrança mas responde 500 (o ERP fica sem o código da solicitação). */
    public bool $createThen500 = false;
    /** Falha de rede/TLS no pedido de token (ex.: 'tlsv1 alert unknown ca'). */
    public ?string $tokenError = null;
    /** Extrato (transacoes da API Banking v2) e saldo disponível; ADR 0318. */
    public array $statement = [];
    public mixed $balance = 1500.25;
    /** A aplicação não tem o escopo extrato.read: o pedido de token com ele volta 403. */
    public bool $refuseStatementScope = false;
    /** Quantas consultas do extrato/saldo seguidas o Inter limita (429). */
    public int $rateLimited = 0;
    /** O saldo responde 429 sempre. */
    public bool $balanceLimited = false;
    /** Extrato completo responde 500 (como o sandbox em 16/11/2026); o simples também, se pedido. */
    public bool $completeStatementFails = false;
    public bool $simpleStatementFails = false;
    /** Escopos pedidos em cada token. @var list<string> */
    public array $tokenScopes = [];
    private int $sequence = 0;

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
        if ($certificatePem === '' || $privateKeyPem === '') {
            throw new RuntimeException('sem mTLS');
        }
        if ($path === '/oauth/v2/token') {
            if ($this->tokenError !== null) {
                throw new RuntimeException('Sem resposta do Inter: ' . $this->tokenError);
            }
            parse_str((string)$body, $form);
            $this->tokenScopes[] = (string)($form['scope'] ?? '');
            if ($this->refuseStatementScope && str_contains((string)($form['scope'] ?? ''), 'extrato.read')) {
                return ['status' => 403, 'body' => '{"title":"escopo não liberado"}'];
            }
            return ['status' => 200, 'body' => json_encode(['access_token' => 'tok-' . (++$this->sequence), 'expires_in' => 3600])];
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('timeout');
        }
        if ($this->nextResponse !== null) {
            [$response, $this->nextResponse] = [$this->nextResponse, null];
            return $response;
        }
        if (($headers['Authorization'] ?? '') === '' || !str_starts_with($headers['Authorization'], 'Bearer tok-')) {
            return ['status' => 401, 'body' => '{}'];
        }
        if ($method === 'GET' && str_starts_with($path, '/banking/v2/') && $this->rateLimited > 0) {
            $this->rateLimited--;
            return ['status' => 429, 'body' => ''];
        }
        if ($method === 'GET' && $path === '/banking/v2/extrato/completo' && $this->completeStatementFails) {
            return ['status' => 500, 'body' => '{"title":"Erro desconhecido.","detail":"Tente novamente mais tarde"}'];
        }
        if ($method === 'GET' && $path === '/banking/v2/extrato') {
            if ($this->simpleStatementFails) {
                return ['status' => 500, 'body' => '{"title":"Erro desconhecido."}'];
            }
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $simple = array_map(static function (array $t): array {
                $t['dataEntrada'] = $t['dataTransacao'];
                unset($t['idTransacao'], $t['dataTransacao'], $t['dataInclusao']);
                return $t;
            }, array_values(array_filter($this->statement, static fn (array $t): bool => ($t['dataTransacao'] ?? '') >= ($query['dataInicio'] ?? '')
                && ($t['dataTransacao'] ?? '') <= ($query['dataFim'] ?? ''))));
            return ['status' => 200, 'body' => json_encode(['transacoes' => $simple])];
        }
        if ($method === 'GET' && $path === '/banking/v2/extrato/completo') {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $size = max(1, (int)($query['tamanhoPagina'] ?? 50));
            $page = (int)($query['pagina'] ?? 0);
            $inPeriod = array_values(array_filter($this->statement, static fn (array $t): bool => ($t['dataTransacao'] ?? '') >= ($query['dataInicio'] ?? '')
                && ($t['dataTransacao'] ?? '') <= ($query['dataFim'] ?? '')));
            $pages = max(1, (int)ceil(count($inPeriod) / $size));
            return ['status' => 200, 'body' => json_encode(['totalPaginas' => $pages, 'totalElementos' => count($inPeriod), 'ultimaPagina' => $page + 1 >= $pages,
                'transacoes' => array_slice($inPeriod, $page * $size, $size)])];
        }
        if ($method === 'GET' && $path === '/banking/v2/saldo') {
            if ($this->balanceLimited) {
                return ['status' => 429, 'body' => '{"title":"Too Many Requests"}'];
            }
            return ['status' => 200, 'body' => json_encode(['bloqueadoCheque' => 0, 'disponivel' => $this->balance, 'bloqueadoJudicialmente' => 0, 'limite' => 0])];
        }
        if ($method === 'GET' && $path === '/cobranca/v3/cobrancas') {
            if ($this->listFails) {
                return ['status' => 500, 'body' => '{"title":"Erro desconhecido."}'];
            }
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $found = array_values(array_filter(array_keys($this->charges), fn (string $id): bool => $this->charges[$id]['seuNumero'] === ($query['seuNumero'] ?? null)));
            return ['status' => 200, 'body' => json_encode(['totalPaginas' => 1, 'totalElementos' => count($found),
                'cobrancas' => array_map(fn (string $id): array => $this->view($id), $found)])];
        }
        if ($method === 'POST' && $path === '/cobranca/v3/cobrancas') {
            $data = json_decode((string)$body, true);
            $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->sequence);
            $this->charges[$id] = ['seuNumero' => $data['seuNumero'], 'valorNominal' => $data['valorNominal'], 'situacao' => $this->initialSituation,
                'dataSituacao' => '2026-11-10', 'request' => $data];
            if ($this->createThen500) {
                $this->createThen500 = false;
                return ['status' => 500, 'body' => '{"title":"Erro desconhecido."}'];
            }
            return ['status' => 200, 'body' => json_encode(['codigoSolicitacao' => $id])];
        }
        if (preg_match('#^/cobranca/v3/cobrancas/([^/]+)(/pdf|/cancelar|/pagar)?$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($this->charges[$id])) {
                return ['status' => 404, 'body' => '{"title":"Cobrança não encontrada"}'];
            }
            $action = $m[2] ?? '';
            if ($method === 'POST' && $action === '/cancelar') {
                $this->charges[$id]['situacao'] = 'CANCELADO';
                return ['status' => 202, 'body' => ''];
            }
            if ($method === 'POST' && $action === '/pagar') {
                if (!str_contains((string)($headers['Accept'] ?? ''), 'application/problem+json')) {
                    // Como o sandbox real: a rota só produz problem+json.
                    return ['status' => 406, 'body' => json_encode(['title' => 'Falha', 'violacoes' => [['razao' => 'Specified Accept Types [application/json] not supported.', 'propriedade' => '']]])];
                }
                $paid = json_decode((string)$body, true);
                if (!in_array($paid['pagarCom'] ?? null, ['BOLETO', 'PIX'], true)) {
                    return ['status' => 400, 'body' => '{"title":"pagarCom inválido"}'];
                }
                $this->set($id, 'RECEBIDO', (string)$this->charges[$id]['valorNominal']);
                return ['status' => 204, 'body' => ''];
            }
            if ($method === 'GET' && $action === '/pdf') {
                return ['status' => 200, 'body' => json_encode(['pdf' => base64_encode("%PDF-1.4\n% boleto sintético\n")])];
            }
            if ($method === 'GET' && $action === '') {
                return ['status' => 200, 'body' => json_encode($this->view($id))];
            }
        }
        return ['status' => 404, 'body' => '{}'];
    }

    /** Muda a situação de uma cobrança como o banco faria (pagamento, cancelamento...). */
    public function set(string $id, string $situation, ?string $received = null, string $date = '2026-11-12'): void
    {
        $this->charges[$id]['situacao'] = $situation;
        $this->charges[$id]['dataSituacao'] = $date;
        $this->charges[$id]['valorTotalRecebido'] = $received;
    }

    public function count(string $method, string $prefix): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with($r['path'], $prefix)));
    }

    private function view(string $id): array
    {
        $c = $this->charges[$id];
        $digits = str_pad(substr($c['seuNumero'], -10), 10, '0', STR_PAD_LEFT);
        $hasBoleto = $c['situacao'] !== 'EM_PROCESSAMENTO' && $c['situacao'] !== 'FALHA_EMISSAO';
        return [
            'cobranca' => ['codigoSolicitacao' => $id, 'seuNumero' => $c['seuNumero'], 'situacao' => $c['situacao'], 'dataSituacao' => $c['dataSituacao'],
                'valorNominal' => $c['valorNominal'], 'valorTotalRecebido' => $c['valorTotalRecebido'] ?? null],
            'boleto' => $hasBoleto ? ['nossoNumero' => '9' . $digits, 'codigoBarras' => '07799' . str_repeat('0', 29) . $digits, 'linhaDigitavel' => '07790.00009 ' . $digits . ' TESTE'] : null,
            'pix' => $hasBoleto ? ['txid' => 'TXIDTESTE' . $digits, 'pixCopiaECola' => '00020101021226TESTE' . $digits] : null,
        ];
    }

    /**
     * ZIP montado à mão (sem ZipArchive), como o pacote que o banco entrega. @param array<string,string> $files nome => conteúdo
     */
    public static function zip(array $files, bool $deflate = true): string
    {
        $local = $central = '';
        foreach ($files as $name => $content) {
            $data = $deflate ? (string)gzdeflate($content) : $content;
            $head = pack('vvvvvVVVvv', 20, 0, $deflate ? 8 : 0, 0, 0, crc32($content), strlen($data), strlen($content), strlen($name), 0);
            $central .= "PK\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($local)) . $name;
            $local .= "PK\x03\x04" . $head . $name . $data;
        }
        return $local . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
    }

    /** Par certificado/chave autoassinado gerado na hora (nunca um certificado real). @return array{0:string,1:string} */
    public static function certificatePair(string $days = '30'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Teste Connect Sandbox'], $key);
        $cert = openssl_csr_sign($csr, null, $key, (int)$days);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        return [$certPem, $keyPem];
    }
}
