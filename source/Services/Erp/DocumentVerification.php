<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;

/**
 * Verificação pública de documento emitido (ADR 0098), para a página /verificar.
 *
 * - Usa o código de verificação que os documentos da unidade já têm (erp_unit_documents.verification_code,
 *   ADRs 0044 e 0057), no formato de UnitDocuments::normalizeCode (16 caracteres, com ou sem hífens).
 * - A resposta só diz se o documento é autêntico, o tipo, a data de emissão e o nome do condomínio. Nunca
 *   mostra valores, nomes de pessoas, unidade, situação de débito nem o arquivo.
 * - Código inválido, inexistente ou cancelado recebe a mesma mensagem genérica e conta no limite por IP.
 * - Limite por IP que falha fechado (DocumentVerificationThrottle). Sem IP válido, também recusa.
 */
final class DocumentVerification
{
    public const GENERIC = 'Não encontramos um documento válido com este código. Confira o código e tente de novo.';
    public const THROTTLED = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
    public const UNAVAILABLE = 'Não foi possível verificar o documento agora. Tente de novo mais tarde.';
    public const AUTHENTIC = 'Documento autêntico.';

    private ?PDO $pdo;
    private DocumentVerificationThrottle $throttle;

    public function __construct(?PDO $pdo = null, ?DocumentVerificationThrottle $throttle = null)
    {
        $this->pdo = $pdo;
        $this->throttle = $throttle ?? new DocumentVerificationThrottle($pdo);
    }

    /**
     * @return array{ok:bool, message:string, document:array{kind:string, issued_on:string, condominium:string}|null}
     */
    public function check(?string $code, ?string $ip, ?int $now = null): array
    {
        $subject = DocumentVerificationThrottle::subject($ip);
        if ($subject === null) {
            return self::refusal(self::UNAVAILABLE);
        }
        $state = $this->throttle->check($subject, $now);
        if ($state !== DocumentVerificationThrottle::OK) {
            return self::refusal($state === DocumentVerificationThrottle::BLOCKED ? self::THROTTLED : self::UNAVAILABLE);
        }
        $normalized = UnitDocuments::normalizeCode((string)$code);
        $row = null;
        if ($normalized !== null) {
            try {
                $stmt = $this->pdo()->prepare('SELECT d.kind, d.status, d.created_at, c.name AS condominium_name
                    FROM erp_unit_documents d JOIN condominiums c ON c.id = d.condominium_id WHERE d.verification_code = :c LIMIT 1');
                $stmt->execute(['c' => $normalized]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable) {
                return self::refusal(self::UNAVAILABLE);
            }
        }
        $document = is_array($row) ? self::present($row) : null;
        if ($document === null) {
            return self::refusal($this->throttle->fail($subject, $now) ? self::GENERIC : self::UNAVAILABLE);
        }
        return ['ok' => true, 'message' => self::AUTHENTIC, 'document' => $document];
    }

    /**
     * O que a página pode mostrar (regra pura): só tipo, data de emissão e condomínio. Documento cancelado
     * ou de tipo desconhecido = null (mensagem genérica).
     *
     * @return array{kind:string, issued_on:string, condominium:string}|null
     */
    public static function present(array $row): ?array
    {
        $kind = UnitDocuments::ALL_KINDS[(string)($row['kind'] ?? '')] ?? null;
        $issued = strtotime((string)($row['created_at'] ?? ''));
        if (($row['status'] ?? null) !== 'issued' || $kind === null || $issued === false) {
            return null;
        }
        return ['kind' => $kind, 'issued_on' => date('d/m/Y', $issued), 'condominium' => (string)($row['condominium_name'] ?? '')];
    }

    /** @return array{ok:false, message:string, document:null} */
    private static function refusal(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'document' => null];
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
