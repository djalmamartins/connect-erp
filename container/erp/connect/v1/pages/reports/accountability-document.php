<?php
/**
 * Prestação de contas no padrão do PDF de outubro (revisão de 15/11 item 21, ADR 0314): a mesma página serve para ver
 * no navegador e para o PDF (dompdf). Cada parte começa numa página nova, com o cabeçalho da Connect (modelo 3).
 * CSS simples com tabelas, porque o dompdf não entende flex/grid.
 *
 * @var array $doc AccountabilityDocument::build() @var bool $forPdf @var ?string $back @var array $issuer
 * @var string $logo @var string $today @var string $longToday @var callable $imageSrc
 */
$num = static fn (int $cents): string => ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (?string $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '';
$condo = $doc['condominium'];
$demo = $doc['demonstrative'];
$range = $date($doc['from']) . ' até ' . $date($doc['to']);
$council = $doc['board']['council'];
$head = function (string $title, ?string $subtitle = null, bool $first = false) use ($issuer, $logo, $today, $condo): string {
    $e = fn (string $t): string => $this->e($t);
    return '<div class="sheet' . ($first ? '' : ' sheet--break') . '"><table class="issuer"><tr><td class="issuer__logo">' . ($logo !== '' ? '<img src="' . $logo . '" alt="Connect">' : '')
        . '</td><td class="issuer__data"><strong>' . $e($issuer['name']) . '</strong><br>CNPJ: ' . $e($issuer['document']) . '<br>' . $e($issuer['address']) . '</td></tr></table>'
        . '<p class="today">' . $e($today) . '</p><p class="condo">' . $e((string)$condo['label']) . '</p>'
        . ($subtitle !== null ? '<p class="subtitle">' . $e($subtitle) . '</p>' : '') . '<h2 class="title">' . $e($title) . '</h2>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Prestação de contas · <?= $this->e((string)$condo['name']) ?> · <?= $this->e($doc['period']) ?></title>
    <style>
        /* A4 retrato ("de comprido", ADR 0652): o dompdf usa setPaper; o size vale para o Imprimir do navegador. */
        @page { size: A4 portrait; margin: 26px 28px 40px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2a24; font-size: 8.5pt; line-height: 1.4; background: <?= $forPdf ? '#fff' : '#eef2f0' ?>; }
        .sheet { background: #fff; <?= $forPdf ? '' : 'max-width: 794px; margin: 16px auto; padding: 28px; border: 1px solid #dfe5e2;' ?> }
        .sheet--break { page-break-before: always; }
        .issuer { width: 100%; border-collapse: collapse; }
        .issuer td { vertical-align: top; padding: 0; }
        .issuer__logo img { height: 36px; }
        .issuer__data { text-align: right; font-size: 8pt; line-height: 1.5; }
        .issuer__data strong { font-size: 9.5pt; }
        .today { text-align: right; margin: 8px 0 2px; }
        .condo { text-align: center; font-weight: bold; font-size: 9.5pt; margin: 2px 0; }
        .subtitle { text-align: center; margin: 2px 0; }
        .title { text-align: center; font-size: 11pt; margin: 4px 0 14px; }
        .cover { text-align: center; padding-top: 120px; }
        .cover h1 { font-size: 22pt; font-weight: normal; letter-spacing: 1px; margin: 0 0 40px; }
        .cover p { font-size: 12pt; margin: 6px 0; }
        .cover strong { font-size: 13pt; }
        .text { font-size: 9pt; line-height: 1.5; }
        .text ul { margin: 6px 0 10px 18px; padding: 0; }
        .indent { margin-left: 24px; }
        .sign { text-align: center; margin-top: 14px; page-break-inside: avoid; }
        .sign p { margin: 12px 0 0; }
        table.grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { padding: 4px 6px; text-align: left; vertical-align: top; }
        .grid thead th { border-bottom: 1px solid #9aa59f; font-weight: bold; }
        .grid td { border-bottom: 1px solid #edf0ee; }
        .grid .n { text-align: right; white-space: nowrap; }
        .grid .sec td { font-weight: bold; background: #f3f6f5; }
        .grid .grp td { font-weight: bold; padding-left: 14px; }
        .grid .acc td { padding-left: 26px; }
        .grid .item td.d { padding-left: 26px; white-space: nowrap; width: 80px; }
        .grid .nw { white-space: nowrap; }
        /* Leitura (ADR 0652): linhas alternadas branco e verde bem claro; total do grupo em verde claro e total geral mais forte. */
        .grid tr.z td { background: #f2f8f4; }
        .grid .tot td { font-weight: bold; border-top: 1px solid #9aa59f; background: #e2efe7; color: #0b3d2e; }
        .grid .tot--all td { background: #c9e1d3; border-top: 1px solid #0b3d2e; }
        .grid .grp td { background: #fafcfb; }
        .grid--lines th, .grid--lines td { padding: 4px 4px; font-size: 7.8pt; }
        .grid .tot td:first-child { padding-left: 14px; }
        .note { color: #5d6b63; font-size: 7.5pt; margin-top: 6px; }
        .centered { text-align: center; font-weight: bold; margin: 18px 0 6px; }
        .proof { text-align: center; margin: 10px 0; page-break-inside: avoid; }
        .proof img { max-width: 100%; max-height: 200mm; border: 1px solid #dfe5e2; }
        .proof p { color: #5d6b63; margin: 4px 0; }
        .toolbar { position: sticky; top: 0; z-index: 2; background: #0b3d2e; padding: 10px 16px; text-align: right; }
        .toolbar a, .toolbar button { color: #fff; margin-left: 14px; font: inherit; font-size: 10pt; background: none; border: 0; cursor: pointer; text-decoration: underline; }
        @media print { .toolbar { display: none; } body { background: #fff; } .sheet { border: 0; margin: 0; max-width: none; padding: 0; } }
    </style>
</head>
<body>
<?php if (!$forPdf): ?>
    <div class="toolbar"><?php if ($back !== null): ?><a href="<?= $this->e($back) ?>">Voltar</a><?php endif; ?><button type="button" onclick="window.print()">Imprimir</button></div>
<?php endif; ?>

<?= $head('PRESTAÇÃO DE CONTAS', null, true) ?>
    <div class="cover">
        <p><strong><?= $this->e((string)$condo['name']) ?></strong></p>
        <p><?= $this->e($doc['period']) ?></p>
        <?php if (!$doc['closed']): ?><p class="note">Mês ainda não fechado: os valores podem mudar até o fechamento.</p><?php endif; ?>
    </div>
</div>

<?= $head('TERMO DE ABERTURA', $doc['period']) ?>
    <p class="centered" style="font-weight:normal">(Lei nº 8.541/92, Art. 18, I)</p>
    <div class="text">
        <p>Contém este livro ______ folhas numeradas manualmente, seguidamente do número ______ ao número ______ e servirá para o lançamento dos pagamentos e recebimentos do contribuinte abaixo identificado.</p>
        <div class="indent">
            Razão Social: <?= $this->e((string)($condo['legal_name'] ?? $condo['name'])) ?><br>
            Endereço: <?= $this->e((string)$condo['street']) ?> &nbsp;&nbsp; Número: <?= $this->e((string)$condo['number']) ?><br>
            Município: <?= $this->e((string)$condo['city']) ?> &nbsp;&nbsp; Estado: <?= $this->e((string)$condo['state']) ?><br>
            CNPJ: <?= $this->e((string)($condo['document'] ?? '')) ?>
        </div>
        <p>Com registro no(a): ______________________________ Sob número: __________ Em: ___/___/______</p>
    </div>
    <div class="sign">
        <p><?= $this->e(($condo['city'] !== '' ? $condo['city'] . ', ' : '') . $longToday) ?></p>
        <p>______________________________________________</p>
        <p><?= $this->e($issuer['name']) ?></p>
    </div>
</div>

<?= $head('PARECER SOBRE PRESTAÇÃO DE CONTAS') ?>
    <div class="text">
        <p><strong>CONDOMÍNIO:</strong> <?= $this->e((string)$condo['name']) ?><br><strong>MÊS DE COMPETÊNCIA:</strong> <?= $this->e($doc['period']) ?></p>
        <p class="centered">PARECER DO SÍNDICO E CONSELHEIROS</p>
        <p>Após análise dos documentos que compõem a Prestação de Contas do período de <?= $this->e($doc['period']) ?>, referente ao Condomínio <?= $this->e((string)$condo['name']) ?>, o(a) Síndico(a) e os conselheiros abaixo identificados declaram que:</p>
        <ul>
            <li>Foram verificadas as receitas e despesas apresentadas;</li>
            <li>Foram analisados os comprovantes e relatórios financeiros disponibilizados;</li>
            <li>Não foram constatadas inconsistências que impeçam a aprovação das contas;</li>
            <li>Autorizam a liberação e aprovação da Prestação de Contas referente ao período mencionado.</li>
        </ul>
        <p>Desta forma, manifestam-se favoráveis à aprovação e liberação da prestação de contas do período de <?= $this->e($doc['period']) ?>.</p>
    </div>
    <div class="sign">
        <p class="centered">ASSINATURAS:</p>
        <p>______________________________________________</p>
        <p>Síndico(a): <?= $this->e((string)($doc['board']['sindico'] ?? '')) ?></p>
        <?php foreach ($council !== [] ? $council : ['', ''] as $member): ?>
            <p>______________________________________________</p>
            <p><?= $member !== '' ? 'Conselheiro(a): ' . $this->e($member) : 'Conselho (caso houver)' ?></p>
        <?php endforeach; ?>
    </div>
</div>

<?= $head('Relatório demonstrativo financeiro de contas de ' . $range) ?>
    <table class="grid">
        <thead><tr><th>Lançamento</th><th></th><th class="n">Valor</th></tr></thead>
        <tbody>
        <?php foreach (['receita', 'despesa'] as $nature): $section = $demo[$nature]; ?>
            <tr class="sec"><td colspan="3"><?= $this->e($section['label']) ?></td></tr>
            <?php if ($section['groups'] === []): ?><tr><td colspan="3" class="note">Nenhum lançamento pago no mês.</td></tr><?php endif; ?>
            <?php foreach ($section['groups'] as $group): ?>
                <tr class="grp"><td colspan="3"><?= $this->e($group['name']) ?></td></tr>
                <?php foreach ($group['accounts'] as $account): ?>
                    <tr class="acc"><td colspan="3"><?= $this->e($account['name']) ?></td></tr>
                    <?php foreach ($account['items'] as $k => $item): ?>
                        <tr class="item<?= $k % 2 === 1 ? ' z' : '' ?>"><td class="d"><?= $date($item['paid_on']) ?></td><td><?= $this->e($item['description']) ?></td><td class="n"><?= $num($item['amount_cents']) ?></td></tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="tot"><td colspan="2">Total Grupo</td><td class="n"><?= $num($group['total_cents']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="tot tot--all"><td colspan="2" style="padding-left:6px">Total Geral</td><td class="n"><?= $num($section['total_cents']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <table class="grid" style="margin-top:14px">
        <thead><tr><th>Resumo</th><th class="n">Valor</th></tr></thead>
        <tbody>
            <tr><td>Receitas</td><td class="n"><?= $num($demo['receita']['total_cents']) ?></td></tr>
            <tr class="z"><td>Despesas</td><td class="n"><?= $num($demo['despesa']['total_cents']) ?></td></tr>
            <tr class="tot tot--all"><td>Total (Receitas - Despesas)</td><td class="n"><?= $num($demo['result_cents']) ?></td></tr>
        </tbody>
    </table>
    <p class="centered">RESUMO DAS CONTAS*</p>
    <table class="grid">
        <thead><tr><th>Conta</th><th class="n">Saldo anterior</th><th class="n">Entradas (D)</th><th class="n">Saídas (C)</th><th class="n">Saldo atual</th></tr></thead>
        <tbody>
        <?php foreach ($doc['banks'] as $k => $bank): ?>
            <tr<?= $k % 2 === 1 ? ' class="z"' : '' ?>><td><?= $this->e((string)$bank['name']) ?></td><td class="n"><?= $num((int)$bank['opening_cents']) ?></td><td class="n"><?= $num((int)$bank['received_cents'] + (int)($bank['transfers_in_cents'] ?? 0)) ?></td>
                <td class="n"><?= $num((int)$bank['paid_cents'] + (int)($bank['transfers_out_cents'] ?? 0)) ?></td><td class="n"><?= $num((int)$bank['closing_cents']) ?></td></tr>
        <?php endforeach; ?>
        <?php $sum = static fn (string $k): int => array_sum(array_map(static fn (array $b): int => (int)($b[$k] ?? 0), $doc['banks'])); ?>
            <tr class="tot tot--all"><td>Total</td><td class="n"><?= $num($sum('opening_cents')) ?></td><td class="n"><?= $num($sum('received_cents') + $sum('transfers_in_cents')) ?></td>
                <td class="n"><?= $num($sum('paid_cents') + $sum('transfers_out_cents')) ?></td><td class="n"><?= $num($sum('closing_cents')) ?></td></tr>
        </tbody>
    </table>
    <p class="note">*Os valores de entrada e saída das contas podem não ser iguais aos de receitas e despesas do demonstrativo, porque transferências entre contas não são receita nem despesa.</p>
</div>

<?= $head('Relatório de lançamentos de ' . $range) ?>
    <table class="grid grid--lines">
        <thead><tr><th>Data</th><th>Tipo</th><th>Lançamento</th><th class="n">Valor (R$)</th><th class="n">Sobras de Rateio (R$)</th><th class="n">Fundo de Reserva (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($doc['expenses'] as $k => $line): ?>
            <tr<?= $k % 2 === 1 ? ' class="z"' : '' ?>><td class="nw"><?= $date($line['paid_on']) ?></td><td>Despesa</td><td><?= $this->e((string)$line['description']) ?></td><td class="n"><?= $num((int)$line['amount_cents']) ?></td>
                <td class="n"><?= $num((int)($line['surplus_cents'] ?? 0)) ?></td><td class="n"><?= $num((int)($line['reserve_cents'] ?? 0)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($doc['expenses'] === []): ?><tr><td colspan="6" class="note">Nenhuma despesa paga no mês.</td></tr><?php endif; ?>
            <tr class="tot"><td colspan="3">Total</td><td class="n"><?= $num((int)$doc['expense_total_cents']) ?></td><td class="n"><?= $num((int)($doc['surplus_total_cents'] ?? 0)) ?></td><td class="n"><?= $num((int)($doc['reserve_total_cents'] ?? 0)) ?></td></tr>
            <tr class="tot tot--all"><td colspan="3">Total Geral</td><td class="n"><?= $num((int)$doc['expense_total_cents']) ?></td><td colspan="2"></td></tr>
        </tbody>
    </table>
</div>

<?php foreach ($doc['expenses'] as $line): ?>
<?= $head('Relatório de lançamentos de ' . $range) ?>
    <table class="grid grid--lines">
        <thead><tr><th>Conta</th><th>Documento</th><th>Descrição</th><th>Lançamento</th><th>Compet.</th><th>Pagamento</th><th class="n">Valor</th></tr></thead>
        <tbody><tr><td><?= $this->e((string)($line['bank_name'] ?? '')) ?></td><td><?= $this->e((string)($line['document_number'] ?? '')) ?></td><td><?= $this->e((string)$line['description']) ?></td>
            <td class="nw"><?= $date($line['due_on'] ?? null) ?></td><td class="nw"><?= $this->e(preg_replace('/^(\d{4})-(\d{2})$/', '$2/$1', (string)$line['competence'])) ?></td><td class="nw"><?= $date($line['paid_on']) ?></td><td class="n"><?= $num((int)$line['amount_cents']) ?></td></tr></tbody>
    </table>
    <?php foreach ($line['annexes'] as $annex): $placement = $annex['placement'] ?? null; ?>
        <div class="proof">
            <?php if ($forPdf && $placement === 'embed' && ($src = $imageSrc($annex)) !== ''): ?>
                <img src="<?= $src ?>" alt="<?= $this->e((string)$annex['original_name']) ?>">
                <p><?= $this->e((string)$annex['kind_label']) ?> · <?= $this->e((string)$annex['original_name']) ?></p>
            <?php elseif ($forPdf): ?>
                <p><?= $this->e((string)$annex['kind_label']) ?> · <?= $this->e((string)$annex['original_name']) ?>: <?= $placement === 'blocked' ? 'bloqueado pelo antivírus' : ($placement === 'missing' ? 'arquivo não encontrado' : ($placement === 'merge' ? 'em PDF, nas páginas a seguir' : 'em PDF, no pacote do livro caixa')) ?>.</p>
            <?php else: ?>
                <p><a href="<?= $this->e(erp_url('/financeiro/lancamentos/' . (int)$line['entry_id'] . '/anexos/' . (int)$annex['id'])) ?>" target="_blank" rel="noopener"><?= $this->e((string)$annex['kind_label']) ?> · <?= $this->e((string)$annex['original_name']) ?></a></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($line['annexes'] === []): ?><p class="note">Sem comprovante anexado.</p><?php endif; ?>
    <?php if ($forPdf): ?><div data-annex-anchor="<?= (int)$line['seq'] ?>" style="height:1px"></div><?php endif; ?>
</div>
<?php endforeach; ?>
</body>
</html>
