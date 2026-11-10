<?php

declare(strict_types=1);

/*
 * Base de conhecimento do Connect ERP (ADR 0141). Uso:
 *
 *   php tools/help.php check [--sem-prints]  confere os artigos; falha se uma página do menu do ERP
 *                                             não tem artigo, se falta print ou se há link quebrado
 *   php tools/help.php scaffold               cria o rascunho do artigo de cada página do menu que
 *                                             ainda não tem artigo (nunca sobrescreve)
 *   php tools/help.php screens [--only a,b]   lista os prints em JSON (usado por tools/help/shots.mjs)
 *   php tools/help.php sync                   publica a base na Central de Ajuda (/suporte)
 *   php tools/help.php stats                  quantos artigos, prints e páginas cobertas
 *
 * O processo completo está em docs/process/HELP_CENTER.md.
 */

$root = dirname(__DIR__);
$command = $argv[1] ?? 'help';
if ($command === 'sync') {
    // Só o sync precisa do banco (o autoload do Composer carrega Boot/Settings.php, que conecta).
    require $root . '/vendor/autoload.php';
} else {
    // check/scaffold/screens/stats rodam sem MariaDB (CI): só o namespace Source.
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'Source\\')) {
            $file = $root . '/source/' . str_replace('\\', '/', substr($class, 7)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

use Source\Services\Help\HelpScaffold;
use Source\Services\Help\HelpSync;
use Source\Services\Help\KnowledgeBase;

$options = array_slice($argv, 2);
$kb = new KnowledgeBase();
$catalog = KnowledgeBase::erpCatalog();
$codes = array_keys($catalog['pages']);

switch ($command) {
    case 'check':
        $problems = $kb->problems($codes, !in_array('--sem-prints', $options, true));
        $drafts = array_filter($kb->articles(), static fn (array $a): bool => $a['draft']);
        if ($drafts) {
            fwrite(STDERR, count($drafts) . " artigo(s) ainda em rascunho (não aparecem no site):\n");
            foreach ($drafts as $article) {
                fwrite(STDERR, "  - {$article['file']}\n");
            }
        }
        if ($problems) {
            fwrite(STDERR, count($problems) . " problema(s) na base de conhecimento:\n");
            foreach ($problems as $problem) {
                fwrite(STDERR, "  - {$problem}\n");
            }
            exit(1);
        }
        echo 'Base de conhecimento OK: ' . count($kb->articles()) . ' artigos, ' . count($codes) . " páginas do menu cobertas.\n";
        exit(0);

    case 'scaffold':
        $inventoryFile = $root . '/tools/help/.inventario.json';
        $inventory = is_file($inventoryFile) ? (json_decode((string)file_get_contents($inventoryFile), true) ?: []) : [];
        if (!$inventory) {
            fwrite(STDERR, "Sem tools/help/.inventario.json: o rascunho sai sem os botões e colunas reais (rode node tools/help/shots.mjs --inventario).\n");
        }
        $situations = HelpScaffold::situationsFromGuide($root . '/docs/guides/GUIA_DO_SISTEMA.md');
        $created = (new HelpScaffold($kb, $catalog, $inventory, $situations))->run(!in_array('--publicar', $options, true));
        foreach ($created as $file) {
            echo "criado {$file}\n";
        }
        echo count($created) . " rascunho(s) criado(s). Complete o texto, rode os prints e troque 'rascunho: sim' por 'rascunho: nao'.\n";
        exit(0);

    case 'screens':
        $only = [];
        foreach ($options as $index => $option) {
            if ($option === '--only' && isset($options[$index + 1])) {
                $only = array_filter(explode(',', $options[$index + 1]));
            } elseif (str_starts_with($option, '--only=')) {
                $only = array_filter(explode(',', substr($option, 7)));
            }
        }
        $list = [];
        foreach ($kb->articles() as $slug => $article) {
            foreach ($article['screens'] as $screen) {
                if ($only && !in_array($screen['id'], $only, true) && !in_array($slug, $only, true)) {
                    continue;
                }
                $list[] = $screen + ['article' => $slug];
            }
        }
        echo json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);

    case 'sync':
        $problems = $kb->problems($codes, false);
        if ($problems) {
            fwrite(STDERR, "A base tem problemas; rode 'php tools/help.php check'. Nada foi publicado.\n");
            exit(1);
        }
        $stats = (new HelpSync(\Source\Core\Connect::getInstance(), $kb))->run();
        printf("Central de Ajuda: %d categorias, %d artigos novos, %d atualizados, %d sem mudança, %d retirados.\n", $stats['categories'], $stats['created'], $stats['updated'], $stats['unchanged'], $stats['unpublished']);
        exit(0);

    case 'stats':
        $articles = $kb->articles();
        $screens = array_sum(array_map(static fn ($a) => count($a['screens']), $articles));
        $shots = count(glob($kb->shotsDirectory() . '/*.jpg') ?: []);
        $covered = count(array_intersect($codes, array_keys($kb->byCode())));
        printf("%d artigos, %d prints declarados, %d prints gerados, %d de %d páginas do menu com artigo.\n", count($articles), $screens, $shots, $covered, count($codes));
        exit(0);

    default:
        echo "Uso: php tools/help.php check|scaffold|screens|sync|stats\n";
        exit($command === 'help' ? 0 : 1);
}
