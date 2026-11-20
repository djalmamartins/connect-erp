<?php

declare(strict_types=1);

namespace Source\Support;

use Source\Models\User;
use Source\Services\Environments;

/**
 * Alternador de ambientes único (ADR 0627): o mesmo botão e o mesmo painel "Ambientes Connect" no
 * ERP, no Studio e no Help Desk. Quem vê cada item e para onde ele leva continua com
 * Source\Services\Environments (ADR 0034); aqui só fica o desenho, igual em todos os sistemas.
 *
 * Estilo e comportamento: container/shared/assets/envswitch/envswitch.css e envswitch.js.
 * A cor de destaque vem do data-environment do componente (ERP verde, Studio roxo, Help Desk azul).
 */
final class EnvironmentSwitcher
{
    /** Ícones próprios (traço 1.6, 24×24), os mesmos em todos os sistemas. */
    private const ICONS = [
        'studio' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'erp' => '<path d="M4.5 20.5v-15a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v15"/><path d="M14.5 9.5h4a1 1 0 0 1 1 1v10"/><path d="M3 20.5h18"/><path d="M7.5 8h1M10.5 8h1M7.5 11.5h1M10.5 11.5h1M7.5 15h1M10.5 15h1M17 13h.01M17 16.5h.01"/>',
        'helpdesk' => '<path d="M4.5 14v-2a7.5 7.5 0 0 1 15 0v2"/><rect x="3.5" y="13.5" width="4" height="6" rx="1.5"/><rect x="16.5" y="13.5" width="4" height="6" rx="1.5"/><path d="M18.5 19.5c0 1.2-1.6 2-4 2h-2"/>',
        'residents' => '<path d="M3.5 11 12 4l8.5 7"/><path d="M5.5 9.5v10h13v-10"/><path d="M10 19.5v-5h4v5"/>',
        'talk' => '<path d="M20.5 11.5a8.5 7.5 0 0 1-12.2 6.8L4 19.5l1.3-3.6A7 7 0 0 1 3.5 11.5a8.5 7.5 0 0 1 17 0Z"/>',
        'site' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.3 2.3 3.5 5.2 3.5 8.5s-1.2 6.2-3.5 8.5c-2.3-2.3-3.5-5.2-3.5-8.5S9.7 5.8 12 3.5Z"/>',
    ];

    private const CHEVRON = '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/>';

    /**
     * Cabeçalho do ambiente atual (rótulo pequeno + nome) e o painel com os ambientes do usuário.
     *
     * @param string $current ambiente atual: 'erp', 'studio' ou 'helpdesk'
     * @param string $kicker  linha pequena do botão (ex.: "ERP", "CMS", "Atendimento")
     * @param string $name    nome do sistema no botão (ex.: "Connect ERP")
     */
    public static function render(?User $user, string $current, string $kicker, string $name, ?string $host = null): string
    {
        return self::markup(Environments::menu($user, $current, $host), $current, $kicker, $name);
    }

    /**
     * HTML a partir de itens prontos (o mesmo formato de Environments::menu()).
     *
     * @param list<array{key:string,label:string,description:string,href:string,current:bool}> $items
     */
    public static function markup(array $items, string $current, string $kicker, string $name): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $panelId = 'cx-envswitch-panel';
        $html = '<div class="cx-envswitch" data-cx-envswitch data-environment="' . $e($current) . '">'
            . '<button type="button" class="cx-envswitch__toggle" aria-haspopup="true" aria-expanded="false" aria-controls="' . $panelId . '" title="Trocar de ambiente">'
            . '<span class="cx-envswitch__context"><small>' . $e($kicker) . '</small><strong>' . $e($name) . '</strong></span>'
            . self::svg(self::CHEVRON, 'cx-envswitch__chevron')
            . '</button>'
            . '<nav class="cx-envswitch__panel" id="' . $panelId . '" aria-label="Ambientes Connect" hidden>'
            . '<small class="cx-envswitch__title">Ambientes Connect</small>';
        foreach ($items as $item) {
            $isCurrent = (bool)($item['current'] ?? false);
            $html .= '<a class="cx-envswitch__item' . ($isCurrent ? ' is-current' : '') . '" href="' . $e((string)$item['href']) . '"'
                . ($isCurrent ? ' aria-current="page"' : '') . ' data-environment="' . $e((string)$item['key']) . '">'
                . self::svg(self::ICONS[$item['key']] ?? self::ICONS['site'], 'cx-envswitch__icon')
                . '<span><strong>' . $e((string)$item['label']) . '</strong><small>' . $e((string)$item['description']) . '</small></span>'
                . ($isCurrent ? '<em>Atual</em>' : '')
                . '</a>';
        }
        return $html . '</nav></div>';
    }

    private static function svg(string $paths, string $class): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}
