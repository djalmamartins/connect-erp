<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;

/**
 * Regras puras do convite do morador (ADR 0093): sem CPF ou CNPJ válido no cadastro não há convite, e o
 * aceite sempre confere o documento. Morador que ficou sem documento depois do convite não aceita.
 */
final class InvitationRules
{
    public const NO_DOCUMENT = 'Cadastre o documento do morador antes de convidar.';

    /** CPF (11) ou CNPJ (14) com os dígitos verificadores certos. */
    public static function validDocument(?string $document): bool
    {
        $digits = preg_replace('/\D/', '', (string)$document) ?? '';
        if (!in_array(strlen($digits), [11, 14], true) || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return false;
        }
        if (strlen($digits) === 14) {
            return PartnerAccess::validCnpj($digits) !== null;
        }
        foreach ([9, 10] as $length) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int)$digits[$i] * ($length + 1 - $i);
            }
            $check = ($sum * 10) % 11 % 10;
            if ((int)$digits[$length] !== $check) {
                return false;
            }
        }
        return true;
    }

    /**
     * Antes de convidar: todo morador escolhido precisa de documento válido (422 no controller).
     *
     * @param array<int, ?string> $documents id do morador => documento do cadastro
     */
    public static function assertInvitable(array $documents): void
    {
        $missing = array_keys(array_filter($documents, static fn (?string $d): bool => !self::validDocument($d)));
        if ($missing !== []) {
            throw new InvalidArgumentException(self::NO_DOCUMENT . ' Moradores sem documento válido: ' . implode(', ', array_map('intval', $missing)) . '.');
        }
    }

    /** No aceite: o cadastro precisa ter documento válido, e o informado precisa ser igual. Sem exceção. */
    public static function documentMatches(?string $registered, string $typed): bool
    {
        $registered = preg_replace('/\D/', '', (string)$registered) ?? '';
        $typed = preg_replace('/\D/', '', $typed) ?? '';
        return self::validDocument($registered) && $typed !== '' && hash_equals($registered, $typed);
    }
}
