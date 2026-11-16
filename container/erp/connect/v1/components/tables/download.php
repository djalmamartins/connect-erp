<?php
/**
 * Botão Download das listas e relatórios (revisão de 15/11 item 18, modelo 3, ADR 0314): PDF, Excel e CSV da mesma
 * consulta, com os filtros da tela. O PDF abre numa aba do navegador para ver antes de baixar (pedido do Djalma, 16/11);
 * Excel e CSV baixam. O arquivo sai do servidor (ListDocument), que confere permissão, escopo e o limite de
 * downloads por hora, e registra o download na auditoria.
 * @var string $downloadUrl URL da consulta (com ou sem format=) @var ?string $downloadTitle título no documento (as listas dos cadastros usam o nome da tela)
 * @var ?list<string> $downloadFormats padrão pdf, xlsx e csv @var ?string $downloadParam nome do parâmetro (padrão format)
 */
use Source\Services\Erp\ListDocument;

$downloadParam ??= 'format';
[$downloadBase, $downloadQueryString] = explode('?', $downloadUrl, 2) + [1 => ''];
parse_str($downloadQueryString, $downloadQuery);
$downloadItems = [
    'pdf' => ['printer', 'PDF', 'Abre no navegador para ver; de lá você imprime ou baixa.'],
    'xlsx' => ['file', 'Excel', 'Planilha com os valores em número.'],
    'csv' => ['download', 'CSV', 'Texto separado por ponto e vírgula.'],
];
?>
<details class="erp-dropdown erp-download" data-erp-dropdown>
    <summary class="erp-btn <?= $this->e($downloadTone ?? 'erp-btn--primary') ?>"><?= $icon('download', 'erp-icon--sm') ?>Download</summary>
    <div class="erp-dropdown__panel erp-download__panel">
        <?php foreach ($downloadFormats ?? array_keys($downloadItems) as $format): if (!isset($downloadItems[$format], ListDocument::FORMATS[$format])) { continue; }
            [$iconName, $label, $hint] = $downloadItems[$format];
            $href = $downloadBase . '?' . http_build_query(array_merge($downloadQuery, [$downloadParam => $format], ($downloadTitle ?? '') !== '' ? ['title' => $downloadTitle] : [])); ?>
            <a class="erp-dropdown__item" href="<?= $this->e($href) ?>"<?= $format === 'pdf' ? ' target="_blank" rel="noopener"' : ' download' ?> data-download-format="<?= $format ?>"><?= $icon($iconName, 'erp-icon--sm') ?><span><?= $label ?><small><?= $hint ?></small></span></a>
        <?php endforeach; ?>
    </div>
</details>
