<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use Source\Core\Session;
use Source\Models\User;

/**
 * Vínculo que o morador está vendo (ADR 0302). Quem tem mais de uma unidade troca pelo seletor; a escolha
 * fica na sessão. O pedido só escolhe entre os vínculos válidos da própria conta (ResidentLinks::pick).
 */
final class ResidentSession
{
    public const KEY = 'resident_link';

    /** @return array{0: list<array>, 1: ?array} todos os vínculos e o atual */
    public static function resolve(?User $user, mixed $wanted = null, ?ResidentLinks $links = null): array
    {
        $all = ($links ?? new ResidentLinks())->forUser($user);
        $choice = $wanted !== null && $wanted !== '' ? $wanted : ((new Session())->{self::KEY} ?? null);
        return [$all, ResidentLinks::pick($all, $choice)];
    }

    public static function choose(int $residentId): void
    {
        (new Session())->set(self::KEY, $residentId);
    }
}
