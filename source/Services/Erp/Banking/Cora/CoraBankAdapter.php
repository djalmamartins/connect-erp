<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\Cora;

use InvalidArgumentException;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapter;
use Source\Services\Erp\Banking\BankChargeExtras;
use Source\Services\Erp\Banking\BankPaymentSimulator;
use Source\Services\Erp\Banking\BankStatementSource;
use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * Cora (Cora SCD, banco 403), Integração Direta, ADR 0322. SÓ O AMBIENTE DE TESTE (stage) nesta versão.
 *
 * Contrato da Cora usado aqui (INFERIDO da documentação pública developers.cora.com.br em 16/11/2026;
 * conferir cada item na homologação do stage antes de qualquer uso real, checklist em docs/banking/CORA.md):
 *  - mTLS em TODAS as chamadas (certificado e chave da credencial do ambiente), host matls-clients;
 *  - POST   /token  grant_type=client_credentials&client_id=...  (sem client secret; token de 24 h, sem refresh)
 *  - POST   /v2/invoices/            emite, síncrono, com Idempotency-Key (UUID). Devolve id inv_..., status,
 *                                    payment_options.bank_slip {barcode, digitable, our_number, url} e pix.emv
 *  - GET    /v2/invoices/{id}        consulta (status, total_paid, occurrence_date, payments[])
 *  - DELETE /v2/invoices/{id}        cancela (204; 422 REC-0006 = já pago)
 *  - POST   /v2/invoices/pay {id}    só stage: simula o pagamento (Idempotency-Key)
 *  - GET    /bank-statement/statement?start&end&page&perPage  entries (id, type CREDIT/DEBIT, amount em centavos,
 *                                    createdAt, transaction.description, transaction.counterParty.name)
 *  - GET    /third-party/account/balance                      balance (INFERIDO: em centavos, como o resto da API)
 * Valores em centavos (inteiros), ao contrário do Inter. Valor mínimo do boleto: R$ 5,00.
 * A Cora não tem arquivo de retorno; o webhook fica para quando houver endereço público (a baixa vem
 * da consulta automática de hora em hora, tools/bank-charges-sync.php).
 *
 * Mapeamento do status da Cora:
 *   OPEN, LATE → registered; IN_PAYMENT → registered com aviso (pagamento em compensação, ainda sem baixa);
 *   PAID → registered + evento 'paid'; CANCELLED → evento 'cancelled';
 *   DRAFT, INITIATED, RECURRENCE_DRAFT → pending; desconhecido → pending com aviso (nunca muda estado no escuro).
 */
final class CoraBankAdapter implements BankAdapter, BankChargeExtras, BankStatementSource, BankPaymentSimulator
{
    public const NAME = 'cora';
    public const BANK_CODE = '403';
    public const STAGE_URL = 'https://matls-clients.api.stage.cora.com.br';
    public const PRODUCTION_URL = 'https://matls-clients.api.cora.com.br';
    public const MIN_AMOUNT_CENTS = 500;
    private const STATEMENT_PAGE_SIZE = 200;
    private const STATEMENT_MAX_PAGES = 50;
    /** Esperas (segundos) antes de repetir um pedido que a Cora limitou (429) ou que ficou sem fôlego (503/504). */
    private const RETRY_WAITS = [3, 8, 15];

    private ?array $token = null;

    /**
     * @param array{client_id:string, certificate:string, private_key:string, client_secret?:string, account_number?:string} $credentials
     */
    public function __construct(
        private array $credentials,
        private InterHttp $http,
        private string $baseUrl = self::STAGE_URL,
        private ?\Closure $clock = null,
        private ?\Closure $sleep = null,
    ) {
        foreach (['client_id', 'certificate', 'private_key'] as $key) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                throw new InvalidArgumentException("Credencial da Cora incompleta: falta {$key}.");
            }
        }
        if ($baseUrl !== self::STAGE_URL) {
            // Produção só depois de homologação e aprovação explícita do Djalma (BANKING_INTEGRATIONS.md, item 10).
            throw new InvalidArgumentException('O adaptador da Cora só está liberado para o ambiente de teste (stage).');
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

    /** Corpo da emissão (regra pura, pública para os testes). Dado faltando = InvalidArgumentException. */
    public static function chargeBody(array $charge): array
    {
        $payer = is_array($charge['payer'] ?? null) ? $charge['payer'] : [];
        $document = preg_replace('/\D+/', '', (string)($payer['document'] ?? '')) ?? '';
        if (!in_array(strlen($document), [11, 14], true)) {
            throw new InvalidArgumentException('Pagador sem CPF ou CNPJ válido: cadastre o responsável financeiro ou o proprietário da unidade com documento.');
        }
        $name = trim((string)($payer['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new InvalidArgumentException('Pagador sem nome (a Cora pede pelo menos 3 letras).');
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
        if (!preg_match('/^\d{1,20}$/', $ourNumber)) {
            throw new InvalidArgumentException('Nosso número inválido para a Cora.');
        }
        $amount = (int)$charge['amount_cents'];
        if ($amount < self::MIN_AMOUNT_CENTS) {
            throw new InvalidArgumentException('Valor mínimo do boleto na Cora é R$ 5,00.');
        }
        $due = self::date($charge['due_on'] ?? null);
        if ($due === null) {
            throw new InvalidArgumentException('Vencimento inválido.');
        }
        $description = trim((string)($charge['description'] ?? ''));
        $email = filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
        $customer = [
            'name' => mb_substr($name, 0, 255),
            'document' => ['identity' => $document, 'type' => strlen($document) === 11 ? 'CPF' : 'CNPJ'],
            'address' => array_filter([
                'street' => mb_substr($street, 0, 100),
                'number' => mb_substr(trim((string)($payer['number'] ?? '')) ?: 'S/N', 0, 10),
                'district' => mb_substr($district, 0, 60),
                'city' => mb_substr($city, 0, 60),
                'state' => $state,
                'zip_code' => $zip,
                'complement' => mb_substr(trim((string)($payer['complement'] ?? '')), 0, 30),
            ], static fn ($v): bool => $v !== ''),
        ];
        if ($email !== null) {
            $customer['email'] = $email;
        }
        $body = [
            'code' => $ourNumber,
            'customer' => $customer,
            'services' => [['name' => mb_substr($description !== '' ? $description : 'Cota condominial', 0, 255), 'amount' => $amount]],
            'payment_terms' => ['due_date' => $due],
            'payment_forms' => ['BANK_SLIP', 'PIX'],
        ];
        $fine = (int)($charge['fine_bp'] ?? 0);
        $interest = (int)($charge['monthly_interest_bp'] ?? 0);
        if ($fine > 0) {
            $body['payment_terms']['fine'] = ['rate' => round($fine / 100, 2)];
        }
        if ($interest > 0) {
            // INFERIDO: a Cora trata interest.rate como taxa mensal (conferir no PDF do boleto do stage).
            $body['payment_terms']['interest'] = ['rate' => round($interest / 100, 2)];
        }
        return $body;
    }

    /**
     * Chave de idempotência estável por conta e nosso número (formato UUID): pedir de novo o mesmo boleto
     * (timeout, clique duplo) devolve o boleto já criado na Cora, sem cobrar em dobro.
     */
    public static function idempotencyKey(string $accountNumber, string $ourNumber): string
    {
        $hex = substr(hash('sha256', 'connect-erp:cora:' . preg_replace('/\D+/', '', $accountNumber) . ':' . $ourNumber), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public function registerCharge(array $charge): array
    {
        try {
            $body = self::chargeBody($charge);
        } catch (InvalidArgumentException $error) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => $error->getMessage()];
        }
        $key = self::idempotencyKey((string)($charge['account_number'] ?? $this->credentials['account_number'] ?? ''), (string)$charge['our_number']);
        $response = $this->call('POST', '/v2/invoices/', $body, ['Idempotency-Key' => $key]);
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => self::errorMessage($response)];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Cora respondeu ' . $response['status'] . ' na emissão.');
        }
        $data = self::json($response['body']);
        $id = (string)($data['id'] ?? '');
        if (!self::validInvoiceId($id)) {
            throw new RuntimeException('Cora não devolveu o identificador do boleto.');
        }
        return self::mapCharge($data, (string)$charge['our_number'], $id);
    }

    public function getCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validInvoiceId($externalId)) {
            // Sem o id da Cora não dá para consultar. Emitir de novo usa a mesma chave de idempotência, então não duplica.
            return ['status' => 'pending', 'external_id' => null, 'digitable_line' => null, 'barcode' => null,
                'error' => 'Sem o identificador do boleto na Cora. Confira na Cora Web antes de emitir de novo.'];
        }
        $response = $this->call('GET', '/v2/invoices/' . rawurlencode($externalId));
        if ($response['status'] === 404) {
            return ['status' => 'pending', 'external_id' => $externalId, 'digitable_line' => null, 'barcode' => null, 'error' => 'Cora não encontrou o boleto.'];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Cora respondeu ' . $response['status'] . ' na consulta.');
        }
        return self::mapCharge(self::json($response['body']), $ourNumber, $externalId);
    }

    /** Converte o boleto da Cora no resultado do adaptador (regra pura). */
    public static function mapCharge(array $data, string $ourNumber, string $externalId): array
    {
        $code = isset($data['code']) && $data['code'] !== null && $data['code'] !== '' ? (string)$data['code'] : $ourNumber;
        if ($code !== $ourNumber) {
            throw new RuntimeException('Cora devolveu outro boleto para este identificador (code diferente).');
        }
        $options = is_array($data['payment_options'] ?? null) ? $data['payment_options'] : [];
        $slip = is_array($options['bank_slip'] ?? null) ? $options['bank_slip'] : [];
        $pix = is_array($data['pix'] ?? null) ? $data['pix'] : (is_array($options['pix'] ?? null) ? $options['pix'] : []);
        $status = strtoupper((string)($data['status'] ?? ''));
        $result = [
            'status' => 'pending', 'external_id' => $externalId,
            'digitable_line' => self::text($slip['digitable'] ?? null, 80),
            'barcode' => self::text($slip['barcode'] ?? null, 60),
            'bank_our_number' => self::text($slip['our_number'] ?? null, 20),
            'pix_txid' => null,
            'pix_copy_paste' => self::text($pix['emv'] ?? null, 1000),
            'error' => null, 'event' => null,
        ];
        $hasSlip = $result['digitable_line'] !== null;
        $event = static fn (string $kind, ?int $cents, ?string $on): array => [
            'event_key' => 'cora:' . $externalId . ':' . $status, 'kind' => $kind, 'our_number' => $ourNumber,
            'amount_cents' => $cents, 'occurred_on' => $on,
        ];
        switch ($status) {
            case 'OPEN':
            case 'LATE':
                $result['status'] = 'registered';
                break;
            case 'IN_PAYMENT':
                $result['status'] = 'registered';
                $result['error'] = 'Pagamento em compensação na Cora; a baixa entra quando ela confirmar.';
                break;
            case 'PAID':
                $result['status'] = 'registered';
                $paid = self::paidCents($data);
                $result['event'] = $event('paid', $paid, self::date($data['occurrence_date'] ?? null) ?? self::paidOn($data));
                break;
            case 'CANCELLED':
                $result['status'] = $hasSlip ? 'registered' : 'pending';
                $result['event'] = $event('cancelled', null, null);
                break;
            case 'DRAFT':
            case 'INITIATED':
            case 'RECURRENCE_DRAFT':
                $result['error'] = 'A Cora ainda está registrando o boleto.';
                break;
            default:
                $result['error'] = 'Situação desconhecida na Cora: ' . mb_substr($status, 0, 30) . '.';
        }
        return $result;
    }

    public function cancelCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validInvoiceId($externalId)) {
            return ['status' => 'pending', 'error' => 'Sem o identificador do boleto na Cora: cancele pela Cora Web.'];
        }
        $response = $this->call('DELETE', '/v2/invoices/' . rawurlencode($externalId));
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['status' => 'cancelled', 'error' => null];
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'error' => self::errorMessage($response)];
        }
        throw new RuntimeException('Cora respondeu ' . $response['status'] . ' no cancelamento.');
    }

    /** Só stage (o construtor recusa outra URL): a Cora simula o pagamento do boleto. Boleto e Pix usam a mesma rota. */
    public function simulatePayment(?string $externalId, string $method = 'BOLETO'): void
    {
        if ($externalId === null || !self::validInvoiceId($externalId)) {
            throw new InvalidArgumentException('Boleto sem o identificador da Cora.');
        }
        if (!in_array($method, ['BOLETO', 'PIX'], true)) {
            throw new InvalidArgumentException('Forma de pagamento simulado inválida (BOLETO ou PIX).');
        }
        $response = $this->call('POST', '/v2/invoices/pay', ['id' => $externalId], ['Idempotency-Key' => self::randomUuid()]);
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return;
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            throw new InvalidArgumentException('A Cora recusou a simulação: ' . self::errorMessage($response));
        }
        throw new RuntimeException('Cora respondeu ' . $response['status'] . ' na simulação de pagamento.');
    }

    public function parseReturn(string $content): array
    {
        throw new InvalidArgumentException('A Cora não usa arquivo de retorno: a baixa chega pela consulta do boleto.');
    }

    /**
     * PDF do boleto gerado pela Cora (payment_options.bank_slip.url). O certificado da conta só vai para
     * endereço da própria Cora: URL em outro domínio = RuntimeException (PENDENTE: conferir no stage onde fica o PDF).
     */
    public function downloadBoleto(string $ourNumber, ?string $externalId): string
    {
        if ($externalId === null || !self::validInvoiceId($externalId)) {
            throw new RuntimeException('Boleto sem o identificador da Cora.');
        }
        $response = $this->call('GET', '/v2/invoices/' . rawurlencode($externalId));
        if ($response['status'] !== 200) {
            throw new RuntimeException('Cora respondeu ' . $response['status'] . ' na consulta do PDF.');
        }
        $data = self::json($response['body']);
        $url = (string)($data['payment_options']['bank_slip']['url'] ?? '');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ($host !== 'cora.com.br' && !str_ends_with($host, '.cora.com.br'))) {
            throw new RuntimeException('A Cora devolveu o PDF num endereço fora do domínio dela.');
        }
        $pdf = $this->http->request('GET', $url, ['Accept' => 'application/pdf'], null, $this->credentials['certificate'], $this->credentials['private_key']);
        if ($pdf['status'] !== 200 || !str_starts_with($pdf['body'], '%PDF')) {
            throw new RuntimeException('Cora devolveu um PDF inválido.');
        }
        return $pdf['body'];
    }

    public function parseWebhook(string $body): array
    {
        // PENDENTE: o webhook da Cora entra quando houver endereço público; até lá a baixa vem da consulta de hora em hora.
        throw new InvalidArgumentException('Aviso de pagamento da Cora ainda não está ligado.');
    }

    public function statement(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);
        if ($from === null || $to === null || $from > $to) {
            throw new InvalidArgumentException('Período do extrato inválido.');
        }
        $lines = [];
        $seen = [];
        for ($page = 1; $page <= self::STATEMENT_MAX_PAGES; $page++) {
            $response = $this->call('GET', '/bank-statement/statement?' . http_build_query(['start' => $from, 'end' => $to,
                'page' => $page, 'perPage' => self::STATEMENT_PAGE_SIZE, 'aggr' => 'false']));
            if ($response['status'] !== 200) {
                throw new RuntimeException(self::errorMessage($response));
            }
            $data = self::json($response['body']);
            $items = is_array($data['entries'] ?? null) ? $data['entries'] : [];
            foreach ($items as $item) {
                if (is_array($item) && ($line = self::statementLine($item, $seen)) !== null) {
                    $lines[] = $line;
                }
            }
            if (count($items) < self::STATEMENT_PAGE_SIZE) {
                return $lines;
            }
        }
        throw new RuntimeException('O extrato da Cora tem páginas demais: escolha um período menor.');
    }

    /** A Cora informa só o saldo de agora: para um dia passado devolve null (o OFX sai sem saldo). */
    public function balance(string $on): ?int
    {
        $on = self::date($on);
        if ($on === null) {
            throw new InvalidArgumentException('Data do saldo inválida.');
        }
        $today = date('Y-m-d', $this->clock !== null ? ($this->clock)() : time());
        if ($on < $today) {
            return null;
        }
        $response = $this->call('GET', '/third-party/account/balance');
        if ($response['status'] !== 200) {
            throw new RuntimeException(self::errorMessage($response));
        }
        $value = self::json($response['body'])['balance'] ?? null;
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) ? (int)$value : null;
    }

    /**
     * Uma movimentação do extrato da Cora na forma de linha de OFX (regra pura, pública para os testes).
     *
     * @param array<string, true> $seen FITIDs já usados nesta consulta
     */
    public static function statementLine(array $item, array &$seen): ?array
    {
        $date = self::date($item['createdAt'] ?? null);
        $amount = $item['amount'] ?? null;
        $cents = is_int($amount) ? abs($amount) : (is_string($amount) && preg_match('/^-?\d+$/', $amount) ? abs((int)$amount) : null);
        $type = strtoupper(trim((string)($item['type'] ?? '')));
        if ($date === null || $cents === null || !in_array($type, ['CREDIT', 'DEBIT'], true)) {
            return null;   // BLOCK/UNBLOCK não movem saldo disponível no extrato
        }
        $transaction = is_array($item['transaction'] ?? null) ? $item['transaction'] : [];
        $party = is_array($transaction['counterParty'] ?? null) ? $transaction['counterParty'] : [];
        $memo = trim(implode(' - ', array_unique(array_filter([self::text($transaction['description'] ?? null, 100), self::text($party['name'] ?? null, 60)]))));
        $id = self::text($item['id'] ?? null, 70);
        $base = $id !== null ? 'cora-' . preg_replace('/[^A-Za-z0-9._:-]/', '', $id) : 'cora-h' . substr(sha1($date . '|' . $type . '|' . $cents . '|' . $memo), 0, 24);
        $fitid = $base;
        for ($n = 2; isset($seen[$fitid]); $n++) {
            $fitid = $base . '-' . $n;
        }
        $seen[$fitid] = true;
        return ['fitid' => mb_substr($fitid, 0, 80), 'kind' => $type === 'CREDIT' ? 'credit' : 'debit', 'posted_on' => $date,
            'amount_cents' => $type === 'CREDIT' ? $cents : -$cents, 'memo' => mb_substr($memo !== '' ? $memo : (string)($transaction['type'] ?? ''), 0, 160)];
    }

    public function checkAuthentication(): void
    {
        $this->accessToken();
    }

    /**
     * Pedido autenticado. 429 (limite por minuto) e 503/504 (sobrecarga) esperam e repetem: a emissão leva
     * Idempotency-Key, então repetir não duplica o boleto.
     *
     * @param array<string, string> $extraHeaders
     * @return array{status:int, body:string}
     */
    private function call(string $method, string $path, ?array $json = null, array $extraHeaders = []): array
    {
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ([...self::RETRY_WAITS, null] as $wait) {
            $headers = ['Authorization' => 'Bearer ' . $this->accessToken(), 'Accept' => 'application/json'] + $extraHeaders;
            if ($json !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            $response = $this->http->request($method, $this->baseUrl . $path, $headers, $body, $this->credentials['certificate'], $this->credentials['private_key']);
            if (!in_array($response['status'], [429, 503, 504], true)) {
                return $response;
            }
            if ($wait !== null) {
                $this->sleep !== null ? ($this->sleep)($wait) : sleep($wait);
            }
        }
        throw new RuntimeException('A Cora limitou os pedidos (' . $response['status'] . ') e continuou recusando depois de esperar. Espere um minuto e tente de novo.');
    }

    private function accessToken(): string
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        if ($this->token !== null && $now < $this->token[1]) {
            return $this->token[0];
        }
        $response = $this->http->request('POST', $this->baseUrl . '/token', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $this->credentials['client_id']]),
            $this->credentials['certificate'], $this->credentials['private_key']);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Cora recusou a autenticação (' . $response['status'] . '). Confira o client ID e se o certificado é do ambiente de teste.');
        }
        $data = self::json($response['body']);
        $token = (string)($data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Cora não devolveu o token de acesso.');
        }
        // Renova cinco minutos antes de vencer (o token da Cora vale 24 h).
        $this->token = [$token, $now + max(60, (int)($data['expires_in'] ?? 86400) - 300)];
        return $token;
    }

    private static function paidCents(array $data): ?int
    {
        foreach ([$data['total_paid'] ?? null, $data['total_amount'] ?? null] as $value) {
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && preg_match('/^\d+$/', $value) && (int)$value > 0) {
                return (int)$value;
            }
        }
        return null;
    }

    private static function paidOn(array $data): ?string
    {
        foreach (is_array($data['payments'] ?? null) ? $data['payments'] : [] as $payment) {
            if (is_array($payment) && ($on = self::date($payment['finalized_at'] ?? $payment['created_at'] ?? null)) !== null) {
                return $on;
            }
        }
        return null;
    }

    private static function validInvoiceId(string $id): bool
    {
        return preg_match('/^inv_[A-Za-z0-9]{6,60}$/', $id) === 1;
    }

    private static function randomUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta da Cora não é JSON.');
        }
        return $data;
    }

    /** Mensagem de erro da Cora, curta e sem dados do pagador. */
    private static function errorMessage(array $response): string
    {
        $data = json_decode($response['body'], true);
        $parts = [];
        if (is_array($data)) {
            foreach (['code', 'message', 'error', 'error_description'] as $key) {
                if (is_scalar($data[$key] ?? null) && trim((string)$data[$key]) !== '') {
                    $parts[] = trim((string)$data[$key]);
                }
            }
            foreach (is_array($data['errors'] ?? null) ? array_slice($data['errors'], 0, 3) : [] as $e) {
                if (is_array($e)) {
                    $parts[] = trim((string)($e['field'] ?? $e['code'] ?? '') . ' ' . (string)($e['message'] ?? ''));
                } elseif (is_scalar($e)) {
                    $parts[] = (string)$e;
                }
            }
        }
        $text = implode(': ', array_unique(array_filter($parts)));
        if (str_contains($text, 'REC-0006')) {
            $text .= ' (o boleto já foi pago)';
        }
        return mb_substr('Cora recusou (' . $response['status'] . ')' . ($text !== '' ? ' ' . $text : '') . '.', 0, 400);
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
