<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;

/**
 * Webhook de cobrança (ADR 0156). Endereço: /api/v1/webhooks/bank/{adapter}/{segredo}.
 *
 * - O segredo do endereço identifica a conta bancária (só o sha256 fica no banco). Segredo errado
 *   = 404, sem dizer se a conta existe. Em produção, o proxy ainda deve exigir o certificado do
 *   Inter (mTLS) e liberar só os IPs do banco (docs/banking/INTER.md).
 * - O corpo NÃO é confiável: dele só saem os códigos das cobranças. Cada uma é consultada no banco
 *   com as nossas credenciais e é a consulta que muda o estado (consulta na notificação). Assim uma
 *   notificação forjada, repetida ou fora de ordem não dá baixa em nada.
 * - A mesma entrega (sha256 do corpo) processada com sucesso não roda de novo; a que falhou roda de
 *   novo quando o banco reenviar. O mesmo evento (event_key) também não aplica duas vezes.
 * - O corpo é guardado cifrado no cofre, para auditoria.
 */
final class BankWebhooks
{
    public const MAX_BYTES = 262_144;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null, private ?BankVault $vault = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** @return array{http:int, status:string, items?:int, applied?:int} */
    public function receive(string $adapter, string $secret, string $body): array
    {
        $account = (new BankCredentials($this->pdo, $this->vault))->accountByWebhookSecret($adapter, $secret);
        if ($account === null) {
            return ['http' => 404, 'status' => 'not_found'];
        }
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return ['http' => 413, 'status' => 'too_large'];
        }
        $hash = hash('sha256', $body);
        $stmt = $this->pdo->prepare('SELECT id, status FROM erp_bank_webhook_deliveries WHERE bank_account_id = :b AND sha256 = :h');
        $stmt->execute(['b' => $account['id'], 'h' => $hash]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing) && $existing['status'] === 'processed') {
            return ['http' => 200, 'status' => 'duplicate'];
        }
        if (is_array($existing)) {
            $deliveryId = (int)$existing['id'];
        } else {
            $vault = $this->vault ?? new BankVault();
            $this->pdo->prepare('INSERT IGNORE INTO erp_bank_webhook_deliveries (condominium_id, bank_account_id, adapter, sha256, payload)
                VALUES (:c, :b, :a, :h, :p)')->execute(['c' => $account['condominium_id'], 'b' => $account['id'], 'a' => $adapter, 'h' => $hash,
                'p' => $vault->sealText($body, 'erp_bank_webhook:' . $account['id'])]);
            $stmt->execute(['b' => $account['id'], 'h' => $hash]);
            $deliveryId = (int)$stmt->fetchColumn();
        }

        $bank = BankAdapters::forAccount($account, $this->pdo);
        if (!$bank instanceof BankChargeExtras) {
            $this->finish($deliveryId, 'failed', 0, 0, 'Adaptador sem webhook.');
            return ['http' => 404, 'status' => 'not_found'];
        }
        try {
            $items = $bank->parseWebhook($body);
        } catch (InvalidArgumentException $error) {
            $this->finish($deliveryId, 'failed', 0, 0, $error->getMessage());
            return ['http' => 400, 'status' => 'invalid'];
        }
        $charges = new BankCharges($this->pdo);
        $applied = 0;
        $failed = 0;
        foreach ($items as $item) {
            try {
                if ($charges->refreshFromBank($account, $item['external_id']) !== null) {
                    $applied++;
                }
            } catch (\Throwable $error) {
                $failed++;
                AppLogger::exception($error, 'erp', ['event_type' => 'bank_webhook_item_failed', 'delivery_id' => $deliveryId]);
            }
        }
        if ($failed > 0) {
            $this->finish($deliveryId, 'failed', count($items), $applied, "{$failed} cobrança(s) sem resposta do banco; aguardando reenvio.");
            return ['http' => 503, 'status' => 'retry', 'items' => count($items), 'applied' => $applied];
        }
        $this->finish($deliveryId, 'processed', count($items), $applied, null);
        return ['http' => 200, 'status' => 'processed', 'items' => count($items), 'applied' => $applied];
    }

    private function finish(int $deliveryId, string $status, int $items, int $applied, ?string $error): void
    {
        $this->pdo->prepare('UPDATE erp_bank_webhook_deliveries SET status = :s, items_count = :i, applied_count = :a, error = :e,
            processed_at = NOW() WHERE id = :id')->execute(['s' => $status, 'i' => $items, 'a' => $applied, 'e' => $error === null ? null : mb_substr($error, 0, 255), 'id' => $deliveryId]);
    }
}
