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
    private int $sequence = 0;

    public function request(string $method, string $url, array $headers, ?string $body, string $certificatePem, string $privateKeyPem): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
        if ($certificatePem === '' || $privateKeyPem === '') {
            throw new RuntimeException('sem mTLS');
        }
        if ($path === '/oauth/v2/token') {
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
        if ($method === 'POST' && $path === '/cobranca/v3/cobrancas') {
            $data = json_decode((string)$body, true);
            $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->sequence);
            $this->charges[$id] = ['seuNumero' => $data['seuNumero'], 'valorNominal' => $data['valorNominal'], 'situacao' => $this->initialSituation,
                'dataSituacao' => '2026-11-10', 'request' => $data];
            return ['status' => 200, 'body' => json_encode(['codigoSolicitacao' => $id])];
        }
        if (preg_match('#^/cobranca/v3/cobrancas/([^/]+)(/pdf|/cancelar)?$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($this->charges[$id])) {
                return ['status' => 404, 'body' => '{"title":"Cobrança não encontrada"}'];
            }
            $action = $m[2] ?? '';
            if ($method === 'POST' && $action === '/cancelar') {
                $this->charges[$id]['situacao'] = 'CANCELADO';
                return ['status' => 202, 'body' => ''];
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
