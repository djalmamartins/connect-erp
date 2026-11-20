<?php

declare(strict_types=1);

namespace Source\Services\Erp;

/**
 * Menu lateral do ERP (ADR 0397).
 *
 * O catálogo (catalog.php) continua dizendo o endereço, o grupo de permissão e a migalha de cada página; o menu
 * é uma camada só de organização por cima dele, montada aqui. Assim o menu muda sem mudar nenhum endereço.
 *
 * - "novo" (padrão): Agenda, Portaria e Leituras no 1º nível, no lugar do grupo "Operacional" (auditoria do
 *   APControle, 17/11/2026). As páginas de NEW_MOVES mudam de lugar; todas as outras ficam no grupo, na seção e
 *   na ordem do catálogo, inclusive as páginas criadas depois.
 * - "atual": o menu de antes, igual ao catálogo, guardado a pedido do Djalma. Cada pessoa escolhe no rodapé da
 *   sidebar; a escolha fica num cookie do navegador (preferência de tela, nenhum dado pessoal).
 */
final class ErpNav
{
    public const COOKIE = 'erp_menu';
    public const MODES = ['novo', 'atual'];
    public const DEFAULT_MODE = 'novo';

    /** Grupos do menu novo, na ordem: chave, rótulo, ícone. A chave igual a um grupo do catálogo reaproveita a visão geral dele. */
    private const NEW_GROUPS = [
        ['dashboard', 'Dashboard', 'home'],
        ['condominios', 'Condomínios', 'building'],
        ['cadastros', 'Cadastros', 'users'],
        ['agenda', 'Agenda', 'calendar'],
        ['portaria', 'Portaria', 'shield'],
        ['leituras', 'Leituras', 'gauge'],
        ['financeiro', 'Financeiro', 'wallet'],
        ['cobranca', 'Cobrança', 'receipt'],
        ['prestacao-contas', 'Prestação de contas', 'scale'],
        ['comunicacao', 'Comunicação', 'megaphone'],
        ['documentos', 'Documentos', 'file'],
        ['relatorios', 'Relatórios', 'chart'],
        ['importacoes', 'Importações', 'upload'],
        ['configuracoes', 'Configurações', 'settings'],
    ];

    /** Páginas que mudam de lugar no menu novo: id => [grupo do menu, seção], já na ordem em que aparecem. */
    private const NEW_MOVES = [
        'CN-004' => ['agenda', 'Atendimento'],
        'AP-112' => ['agenda', 'Atendimento'],
        'AP-110' => ['agenda', 'Atendimento'],
        'CN-001' => ['agenda', 'Atendimento'],
        'AP-113' => ['agenda', 'Áreas comuns'],
        'AP-114' => ['agenda', 'Áreas comuns'],
        'AP-115' => ['agenda', 'Áreas comuns'],
        'AP-120' => ['agenda', 'Mudanças'],
        'AP-121' => ['agenda', 'Mudanças'],
        'AP-118' => ['agenda', 'Contratos e seguros'],
        'AP-119' => ['agenda', 'Contratos e seguros'],
        'AP-131' => ['portaria', ''],
        'AP-132' => ['portaria', ''],
        'AP-133' => ['portaria', ''],
        'AP-135' => ['portaria', ''],
        'AP-136' => ['portaria', ''],
        'AP-134' => ['portaria', ''],
        'AP-041' => ['leituras', ''],
        'AP-042' => ['leituras', ''],
        'AP-043' => ['leituras', ''],
        'AP-044' => ['leituras', ''],
    ];

    /** Modo escolhido pela pessoa (cookie), ou o padrão. */
    public static function mode(?array $cookies = null): string
    {
        $value = (string)(($cookies ?? $_COOKIE)[self::COOKIE] ?? '');
        return in_array($value, self::MODES, true) ? $value : self::DEFAULT_MODE;
    }

    /**
     * Grupos do menu no modo pedido.
     *
     * @param array{groups: array<string, array{label: string, icon: string, prefix: string}>, pages: array<string, array<string, string>>} $catalog
     * @return array<string, array{label: string, icon: string, hub: ?string, items: list<array{0: string, 1: string}>}>
     */
    public static function build(array $catalog, string $mode): array
    {
        $groups = $mode === 'atual'
            ? array_map(static fn (string $key, array $g): array => [$key, $g['label'], $g['icon']], array_keys($catalog['groups']), $catalog['groups'])
            : self::NEW_GROUPS;
        $moves = $mode === 'atual' ? [] : array_filter(self::NEW_MOVES, static fn (string $id): bool => isset($catalog['pages'][$id]), ARRAY_FILTER_USE_KEY);

        $nav = [];
        foreach ($groups as [$key, $label, $icon]) {
            $hub = $catalog['groups'][$key]['prefix'] ?? null;
            $nav[$key] = ['label' => $label, 'icon' => $icon, 'hub' => $hub, 'items' => []];
        }
        foreach ($catalog['pages'] as $id => $page) {
            if (!isset($moves[$id]) && isset($nav[$page['group']])) {
                $nav[$page['group']]['items'][] = [$id, (string)$page['section']];
            }
        }
        foreach ($moves as $id => [$group, $section]) {
            $nav[$group]['items'][] = [$id, $section];
        }
        return $nav;
    }

    /**
     * Grupo do menu onde a página está (para abrir o grupo certo na sidebar).
     *
     * @param array<string, array{items: list<array{0: string, 1: string}>}> $nav
     */
    public static function groupOf(array $nav, ?string $pageId): ?string
    {
        if ($pageId === null) {
            return null;
        }
        foreach ($nav as $key => $group) {
            foreach ($group['items'] as [$id]) {
                if ($id === $pageId) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Seção do menu onde a página está, ou ''. */
    public static function sectionOf(array $nav, ?string $pageId): string
    {
        foreach ($nav as $group) {
            foreach ($group['items'] as [$id, $section]) {
                if ($id === $pageId) {
                    return $section;
                }
            }
        }
        return '';
    }
}
