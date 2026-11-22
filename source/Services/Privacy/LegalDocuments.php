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

    /** Categorias opcionais do banner. "stats" só existe com SITE_GA4_ID; "ads" só com SITE_GOOGLE_ADS_ID ou SITE_META_PIXEL_ID. */
    public static function cookieCategories(): array
    {
        $categories = ['maps' => 'Mapa do Google na página de contato'];
        if (self::analyticsId() !== '') {
            $categories['stats'] = 'Estatística de visitas (Google Analytics)';
        }
        $ads = array_filter([self::googleAdsId() !== '' ? 'Google Ads' : '', self::metaPixelId() !== '' ? 'Facebook e Instagram' : '']);
        if ($ads !== []) {
            $categories['ads'] = 'Anúncios e medição de campanhas (' . implode(' e ', $ads) . ')';
        }
        return $categories;
    }

    /**
     * Versão do banner que o visitante aceitou: COOKIES mais as categorias ligadas no .env (ex.: "1.msa"). Quando entra uma
     * categoria nova (por exemplo, o Google Ads), a versão muda e o banner pergunta de novo a quem já tinha escolhido.
     */
    public static function cookieVersion(): string
    {
        return self::COOKIES . '.' . implode('', array_map(static fn (string $key): string => $key[0], array_keys(self::cookieCategories())));
    }

    /** Id do GA4 (G-XXXX) do .env; vazio = nenhum rastreador no site. */
    public static function analyticsId(): string
    {
        $id = trim((string)(getenv('SITE_GA4_ID') ?: ''));
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
    }

    /** Id de conversão do Google Ads (AW-123456789) do .env; só carrega com "ads" aceito. */
    public static function googleAdsId(): string
    {
        $id = trim((string)(getenv('SITE_GOOGLE_ADS_ID') ?: ''));
        return preg_match('/^AW-[0-9]{6,15}$/', $id) ? $id : '';
    }

    /** Id do Pixel da Meta (só números) do .env; só carrega com "ads" aceito. */
    public static function metaPixelId(): string
    {
        $id = trim((string)(getenv('SITE_META_PIXEL_ID') ?: ''));
        return preg_match('/^[0-9]{10,20}$/', $id) ? $id : '';
    }

    /** Data da versão no formato da tela (20/11/2026). */
    public static function label(string $version): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $version);
        return $date ? $date->format('d/m/Y') : $version;
    }
}
