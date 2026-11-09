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
        $normalized = self::normalize($code);
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
     * Código digitado ou colado → 16 caracteres, ou null (regra pura, ADR 0099). Aceita maiúsculas ou
     * minúsculas, hífens, espaços e os traços e espaços que editores de texto e PDF costumam trocar
     * (travessão, meia-risca, hífen sem quebra, espaço sem quebra). O formato final é o de
     * UnitDocuments::normalizeCode.
     */
    public static function normalize(?string $code): ?string
    {
        if ($code === null || strlen($code) > 64) {
            return null;
        }
        $code = (string)preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{00A0}\x{2007}\x{202F}\x{200B}]/u', '-', $code);
        return UnitDocuments::normalizeCode($code);
    }

    /** Código para imprimir: "ABCD-EFGH-JKMN-PQRS", ou null se o código não for válido (regra pura). */
    public static function formatCode(?string $code): ?string
    {
        $normalized = self::normalize($code);
        return $normalized === null ? null : implode('-', str_split($normalized, 4));
    }

    /**
     * Link impresso no documento (regra pura): a página pública com o código no fragmento
     * ("…/verificar#ABCD-EFGH-JKMN-PQRS"). O navegador não manda o fragmento ao servidor, então o código
     * não vai para o log de acesso; a página só preenche o campo, sem enviar sozinha.
     */
    public static function link(?string $code, string $base): ?string
    {
        $formatted = self::formatCode($code);
        $base = rtrim(trim($base), '/');
        if ($formatted === null || preg_match('~^https?://[^\s/?#]+(?:/[^\s?#]*)?$~i', $base) !== 1) {
            return null;
        }
        return $base . '/verificar#' . $formatted;
    }

    /**
     * Rodapé de verificação dos documentos da unidade (regra pura, ADR 0099): o código formatado, o
     * endereço para conferir (sem o código) e o link com o código no fragmento.
     *
     * @return array{code:string, address:string, link:string}|null
     */
    public static function footer(?string $code, string $base): ?array
    {
        $link = self::link($code, $base);
        if ($link === null) {
            return null;
        }
        return ['code' => (string)self::formatCode($code), 'address' => rtrim(trim($base), '/') . '/verificar', 'link' => $link];
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
