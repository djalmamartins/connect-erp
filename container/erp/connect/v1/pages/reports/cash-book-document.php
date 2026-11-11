<?php
/**
 * Livro caixa mensal (CN-007, ADR 0174): impressão no navegador e PDF (dompdf). CSS simples com tabelas, porque o
 * dompdf não entende flex/grid. Os anexos de imagem entram no fim, um por página; os em PDF ficam no pacote.
 *
 * @var array $book CashBook::build() + CashBookDocument::prepare()
 * @var bool $forPdf @var ?string $printBack @var string $logo @var callable $imageSrc
 * @var string $siteName @var string $generatedAt
 */
use Source\Services\Erp\CashBookDocument as Doc;

$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$num = static fn (int $cents): string => $cents === 0 ? '' : number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $d;
$condo = $book['condominium'];
$month = Doc::monthLabel((string)$book['competence']);
$placementLabel = ['embed' => 'Nesta via, página de anexos', 'package' => 'No pacote (arquivo separado)', 'blocked' => 'Bloqueado pelo antivírus', 'missing' => 'Arquivo não encontrado'];
$annexes = $book['annexes'];
$embedded = array_values(array_filter($annexes, static fn (array $a): bool => $a['placement'] === 'embed'));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Livro caixa · <?= $this->e($condo['name']) ?> · <?= $this->e($month) ?></title>
    <style>
        @page { margin: 14mm 12mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2a28; font-size: 8.5pt; line-height: 1.4; background: #fff; }
        .wrap { max-width: 1000px; margin: 0 auto; padding: <?= $forPdf ? '0' : '24px 16px' ?>; }
        .top { width: 100%; border-collapse: collapse; background: #0b3d2e; border-bottom: 4px solid #c5a131; }
        .top td { padding: 12px 14px; color: #fff; vertical-align: middle; }
        .top img { height: 42px; }
        .top .title { text-align: right; }
        .top .title strong { display: block; font-size: 16pt; letter-spacing: .5px; }
        .top .title span { color: #d6e4de; font-size: 9pt; }
        .opening { width: 100%; border-collapse: collapse; margin: 10px 0 4px; }
        .opening td { padding: 5px 8px; border: 1px solid #e2e8e5; vertical-align: top; }
        .opening small { display: block; color: #6b7a75; font-size: 6.5pt; text-transform: uppercase; letter-spacing: .3px; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 7.5pt; font-weight: bold; }
        .status--closed { background: #e3f3ea; color: #1a7f4b; }
        .status--draft { background: #fbf1d9; color: #8a5a00; }
        h2 { font-size: 10pt; margin: 14px 0 5px; padding-bottom: 3px; border-bottom: 2px solid #e2e8e5; color: #0b3d2e; }
        .kpi { width: 100%; border-collapse: collapse; margin: 6px 0; }
        .kpi td { width: 25%; padding: 7px 8px; border: 1px solid #e2e8e5; text-align: center; }
        .kpi small { display: block; color: #6b7a75; font-size: 6.5pt; text-transform: uppercase; }
        .kpi strong { display: block; font-size: 11pt; color: #0b3d2e; }
        table.grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { padding: 4px 5px; border: 1px solid #e2e8e5; text-align: left; vertical-align: top; }
        .grid th { background: #f3f6f5; font-size: 6.5pt; text-transform: uppercase; color: #52615c; }
        .grid tfoot td { background: #f3f6f5; font-weight: bold; }
        .grid tr.muted td { color: #6b7a75; }
        .n { text-align: right !important; white-space: nowrap; }
        .nw { white-space: nowrap; }
        .in { color: #1a7f4b; }
        .out { color: #b42318; }
        .sub { display: block; color: #6b7a75; font-size: 7pt; }
        .ref { display: inline-block; padding: 0 4px; margin: 0 2px 2px 0; border: 1px solid #c5a131; border-radius: 3px; color: #7a5f12; font-size: 7pt; font-weight: bold; }
        .alert { padding: 6px 9px; margin: 8px 0; border-left: 4px solid #c5a131; background: #fbf6e9; }
        .alert--danger { border-left-color: #b42318; background: #fdecea; }
        .ok { padding: 6px 9px; margin: 8px 0; border-left: 4px solid #1a7f4b; background: #e9f6ef; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 34px; page-break-inside: avoid; }
        .sign td { width: 33%; padding: 0 10px; text-align: center; vertical-align: top; }
        .sign .line { border-top: 1px solid #1f2a28; padding-top: 4px; margin-top: 30px; }
        .sign small { display: block; color: #6b7a75; }
        .annex { page-break-before: always; text-align: center; }
        .annex h3 { text-align: left; font-size: 10pt; color: #0b3d2e; margin: 0 0 6px; }
        .annex p { text-align: left; color: #6b7a75; margin: 0 0 8px; }
        .annex img { max-width: 100%; max-height: 225mm; border: 1px solid #e2e8e5; }
        .foot { margin-top: 14px; padding-top: 6px; border-top: 1px solid #e2e8e5; color: #6b7a75; font-size: 7pt; }
        .print { margin: 0 0 12px; }
        .print button, .print a { font: inherit; font-size: 10pt; padding: 8px 14px; border: 1px solid #0b3d2e; background: #0b3d2e; color: #fff; border-radius: 6px; text-decoration: none; cursor: pointer; margin-right: 6px; }
        .print a.ghost { background: #fff; color: #0b3d2e; }
        @media print { .print { display: none; } .wrap { padding: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    <?php if (!$forPdf): ?>
        <div class="print"><button type="button" onclick="window.print()">Imprimir</button><?php if ($printBack !== null): ?><a class="ghost" href="<?= $this->e($printBack) ?>">Voltar</a><?php endif; ?></div>
    <?php endif; ?>

    <table class="top" role="presentation"><tr>
        <td><?php if ($logo !== ''): ?><img src="<?= $logo ?>" alt="<?= $this->e($siteName) ?>"><?php else: ?><strong><?= $this->e($siteName) ?></strong><?php endif; ?></td>
        <td class="title"><strong>LIVRO CAIXA</strong><span><?= $this->e(mb_strtoupper(mb_substr($month, 0, 1)) . mb_substr($month, 1)) ?></span></td>
    </tr></table>

    <table class="opening" role="presentation"><tr>
        <td style="width:46%"><small>Condomínio</small><strong><?= $this->e($condo['legal_name'] ?? $condo['name']) ?></strong><?php if (($condo['legal_name'] ?? null) !== null && $condo['legal_name'] !== $condo['name']): ?><span class="sub"><?= $this->e($condo['name']) ?></span><?php endif; ?></td>
        <td style="width:22%"><small>CNPJ</small><?= $this->e($condo['document'] ?? 'não informado') ?></td>
        <td style="width:16%"><small>Período</small><?= $this->e($date($book['from'])) ?> a <?= $this->e($date($book['to'])) ?></td>
        <td style="width:16%"><small>Situação</small><span class="status status--<?= $book['closed'] ? 'closed' : 'draft' ?>"><?= $book['closed'] ? 'Mês fechado' : 'Prévia' ?></span></td>
    </tr><?php if (($condo['address'] ?? null) !== null): ?><tr><td colspan="4"><small>Endereço</small><?= $this->e($condo['address']) ?></td></tr><?php endif; ?></table>
    <?php if (!$book['closed']): ?>
        <div class="alert"><strong>Prévia.</strong> A competência <?= $this->e($month) ?> ainda não foi fechada no fechamento mensal: os valores podem mudar até o fechamento.</div>
    <?php endif; ?>

    <h2>Resumo do mês</h2>
    <table class="kpi" role="presentation"><tr>
        <td><small>Saldo anterior</small><strong><?= $this->e($brl((int)$book['opening_cents'])) ?></strong></td>
        <td><small>Entradas</small><strong class="in"><?= $this->e($brl((int)$book['in_cents'])) ?></strong></td>
        <td><small>Saídas</small><strong class="out"><?= $this->e($brl((int)$book['out_cents'])) ?></strong></td>
        <td><small>Saldo final</small><strong><?= $this->e($brl((int)$book['closing_cents'])) ?></strong></td>
    </tr></table>
    <?php if ($book['reconciled']): ?>
        <div class="ok">Conferido: o saldo final do livro é igual à soma dos saldos das contas bancárias (<?= $this->e($brl((int)$book['banks_closing_cents'])) ?>).</div>
    <?php else: ?>
        <div class="alert alert--danger"><strong>Diferença de <?= $this->e($brl((int)$book['difference_cents'])) ?></strong> entre o livro e as contas bancárias (<?= $this->e($brl((int)$book['banks_closing_cents'])) ?>). Confira lançamentos pagos sem conta bancária.</div>
    <?php endif; ?>

    <?php if ($book['banks'] !== []): ?>
    <h2>Contas bancárias</h2>
    <table class="grid">
        <thead><tr><th>Conta</th><th class="n">Saldo anterior</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Saldo final</th></tr></thead>
        <tbody>
        <?php foreach ($book['banks'] as $bank): ?>
            <tr><td><?= $this->e($bank['name']) ?><?php if ((int)$bank['transfers_in_cents'] + (int)$bank['transfers_out_cents'] > 0): ?><span class="sub">Inclui transferências entre contas: +<?= $this->e($num((int)$bank['transfers_in_cents']) ?: '0,00') ?> / −<?= $this->e($num((int)$bank['transfers_out_cents']) ?: '0,00') ?></span><?php endif; ?></td>
                <td class="n"><?= $this->e($brl((int)$bank['opening_cents'])) ?></td><td class="n in"><?= $this->e($num((int)$bank['received_cents'])) ?></td>
                <td class="n out"><?= $this->e($num((int)$bank['paid_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['closing_cents'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Movimento do mês</h2>
    <?php if ($book['lines'] === []): ?>
        <p>Nenhum pagamento ou recebimento registrado em <?= $this->e($month) ?>.</p>
    <?php else: ?>
    <table class="grid">
        <thead><tr><th>Nº</th><th>Data</th><th>Histórico</th><th>Documento</th><th>Anexos</th><th class="n">Entrada</th><th class="n">Saída</th><th class="n">Saldo</th></tr></thead>
        <tbody>
            <tr class="muted"><td></td><td class="nw"><?= $this->e($date($book['from'])) ?></td><td colspan="5">Saldo anterior</td><td class="n"><?= $this->e($num((int)$book['opening_cents']) ?: '0,00') ?></td></tr>
        <?php foreach ($book['lines'] as $line): ?>
            <tr>
                <td class="nw"><?= (int)$line['seq'] ?></td>
                <td class="nw"><?= $this->e($date($line['paid_on'])) ?></td>
                <td><?= $this->e($line['description']) ?><span class="sub"><?= $this->e($line['account_code'] . ' ' . $line['account_name']) ?><?= $line['counterpart'] !== null ? ' · ' . $this->e($line['counterpart']) : '' ?><?= $line['bank_name'] !== null ? ' · ' . $this->e($line['bank_name']) : '' ?></span></td>
                <td><?= $this->e($line['document_number'] ?? '') ?></td>
                <td><?php foreach ($line['annex_refs'] as $ref): ?><span class="ref"><?= $this->e($ref) ?></span><?php endforeach; ?></td>
                <td class="n in"><?= $this->e($num((int)$line['in_cents'])) ?></td>
                <td class="n out"><?= $this->e($num((int)$line['out_cents'])) ?></td>
                <td class="n"><?= $this->e(($line['balance_cents'] < 0 ? '−' : '') . (number_format(abs((int)$line['balance_cents']) / 100, 2, ',', '.'))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5">Totais do mês</td><td class="n"><?= $this->e($num((int)$book['in_cents']) ?: '0,00') ?></td><td class="n"><?= $this->e($num((int)$book['out_cents']) ?: '0,00') ?></td><td class="n"><?= $this->e(($book['closing_cents'] < 0 ? '−' : '') . number_format(abs((int)$book['closing_cents']) / 100, 2, ',', '.')) ?></td></tr></tfoot>
    </table>
    <?php endif; ?>

    <?php if ($book['accounts'] !== []): ?>
    <h2>Resumo por conta</h2>
    <table class="grid">
        <thead><tr><th>Natureza</th><th>Código</th><th>Conta</th><th class="n">Lançamentos</th><th class="n">Total</th></tr></thead>
        <tbody>
        <?php foreach ($book['accounts'] as $account): ?>
            <tr><td><?= $account['nature'] === 'receita' ? 'Receita' : 'Despesa' ?></td><td class="nw"><?= $this->e($account['code']) ?></td><td><?= $this->e($account['name']) ?></td>
                <td class="n"><?= (int)$account['entries'] ?></td><td class="n <?= $account['nature'] === 'receita' ? 'in' : 'out' ?>"><?= $this->e($brl((int)$account['total_cents'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Documentos das despesas</h2>
    <?php if ($book['missing_proof'] === []): ?>
        <div class="ok">Todas as despesas pagas do mês têm nota fiscal, comprovante ou recibo anexado.</div>
    <?php else: ?>
        <div class="alert"><strong><?= count($book['missing_proof']) ?> despesa(s) paga(s) sem nota fiscal, comprovante ou recibo anexado:</strong></div>
        <table class="grid">
            <thead><tr><th>Nº</th><th>Data</th><th>Histórico</th><th class="n">Valor</th></tr></thead>
            <tbody><?php foreach ($book['missing_proof'] as $item): ?><tr><td><?= (int)$item['seq'] ?></td><td class="nw"><?= $this->e($date($item['paid_on'])) ?></td><td><?= $this->e($item['description']) ?></td><td class="n"><?= $this->e($brl((int)$item['amount_cents'])) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    <?php endif; ?>

    <table class="sign" role="presentation"><tr>
        <td><div class="line">Síndico(a)</div><small><?= $this->e($condo['name']) ?></small></td>
        <td><div class="line">Conselho fiscal</div><small>&nbsp;</small></td>
        <td><div class="line"><?= $this->e($siteName) ?></div><small>Administradora</small></td>
    </tr></table>

    <?php if ($annexes !== []): ?>
    <h2 style="page-break-before: always">Índice dos anexos</h2>
    <table class="grid">
        <thead><tr><th>Ref.</th><th>Lanç.</th><th>Tipo</th><th>Arquivo</th><th>Onde está</th></tr></thead>
        <tbody>
        <?php foreach ($annexes as $annex): ?>
            <tr<?= in_array($annex['placement'], ['blocked', 'missing'], true) ? ' class="muted"' : '' ?>><td><span class="ref"><?= $this->e($annex['ref']) ?></span></td><td><?= (int)$annex['line'] ?></td><td><?= $this->e($annex['kind_label']) ?></td>
                <td><?= $this->e($annex['original_name']) ?></td><td><?= $this->e($placementLabel[$annex['placement']] ?? '') ?><?php if ($annex['placement'] === 'package'): ?><span class="sub"><?= $this->e(Doc::packageName($annex)) ?></span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="foot">Gerado em <?= $this->e($generatedAt) ?> pelo Connect ERP. Regime de caixa: entram os lançamentos válidos com pagamento ou recebimento no mês. Transferências entre contas do condomínio não alteram o caixa e aparecem só no quadro das contas bancárias.</p>

    <?php foreach ($embedded as $annex): $src = $forPdf ? $imageSrc($annex) : ''; ?>
        <div class="annex">
            <h3>Anexo <?= $this->e($annex['ref']) ?> · <?= $this->e($annex['kind_label']) ?> · lançamento nº <?= (int)$annex['line'] ?></h3>
            <p><?= $this->e($annex['original_name']) ?></p>
            <?php if ($src !== ''): ?><img src="<?= $src ?>" alt="Anexo <?= $this->e($annex['ref']) ?>"><?php elseif (!$forPdf): ?><p>A imagem entra no PDF e no pacote.</p><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
