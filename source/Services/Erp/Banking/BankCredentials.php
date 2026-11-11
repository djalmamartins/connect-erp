<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;
use Source\Support\Audit;

/**
 * Credenciais bancárias de uma conta (ADR 0156). Gravadas só pelo comando de terminal
 * tools/bank-credentials.php (quem tem acesso ao servidor), nunca por formulário web: assim
 * client_secret, certificado e chave não passam pelo navegador nem pela API.
 *
 * - Tudo cifrado no cofre (BankVault) com o contexto da conta; o banco de dados só vê metadados
 *   (4 últimos caracteres do client_id, titular e validade do certificado).
 * - Só o ambiente sandbox é aceito. Produção exige homologação e aprovação explícita (outro ADR).
 * - Ao gravar, a conta passa a usar o adaptador do banco e ganha um segredo novo para o endereço
 *   do webhook (mostrado uma única vez; no banco fica só o sha256).
 */
final class BankCredentials
{
    public const ADAPTERS = [InterBankAdapter::NAME => InterBankAdapter::BANK_CODE];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null, private ?BankVault $vault = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Valida o par certificado/chave (regra pura). @return array{subject:string, expires_on:string} */
    public static function inspectCertificate(string $certificatePem, string $privateKeyPem, ?string $today = null): array
    {
        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new InvalidArgumentException('Certificado inválido: use o arquivo .crt (PEM) baixado do Inter.');
        }
        $key = @openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('Chave privada inválida: use o arquivo .key (PEM) baixado junto com o certificado.');
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidArgumentException('A chave privada não pertence a este certificado.');
        }
        $info = openssl_x509_parse($certificate) ?: [];
        $expires = date('Y-m-d', (int)($info['validTo_time_t'] ?? 0));
        if ($expires < ($today ?? date('Y-m-d'))) {
            throw new InvalidArgumentException("Certificado vencido em {$expires}: gere outro no Internet Banking do Inter.");
        }
        $subject = (string)($info['subject']['CN'] ?? $info['name'] ?? '');
        return ['subject' => mb_substr($subject, 0, 160), 'expires_on' => $expires];
    }

    /**
     * Grava (ou troca) as credenciais da conta e liga o adaptador.
     *
     * @param array{client_id:string, client_secret:string, certificate:string, private_key:string} $secrets
     * @return array{webhook_secret:string, certificate_expires_on:string}
     */
    public function save(int $bankAccountId, string $adapter, string $environment, array $secrets, ?int $userId = null): array
    {
        if (!isset(self::ADAPTERS[$adapter])) {
            throw new InvalidArgumentException("Adaptador '{$adapter}' não aceita credenciais.");
        }
        if ($environment !== 'sandbox') {
            throw new InvalidArgumentException('Só o ambiente sandbox está liberado. Produção depende de homologação e aprovação (ADR 0156).');
        }
        $account = $this->account($bankAccountId);
        if ($account['bank_code'] !== self::ADAPTERS[$adapter]) {
            throw new InvalidArgumentException("A conta {$bankAccountId} não é do banco {$adapter} (código " . self::ADAPTERS[$adapter] . ").");
        }
        foreach (['client_id', 'client_secret', 'certificate', 'private_key'] as $key) {
            $secrets[$key] = trim((string)($secrets[$key] ?? ''));
            if ($secrets[$key] === '') {
                throw new InvalidArgumentException("Falta {$key}.");
            }
        }
        $cert = self::inspectCertificate($secrets['certificate'], $secrets['private_key']);
        $webhookSecret = bin2hex(random_bytes(24));
        $sealed = $this->vault()->seal([
            'client_id' => $secrets['client_id'], 'client_secret' => $secrets['client_secret'],
            'certificate' => $secrets['certificate'], 'private_key' => $secrets['private_key'],
        ], self::context($bankAccountId));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO erp_bank_credentials (bank_account_id, adapter, environment, secrets, client_id_hint, certificate_subject,
                    certificate_expires_on, webhook_token_hash, created_by, updated_by)
                VALUES (:b, :a, :e, :s, :h, :cs, :ce, :w, :u, :u2)
                ON DUPLICATE KEY UPDATE adapter = VALUES(adapter), environment = VALUES(environment), secrets = VALUES(secrets),
                    client_id_hint = VALUES(client_id_hint), certificate_subject = VALUES(certificate_subject),
                    certificate_expires_on = VALUES(certificate_expires_on), webhook_token_hash = VALUES(webhook_token_hash), updated_by = VALUES(updated_by)')
                ->execute(['b' => $bankAccountId, 'a' => $adapter, 'e' => $environment, 's' => $sealed, 'h' => '…' . mb_substr($secrets['client_id'], -4),
                    'cs' => $cert['subject'], 'ce' => $cert['expires_on'], 'w' => hash('sha256', $webhookSecret), 'u' => $userId, 'u2' => $userId]);
            $this->pdo->prepare('UPDATE erp_bank_accounts SET adapter = :a WHERE id = :id')->execute(['a' => $adapter, 'id' => $bankAccountId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_bank_credentials', $bankAccountId, null, ['adapter' => $adapter, 'environment' => $environment,
            'certificate_expires_on' => $cert['expires_on'], 'source' => 'cli']);
        return ['webhook_secret' => $webhookSecret, 'certificate_expires_on' => $cert['expires_on']];
    }

    /** Desliga a integração da conta: volta ao sandbox e apaga as credenciais. */
    public function remove(int $bankAccountId, ?int $userId = null): void
    {
        $this->account($bankAccountId);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM erp_bank_credentials WHERE bank_account_id = :b')->execute(['b' => $bankAccountId]);
        $this->pdo->prepare("UPDATE erp_bank_accounts SET adapter = 'sandbox' WHERE id = :id")->execute(['id' => $bankAccountId]);
        $this->pdo->commit();
        Audit::record('delete', 'erp_bank_credentials', $bankAccountId, null, ['source' => 'cli', 'by' => $userId]);
    }

    /** Segredos abertos, só para montar o adaptador. @return array<string, string> */
    public function secrets(int $bankAccountId): array
    {
        $row = $this->row($bankAccountId);
        if ($row === null) {
            throw new \RuntimeException('Conta bancária sem credencial cadastrada.');
        }
        return $this->vault()->open((string)$row['secrets'], self::context($bankAccountId));
    }

    /** Metadados para a tela (sem segredo nenhum). */
    public function status(int $bankAccountId): ?array
    {
        $row = $this->row($bankAccountId);
        if ($row === null) {
            return null;
        }
        return ['adapter' => (string)$row['adapter'], 'environment' => (string)$row['environment'], 'client_id_hint' => $row['client_id_hint'],
            'certificate_subject' => $row['certificate_subject'], 'certificate_expires_on' => $row['certificate_expires_on'],
            'updated_at' => $row['updated_at']];
    }

    /** Conta bancária dona do segredo do webhook (comparação pelo sha256, sem revelar se existe). */
    public function accountByWebhookSecret(string $adapter, string $secret): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $secret)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT b.* FROM erp_bank_credentials c JOIN erp_bank_accounts b ON b.id = c.bank_account_id
            WHERE c.webhook_token_hash = :h AND c.adapter = :a AND b.adapter = :a2 AND b.deleted_at IS NULL AND b.status = 'active'");
        $stmt->execute(['h' => hash('sha256', $secret), 'a' => $adapter, 'a2' => $adapter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function context(int $bankAccountId): string
    {
        return 'erp_bank_credentials:' . $bankAccountId;
    }

    private function vault(): BankVault
    {
        return $this->vault ??= new BankVault();
    }

    private function row(int $bankAccountId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_bank_credentials WHERE bank_account_id = :b');
        $stmt->execute(['b' => $bankAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function account(int $bankAccountId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_accounts WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute(['id' => $bankAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException("Conta bancária {$bankAccountId} não encontrada.");
        }
        return $row;
    }
}
