<?php

declare(strict_types=1);

namespace Source\Services\Privacy;

/**
 * Versões da política de privacidade, dos termos de uso e do banner de cookies (ADR 0604).
 *
 * O texto fica no código (pages/legal/*.php), não no Studio, para que cada aceite aponte para um texto que não muda depois.
 * Ao mudar o sentido de um texto, troque a versão aqui: quem aceitou a versão anterior aceita de novo na próxima entrada
 * (etapa 2 do ADR 0604) e o banner volta a perguntar quando muda COOKIES.
 */
final class LegalDocuments
{
    public const PRIVACY = '2026-11-20';
    public const TERMS = '2026-11-20';
    /** Muda quando entra uma categoria nova de cookie, para o banner perguntar de novo. */
    public const COOKIES = '1';

    /** Nome do cookie (necessário) que guarda a escolha do banner. */
    public const COOKIE_NAME = 'connect_cookie_consent';

    /** Categorias opcionais do banner. "stats" só existe quando o .env traz SITE_GA4_ID. */
    public static function cookieCategories(): array
    {
        $categories = ['maps' => 'Mapa do Google na página de contato'];
        if (self::analyticsId() !== '') {
            $categories['stats'] = 'Estatística de visitas (Google Analytics)';
        }
        return $categories;
    }

    /** Id do GA4 (G-XXXX) do .env; vazio = nenhum rastreador no site. */
    public static function analyticsId(): string
    {
        $id = trim((string)(getenv('SITE_GA4_ID') ?: ''));
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
    }

    /** Data da versão no formato da tela (20/11/2026). */
    public static function label(string $version): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $version);
        return $date ? $date->format('d/m/Y') : $version;
    }
}
