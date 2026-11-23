<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\CondoConta;

use InvalidArgumentException;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapter;
use Source\Services\Erp\Banking\BankChargeExtras;
use Source\Services\Erp\Banking\BankPaymentSimulator;
use Source\Services\Erp\Banking\BankStatementSource;
use Source\Services\Erp\Banking\Inter\InterHttp;

/**
 * CondoConta (CondoConta Open API), ADR 0649. SÓ O SANDBOX nesta versão.
 *
 * Contrato CONFIRMADO na especificação OpenAPI 2.37.0 (api-open.condoconta.com.br/openapi.json, lida em 23/11/2026)
 * e no ambiente do Postman da própria CondoConta; o comportamento real ainda precisa da homologação no sandbox
 * (checklist em docs/banking/CONDOCONTA.md):
 *  - Sandbox https://api-open-sandbox.condoconta.net; produção https://api-open.condoconta.com.br (NÃO LIGADA).
 *  - POST /token (form) grant_type=client_credentials, scope, client_id, client_secret → access_token, expires_in
 *    (token de 1 h; o banco aceita só 5 pedidos de token por minuto, por isso o token fica guardado na instância).
 *  - POST /slip                 emite, síncrono: our_number, bar_code, digitable_line, pdf (URL), qr_code (Pix).
 *                               Idempotente: your_number (até 16 caracteres) + mesmo valor devolve a cobrança existente.
 *  - GET  /slip/{our_number}    consulta: status CREATED|REGISTERED|PAID|CANCELED|ERROR, received_value, payment_date.
 *  - POST /slip/{our_number}/cancel   cancela (204).
 *  - GET  /account/balance      saldo atual (balance, em reais).
 *  - GET  /account/balance/sheet?initial_date&final_date   extrato por dia (items[].balance = saldo do dia,
 *                               items[].transactions[]: transaction_code, operation CASH_IN|CASH_OUT, value, description).
 *  - PUT  /webhooks/slip {callback_url}  aviso de mudança da cobrança (sem assinatura documentada: o sistema consulta
 *                               a cobrança antes de mudar qualquer estado, como no Inter).
 * Valores em reais com casas decimais. Sem certificado (OAuth2 client credentials). A documentação não tem simulação
 * de pagamento no sandbox.
 *
 * Mapeamento do status: CREATED/REGISTERED → registrado (o boleto já tem linha e PDF; a CIP leva ~5 minutos);
 * PAID → evento 'paid'; CANCELED → evento 'cancelled'; ERROR → recusado; desconhecido → aguardando com aviso.
 */
final class CondoContaBankAdapter implements BankAdapter, BankChargeExtras, BankStatementSource, BankPaymentSimulator
{
    public const NAME = 'condoconta';
    /**
     * PENDENTE: código do banco da conta CondoConta no cadastro da conta bancária. Valor PROVISÓRIO só para o sandbox;
     * trocar pelo número que aparece nos dados da conta no app da CondoConta antes de ligar a conta real (ADR 0649).
     */
    public const BANK_CODE = '999';
    public const SANDBOX_URL = 'https://api-open-sandbox.condoconta.net';
    public const PRODUCTION_URL = 'https://api-open.condoconta.com.br';
    public const SCOPES = 'boleto-cobranca.write boleto-cobranca.read extrato.read webhook.write webhook.read';
    public const MIN_AMOUNT_CENTS = 250;
    public const MAX_AMOUNT_CENTS = 25000000;
    /** Dias para a baixa automática depois do vencimento (0 a 60 na CondoConta). */
    public const EXPIRATION_DAYS = 60;
    private const RETRY_WAITS = [3, 8, 15];

    private ?array $token = null;

    /** @param array{client_id:string, client_secret:string, account_number?:string} $credentials */
    public function __construct(
        private array $credentials,
        private InterHttp $http,
        private string $baseUrl = self::SANDBOX_URL,
        private ?\Closure $clock = null,
        private ?\Closure $sleep = null,
    ) {
        foreach (['client_id' => 'client ID', 'client_secret' => 'client secret'] as $key => $label) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                throw new InvalidArgumentException("Credencial da CondoConta incompleta: falta o {$label}.");
            }
        }
        if ($baseUrl !== self::SANDBOX_URL) {
            // Produção só depois de homologação e aprovação explícita do Djalma (BANKING_INTEGRATIONS.md, item 10).
            throw new InvalidArgumentException('O adaptador da CondoConta só está liberado para o sandbox.');
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): array
    {
        return ['register', 'query', 'cancel', 'pdf', 'pix', 'statement', 'webhook'];
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
            throw new InvalidArgumentException('Pagador sem nome.');
        }
        $zip = preg_replace('/\D+/', '', (string)($payer['zip_code'] ?? '')) ?? '';
        $state = strtoupper(trim((string)($payer['state'] ?? '')));
        $street = trim((string)($payer['street'] ?? ''));
        $city = trim((string)($payer['city'] ?? ''));
        if (strlen($zip) !== 8 || !preg_match('/^[A-Z]{2}$/', $state) || $street === '' || $city === '') {
            throw new InvalidArgumentException('Endereço de cobrança incompleto: confira CEP, logradouro, cidade e UF no cadastro do condomínio.');
        }
        $ourNumber = (string)$charge['our_number'];
        if (!preg_match('/^\d{1,16}$/', $ourNumber)) {
            throw new InvalidArgumentException('Nosso número inválido para a CondoConta (até 16 números).');
        }
        $amount = (int)$charge['amount_cents'];
        if ($amount < self::MIN_AMOUNT_CENTS || $amount > self::MAX_AMOUNT_CENTS) {
            throw new InvalidArgumentException('Na CondoConta o boleto vai de R$ 2,50 a R$ 250.000,00.');
        }
        $due = self::date($charge['due_on'] ?? null);
        if ($due === null) {
            throw new InvalidArgumentException('Vencimento inválido.');
        }
        $payerBody = array_filter([
            'document_number' => $document,
            'name' => mb_substr($name, 0, 100),
            'address' => mb_substr($street, 0, 100),
            'number' => mb_substr(trim((string)($payer['number'] ?? '')) ?: 'S/N', 0, 10),
            'complement' => mb_substr(trim((string)($payer['complement'] ?? '')), 0, 50),
            'neighborhood' => mb_substr(trim((string)($payer['district'] ?? '')), 0, 50),
            'city' => mb_substr($city, 0, 60),
            'state' => $state,
            'zipcode' => $zip,
            'email' => filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: '',
        ], static fn ($v): bool => $v !== '');
        $description = trim((string)($charge['description'] ?? ''));
        $body = [
            'type' => 'CONDOMINIUM',
            'your_number' => $ourNumber,
            'value' => self::money($amount),
            'due_date' => $due,
            'expiration_days' => self::EXPIRATION_DAYS,
            'payer' => $payerBody,
            'message' => mb_substr($description !== '' ? $description : 'Cota condominial', 0, 200),
        ];
        $nextDay = (new \DateTimeImmutable($due))->modify('+1 day')->format('Y-m-d');
        $fine = (int)($charge['fine_bp'] ?? 0);
        $interest = (int)($charge['monthly_interest_bp'] ?? 0);
        if ($fine > 0) {
            $body['fine'] = ['type' => 'PERCENT_VALUE', 'value' => number_format($fine / 100, 2, '.', ''), 'date' => $nextDay];
        }
        if ($interest > 0) {
            $body['interest'] = ['type' => 'MONTHLY_VALUE', 'value_type' => 'PERCENT_VALUE', 'value' => number_format($interest / 100, 3, '.', ''), 'date' => $nextDay];
        }
        return $body;
    }

    public function registerCharge(array $charge): array
    {
        try {
            $body = self::chargeBody($charge);
        } catch (InvalidArgumentException $error) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => $error->getMessage()];
        }
        $response = $this->call('POST', '/slip', $body);
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => self::errorMessage($response)];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('CondoConta respondeu ' . $response['status'] . ' na emissão.');
        }
        $data = self::json($response['body']);
        $id = self::text($data['our_number'] ?? null, 40);
        if ($id === null || !self::validOurNumber($id)) {
            throw new RuntimeException('CondoConta não devolveu o nosso número da cobrança.');
        }
        return self::mapCharge($data + ['status' => 'CREATED'], (string)$charge['our_number'], $id);
    }

    public function getCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validOurNumber($externalId)) {
            // A emissão é idempotente pelo your_number: emitir de novo devolve a mesma cobrança, sem duplicar.
            return ['status' => 'pending', 'external_id' => null, 'digitable_line' => null, 'barcode' => null,
                'error' => 'Sem o nosso número da CondoConta. Confira no app da CondoConta antes de emitir de novo.'];
        }
        $response = $this->call('GET', '/slip/' . rawurlencode($externalId));
        if ($response['status'] === 404) {
            return ['status' => 'pending', 'external_id' => $externalId, 'digitable_line' => null, 'barcode' => null, 'error' => 'CondoConta não encontrou a cobrança.'];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('CondoConta respondeu ' . $response['status'] . ' na consulta.');
        }
        return self::mapCharge(self::json($response['body']), $ourNumber, $externalId);
    }

    /** Converte a cobrança da CondoConta no resultado do adaptador (regra pura). */
    public static function mapCharge(array $data, string $ourNumber, string $externalId): array
    {
        $theirs = self::text($data['your_number'] ?? null, 20);
        if ($theirs !== null && ltrim($theirs, '0') !== ltrim($ourNumber, '0')) {
            throw new RuntimeException('CondoConta devolveu outra cobrança para este nosso número (your_number diferente).');
        }
        $status = strtoupper(trim((string)($data['status'] ?? '')));
        $result = [
            'status' => 'pending', 'external_id' => $externalId,
            'digitable_line' => self::text($data['digitable_line'] ?? $data['slip']['digitable_line'] ?? null, 80),
            'barcode' => self::text($data['bar_code'] ?? $data['slip']['bar_code'] ?? null, 60),
            'bank_our_number' => $externalId,
            'pix_txid' => null,
            'pix_copy_paste' => self::text($data['qr_code'] ?? $data['pix']['qr_code'] ?? null, 1000),
            'error' => null, 'event' => null,
        ];
        $event = static fn (string $kind, ?int $cents, ?string $on): array => [
            'event_key' => 'condoconta:' . $externalId . ':' . $kind, 'kind' => $kind, 'our_number' => $ourNumber,
            'amount_cents' => $cents, 'occurred_on' => $on,
        ];
        switch ($status) {
            case 'CREATED':
            case 'REGISTERED':
                $result['status'] = $result['digitable_line'] !== null ? 'registered' : 'pending';
                if ($result['digitable_line'] === null) {
                    $result['error'] = 'A CondoConta ainda não devolveu a linha digitável.';
                }
                break;
            case 'PAID':
                $result['status'] = 'registered';
                $result['event'] = $event('paid', self::cents($data['received_value'] ?? null) ?? self::cents($data['value'] ?? null),
                    self::date($data['payment_date'] ?? null) ?? self::paidOn($data));
                break;
            case 'CANCELED':
                $result['status'] = $result['digitable_line'] !== null ? 'registered' : 'pending';
                $result['event'] = $event('cancelled', null, self::date($data['status_date'] ?? null));
                break;
            case 'ERROR':
                $result['status'] = 'rejected';
                $result['error'] = 'A CondoConta não conseguiu registrar a cobrança. Confira os dados do pagador e emita de novo.';
                break;
            default:
                $result['error'] = 'Situação desconhecida na CondoConta: ' . mb_substr($status, 0, 30) . '.';
        }
        return $result;
    }

    public function cancelCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validOurNumber($externalId)) {
            return ['status' => 'pending', 'error' => 'Sem o nosso número da CondoConta: cancele pelo app da CondoConta.'];
        }
        $response = $this->call('POST', '/slip/' . rawurlencode($externalId) . '/cancel');
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['status' => 'cancelled', 'error' => null];
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'error' => self::errorMessage($response)];
        }
        throw new RuntimeException('CondoConta respondeu ' . $response['status'] . ' no cancelamento.');
    }

    /** A documentação da CondoConta não tem simulação de pagamento: a recusa vira mensagem na tela, sem chamar o banco. */
    public function simulatePayment(?string $externalId, string $method = 'BOLETO'): void
    {
        throw new InvalidArgumentException('A CondoConta não simula pagamento pela API. A baixa vem da consulta da cobrança.');
    }

    public function parseReturn(string $content): array
    {
        throw new InvalidArgumentException('A CondoConta pela API não usa arquivo de retorno: a baixa chega pela consulta da cobrança.');
    }

    /**
     * PDF da CondoConta (campo pdf da cobrança, uma URL). O token só vai para endereço da própria CondoConta:
     * URL fora de *.condoconta.com.br / *.condoconta.net = RuntimeException.
     */
    public function downloadBoleto(string $ourNumber, ?string $externalId): string
    {
        if ($externalId === null || !self::validOurNumber($externalId)) {
            throw new RuntimeException('Cobrança sem o nosso número da CondoConta.');
        }
        $response = $this->call('GET', '/slip/' . rawurlencode($externalId));
        if ($response['status'] !== 200) {
            throw new RuntimeException('CondoConta respondeu ' . $response['status'] . ' na consulta do PDF.');
        }
        $url = (string)(self::json($response['body'])['pdf'] ?? '');
        if (!self::ownUrl($url)) {
            throw new RuntimeException('A CondoConta devolveu o PDF num endereço fora do domínio dela.');
        }
        $pdf = $this->http->request('GET', $url, ['Accept' => 'application/pdf', 'Authorization' => 'Bearer ' . $this->accessToken()], null, '', '');
        if ($pdf['status'] !== 200 || !str_starts_with($pdf['body'], '%PDF')) {
            throw new RuntimeException('CondoConta devolveu um PDF inválido.');
        }
        return $pdf['body'];
    }

    /**
     * Aviso da CondoConta (webhook do tipo slip). O conteúdo NÃO é confiável e a documentação não traz assinatura:
     * só os nossos números citados saem daqui, e quem chama consulta cada cobrança antes de mudar estado.
     */
    public function parseWebhook(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Aviso da CondoConta não é JSON.');
        }
        $found = [];
        $walk = function (array $node, int $depth) use (&$walk, &$found): void {
            if ($depth > 5 || count($found) >= 100) {
                return;
            }
            $id = isset($node['our_number']) && is_scalar($node['our_number']) ? trim((string)$node['our_number']) : '';
            if ($id !== '' && self::validOurNumber($id)) {
                $found[$id] = ['external_id' => $id, 'our_number' => self::text($node['your_number'] ?? null, 20)];
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($data, 0);
        if ($found === []) {
            throw new InvalidArgumentException('Aviso da CondoConta sem nosso número.');
        }
        return array_values($found);
    }

    public function statement(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);
        if ($from === null || $to === null || $from > $to) {
            throw new InvalidArgumentException('Período do extrato inválido.');
        }
        $data = $this->sheet($from, $to);
        $lines = [];
        $seen = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $day) {
            foreach (is_array($day['transactions'] ?? null) ? $day['transactions'] : [] as $item) {
                // A transação pode vir sem data própria: vale a data do dia da folha.
                if (is_array($item) && is_array($day) && ($line = self::statementLine($item + ['date' => $day['date'] ?? null], $seen)) !== null) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    /** Saldo do fim do dia: hoje pelo saldo atual; dia passado pelo saldo do dia no extrato (items[].balance). */
    public function balance(string $on): ?int
    {
        $on = self::date($on);
        if ($on === null) {
            throw new InvalidArgumentException('Data do saldo inválida.');
        }
        $today = date('Y-m-d', $this->clock !== null ? ($this->clock)() : time());
        if ($on >= $today) {
            $response = $this->call('GET', '/account/balance');
            if ($response['status'] !== 200) {
                throw new RuntimeException(self::errorMessage($response));
            }
            return self::cents(self::json($response['body'])['balance'] ?? null, true);
        }
        $items = $this->sheet($on, $on)['items'] ?? null;
        foreach (is_array($items) ? $items : [] as $day) {
            if (is_array($day) && self::date($day['date'] ?? null) === $on) {
                return self::cents($day['balance'] ?? null, true);
            }
        }
        return null;
    }

    /**
     * Uma transação do extrato da CondoConta como linha de OFX (regra pura, pública para os testes). FITID = o
     * transaction_code da CondoConta (estável); sem ele, hash dos campos.
     *
     * @param array<string, true> $seen
     */
    public static function statementLine(array $item, array &$seen): ?array
    {
        $date = self::date($item['date'] ?? null);
        $cents = self::cents($item['value'] ?? null, true);
        $flow = strtoupper(trim((string)($item['operation'] ?? '')));
        if ($date === null || $cents === null || !in_array($flow, ['CASH_IN', 'CASH_OUT'], true)) {
            return null;
        }
        $cents = abs($cents);
        $party = $flow === 'CASH_IN' ? ($item['origin_name'] ?? null) : ($item['destination_name'] ?? null);
        $memo = trim(implode(' - ', array_unique(array_filter([self::text($item['description'] ?? null, 100), self::text($party, 60)]))));
        $code = self::text($item['transaction_code'] ?? null, 70);
        $base = $code !== null ? 'condoconta-' . preg_replace('/[^A-Za-z0-9._:-]/', '', $code)
            : 'condoconta-h' . substr(sha1($date . '|' . $flow . '|' . $cents . '|' . $memo), 0, 24);
        $fitid = $base;
        for ($n = 2; isset($seen[$fitid]); $n++) {
            $fitid = $base . '-' . $n;
        }
        $seen[$fitid] = true;
        return ['fitid' => mb_substr($fitid, 0, 80), 'kind' => $flow === 'CASH_IN' ? 'credit' : 'debit', 'posted_on' => $date,
            'amount_cents' => $flow === 'CASH_IN' ? $cents : -$cents, 'memo' => mb_substr($memo !== '' ? $memo : (string)($item['type'] ?? 'CondoConta'), 0, 160)];
    }

    public function checkAuthentication(): void
    {
        $this->token = null;
        $this->accessToken();
    }

    private function sheet(string $from, string $to): array
    {
        $response = $this->call('GET', '/account/balance/sheet?' . http_build_query(['initial_date' => $from, 'final_date' => $to]));
        if ($response['status'] !== 200) {
            throw new RuntimeException(self::errorMessage($response));
        }
        return self::json($response['body']);
    }

    /**
     * Pedido autenticado. 429 e 503/504 esperam e repetem (a emissão é idempotente pelo your_number).
     *
     * @return array{status:int, body:string}
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ([...self::RETRY_WAITS, null] as $wait) {
            $headers = ['Authorization' => 'Bearer ' . $this->accessToken(), 'Accept' => 'application/json'];
            if ($json !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            $response = $this->http->request($method, $this->baseUrl . $path, $headers, $body, '', '');
            if ($response['status'] === 401 && $this->token !== null) {
                $this->token = null;   // token vencido antes da hora: pede outro uma vez
                continue;
            }
            if (!in_array($response['status'], [429, 503, 504], true)) {
                return $response;
            }
            if ($wait !== null) {
                $this->sleep !== null ? ($this->sleep)($wait) : sleep($wait);
            }
        }
        throw new RuntimeException('A CondoConta limitou os pedidos (' . $response['status'] . ') e continuou recusando depois de esperar. Espere um minuto e tente de novo.');
    }

    private function accessToken(): string
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        if ($this->token !== null && $now < $this->token[1]) {
            return $this->token[0];
        }
        $response = $this->http->request('POST', $this->baseUrl . '/token', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query(['grant_type' => 'client_credentials', 'scope' => self::SCOPES, 'client_id' => trim((string)$this->credentials['client_id']),
                'client_secret' => trim((string)$this->credentials['client_secret'])]), '', '');
        if ($response['status'] !== 200) {
            throw new RuntimeException('CondoConta recusou a autenticação (' . $response['status'] . '). Confira o client ID e o client secret do sandbox.');
        }
        $data = self::json($response['body']);
        $token = (string)($data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('CondoConta não devolveu o token de acesso.');
        }
        // Renova cinco minutos antes de vencer (o token vale 1 h).
        $this->token = [$token, $now + max(60, (int)($data['expires_in'] ?? 3600) - 300)];
        return $token;
    }

    private static function paidOn(array $data): ?string
    {
        $last = null;
        foreach (is_array($data['payments'] ?? null) ? $data['payments'] : [] as $payment) {
            $on = is_array($payment) ? self::date($payment['paid_at'] ?? null) : null;
            if ($on !== null && ($last === null || $on > $last)) {
                $last = $on;
            }
        }
        return $last;
    }

    private static function ownUrl(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return parse_url($url, PHP_URL_SCHEME) === 'https' && $host !== ''
            && (str_ends_with($host, '.condoconta.com.br') || str_ends_with($host, '.condoconta.net') || in_array($host, ['condoconta.com.br', 'condoconta.net'], true));
    }

    private static function validOurNumber(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9-]{1,40}$/', $id) === 1;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Reais (número ou texto "1234.56") em centavos. */
    private static function cents(mixed $value, bool $allowNegative = false): ?int
    {
        if (is_int($value) || is_float($value)) {
            $cents = (int)round($value * 100);
        } elseif (is_string($value) && preg_match('/^-?\d+(?:\.\d{1,3})?$/', trim($value))) {
            $cents = (int)round(((float)trim($value)) * 100);
        } else {
            return null;
        }
        return $cents < 0 && !$allowNegative ? null : $cents;
    }

    private static function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta da CondoConta não é JSON.');
        }
        return $data;
    }

    /** Mensagem de erro da CondoConta ({detail: texto} ou {detail: [{loc, msg}]}), curta e sem dados do pagador. */
    private static function errorMessage(array $response): string
    {
        $data = json_decode($response['body'], true);
        $parts = [];
        $detail = is_array($data) ? ($data['detail'] ?? null) : null;
        if (is_string($detail)) {
            $parts[] = trim($detail);
        } elseif (is_array($detail)) {
            foreach (array_slice($detail, 0, 3) as $d) {
                if (is_array($d)) {
                    $loc = is_array($d['loc'] ?? null) ? implode('.', array_slice(array_map('strval', $d['loc']), -2)) : '';
                    $parts[] = trim($loc . ' ' . (string)($d['msg'] ?? ''));
                }
            }
        }
        $text = implode(': ', array_unique(array_filter($parts)));
        return mb_substr('CondoConta recusou (' . $response['status'] . ')' . ($text !== '' ? ' ' . $text : '') . '.', 0, 400);
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
