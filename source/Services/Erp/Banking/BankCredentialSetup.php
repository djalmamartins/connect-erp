<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Banking\Inter\CurlInterHttp;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;
use Source\Services\Erp\Banking\Inter\InterHttp;
use Source\Services\LoginThrottle;
use Source\Support\AppLogger;

/**
 * Integração do banco pela tela (ADR 0158): quem pode gerenciar configurações e contas bancárias envia
 * o pacote do banco (ZIP ou .crt/.key), o client ID e o secret. O sistema abre o pacote em memória,
 * acha o certificado e a chave pelo conteúdo (não pelo nome), confere o par e a validade, testa a
 * conexão (só pede um token, não emite nada) e só então grava cifrado no cofre (BankCredentials).
 *
 * - Nada é gravado em disco; o secret, o certificado e a chave nunca voltam na resposta nem vão para log.
 * - Conta fora do escopo do usuário = RegistryNotFound (404). Só sandbox (ADR 0156).
 * - Teste com falha não grava, salvo pedido explícito (save_anyway), e devolve um diagnóstico em português
 *   (ex.: certificado de produção no sandbox = "unknown ca").
 * - Limite de 10 tentativas por usuário por hora (auth_throttle_events), que falha fechado.
 */
final class BankCredentialSetup
{
    public const MAX_FILES = 4;
    public const MAX_FILE_BYTES = 262144;
    public const THROTTLE_SCOPE = 'bank-cred';
    public const THROTTLE_MAX = 10;
    public const THROTTLE_WINDOW = 3600;

    private PDO $pdo;
    private CondominiumScope $scope;
    private BankCredentials $credentials;
    /** @var \Closure(): InterHttp */
    private \Closure $httpFactory;

    public function __construct(?PDO $pdo = null, ?BankVault $vault = null, ?\Closure $httpFactory = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = new CondominiumScope($this->pdo);
        $this->credentials = new BankCredentials($this->pdo, $vault);
        $this->httpFactory = $httpFactory ?? static fn (): InterHttp => new CurlInterHttp();
    }

    /**
     * Acha certificado e chave em PEM nos arquivos enviados, abrindo ZIPs (regra pura).
     *
     * @param list<array{name?:mixed, content?:mixed}> $files content em base64
     * @return array{certificate:string, private_key:string}
     */
    public static function extract(array $files): array
    {
        if ($files === [] || count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Envie o ZIP do banco ou os arquivos .crt e .key.');
        }
        $plain = [];
        foreach ($files as $file) {
            $name = is_string($file['name'] ?? null) ? basename($file['name']) : 'arquivo';
            $raw = is_string($file['content'] ?? null) ? base64_decode($file['content'], true) : false;
            if ($raw === false || $raw === '' || strlen($raw) > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException("Não consegui ler {$name} (vazio ou grande demais).");
            }
            if (str_starts_with($raw, "PK\x03\x04")) {
                array_push($plain, ...ZipEntries::read($raw));
            } else {
                $plain[] = ['name' => $name, 'content' => $raw];
            }
        }
        $certificate = $key = null;
        foreach ($plain as $file) {
            if ($certificate === null && preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $file['content'], $m)) {
                $certificate = $m[0] . "\n";
            }
            if ($key === null && preg_match('/-----BEGIN ((?:RSA |EC |ENCRYPTED )?PRIVATE KEY)-----.+?-----END \1-----/s', $file['content'], $m)) {
                if (str_contains($m[1], 'ENCRYPTED')) {
                    throw new InvalidArgumentException('A chave privada está protegida por senha. Use a chave .key sem senha que o banco entrega.');
                }
                $key = $m[0] . "\n";
            }
        }
        if ($certificate === null) {
            throw new InvalidArgumentException('Não achei o certificado (.crt em formato PEM) no que foi enviado.');
        }
        if ($key === null) {
            throw new InvalidArgumentException('Não achei a chave privada (.key em formato PEM) no que foi enviado.');
        }
        return ['certificate' => $certificate, 'private_key' => $key];
    }

    /** Traduz a falha do teste para o que fazer (regra pura). A mensagem técnica não leva segredo. */
    public static function diagnose(string $error): string
    {
        $lower = strtolower($error);
        return match (true) {
            str_contains($lower, 'unknown ca') => 'O Inter não reconheceu o certificado. Ele parece ser de produção (gerado no Internet Banking). Para o sandbox, crie a aplicação no portal de sandbox do Inter e envie o pacote de lá.',
            str_contains($lower, 'recusou a autenticação') => 'O Inter recusou o client ID ou o secret. Confira se os dois são da mesma aplicação do certificado enviado.',
            str_contains($lower, 'sem resposta') => 'O Inter não respondeu. O sandbox do Inter só funciona de segunda a sexta, das 8h às 20h; fora desse horário, tente de novo depois.',
            default => 'O teste com o Inter falhou: ' . mb_substr($error, 0, 200),
        };
    }

    /** Contas do Inter (banco 077) nos condomínios do usuário, com a situação da credencial (sem segredo). */
    public function accounts(User $user): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $this->pdo->query("SELECT a.id, a.name, a.adapter, a.condominium_id, c.name AS condominium FROM erp_bank_accounts a JOIN condominiums c ON c.id = a.condominium_id
            WHERE a.deleted_at IS NULL AND a.bank_code = '" . InterBankAdapter::BANK_CODE . "' AND a.condominium_id IN (" . implode(',', $ids) . ') ORDER BY c.name, a.name')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'condominium_id' => (int)$row['condominium_id'],
            'condominium' => (string)$row['condominium'], 'adapter' => (string)$row['adapter'], 'credential' => $this->credentials->status((int)$row['id'])], $rows);
    }

    /**
     * Abre o pacote, testa e grava. @param array{client_id?:mixed, client_secret?:mixed, files?:mixed, save_anyway?:mixed} $input
     * @return array{connected:bool, message:string, certificate_expires_on:string, webhook_url:string, credential:?array}
     */
    public function connect(User $user, int $bankAccountId, array $input, string $webhookBase): array
    {
        $account = $this->account($user, $bankAccountId);
        $this->throttle($user);
        $clientId = trim(is_string($input['client_id'] ?? null) ? $input['client_id'] : '');
        $secret = trim(is_string($input['client_secret'] ?? null) ? $input['client_secret'] : '');
        if ($clientId === '' || mb_strlen($clientId) > 200 || $secret === '' || mb_strlen($secret) > 500) {
            throw new InvalidArgumentException('Informe o client ID e o client secret da aplicação do banco.');
        }
        $files = is_array($input['files'] ?? null) ? array_values(array_filter($input['files'], 'is_array')) : [];
        $pair = self::extract($files);
        BankCredentials::inspectCertificate($pair['certificate'], $pair['private_key']);
        $secrets = ['client_id' => $clientId, 'client_secret' => $secret] + $pair;

        $connected = true;
        $message = 'Conexão com o Inter (sandbox) testada: token obtido.';
        try {
            $this->adapter($secrets + ['account_number' => (string)($account['account_number'] ?? '')])->checkAuthentication();
        } catch (RuntimeException|InvalidArgumentException $error) {
            $connected = false;
            $message = self::diagnose($error->getMessage());
            AppLogger::log('warning', 'Teste da credencial bancária falhou', ['event_type' => 'bank_credential_test_failed', 'bank_account_id' => $bankAccountId,
                'error_class' => $error::class], 'erp');
            if (($input['save_anyway'] ?? false) !== true) {
                throw new DomainException($message . ' Nada foi gravado.');
            }
        }
        $saved = $this->credentials->save($bankAccountId, InterBankAdapter::NAME, 'sandbox', $secrets, (int)$user->id, 'web');
        return ['connected' => $connected, 'message' => $connected ? $message : $message . ' A credencial foi gravada mesmo assim, como você pediu.',
            'certificate_expires_on' => $saved['certificate_expires_on'],
            'webhook_url' => rtrim($webhookBase, '/') . '/api/v1/webhooks/bank/' . InterBankAdapter::NAME . '/' . $saved['webhook_secret'],
            'credential' => $this->credentials->status($bankAccountId)];
    }

    /** Testa a credencial já gravada (só token). @return array{connected:bool, message:string} */
    public function test(User $user, int $bankAccountId): array
    {
        $account = $this->account($user, $bankAccountId);
        $this->throttle($user);
        try {
            $this->adapter($this->credentials->secrets($bankAccountId) + ['account_number' => (string)($account['account_number'] ?? '')])->checkAuthentication();
            return ['connected' => true, 'message' => 'Conexão com o Inter (sandbox) testada: token obtido.'];
        } catch (RuntimeException|InvalidArgumentException $error) {
            if ($error->getMessage() === 'Conta bancária sem credencial cadastrada.') {
                throw new DomainException('Esta conta ainda não tem credencial do banco.');
            }
            return ['connected' => false, 'message' => self::diagnose($error->getMessage())];
        }
    }

    public function remove(User $user, int $bankAccountId): void
    {
        $this->account($user, $bankAccountId);
        $this->credentials->remove($bankAccountId, (int)$user->id, 'web');
    }

    private function adapter(array $secrets): InterBankAdapter
    {
        return new InterBankAdapter($secrets, ($this->httpFactory)());
    }

    private function account(User $user, int $bankAccountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_accounts WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $bankAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound('Conta bancária não encontrada.');
        }
        if ((string)$row['bank_code'] !== InterBankAdapter::BANK_CODE) {
            throw new InvalidArgumentException('Só contas do Banco Inter (código 077) aceitam esta integração por enquanto.');
        }
        return $row;
    }

    /** Conta a tentativa; passou do limite ou não deu para conferir = recusa. */
    private function throttle(User $user, ?int $now = null): void
    {
        $now ??= time();
        $hash = LoginThrottle::hash('bank-cred-user:' . (int)$user->id);
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_throttle_events WHERE scope = :s AND subject_hash = :h AND created_at > :since');
            $stmt->execute(['s' => self::THROTTLE_SCOPE, 'h' => $hash, 'since' => $now - self::THROTTLE_WINDOW]);
            $count = (int)$stmt->fetchColumn();
            if ($count < self::THROTTLE_MAX) {
                $this->pdo->prepare('INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES (:s, :h, :t)')
                    ->execute(['s' => self::THROTTLE_SCOPE, 'h' => $hash, 't' => $now]);
            }
        } catch (\Throwable $error) {
            AppLogger::log('error', 'Limite da credencial bancária indisponível', ['event_type' => 'bank_credential_throttle_unavailable', 'error_class' => $error::class], 'erp');
            throw new DomainException('Não foi possível conferir o limite de tentativas agora. Tente de novo mais tarde.');
        }
        if ($count >= self::THROTTLE_MAX) {
            throw new DomainException('Muitas tentativas na última hora. Espere um pouco e tente de novo.');
        }
    }
}
