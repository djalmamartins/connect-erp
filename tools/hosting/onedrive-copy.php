<?php

declare(strict_types=1);

/**
 * Copia os arquivos que já estão no servidor para o OneDrive da Connect (ADR 0376), antes de trocar para
 * DOCUMENT_STORAGE=onedrive (HOSPEDAGEM.md §10).
 *
 *   php tools/hosting/onedrive-copy.php --dry-run   conta o que falta subir, sem enviar
 *   php tools/hosting/onedrive-copy.php             envia; pode ser interrompido e rodado de novo
 *
 * Saída 0: tudo copiado; 1: algum arquivo falhou (rode de novo); 2: OneDrive incompleto no .env ou sem ligação.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Source\Support\Storage\DocumentStores;
use Source\Support\Storage\StorageCopy;

$dryRun = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__, 2);
$env = DocumentStores::environment();
if (($missing = DocumentStores::missing($env)) !== []) {
    fwrite(STDERR, 'Falta no .env: ' . implode(', ', $missing) . " (HOSPEDAGEM.md §10).\n");
    exit(2);
}
// Confere a ligação antes de começar (ADR 0378): sem isso, cada arquivo falharia um por um.
if (($problem = DocumentStores::oneDrive('documents', $env)->probe()) !== null) {
    fwrite(STDERR, 'OneDrive: ' . $problem . "\n");
    exit(2);
}
$folders = ['documents' => 'storage/private/documents', 'inspections' => 'storage/private/inspections', 'resident-photos' => 'storage/private/resident-photos'];
@mkdir($root . '/storage/temp', 0775, true);
$failed = 0;
foreach ($folders as $area => $folder) {
    $result = StorageCopy::run($root . '/' . $folder, DocumentStores::oneDrive($area, $env), $root . '/storage/temp/onedrive-copy-' . $area . '.done', $dryRun);
    $failed += count($result['failed']);
    printf("%s: %d arquivo(s), %d já copiado(s), %d copiado(s) agora, %d com falha%s\n", $area, $result['total'], $result['skipped'],
        $result['copied'], count($result['failed']), $dryRun ? ' (simulação)' : '');
    foreach (array_slice($result['failed'], 0, 20) as $relative) {
        echo "  falhou: {$folder}/{$relative}\n";
    }
}
if ($failed > 0) {
    echo "Rode de novo: só o que falhou sobe. Veja o log (event_type onedrive_put).\n";
    exit(1);
}
echo $dryRun ? "Simulação concluída.\n" : "Tudo copiado. Agora troque DOCUMENT_STORAGE=onedrive no .env.\n";
