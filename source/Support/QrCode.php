<?php

declare(strict_types=1);

namespace Source\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR code em SVG gerado no próprio servidor (ADR 0029). Usado na configuração do 2FA: o link
 * otpauth:// leva o segredo TOTP, então ele nunca vai para um serviço externo de QR.
 * Devolve null quando a biblioteca não está instalada, para a tela seguir com a chave em texto.
 */
final class QrCode
{
    public static function svg(string $content, int $size = 196, string $label = 'QR code'): ?string
    {
        if ($content === '' || !class_exists(Writer::class)) {
            return null;
        }
        try {
            $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd()));
            $svg = $writer->writeString($content);
        } catch (\Throwable) {
            return null;
        }
        $svg = trim((string)preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
        if (!str_starts_with($svg, '<svg')) {
            return null;
        }
        $attributes = 'role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" focusable="false"';
        return '<svg ' . $attributes . substr($svg, 4);
    }
}
