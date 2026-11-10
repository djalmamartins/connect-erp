<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Ícones de traço da Central de Ajuda (24×24, stroke currentColor), servidos inline: a página
 * pública não depende de CDN de terceiros (ADR 0141). Os nomes seguem os que já estavam gravados
 * nas categorias do Help Desk (estilo "nome-outline"); nome desconhecido vira o ícone de ajuda.
 * Conteúdo estático: nenhum dado do usuário entra no SVG.
 */
final class Icons
{
    private const PATHS = [
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'log-in' => '<path d="M15 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l4-4-4-4M14 12H3"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'headset' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/><path d="M19 19c0 1.5-1.5 2.5-4 2.5h-2"/>',
        'chatbubbles' => '<path d="M4 15.5V6a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H8Z"/><path d="M17 8h1.5a1.5 1.5 0 0 1 1.5 1.5V19l-3-2.5h-6a1.5 1.5 0 0 1-1.5-1.5"/>',
        'chatbox-ellipses' => '<path d="M4 5h16v11H9l-5 4Z"/><path d="M8.5 10.5h.01M12 10.5h.01M15.5 10.5h.01"/>',
        'help-buoy' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m5.6 5.6 3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/>',
        'happy' => '<rect x="3" y="4" width="18" height="15" rx="3"/><path d="M8.5 9.5h.01M15.5 9.5h.01"/><path d="M8.5 13.5c1.8 2 5.2 2 7 0"/>',
        'logo-whatsapp' => '<path d="M3.6 20.4l1.25-4.15a8.6 8.6 0 1 1 3.1 3.05Z"/><path fill="currentColor" stroke="none" d="M9.3 7.4c-.4 0-.75.2-1 .5-.65.75-.9 1.7-.6 2.65.95 2.95 3.3 5.3 6.25 6.25.95.3 1.9.05 2.65-.6.4-.4.4-1-.05-1.35l-1.45-1.05c-.4-.3-.95-.25-1.25.1l-.55.6c-1.35-.6-2.45-1.7-3.05-3.05l.6-.55c.35-.3.4-.85.1-1.25L9.9 7.75c-.15-.2-.35-.35-.6-.35Z"/>',
        'arrow-forward' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-up' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'chevron-forward' => '<path d="m9 18 6-6-6-6"/>',
        'document-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'time' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'location' => '<path d="M12 21s7-6 7-11.5A7 7 0 0 0 5 9.5C5 15 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 7 8.5-7"/>',
        'call' => '<path d="M5 4h3.5l1.5 4-2 1.5a11 11 0 0 0 6.5 6.5L16 14l4 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4Z"/>',
        'albums' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M6 4h12"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5-9 9"/>',
        'navigate' => '<path d="m3 11 18-8-8 18-2-8Z"/>',
        'thumbs-up' => '<path d="M7 21H4a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1h3Z"/><path d="M7 11l4-8a2.5 2.5 0 0 1 2.5 2.5V9h5.2a2 2 0 0 1 2 2.3l-1.2 7.5A2.5 2.5 0 0 1 17 21H7"/>',
        'thumbs-down' => '<path d="M17 3h3a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-3Z"/><path d="M17 13l-4 8a2.5 2.5 0 0 1-2.5-2.5V15H5.3a2 2 0 0 1-2-2.3l1.2-7.5A2.5 2.5 0 0 1 7 3h10"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'reader' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'rocket' => '<path d="M5 15c-1.5 1.5-2 4-2 6 2 0 4.5-.5 6-2"/><path d="M9 15l-3-3c2-6 7-9 15-9 0 8-3 13-9 15Z"/><circle cx="15" cy="9" r="1.5"/>',
        'business' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M3 21h18"/>',
        'people' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.2-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.8.7 3 2.4 3.5 5.2"/>',
        'person-circle' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.5 18.5c1.2-2.2 3-3.3 5.5-3.3s4.3 1.1 5.5 3.3"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2Z"/><path d="M16.5 14.5h.01"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'scale' => '<path d="M12 3v18M7 21h10M5 7h14"/><path d="m5 7-3 7a3.5 3.5 0 0 0 6 0Z"/><path d="m19 7-3 7a3.5 3.5 0 0 0 6 0Z"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h3"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l6 4V6L7 10H4a1 1 0 0 0-1 1Z"/><path d="M17 8.5a5 5 0 0 1 0 7M19.5 6a8.5 8.5 0 0 1 0 12"/>',
        'bar-chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'cloud-upload' => '<path d="M7 18a4.5 4.5 0 0 1-.6-9A6 6 0 0 1 18 8a4 4 0 0 1-.5 10"/><path d="M12 12v9M9 15l3-3 3 3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'options' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'shield-checkmark' => '<path d="M12 3 20 6v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6Z"/><path d="m9 12 2 2 4-4"/>',
        'speedometer' => '<path d="M4 18a9 9 0 1 1 16 0"/><path d="m12 14 4-5"/><circle cx="12" cy="14" r="1.5"/>',
        'create' => '<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>',
        'lock-closed' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'ribbon' => '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 13.5-1.5 7.5 5-3 5 3-1.5-7.5"/>',
        'help-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
        'logo-instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.3 6.7h.01"/>',
        'logo-facebook' => '<path d="M14 21v-8h3l.5-3.5H14V7.8c0-1 .3-1.8 1.8-1.8h1.8V3a24 24 0 0 0-2.6-.1c-2.6 0-4.3 1.6-4.3 4.5v2.1H7.7V13h3v8"/>',
        'logo-youtube' => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="m10 9 5 3-5 3Z"/>',
        'logo-linkedin' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7h.01M12 17v-4a2 2 0 0 1 4 0v4M12 10v7"/>',
    ];

    public static function svg(?string $name, string $class = ''): string
    {
        $key = preg_replace('/-(outline|sharp)$/', '', (string)$name) ?? '';
        $paths = self::PATHS[$key] ?? self::PATHS['help-circle'];
        return '<svg class="hc-icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[preg_replace('/-(outline|sharp)$/', '', $name) ?? '']);
    }
}
