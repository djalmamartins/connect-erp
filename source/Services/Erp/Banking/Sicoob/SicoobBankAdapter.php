<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\Sicoob;

use InvalidArgumentException;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapter;
use Source\Services\Erp\Banking\BankChargeExtras;
use Source\Services\Erp\Banking\BankPaymentSimulator;
use Source\Services\Erp\Banking\BankStatementSource;
use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * Sicoob (banco 756), API Cobrança Bancária v3 e Conta Corrente, ADR 0633. SÓ O SANDBOX nesta versão.
 *
 * Contrato usado aqui (INFERIDO em 21/11/2026 de fontes públicas: portal developers.sicoob.com.br, que o proxy da
 * nuvem não abre, um SDK PHP aberto e relatos de integradores; conferir cada item no sandbox e na homologação,
 * checklist em docs/banking/SICOOB.md):
 *  - Sandbox: https://sandbox.sicoob.com.br/sicoob/sandbox/... com o client_id e o access token FIXOS que o portal
 *    mostra (cabeçalhos client_id e Authorization: Bearer). Sem certificado. As respostas do sandbox são fixas
 *    (o mesmo boleto volta para qualquer pedido), por isso o seuNumero devolvido não é conferido no sandbox.
 *  - Produção (NÃO LIGADA): token em auth.sicoob.com.br (client_credentials só com client_id + mTLS do certificado A1).
 *  - POST  /cobranca-bancaria/v3/boletos                 emite; resultado {nossoNumero, linhaDigitavel, codigoBarras, qrCode}
 *  - GET   /cobranca-bancaria/v3/boletos?numeroCliente&codigoModalidade&nossoNumero   consulta (situacaoBoleto, listaHistorico)
 *  - POST  /cobranca-bancaria/v3/boletos/{nossoNumero}/baixar {numeroCliente, codigoModalidade}   baixa (204)
 *  - GET   /cobranca-bancaria/v3/boletos/segunda-via?...&gerarPdf=true                PDF em base64 (pdfBoleto)
 *  - GET   /conta-corrente/v3/extrato/{mes}/{ano}?numeroContaCorrente&diaInicial&diaFinal  transacoes (tipo CREDITO/DEBITO)
 *  - GET   /conta-corrente/v2/saldo?numeroContaCorrente                                saldo
 * Valores em reais com casas decimais (como o Inter), não em centavos. O Sicoob não simula pagamento no sandbox:
 * a baixa vem da consulta de hora em hora (tools/bank-charges-sync.php); webhook PENDENTE (endereço público).
 *
 * Mapeamento de situacaoBoleto (texto, INFERIDO): "Em Aberto"/"Vencido" → registered; "Liquidado" → evento 'paid';
 * "Baixado"/"Baixado por Solicitação" → evento 'cancelled'; desconhecido → pending com aviso.
 */
final class SicoobBankAdapter implements BankAdapter, BankChargeExtras, BankStatementSource, BankPaymentSimulator
{
    public const NAME = 'sicoob';
    public const BANK_CODE = '756';
    public const SANDBOX_URL = 'https://sandbox.sicoob.com.br/sicoob/sandbox';
    public const PRODUCTION_URL = 'https://api.sicoob.com.br';
    /** Modalidade 1 = cobrança simples com registro (padrão quando a conta não informa a carteira). */
    public const DEFAULT_MODALITY = 1;
    private const BILLING = '/cobranca-bancaria/v3';
    private const RETRY_WAITS = [3, 8, 15];

    /**
     * @param array{client_id:string, client_secret:string, client_number:string, modality?:string|int, certificate?:string,
     *   private_key?:string, account_number?:string} $credentials client_secret = access token do sandbox
     */
    public function __construct(
        private array $credentials,
        private InterHttp $http,
        private string $baseUrl = self::SANDBOX_URL,
        private ?\Closure $clock = null,
        private ?\Closure $sleep = null,
    ) {
        foreach (['client_id' => 'client ID', 'client_secret' => 'access token do sandbox', 'client_number' => 'número do cliente (código do beneficiário)'] as $key => $label) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                throw new InvalidArgumentException("Credencial do Sicoob incompleta: falta o {$label}.");
            }
        }
        if (!preg_match('/^\d{1,10}$/', trim((string)$credentials['client_number']))) {
            throw new InvalidArgumentException('Número do cliente do Sicoob inválido: use só os números do código do beneficiário.');
        }
        if ($baseUrl !== self::SANDBOX_URL) {
            // Produção só depois de homologação e aprovação explícita do Djalma (BANKING_INTEGRATIONS.md, item 10).
            throw new InvalidArgumentException('O adaptador do Sicoob só está liberado para o sandbox.');
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): array
    {
        return ['register', 'query', 'cancel', 'pdf', 'pix', 'statement'];
    }

    /** Modalidade de cobrança: carteira da conta (só números) ou 1. */
    public static function modality(mixed $wallet): int
    {
        $digits = preg_replace('/\D+/', '', (string)$wallet) ?? '';
        return $digits !== '' && (int)$digits > 0 && (int)$digits < 100 ? (int)$digits : self::DEFAULT_MODALITY;
    }

    /** Corpo da emissão (regra pura, pública para os testes). Dado faltando = InvalidArgumentException. */
    public static function chargeBody(array $charge, int $clientNumber, int $modality, ?string $today = null): array
    {
        $payer = is_array($charge['payer'] ?? null) ? $charge['payer'] : [];
        $document = preg_replace('/\D+/', '', (string)($payer['document'] ?? '')) ?? '';
        if (!in_array(strlen($document), [11, 14], true)) {
            throw new InvalidArgumentException('Pagador sem CPF ou CNPJ válido: cadastre o responsável financeiro ou o proprietário da unidade com documento.');
        }
        $name = trim((string)($payer['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new InvalidArgumentException('Pagador sem nome.');
        }
        $zip = preg_replace('/\D+/', '', (string)($payer['zip_code'] ?? '')) ?? '';
        $state = strtoupper(trim((string)($payer['state'] ?? '')));
        $street = trim((string)($payer['street'] ?? ''));
        $city = trim((string)($payer['city'] ?? ''));
        $district = trim((string)($payer['district'] ?? ''));
        if (strlen($zip) !== 8 || !preg_match('/^[A-Z]{2}$/', $state) || $street === '' || $city === '' || $district === '') {
            throw new InvalidArgumentException('Endereço de cobrança incompleto: confira CEP, logradouro, bairro, cidade e UF no cadastro do condomínio.');
        }
        $ourNumber = (string)$charge['our_number'];
        if (!preg_match('/^\d{1,18}$/', $ourNumber)) {
            throw new InvalidArgumentException('Nosso número inválido para o Sicoob.');
        }
        $amount = (int)$charge['amount_cents'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Valor do boleto precisa ser maior que zero.');
        }
        $due = self::date($charge['due_on'] ?? null);
        if ($due === null) {
            throw new InvalidArgumentException('Vencimento inválido.');
        }
        $account = preg_replace('/\D+/', '', (string)($charge['account_number'] ?? '')) ?? '';
        if ($account === '' || strlen($account) > 12) {
            throw new InvalidArgumentException('Número da conta corrente do Sicoob inválido no cadastro da conta bancária.');
        }
        $street = trim($street . ', ' . (trim((string)($payer['number'] ?? '')) ?: 'S/N') . (trim((string)($payer['complement'] ?? '')) !== '' ? ' ' . trim((string)$payer['complement']) : ''));
        $email = filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
        $pagador = [
            'numeroCpfCnpj' => $document,
            'nome' => mb_substr($name, 0, 50),
            'endereco' => mb_substr($street, 0, 40),
            'bairro' => mb_substr($district, 0, 30),
            'cidade' => mb_substr($city, 0, 40),
            'cep' => $zip,
            'uf' => $state,
        ];
        if ($email !== null) {
            $pagador['email'] = $email;   // v3: texto, não lista
        }
        $description = trim((string)($charge['description'] ?? ''));
        $body = [
            'numeroCliente' => $clientNumber,
            'codigoModalidade' => $modality,
            'numeroContaCorrente' => (int)$account,
            'codigoEspecieDocumento' => 'DM',
            'dataEmissao' => $today ?? date('Y-m-d'),
            'seuNumero' => $ourNumber,
            'identificacaoBoletoEmpresa' => $ourNumber,
            'identificacaoEmissaoBoleto' => 2,       // a Connect emite o boleto com o layout próprio (decisão de 11/11)
            'identificacaoDistribuicaoBoleto' => 2,  // a Connect entrega (e-mail/app), não o correio do banco
            'valor' => round($amount / 100, 2),
            'dataVencimento' => $due,
            'tipoDesconto' => 0,
            'tipoMulta' => 0,
            'tipoJurosMora' => 3,                    // 3 = isento (quando a regra do condomínio não tem juros)
            'numeroParcela' => 1,
            'aceite' => true,
            'pagador' => $pagador,
            'mensagensInstrucao' => [mb_substr($description !== '' ? $description : 'Cota condominial', 0, 40)],
            'gerarPdf' => false,
            'codigoCadastrarPIX' => 1,               // 1 = boleto com QR do Pix (híbrido)
        ];
        $nextDay = (new \DateTimeImmutable($due))->modify('+1 day')->format('Y-m-d');
        $fine = (int)($charge['fine_bp'] ?? 0);
        $interest = (int)($charge['monthly_interest_bp'] ?? 0);
        if ($fine > 0) {
            $body['tipoMulta'] = 2;                  // 2 = percentual
            $body['dataMulta'] = $nextDay;
            $body['valorMulta'] = round($fine / 100, 2);
        }
        if ($interest > 0) {
            $body['tipoJurosMora'] = 2;              // 2 = taxa mensal (INFERIDO: conferir no sandbox/homologação)
            $body['dataJurosMora'] = $nextDay;
            $body['valorJurosMora'] = round($interest / 100, 2);
        }
        return $body;
    }

    public function registerCharge(array $charge): array
    {
        try {
            $body = self::chargeBody($charge + ['account_number' => $this->credentials['account_number'] ?? ''],
                $this->clientNumber(), self::modality($this->credentials['modality'] ?? $charge['wallet'] ?? null));
        } catch (InvalidArgumentException $error) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => $error->getMessage()];
        }
        $response = $this->call('POST', self::BILLING . '/boletos', $body);
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => self::errorMessage($response)];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Sicoob respondeu ' . $response['status'] . ' na emissão.');
        }
        $data = self::json($response['body']);
        $result = is_array($data['resultado'] ?? null) ? $data['resultado'] : $data;
        $id = self::ourNumberOf($result['nossoNumero'] ?? null);
        if ($id === null) {
            throw new RuntimeException('Sicoob não devolveu o nosso número do boleto.');
        }
        return self::mapCharge($result, (string)$charge['our_number'], $id, false);
    }

    public function getCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || self::ourNumberOf($externalId) === null) {
            return ['status' => 'pending', 'external_id' => null, 'digitable_line' => null, 'barcode' => null,
                'error' => 'Sem o nosso número do Sicoob. Confira no Sicoobnet antes de emitir de novo.'];
        }
        $response = $this->call('GET', self::BILLING . '/boletos?' . http_build_query($this->keyOf($externalId)));
        if ($response['status'] === 404 || $response['status'] === 204) {
            return ['status' => 'pending', 'external_id' => $externalId, 'digitable_line' => null, 'barcode' => null, 'error' => 'Sicoob não encontrou o boleto.'];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Sicoob respondeu ' . $response['status'] . ' na consulta.');
        }
        $data = self::json($response['body']);
        return self::mapCharge(is_array($data['resultado'] ?? null) ? $data['resultado'] : $data, $ourNumber, $externalId, false);
    }

    /**
     * Converte o boleto do Sicoob no resultado do adaptador (regra pura). $strict confere o seuNumero devolvido; o
     * sandbox devolve sempre o mesmo boleto de exemplo, então lá a conferência fica desligada.
     */
    public static function mapCharge(array $data, string $ourNumber, string $externalId, bool $strict = true): array
    {
        $theirs = self::text($data['seuNumero'] ?? null, 20);
        if ($strict && $theirs !== null && ltrim($theirs, '0') !== ltrim($ourNumber, '0')) {
            throw new RuntimeException('Sicoob devolveu outro boleto para este nosso número (seuNumero diferente).');
        }
        $status = mb_strtolower(trim((string)($data['situacaoBoleto'] ?? 'em aberto')));
        $status = strtr($status, ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ê' => 'e']);
        $result = [
            'status' => 'pending', 'external_id' => $externalId,
            'digitable_line' => self::text($data['linhaDigitavel'] ?? null, 80),
            'barcode' => self::text($data['codigoBarras'] ?? null, 60),
            'bank_our_number' => $externalId,
            'pix_txid' => null,
            'pix_copy_paste' => self::text($data['qrCode'] ?? null, 1000),
            'error' => null, 'event' => null,
        ];
        $event = static fn (string $kind, ?int $cents, ?string $on): array => [
            'event_key' => 'sicoob:' . $externalId . ':' . $kind, 'kind' => $kind, 'our_number' => $ourNumber,
            'amount_cents' => $cents, 'occurred_on' => $on,
        ];
        $lastHistory = self::lastHistoryDate($data);
        if (str_starts_with($status, 'liquidad')) {
            $result['status'] = 'registered';
            $result['event'] = $event('paid', self::cents($data['valorPago'] ?? $data['valor'] ?? null), self::date($data['dataLiquidacao'] ?? null) ?? self::lastHistoryDate($data, 'liquida'));
        } elseif (str_starts_with($status, 'baixad')) {
            $result['status'] = $result['digitable_line'] !== null ? 'registered' : 'pending';
            $result['event'] = $event('cancelled', null, $lastHistory);
        } elseif (in_array($status, ['em aberto', 'aberto', 'vencido', 'a vencer', 'registrado'], true)) {
            $result['status'] = $result['digitable_line'] !== null ? 'registered' : 'pending';
            if ($result['digitable_line'] === null) {
                $result['error'] = 'O Sicoob ainda não devolveu a linha digitável.';
            }
        } else {
            $result['error'] = 'Situação desconhecida no Sicoob: ' . mb_substr($status, 0, 30) . '.';
        }
        return $result;
    }

    public function cancelCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || self::ourNumberOf($externalId) === null) {
            return ['status' => 'pending', 'error' => 'Sem o nosso número do Sicoob: baixe o boleto pelo Sicoobnet.'];
        }
        $key = $this->keyOf($externalId);
        unset($key['nossoNumero']);
        $response = $this->call('POST', self::BILLING . '/boletos/' . rawurlencode($externalId) . '/baixar', $key);
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['status' => 'cancelled', 'error' => null];
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'error' => self::errorMessage($response)];
        }
        throw new RuntimeException('Sicoob respondeu ' . $response['status'] . ' na baixa.');
    }

    /** O sandbox do Sicoob não tem simulação de pagamento: a recusa vira mensagem na tela, sem chamar o banco. */
    public function simulatePayment(?string $externalId, string $method = 'BOLETO'): void
    {
        throw new InvalidArgumentException('O sandbox do Sicoob não simula pagamento. A baixa vem da consulta do boleto.');
    }

    public function parseReturn(string $content): array
    {
        throw new InvalidArgumentException('O Sicoob pela API não usa arquivo de retorno: a baixa chega pela consulta do boleto.');
    }

    /** PDF que o próprio Sicoob gera (segunda via em base64). O boleto da Connect sai com o layout dela. */
    public function downloadBoleto(string $ourNumber, ?string $externalId): string
    {
        if ($externalId === null || self::ourNumberOf($externalId) === null) {
            throw new RuntimeException('Boleto sem o nosso número do Sicoob.');
        }
        $response = $this->call('GET', self::BILLING . '/boletos/segunda-via?' . http_build_query($this->keyOf($externalId) + ['gerarPdf' => 'true']));
        if ($response['status'] !== 200) {
            throw new RuntimeException('Sicoob respondeu ' . $response['status'] . ' na segunda via.');
        }
        $data = self::json($response['body']);
        $pdf = base64_decode((string)($data['resultado']['pdfBoleto'] ?? ''), true);
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Sicoob devolveu um PDF inválido.');
        }
        return $pdf;
    }

    public function parseWebhook(string $body): array
    {
        // PENDENTE: o webhook do Sicoob entra quando houver endereço público; até lá a baixa vem da consulta de hora em hora.
        throw new InvalidArgumentException('Aviso de pagamento do Sicoob ainda não está ligado.');
    }

    public function statement(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);
        if ($from === null || $to === null || $from > $to) {
            throw new InvalidArgumentException('Período do extrato inválido.');
        }
        $account = $this->accountNumber();
        $lines = [];
        $seen = [];
        // O extrato do Sicoob é por mês: um pedido para cada mês do período.
        for ($month = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $month->format('Y-m') <= substr($to, 0, 7); $month = $month->modify('+1 month')) {
            $first = max($from, $month->format('Y-m-01'));
            $last = min($to, $month->format('Y-m-t'));
            $response = $this->call('GET', '/conta-corrente/v3/extrato/' . $month->format('n') . '/' . $month->format('Y') . '?' . http_build_query([
                'numeroContaCorrente' => $account, 'diaInicial' => (int)substr($first, 8, 2), 'diaFinal' => (int)substr($last, 8, 2)]));
            if ($response['status'] !== 200) {
                throw new RuntimeException(self::errorMessage($response));
            }
            $data = self::json($response['body']);
            $items = $data['resultado']['transacoes'] ?? [];
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item) && ($line = self::statementLine($item, $seen)) !== null && $line['posted_on'] >= $from && $line['posted_on'] <= $to) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    /** O Sicoob informa só o saldo de agora: para um dia passado devolve null (o OFX sai sem saldo). */
    public function balance(string $on): ?int
    {
        $on = self::date($on);
        if ($on === null) {
            throw new InvalidArgumentException('Data do saldo inválida.');
        }
        if ($on < date('Y-m-d', $this->clock !== null ? ($this->clock)() : time())) {
            return null;
        }
        $response = $this->call('GET', '/conta-corrente/v2/saldo?' . http_build_query(['numeroContaCorrente' => $this->accountNumber()]));
        if ($response['status'] !== 200) {
            throw new RuntimeException(self::errorMessage($response));
        }
        return self::cents(self::json($response['body'])['resultado']['saldo'] ?? null, true);
    }

    /**
     * Uma transação do extrato do Sicoob como linha de OFX (regra pura, pública para os testes). O Sicoob não manda
     * id da transação: o FITID sai do hash dos campos, com sufixo quando duas linhas iguais caem no mesmo extrato.
     *
     * @param array<string, true> $seen
     */
    public static function statementLine(array $item, array &$seen): ?array
    {
        $date = self::date($item['data'] ?? $item['dataLote'] ?? null);
        $cents = self::cents($item['valor'] ?? null, true);
        $type = strtoupper(trim((string)($item['tipo'] ?? '')));
        if ($date === null || $cents === null || !in_array($type, ['CREDITO', 'DEBITO'], true)) {
            return null;
        }
        $cents = abs($cents);
        $memo = trim(implode(' - ', array_unique(array_filter([self::text($item['descricao'] ?? null, 100), self::text($item['descInfComplementar'] ?? null, 60)]))));
        $doc = self::text($item['numeroDocumento'] ?? null, 40) ?? '';
        $base = 'sicoob-h' . substr(sha1($date . '|' . $type . '|' . $cents . '|' . $doc . '|' . $memo), 0, 24);
        $fitid = $base;
        for ($n = 2; isset($seen[$fitid]); $n++) {
            $fitid = $base . '-' . $n;
        }
        $seen[$fitid] = true;
        return ['fitid' => $fitid, 'kind' => $type === 'CREDITO' ? 'credit' : 'debit', 'posted_on' => $date,
            'amount_cents' => $type === 'CREDITO' ? $cents : -$cents, 'memo' => mb_substr($memo !== '' ? $memo : 'Sicoob', 0, 160)];
    }

    /**
     * No sandbox não há pedido de token: o teste da credencial é uma consulta só de leitura. 401/403 = recusada;
     * qualquer outra resposta do banco prova que o client ID e o token passaram.
     */
    public function checkAuthentication(): void
    {
        $response = $this->call('GET', self::BILLING . '/boletos?' . http_build_query($this->keyOf('1')));
        if (in_array($response['status'], [401, 403], true)) {
            throw new RuntimeException('Sicoob recusou a autenticação (' . $response['status'] . '). Confira o client ID e o access token do sandbox.');
        }
        if ($response['status'] >= 500) {
            throw new RuntimeException('Sicoob respondeu ' . $response['status'] . ' no teste.');
        }
    }

    /** @return array{numeroCliente:int, codigoModalidade:int, nossoNumero:string} */
    private function keyOf(string $externalId): array
    {
        return ['numeroCliente' => $this->clientNumber(), 'codigoModalidade' => self::modality($this->credentials['modality'] ?? null), 'nossoNumero' => $externalId];
    }

    private function clientNumber(): int
    {
        return (int)trim((string)$this->credentials['client_number']);
    }

    private function accountNumber(): int
    {
        $digits = preg_replace('/\D+/', '', (string)($this->credentials['account_number'] ?? '')) ?? '';
        if ($digits === '') {
            throw new InvalidArgumentException('Conta bancária do Sicoob sem número da conta corrente.');
        }
        return (int)$digits;
    }

    /**
     * Pedido autenticado (sandbox: client_id + access token fixos, sem certificado). 429 e 503/504 esperam e repetem.
     *
     * @return array{status:int, body:string}
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ([...self::RETRY_WAITS, null] as $wait) {
            $headers = ['client_id' => trim((string)$this->credentials['client_id']), 'Authorization' => 'Bearer ' . trim((string)$this->credentials['client_secret']),
                'Accept' => 'application/json'];
            if ($json !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            $response = $this->http->request($method, $this->baseUrl . $path, $headers, $body,
                (string)($this->credentials['certificate'] ?? ''), (string)($this->credentials['private_key'] ?? ''));
            if (!in_array($response['status'], [429, 503, 504], true)) {
                return $response;
            }
            if ($wait !== null) {
                $this->sleep !== null ? ($this->sleep)($wait) : sleep($wait);
            }
        }
        throw new RuntimeException('O Sicoob limitou os pedidos (' . $response['status'] . ') e continuou recusando depois de esperar. Espere um minuto e tente de novo.');
    }

    private static function ourNumberOf(mixed $value): ?string
    {
        // O sandbox devolve números de exemplo (até negativos); o sinal é aceito para não travar a homologação.
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,19}$/', trim($value)))) {
            return (string)(is_int($value) ? $value : trim($value));
        }
        return null;
    }

    /** Reais (número ou texto "1234.56") em centavos. Negativo só com $allowNegative (saldo e extrato). */
    private static function cents(mixed $value, bool $allowNegative = false): ?int
    {
        if (is_int($value) || is_float($value)) {
            $cents = (int)round($value * 100);
        } elseif (is_string($value) && preg_match('/^-?\d+(?:\.\d{1,2})?$/', trim($value))) {
            $cents = (int)round(((float)trim($value)) * 100);
        } else {
            return null;
        }
        return $cents < 0 && !$allowNegative ? null : $cents;
    }

    /** Data mais recente do histórico; com $word, a do histórico que fala dela (ex.: liquidação), senão a mais recente. */
    private static function lastHistoryDate(array $data, ?string $word = null): ?string
    {
        $last = $match = null;
        foreach (is_array($data['listaHistorico'] ?? null) ? $data['listaHistorico'] : [] as $item) {
            $on = is_array($item) ? self::date($item['dataHistorico'] ?? null) : null;
            if ($on === null) {
                continue;
            }
            if ($last === null || $on > $last) {
                $last = $on;
            }
            if ($word !== null && str_contains(mb_strtolower((string)($item['descricaoHistorico'] ?? '')), $word) && ($match === null || $on > $match)) {
                $match = $on;
            }
        }
        return $match ?? $last;
    }

    private static function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta do Sicoob não é JSON.');
        }
        return $data;
    }

    /** Mensagem de erro do Sicoob ({mensagens:[{mensagem, codigo}]}), curta e sem dados do pagador. */
    private static function errorMessage(array $response): string
    {
        $data = json_decode($response['body'], true);
        $parts = [];
        if (is_array($data)) {
            foreach (is_array($data['mensagens'] ?? null) ? array_slice($data['mensagens'], 0, 3) : [] as $m) {
                if (is_array($m)) {
                    $parts[] = trim((string)($m['codigo'] ?? '') . ' ' . (string)($m['mensagem'] ?? ''));
                }
            }
            foreach (['message', 'error', 'error_description'] as $key) {
                if (is_scalar($data[$key] ?? null) && trim((string)$data[$key]) !== '') {
                    $parts[] = trim((string)$data[$key]);
                }
            }
        }
        $text = implode(': ', array_unique(array_filter($parts)));
        return mb_substr('Sicoob recusou (' . $response['status'] . ')' . ($text !== '' ? ' ' . $text : '') . '.', 0, 400);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function date(mixed $value): ?string
    {
        $value = is_string($value) ? substr($value, 0, 10) : '';
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
