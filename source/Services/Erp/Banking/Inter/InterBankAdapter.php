<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking\Inter;

use InvalidArgumentException;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapter;
use Source\Services\Erp\Banking\BankChargeExtras;
use Source\Services\Erp\Banking\BankStatementSource;

/**
 * Banco Inter, API de Cobrança v3 (boleto com Pix), ADR 0156. SÓ SANDBOX nesta versão.
 *
 * Contrato do Inter usado aqui (INFERIDO da documentação pública developers.inter.co e de SDKs de
 * terceiros; conferir cada item na homologação do sandbox antes de qualquer uso real):
 *  - OAuth2 client_credentials em POST /oauth/v2/token, com mTLS; token vale 1 hora e o Inter limita
 *    a 5 pedidos de token por minuto, então o token fica guardado na instância;
 *  - POST   /cobranca/v3/cobrancas                       emite (assíncrono: devolve codigoSolicitacao)
 *  - GET    /cobranca/v3/cobrancas/{codigoSolicitacao}   consulta (cobranca, boleto, pix)
 *  - GET    /cobranca/v3/cobrancas/{codigoSolicitacao}/pdf  PDF em base64
 *  - POST   /cobranca/v3/cobrancas/{codigoSolicitacao}/cancelar {motivoCancelamento}
 *  - webhook: o Inter chama o nosso endereço com uma lista de cobranças (codigoSolicitacao, seuNumero, situacao...).
 * Extrato e saldo (API Banking v2, ADR 0318, escopo extrato.read pedido num token separado para não
 * travar a cobrança de quem não liberou o extrato na aplicação):
 *  - GET    /banking/v2/extrato/completo?dataInicio&dataFim&pagina&tamanhoPagina  transacoes (idTransacao,
 *           dataTransacao, tipoOperacao C/D, valor, titulo, descricao), paginado (totalPaginas)
 *  - GET    /banking/v2/saldo?dataSaldo=AAAA-MM-DD                                disponivel
 * O Inter não tem arquivo de retorno: a baixa vem da consulta e do webhook.
 *
 * Mapeamento da situação do Inter:
 *   EM_PROCESSAMENTO → pending; A_RECEBER, ATRASADO, PROTESTO → registered; FALHA_EMISSAO → rejected;
 *   RECEBIDO → registered + evento 'paid'; CANCELADO, EXPIRADO → evento 'cancelled';
 *   MARCADO_RECEBIDO (baixa manual no Inter, sem dinheiro pelo banco) → sem evento e com aviso;
 *   desconhecida → pending com aviso (nunca muda estado no escuro).
 */
final class InterBankAdapter implements BankAdapter, BankChargeExtras, BankStatementSource
{
    public const NAME = 'inter';
    public const BANK_CODE = '077';
    public const SANDBOX_URL = 'https://cdpj-sandbox.partners.uatinter.co';
    public const SCOPES = 'boleto-cobranca.read boleto-cobranca.write';
    public const STATEMENT_SCOPE = 'extrato.read';
    /** Linhas por página do extrato e teto de páginas por consulta (anti-laço). */
    private const STATEMENT_PAGE_SIZE = 100;
    private const STATEMENT_MAX_PAGES = 50;
    /** Esperas (segundos) antes de repetir uma consulta do extrato que o Inter limitou (429). */
    private const RATE_LIMIT_WAITS = [5, 15, 30];
    /** Dias depois do vencimento em que o boleto ainda pode ser pago (o Inter aceita até 60). */
    public const DAYS_AFTER_DUE = 30;
    private const MAX_WEBHOOK_ITEMS = 500;

    /** @var array<string, array{0:string, 1:int}> token e validade por escopo */
    private array $tokens = [];

    /**
     * @param array{client_id:string, client_secret:string, certificate:string, private_key:string, account_number?:string} $credentials
     */
    public function __construct(
        private array $credentials,
        private InterHttp $http,
        private string $baseUrl = self::SANDBOX_URL,
        private ?\Closure $clock = null,
        private ?\Closure $sleep = null,
    ) {
        foreach (['client_id', 'client_secret', 'certificate', 'private_key'] as $key) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                throw new InvalidArgumentException("Credencial do Inter incompleta: falta {$key}.");
            }
        }
        if ($baseUrl !== self::SANDBOX_URL) {
            // Produção só depois de homologação e aprovação explícita (BANKING_INTEGRATIONS.md, item 10).
            throw new InvalidArgumentException('O adaptador do Inter só está liberado para o sandbox.');
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): array
    {
        return ['register', 'query', 'cancel', 'pdf', 'pix', 'webhook', 'statement'];
    }

    /** Monta o corpo da emissão (regra pura, pública para os testes). Dado faltando = InvalidArgumentException. */
    public static function chargeBody(array $charge): array
    {
        $payer = is_array($charge['payer'] ?? null) ? $charge['payer'] : [];
        $document = preg_replace('/\D+/', '', (string)($payer['document'] ?? '')) ?? '';
        if (!in_array(strlen($document), [11, 14], true)) {
            throw new InvalidArgumentException('Pagador sem CPF ou CNPJ válido: cadastre o responsável financeiro ou o proprietário da unidade com documento.');
        }
        $zip = preg_replace('/\D+/', '', (string)($payer['zip_code'] ?? '')) ?? '';
        $state = strtoupper(trim((string)($payer['state'] ?? '')));
        $street = trim((string)($payer['street'] ?? ''));
        $city = trim((string)($payer['city'] ?? ''));
        if (strlen($zip) !== 8 || !preg_match('/^[A-Z]{2}$/', $state) || $street === '' || $city === '') {
            throw new InvalidArgumentException('Endereço de cobrança incompleto: confira CEP, logradouro, cidade e UF no cadastro do condomínio.');
        }
        $name = trim((string)($payer['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Pagador sem nome.');
        }
        $ourNumber = (string)$charge['our_number'];
        if (!preg_match('/^\d{1,15}$/', $ourNumber)) {
            throw new InvalidArgumentException('Nosso número inválido para o Inter (até 15 dígitos).');
        }
        $amount = (int)$charge['amount_cents'];
        if ($amount < 250) {
            // INFERIDO: o Inter recusa boleto abaixo de R$ 2,50.
            throw new InvalidArgumentException('Valor mínimo do boleto no Inter é R$ 2,50.');
        }
        $body = [
            'seuNumero' => $ourNumber,
            'valorNominal' => round($amount / 100, 2),
            'dataVencimento' => (string)$charge['due_on'],
            'numDiasAgenda' => self::DAYS_AFTER_DUE,
            'pagador' => array_filter([
                'cpfCnpj' => $document,
                'tipoPessoa' => strlen($document) === 11 ? 'FISICA' : 'JURIDICA',
                'nome' => mb_substr($name, 0, 100),
                'endereco' => mb_substr($street, 0, 100),
                'numero' => mb_substr(trim((string)($payer['number'] ?? '')), 0, 10),
                'complemento' => mb_substr(trim((string)($payer['complement'] ?? '')), 0, 30),
                'bairro' => mb_substr(trim((string)($payer['district'] ?? '')), 0, 60),
                'cidade' => mb_substr($city, 0, 60),
                'uf' => $state,
                'cep' => $zip,
                'email' => filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'mensagem' => ['linha1' => mb_substr(trim((string)($charge['description'] ?? '')), 0, 78)],
        ];
        $fine = (int)($charge['fine_bp'] ?? 0);
        $interest = (int)($charge['monthly_interest_bp'] ?? 0);
        if ($fine > 0) {
            $body['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => round($fine / 100, 2)];
        }
        if ($interest > 0) {
            $body['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => round($interest / 100, 2)];
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
        $response = $this->call('POST', '/cobranca/v3/cobrancas', $body);
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => self::errorMessage($response)];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Inter respondeu ' . $response['status'] . ' na emissão.');
        }
        $data = self::json($response['body']);
        $request = (string)($data['codigoSolicitacao'] ?? '');
        if (!self::validRequestId($request)) {
            throw new RuntimeException('Inter não devolveu o código da solicitação.');
        }
        // Emissão assíncrona: tenta uma consulta; se ainda estiver processando, fica pendente.
        try {
            return $this->getCharge((string)$charge['our_number'], $request);
        } catch (RuntimeException) {
            return ['status' => 'pending', 'external_id' => $request, 'digitable_line' => null, 'barcode' => null, 'error' => 'Inter recebeu o pedido; consulte de novo para confirmar o registro.'];
        }
    }

    public function getCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validRequestId($externalId)) {
            // Sem o código da solicitação não dá para consultar. Não reemite: evita boleto em dobro.
            return ['status' => 'pending', 'external_id' => null, 'digitable_line' => null, 'barcode' => null,
                'error' => 'Sem o código da solicitação do Inter. Confira no Internet Banking antes de emitir de novo.'];
        }
        $response = $this->call('GET', '/cobranca/v3/cobrancas/' . rawurlencode($externalId));
        if ($response['status'] === 404) {
            return ['status' => 'pending', 'external_id' => $externalId, 'digitable_line' => null, 'barcode' => null, 'error' => 'Inter ainda não encontrou a cobrança.'];
        }
        if ($response['status'] >= 300) {
            throw new RuntimeException('Inter respondeu ' . $response['status'] . ' na consulta.');
        }
        return self::mapCharge(self::json($response['body']), $ourNumber, $externalId);
    }

    /** Converte a consulta do Inter no resultado do adaptador (regra pura). */
    public static function mapCharge(array $data, string $ourNumber, string $externalId): array
    {
        $cobranca = is_array($data['cobranca'] ?? null) ? $data['cobranca'] : [];
        $boleto = is_array($data['boleto'] ?? null) ? $data['boleto'] : [];
        $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];
        $situation = strtoupper((string)($cobranca['situacao'] ?? ''));
        $seuNumero = (string)($cobranca['seuNumero'] ?? $ourNumber);
        if ($seuNumero !== $ourNumber) {
            throw new RuntimeException('Inter devolveu outra cobrança para este código (seuNumero diferente).');
        }
        $result = [
            'status' => 'pending', 'external_id' => $externalId,
            'digitable_line' => self::text($boleto['linhaDigitavel'] ?? null, 80),
            'barcode' => self::text($boleto['codigoBarras'] ?? null, 60),
            'bank_our_number' => self::text($boleto['nossoNumero'] ?? null, 20),
            'pix_txid' => self::text($pix['txid'] ?? null, 64),
            'pix_copy_paste' => self::text($pix['pixCopiaECola'] ?? null, 1000),
            'error' => null, 'event' => null,
        ];
        $hasBoleto = $result['digitable_line'] !== null;
        $date = self::date($cobranca['dataSituacao'] ?? null);
        $event = static fn (string $kind, ?int $cents): array => [
            'event_key' => 'inter:' . $externalId . ':' . $situation, 'kind' => $kind, 'our_number' => $ourNumber,
            'amount_cents' => $cents, 'occurred_on' => $date,
        ];
        switch ($situation) {
            case 'EM_PROCESSAMENTO':
                $result['error'] = 'Inter ainda está processando a emissão.';
                break;
            case 'A_RECEBER':
            case 'ATRASADO':
            case 'PROTESTO':
                $result['status'] = 'registered';
                break;
            case 'FALHA_EMISSAO':
                $result['status'] = 'rejected';
                $result['error'] = 'Inter recusou a emissão (FALHA_EMISSAO).';
                break;
            case 'RECEBIDO':
                $result['status'] = 'registered';
                $result['event'] = $event('paid', self::cents($cobranca['valorTotalRecebido'] ?? null));
                break;
            case 'CANCELADO':
            case 'EXPIRADO':
                $result['status'] = $hasBoleto ? 'registered' : 'pending';
                $result['event'] = $event('cancelled', null);
                break;
            case 'MARCADO_RECEBIDO':
                $result['status'] = 'registered';
                $result['error'] = 'Marcado como recebido no Inter, sem pagamento pelo banco: confira e dê baixa à mão.';
                break;
            default:
                $result['error'] = 'Situação desconhecida no Inter: ' . mb_substr($situation, 0, 30) . '.';
        }
        return $result;
    }

    public function cancelCharge(string $ourNumber, ?string $externalId): array
    {
        if ($externalId === null || !self::validRequestId($externalId)) {
            return ['status' => 'pending', 'error' => 'Sem o código da solicitação do Inter: cancele pelo Internet Banking.'];
        }
        $response = $this->call('POST', '/cobranca/v3/cobrancas/' . rawurlencode($externalId) . '/cancelar', ['motivoCancelamento' => 'Cancelado pela administradora'],
            'application/json, application/problem+json');
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['status' => 'cancelled', 'error' => null];
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            return ['status' => 'rejected', 'error' => self::errorMessage($response)];
        }
        throw new RuntimeException('Inter respondeu ' . $response['status'] . ' no cancelamento.');
    }

    /**
     * Só sandbox (o adaptador recusa outra URL no construtor): pede ao Inter que simule o pagamento da
     * cobrança, por boleto ou Pix. Caminho e corpo INFERIDOS (POST .../{codigoSolicitacao}/pagar com
     * pagarCom); conferir na homologação. A baixa continua vindo da consulta (getCharge) ou do webhook.
     */
    public function simulatePayment(?string $externalId, string $method = 'BOLETO'): void
    {
        if ($externalId === null || !self::validRequestId($externalId)) {
            throw new InvalidArgumentException('Boleto sem o código da solicitação do Inter.');
        }
        if (!in_array($method, ['BOLETO', 'PIX'], true)) {
            throw new InvalidArgumentException('Forma de pagamento simulado inválida (BOLETO ou PIX).');
        }
        // CONFIRMADO no sandbox (16/11/2026): esta rota só produz application/problem+json; com Accept
        // application/json sozinho o Inter responde 406 antes de olhar o pedido.
        $response = $this->call('POST', '/cobranca/v3/cobrancas/' . rawurlencode($externalId) . '/pagar', ['pagarCom' => $method],
            'application/json, application/problem+json');
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return;
        }
        if ($response['status'] >= 400 && $response['status'] < 500) {
            throw new InvalidArgumentException('O Inter recusou a simulação: ' . self::errorMessage($response));
        }
        throw new RuntimeException('Inter respondeu ' . $response['status'] . ' na simulação de pagamento.');
    }

    public function parseReturn(string $content): array
    {
        throw new InvalidArgumentException('O Inter não usa arquivo de retorno: a baixa chega pelo webhook e pela consulta do boleto.');
    }

    public function downloadBoleto(string $ourNumber, ?string $externalId): string
    {
        if ($externalId === null || !self::validRequestId($externalId)) {
            throw new RuntimeException('Boleto sem o código da solicitação do Inter.');
        }
        $response = $this->call('GET', '/cobranca/v3/cobrancas/' . rawurlencode($externalId) . '/pdf');
        if ($response['status'] !== 200) {
            throw new RuntimeException('Inter respondeu ' . $response['status'] . ' no PDF.');
        }
        $pdf = base64_decode((string)(self::json($response['body'])['pdf'] ?? ''), true);
        if ($pdf === false || !str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Inter devolveu um PDF inválido.');
        }
        return $pdf;
    }

    public function parseWebhook(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Notificação do Inter com JSON inválido.');
        }
        $items = array_is_list($data) ? $data : [$data];
        if ($items === [] || count($items) > self::MAX_WEBHOOK_ITEMS) {
            throw new InvalidArgumentException('Notificação do Inter vazia ou grande demais.');
        }
        $out = [];
        foreach ($items as $item) {
            $id = is_array($item) ? (string)($item['codigoSolicitacao'] ?? '') : '';
            if (!self::validRequestId($id)) {
                throw new InvalidArgumentException('Notificação do Inter sem codigoSolicitacao válido.');
            }
            $seu = is_array($item) && isset($item['seuNumero']) && preg_match('/^\d{1,20}$/', (string)$item['seuNumero']) ? (string)$item['seuNumero'] : null;
            $out[$id] = ['external_id' => $id, 'our_number' => $out[$id]['our_number'] ?? $seu];
        }
        return array_values($out);
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
        for ($page = 0; $page < self::STATEMENT_MAX_PAGES; $page++) {
            $response = $this->readStatement('/banking/v2/extrato/completo?' . http_build_query(['dataInicio' => $from, 'dataFim' => $to,
                'pagina' => $page, 'tamanhoPagina' => self::STATEMENT_PAGE_SIZE]));
            if ($response['status'] !== 200) {
                throw new RuntimeException(self::errorMessage($response));
            }
            $data = self::json($response['body']);
            $items = is_array($data['transacoes'] ?? null) ? $data['transacoes'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line = self::statementLine($item, $seen);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
            $pages = (int)($data['totalPaginas'] ?? 1);
            if ($items === [] || ($data['ultimaPagina'] ?? false) === true || $page + 1 >= $pages) {
                return $lines;
            }
        }
        throw new RuntimeException('O extrato do Inter tem páginas demais: escolha um período menor.');
    }

    public function balance(string $on): ?int
    {
        $on = self::date($on);
        if ($on === null) {
            throw new InvalidArgumentException('Data do saldo inválida.');
        }
        $response = $this->readStatement('/banking/v2/saldo?' . http_build_query(['dataSaldo' => $on]));
        if ($response['status'] !== 200) {
            throw new RuntimeException(self::errorMessage($response));
        }
        $data = self::json($response['body']);
        $value = $data['disponivel'] ?? null;
        if (!is_scalar($value) || $value === '') {
            return null;
        }
        $negative = str_starts_with(trim((string)(is_float($value) ? number_format($value, 2, '.', '') : $value)), '-');
        $cents = self::cents(is_float($value) ? number_format(abs($value), 2, '.', '') : ltrim(trim((string)$value), '-'));
        return $cents === null ? null : ($negative ? -$cents : $cents);
    }

    /**
     * Uma transação do extrato do Inter na forma de linha de OFX (regra pura, pública para os testes).
     * Sem idTransacao, o FITID vem do conteúdo e da ordem (estável para a mesma consulta).
     *
     * @param array<string, true> $seen FITIDs já usados nesta consulta
     */
    public static function statementLine(array $item, array &$seen): ?array
    {
        $date = self::date($item['dataTransacao'] ?? $item['dataEntrada'] ?? $item['dataInclusao'] ?? null);
        $cents = self::cents($item['valor'] ?? null);
        $operation = strtoupper(trim((string)($item['tipoOperacao'] ?? '')));
        if ($date === null || $cents === null || !in_array($operation, ['C', 'D'], true)) {
            return null;
        }
        $memo = trim(implode(' - ', array_unique(array_filter([self::text($item['titulo'] ?? null, 80), self::text($item['descricao'] ?? null, 120)]))));
        $id = self::text($item['idTransacao'] ?? null, 70);
        $base = $id !== null ? 'inter-' . preg_replace('/[^A-Za-z0-9._:-]/', '', $id) : 'inter-h' . substr(sha1($date . '|' . $operation . '|' . $cents . '|' . $memo), 0, 24);
        $fitid = $base;
        for ($n = 2; isset($seen[$fitid]); $n++) {
            $fitid = $base . '-' . $n;
        }
        $seen[$fitid] = true;
        return ['fitid' => mb_substr($fitid, 0, 80), 'kind' => $operation === 'C' ? 'credit' : 'debit', 'posted_on' => $date,
            'amount_cents' => $operation === 'C' ? $cents : -$cents, 'memo' => mb_substr($memo !== '' ? $memo : (string)($item['tipoTransacao'] ?? ''), 0, 160)];
    }

    /**
     * GET do extrato ou do saldo. O Inter limita as consultas por minuto (429, confirmado no sandbox em 16/11/2026):
     * espera e repete algumas vezes antes de desistir com uma mensagem clara.
     *
     * @return array{status:int, body:string}
     */
    private function readStatement(string $path): array
    {
        $response = ['status' => 429, 'body' => ''];
        foreach ([...self::RATE_LIMIT_WAITS, null] as $wait) {
            $response = $this->call('GET', $path, null, 'application/json', self::STATEMENT_SCOPE);
            if ($response['status'] !== 429) {
                return $response;
            }
            if ($wait !== null) {
                $this->sleep !== null ? ($this->sleep)($wait) : sleep($wait);
            }
        }
        // O que o Inter respondeu (título e detalhe) vai junto: diz se o limite é por minuto, por dia ou do sandbox.
        $what = str_contains($path, '/saldo') ? 'o saldo' : 'o extrato';
        throw new RuntimeException('O Inter continuou limitando as consultas (429) ao buscar ' . $what . ', mesmo depois de esperar 50 s. '
            . 'Espere alguns minutos e tente um período de poucos dias. Resposta do banco: ' . self::errorMessage($response));
    }

    /** Só pede o token (homologação da credencial e do certificado), sem emitir nada. */
    public function checkAuthentication(): void
    {
        $this->token();
    }

    /** @return array{status:int, body:string} */
    private function call(string $method, string $path, ?array $json = null, string $accept = 'application/json', string $scope = self::SCOPES): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($scope), 'Accept' => $accept];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if (($this->credentials['account_number'] ?? '') !== '') {
            $headers['x-conta-corrente'] = preg_replace('/\D+/', '', $this->credentials['account_number']) ?? '';
        }
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return $this->http->request($method, $this->baseUrl . $path, $headers, $body, $this->credentials['certificate'], $this->credentials['private_key']);
    }

    private function token(string $scope = self::SCOPES): string
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        if (isset($this->tokens[$scope]) && $now < $this->tokens[$scope][1]) {
            return $this->tokens[$scope][0];
        }
        $response = $this->http->request('POST', $this->baseUrl . '/oauth/v2/token', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query(['client_id' => $this->credentials['client_id'], 'client_secret' => $this->credentials['client_secret'],
                'grant_type' => 'client_credentials', 'scope' => $scope]),
            $this->credentials['certificate'], $this->credentials['private_key']);
        if ($response['status'] !== 200) {
            if ($scope === self::STATEMENT_SCOPE) {
                throw new RuntimeException('Inter recusou o acesso ao extrato (' . $response['status'] . '). Na aplicação do Inter, marque a permissão de consultar extrato e saldo.');
            }
            throw new RuntimeException('Inter recusou a autenticação (' . $response['status'] . '). Confira client_id, client_secret e certificado.');
        }
        $data = self::json($response['body']);
        $token = (string)($data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Inter não devolveu o token de acesso.');
        }
        // Renova um minuto antes de vencer.
        $this->tokens[$scope] = [$token, $now + max(60, (int)($data['expires_in'] ?? 3600) - 60)];
        return $token;
    }

    private static function validRequestId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) === 1;
    }

    private static function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta do Inter não é JSON.');
        }
        return $data;
    }

    /** Mensagem de erro do Inter, curta e sem dados do pagador. */
    private static function errorMessage(array $response): string
    {
        $data = json_decode($response['body'], true);
        $title = is_array($data) ? trim((string)($data['title'] ?? $data['message'] ?? '')) : '';
        $detail = is_array($data) ? trim((string)($data['detail'] ?? '')) : '';
        $text = trim($title . ($detail !== '' ? ': ' . $detail : ''));
        // Violações por campo (razão e propriedade), quando o Inter manda: é o que diz o que corrigir.
        $violations = [];
        foreach ((is_array($data) && is_array($data['violacoes'] ?? null) ? $data['violacoes'] : []) as $v) {
            if (is_array($v)) {
                $violations[] = trim((string)($v['propriedade'] ?? '') . ' ' . (string)($v['razao'] ?? ''));
            }
        }
        if ($violations !== []) {
            $text .= ' [' . implode('; ', array_slice(array_filter($violations), 0, 3)) . ']';
        }
        return mb_substr('Inter recusou (' . $response['status'] . ')' . ($text !== '' ? ' ' . $text : '') . '.', 0, 400);
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

    /** "500.5" / 500.5 / "500,50" → 50050 sem passar por float binário. */
    public static function cents(mixed $value): ?int
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }
        $text = str_replace(',', '.', is_float($value) ? number_format($value, 2, '.', '') : trim((string)$value));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            return null;
        }
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
    }
}
