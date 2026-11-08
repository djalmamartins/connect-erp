<?php
/**
 * Documento do relatório de vistoria: visualização/impressão no navegador e PDF (dompdf).
 * CSS simples com tabelas, porque o dompdf não entende flex/grid.
 *
 * @var array $inspection InspectionService::get()
 * @var callable $photoSrc fn(int $photoId): string
 * @var bool $forPdf
 * @var string $siteName
 * @var string $generatedAt
 */
use Source\Services\Inspections\InspectionService;

$results = InspectionService::RESULTS;
$checkinLabels = InspectionService::CHECKIN;
$checkin = $inspection['checkin'];
$fmt = static fn(?string $date): string => $date ? date('d/m/Y H:i', strtotime($date)) : '—';
$sections = [];
foreach ($inspection['items'] as $item) {
    $sections[$item['section']][] = $item;
}
$statusLabel = ['in_progress' => 'Em andamento (rascunho)', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'][$inspection['status']] ?? $inspection['status'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Relatório de vistoria #<?= (int)$inspection['id'] ?> · <?= $this->e($inspection['condominium']) ?></title>
    <style>
        @page { margin: 16mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2a28; font-size: 10pt; line-height: 1.45; background: #fff; }
        .wrap { max-width: 900px; margin: 0 auto; padding: <?= $forPdf ? '0' : '24px 16px' ?>; }
        .top { padding: 14px 16px; background: #0b3d2e; color: #fff; border-bottom: 4px solid #c99a22; }
        .top strong { font-size: 15pt; }
        .top small { display: block; color: #d6e4de; }
        h1 { font-size: 16pt; margin: 16px 0 4px; color: #0b3d2e; }
        h2 { font-size: 11pt; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #e2e8e5; color: #0b3d2e; }
        .meta { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .meta td { width: 50%; padding: 6px 8px; border: 1px solid #e2e8e5; vertical-align: top; }
        .meta small { display: block; color: #6b7a75; font-size: 7.5pt; text-transform: uppercase; }
        .totals { width: 100%; border-collapse: collapse; margin: 8px 0; }
        .totals td { width: 25%; padding: 8px; border: 1px solid #e2e8e5; text-align: center; }
        .totals strong { display: block; font-size: 14pt; }
        .items { width: 100%; border-collapse: collapse; }
        .items th, .items td { padding: 6px 8px; border: 1px solid #e2e8e5; text-align: left; vertical-align: top; }
        .items th { background: #f3f6f5; font-size: 8pt; text-transform: uppercase; color: #52615c; }
        .r-ok { color: #1a7f4b; font-weight: bold; }
        .r-issue { color: #b42318; font-weight: bold; }
        .r-not_applicable, .r-pending { color: #6b7a75; font-weight: bold; }
        .alert { padding: 8px 10px; margin: 8px 0; border-left: 4px solid #c99a22; background: #fbf6e9; }
        .photos { margin-top: 6px; }
        .photos img { width: <?= $forPdf ? '150px' : '180px' ?>; max-width: 46%; height: auto; margin: 0 6px 6px 0; border: 1px solid #e2e8e5; }
        .foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e2e8e5; color: #6b7a75; font-size: 8pt; }
        .print { margin: 0 0 12px; }
        .print button, .print a { font: inherit; padding: 8px 14px; border: 1px solid #0b3d2e; background: #0b3d2e; color: #fff; border-radius: 6px; text-decoration: none; cursor: pointer; margin-right: 6px; }
        .print a.ghost { background: #fff; color: #0b3d2e; }
        @media print { .print { display: none; } .wrap { padding: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    <?php if (!$forPdf): ?>
        <div class="print">
            <a class="ghost" href="<?= erp_url('/operacional/vistorias/' . (int)$inspection['id']) ?>">Voltar</a>
            <a href="<?= erp_url('/operacional/vistorias/' . (int)$inspection['id'] . '/relatorio/pdf') ?>">Baixar PDF</a>
            <button type="button" onclick="window.print()">Imprimir</button>
        </div>
    <?php endif; ?>
    <div class="top"><strong><?= $this->e($siteName) ?></strong><small>Relatório de vistoria presencial</small></div>
    <h1><?= $this->e($inspection['condominium']) ?></h1>
    <div><?= $this->e($inspection['template']) ?> · Vistoria nº <?= (int)$inspection['id'] ?> · <?= $this->e($statusLabel) ?></div>

    <table class="meta">
        <tr>
            <td><small>Responsável</small><?= $this->e($inspection['inspector'] ?: '—') ?></td>
            <td><small>Check-in</small><?= $fmt($checkin['at']) ?></td>
        </tr>
        <tr>
            <td><small>Localização no check-in</small><?= $this->e($checkinLabels[$checkin['status']] ?? $checkin['status']) ?>
                <?php if ($checkin['distance_m'] !== null): ?> (<?= number_format((float)$checkin['distance_m'], 0, ',', '.') ?> m do ponto cadastrado)<?php endif; ?>
                <?php if ($checkin['latitude'] !== null): ?><br><span style="font-size:8pt;color:#6b7a75;"><?= number_format((float)$checkin['latitude'], 6, '.', '') ?>, <?= number_format((float)$checkin['longitude'], 6, '.', '') ?><?= $checkin['accuracy_m'] !== null ? ' · precisão ±' . number_format((float)$checkin['accuracy_m'], 0, ',', '.') . ' m' : '' ?></span><?php endif; ?>
            </td>
            <td><small>Conclusão</small><?= $fmt($inspection['completed_at']) ?></td>
        </tr>
    </table>
    <?php if (!empty($checkin['note'])): ?>
        <div class="alert"><strong>Justificativa do check-in:</strong> <?= $this->e((string)$checkin['note']) ?></div>
    <?php endif; ?>

    <table class="totals">
        <tr>
            <td><strong class="r-ok"><?= (int)$inspection['counts']['ok'] ?></strong>Conformes</td>
            <td><strong class="r-issue"><?= (int)$inspection['counts']['issue'] ?></strong>Com problema</td>
            <td><strong><?= (int)$inspection['counts']['not_applicable'] ?></strong>Não se aplica</td>
            <td><strong><?= (int)$inspection['counts']['pending'] ?></strong>Pendentes</td>
        </tr>
    </table>

    <?php if (!empty($inspection['summary'])): ?>
        <h2>Resumo</h2>
        <p><?= nl2br($this->e((string)$inspection['summary'])) ?></p>
    <?php endif; ?>

    <?php foreach ($sections as $section => $items): ?>
        <h2><?= $this->e($section) ?></h2>
        <table class="items">
            <thead><tr><th style="width:45%">Item</th><th style="width:18%">Resultado</th><th>Observação e fotos</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= $this->e($item['label']) ?></td>
                    <td class="r-<?= $this->e($item['result']) ?>"><?= $this->e($results[$item['result']] ?? $item['result']) ?></td>
                    <td>
                        <?= $item['note'] ? nl2br($this->e((string)$item['note'])) : '' ?>
                        <?php if ($item['photos'] !== []): ?>
                            <div class="photos">
                                <?php foreach ($item['photos'] as $photo):
                                    $src = $photoSrc((int)$photo['id']);
                                    if ($src === '') { continue; } ?>
                                    <img src="<?= $this->e($src) ?>" alt="Foto de <?= $this->e($item['label']) ?>" loading="lazy">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <div class="foot">
        Gerado em <?= $this->e($generatedAt) ?> pelo Connect ERP. A localização é a informada pelo aparelho do responsável no momento do check-in e de cada foto.
    </div>
</div>
</body>
</html>
