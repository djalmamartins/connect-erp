<?php
/**
 * Carta de cobrança impressa (ADR 0317): folha A4 dobrada em três. Página 1 = parte externa (fundo floral cinza,
 * aba de cabeça para baixo, destinatário, Correio e remetente); página 2 = parte interna (demonstrativos, avisos
 * e ficha do boleto). Nunca passa de duas folhas: o resumo encolhe (BillingLetter::fit). Medidas em mm, posições absolutas e tabelas,
 * porque o dompdf não entende flex/grid. Bloco com posição absoluta: a largura NÃO inclui o padding (dompdf).
 *
 * @var array $letter BillingLetter::build() @var bool $forPdf @var array $issuer @var string $logo @var string $floral
 * @var string $barcode @var string $qr @var string $appLink
 */
$e = fn (?string $t): string => $this->e((string)$t);
$num = static fn (int $cents): string => ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', '.');
$brl = static fn (int $cents): string => 'R$ ' . $num($cents);
$date = static fn (?string $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '';
$c = $letter['charge'];
$a = $letter['address'];
$payer = $letter['payer'];
$st = $letter['statement'];
$sum = $st['summary'];
$fit = $st['fit'];
$recipient = $payer['name'] ?? ('Unidade ' . $letter['unit']['label']);
$addressLines = array_values(array_filter([
    trim($a['line1'] . ($a['unit'] !== '' ? ' - ' . $a['unit'] : '')),
    $a['district'],
    trim($a['zip'] . ($a['zip'] !== '' && $a['city'] !== '' ? ' - ' : '') . $a['city'] . ($a['state'] !== '' ? ' - ' . $a['state'] : '')),
]));
$issuerParts = array_map('trim', explode(' - ', (string)$issuer['address']));
$issuerLines = array_values(array_filter([implode(' - ', array_slice($issuerParts, 0, 2)), $issuerParts[2] ?? '', implode(' - ', array_slice($issuerParts, 3))]));
$notice = $letter['notice'];
$tiles = $floral !== '' ? range(0, 34) : [];
$cut = static fn (string $t, int $n): string => mb_strimwidth($t, 0, $n, '…');
$bankTotals = ['opening_cents' => 0, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 0];
foreach ($st['banks'] as $b) {
    foreach ($bankTotals as $k => $_) {
        $bankTotals[$k] += (int)($b[$k] ?? 0);
    }
}
// Receitas ou despesas resumidas no nível que coube (quotas, accounts, groups, totals).
$section = function (string $title, array $sec, string $level, string $empty) use ($e, $num, $cut): string {
    $html = '<tr class="sec"><td>' . $e($title) . '</td><td class="n">' . $num($sec['total_cents']) . '</td></tr>';
    if ($sec['groups'] === []) {
        return $html . '<tr><td colspan="2" class="muted">' . $e($empty) . '</td></tr>';
    }
    if ($level === 'totals') {
        return $html;
    }
    foreach ($sec['groups'] as $g) {
        $html .= '<tr class="grp"><td>' . $e($cut((string)$g['name'], 52)) . '</td><td class="n">' . ($level === 'groups' ? $num($g['total_cents']) : '') . '</td></tr>';
        if ($level === 'groups') {
            continue;
        }
        foreach ($g['accounts'] as $acc) {
            $html .= '<tr class="acc"><td>' . $e($cut((string)$acc['name'], 50)) . '</td><td class="n">' . $num($acc['total_cents']) . '</td></tr>';
            if ($level === 'quotas') {
                foreach ($acc['quotas'] as $q) {
                    $html .= '<tr class="it"><td>' . $q['count'] . ($q['count'] > 1 ? ' cotas' : ' cota') . ' de R$ ' . $num($q['amount_cents']) . '</td><td class="n">' . $num($q['total_cents']) . '</td></tr>';
                }
            }
        }
    }
    return $html;
};
$blocks = function (array $blocks) use ($e): string {
    $html = '';
    $list = false;
    foreach ($blocks as $b) {
        if ($b['kind'] !== 'item' && $list) {
            $html .= '</ul>';
            $list = false;
        }
        $html .= match ($b['kind']) {
            'title' => '<p class="fb-title">' . $e($b['text']) . '</p>',
            'highlight' => '<p class="fb-hi">' . $e($b['text']) . '</p>',
            'small' => '<p class="fb-small">' . $e($b['text']) . '</p>',
            'item' => ($list ? '' : '<ul class="fb-list">') . '<li>' . $e($b['text']) . '</li>',
            default => '<p class="fb-text">' . $e($b['text']) . '</p>',
        };
        $list = $b['kind'] === 'item';
    }
    return $html . ($list ? '</ul>' : '');
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Carta de cobrança · <?= $e($letter['condominium']['name']) ?> · <?= $e($letter['unit']['label']) ?> · <?= $e($letter['competence_label']) ?></title>
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #23282b; font-size: 7.5pt; line-height: 1.35; }
        .page { position: relative; width: 210mm; height: 296.5mm; overflow: hidden; }
        .break { page-break-before: always; }
        .tile { position: absolute; width: 42mm; height: 42mm; }
        .gold { color: #a8861f; }
        .muted { color: #6b7275; }
        .n { text-align: right; white-space: nowrap; }
        .fold { position: absolute; left: 0; width: 6mm; border-top: .3mm dashed #9b9b9b; }
        .fold--r { left: auto; right: 0; }

        /* Parte externa */
        /* No dompdf, o elemento girado não pode ter padding nem margem (o centro do giro sai do lugar): o espaço interno fica no .flap__in. */
        .flap { position: absolute; left: 10mm; top: 8mm; width: 190mm; height: 83mm; transform: rotate(180deg); background: #fff; border: .3mm solid #e1ddd2; border-radius: 3mm; }
        .flap__in { padding: 6mm 7mm; }
        .flap h2 { margin: 0 0 3mm; font-size: 11pt; color: #a8861f; }
        .flap table { width: 100%; border-collapse: collapse; }
        .flap td { vertical-align: top; padding: 0; }
        .fb-title { margin: 1.6mm 0 0; font-size: 8.5pt; font-weight: bold; }
        .fb-hi { margin: 0; font-size: 11pt; font-weight: bold; color: #a8861f; }
        .fb-small { margin: 0 0 .6mm; font-size: 6.5pt; color: #6b7275; }
        .fb-text { margin: 1mm 0 0; font-size: 7.5pt; color: #4a5155; }
        .fb-list { margin: 1mm 0 1mm 4mm; padding: 0; font-size: 7.5pt; }
        .fb-list li { margin: 0 0 .8mm; }
        .app li { margin: 0 0 1mm; }
        .app ul { margin: 1mm 0 2mm 4mm; padding: 0; }
        .flap .qr { width: 24mm; height: 24mm; }
        .brand { position: absolute; left: 16mm; top: 108mm; width: 62mm; }
        .brand-qr { position: absolute; right: 16mm; top: 104mm; width: 26mm; height: 26mm; background: #fff; padding: 1.5mm; }
        .brand-qr img { width: 23mm; height: 23mm; }
        .box { position: absolute; background: #fff; border: .3mm solid #9ea3a6; padding: 3mm 4mm; }
        .box h3 { margin: 0 0 1.2mm; font-size: 8.5pt; letter-spacing: .3mm; }
        .box p { margin: 0; font-size: 8.5pt; line-height: 1.45; text-transform: uppercase; }
        .to { left: 30mm; top: 146mm; width: 112mm; }
        .post { left: 10mm; top: 206mm; width: 182mm; padding: 3mm 4mm 2mm; }
        .post table { width: 100%; border-collapse: collapse; }
        .post td { font-size: 6.5pt; font-style: italic; padding: 1.3mm 1mm; vertical-align: top; }
        .chk { width: 3.6mm; height: 3.6mm; border: .3mm solid #23282b; display: inline-block; }
        .from { left: 30mm; top: 254mm; width: 112mm; }

        /* Parte interna */
        .wrap { position: absolute; left: 10mm; top: 8mm; width: 190mm; }
        .head { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding: 0; }
        .head .logo img { width: 52mm; }
        .head .meta { font-size: 7.5pt; line-height: 1.5; padding-left: 4mm; }
        .head .issuer { text-align: right; font-size: 7pt; line-height: 1.45; }
        .who { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        .who td { vertical-align: top; padding: 0; }
        .who .addr { font-size: 8pt; line-height: 1.45; padding-top: 2mm; }
        .pill { background: #eeece6; border-radius: 1.5mm; text-align: center; padding: 1.6mm 2mm; margin-bottom: 1.6mm; }
        .pill small { display: block; font-size: 6.5pt; color: #6b7275; }
        .pill strong { font-size: 11pt; }
        .pill--gold { background: #a8861f; color: #fff; }
        .pill--gold small { color: #f4ecd2; }
        .cols { width: 100%; border-collapse: collapse; margin-top: 3mm; border-top: .4mm solid #a8861f; }
        .colsbox { overflow: hidden; }
        .cols > tbody > tr > td { vertical-align: top; padding: 2mm 0 0; }
        .cols > tbody > tr > td.l { width: 54%; padding-right: 3mm; border-right: .2mm solid #d6d3cb; }
        .cols > tbody > tr > td.r { padding-left: 3mm; }
        h4 { margin: 0 0 1.2mm; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .2mm; color: #a8861f; }
        table.t { width: 100%; border-collapse: collapse; margin-bottom: 2.5mm; font-size: 6.8pt; }
        .t th { text-align: left; font-weight: bold; border-bottom: .2mm solid #9ea3a6; padding: .6mm .8mm; }
        .t td { padding: .2mm .8mm; vertical-align: top; }
        .t .sec td { font-weight: bold; background: #f1efe9; padding-top: .8mm; text-transform: uppercase; font-size: 6.6pt; }
        .t .grp td { font-weight: bold; padding-left: 2mm; }
        .t .acc td:first-child { padding-left: 4mm; }
        .t .it td { color: #6b7275; }
        .t .it td:first-child { padding-left: 7mm; }
        .t .gt td { padding-left: 2mm; border-top: .1mm solid #e4e1da; }
        .t .tot td { font-weight: bold; border-top: .2mm solid #9ea3a6; }
        .t .sum td { font-weight: bold; border-top: .2mm solid #9ea3a6; }
        .delq { background: #f1efe9; padding: 1.5mm 2mm; font-size: 6.8pt; }
        .delq strong { display: block; font-size: 7.5pt; margin-bottom: .5mm; }
        .notice { position: absolute; left: 10mm; width: 182mm; top: 189mm; height: 14mm; border: .3mm dashed #c9c6bf; border-radius: 2mm; padding: 2.5mm 4mm; overflow: hidden; }
        .notice h5 { margin: 0 0 1mm; font-size: 8.5pt; color: #a8861f; }
        .notice p { margin: 0; font-size: 7.3pt; }
        .notice img { max-height: 17mm; float: right; margin-left: 3mm; }
        .access { position: absolute; left: 10mm; width: 190mm; top: 210mm; text-align: center; font-size: 7pt; }
        .cut { position: absolute; left: 0; width: 210mm; top: 215mm; border-top: .3mm dashed #9b9b9b; }
        .slip { position: absolute; left: 10mm; width: 190mm; top: 218mm; }
        .slip table { width: 100%; border-collapse: collapse; }
        .slip td { border: .2mm solid #6b7275; padding: .8mm 1.6mm; vertical-align: top; font-size: 8pt; }
        .slip td small { display: block; font-size: 5.8pt; text-transform: uppercase; color: #6b7275; }
        .slip .bank td { border: 0; border-bottom: .5mm solid #23282b; padding: 0 1.6mm 1mm; vertical-align: bottom; }
        .slip .bank .code { font-size: 12pt; font-weight: bold; border-left: .5mm solid #23282b; border-right: .5mm solid #23282b; text-align: center; width: 18mm; }
        .slip .bank .line { font-size: 9.5pt; font-weight: bold; text-align: right; letter-spacing: .1mm; }
        .slip .v { text-align: right; }
        .bars { height: 13mm; }
        .bars img { width: 103mm; height: 13mm; }
        .auth { float: right; font-size: 6pt; text-transform: uppercase; color: #6b7275; margin-top: 1mm; }
        .pay { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        .pay td { border: 0; padding: 0; vertical-align: middle; }
        .pay__bars { width: 108mm; }
        .pay__pix { width: 25mm; text-align: center; }
        .pay__pix img { width: 23mm; height: 23mm; }
        .pay__pixtext { font-size: 7pt; padding-left: 2mm; }
        .pay__pixtext strong { font-size: 8.5pt; color: #a8861f; }
    </style>
</head>
<body>

<!-- Página 1: parte externa (fundo floral cinza, igual ao do bloco 10) -->
<div class="page">
    <?php foreach ($tiles as $i): ?>
        <img class="tile" src="<?= $floral ?>" alt="" style="left: <?= ($i % 5) * 42 ?>mm; top: <?= intdiv($i, 5) * 42 ?>mm;">
    <?php endforeach; ?>
    <div class="fold" style="top: 99mm"></div><div class="fold fold--r" style="top: 99mm"></div>
    <div class="fold" style="top: 198mm"></div><div class="fold fold--r" style="top: 198mm"></div>

    <div class="flap"><div class="flap__in">
        <table><tr>
            <td style="width: 54%; padding-right: 5mm;">
                <h2><?= $e($letter['flap']['left']['title']) ?></h2>
                <?= $blocks($letter['flap']['left']['blocks']) ?>
            </td>
            <td style="border-left: .2mm solid #e1ddd2; padding-left: 5mm;">
                <h2><?= $e($letter['flap']['right']['title']) ?></h2>
                <?= $blocks($letter['flap']['right']['blocks']) ?>
                <table style="margin-top: 1.5mm;"><tr>
                    <td style="width: 27mm;"><?php if ($qr !== ''): ?><img class="qr" src="<?= $qr ?>" alt="QR code do aplicativo"><?php endif; ?></td>
                    <td style="vertical-align: middle; font-size: 7pt;"><strong>Aponte a câmera para o QR code</strong><br><?= $e($appLink) ?></td>
                </tr></table>
            </td>
        </tr></table>
    </div></div>

    <?php if ($logo !== ''): ?><img class="brand" src="<?= $logo ?>" alt="Connect Condomínios"><?php endif; ?>
    <?php if ($qr !== ''): ?><div class="brand-qr"><img src="<?= $qr ?>" alt="QR code do aplicativo"></div><?php endif; ?>

    <div class="box to">
        <h3>DESTINATÁRIO:</h3>
        <p><?= $e($recipient) ?></p>
        <?php foreach ($addressLines as $line): ?><p><?= $e($line) ?></p><?php endforeach; ?>
    </div>

    <div class="box post">
        <h3>Para uso do Correio:</h3>
        <table>
            <tr><td><span class="chk"></span> Mudou-se</td><td><span class="chk"></span> Recusado</td><td><span class="chk"></span> Falecido</td><td><span class="chk"></span> Não procurado</td><td rowspan="2">Reintegrado ao Serviço Postal em ____/____/____</td></tr>
            <tr><td><span class="chk"></span> Desconhecido</td><td><span class="chk"></span> Ausente</td><td colspan="2"><span class="chk"></span> Endereço insuficiente</td></tr>
            <tr><td colspan="2"><span class="chk"></span> Informação fornecida pelo porteiro/síndico</td><td colspan="2"><span class="chk"></span> Não existe o número indicado</td><td>_____________________________<br>Responsável</td></tr>
        </table>
    </div>

    <div class="box from">
        <h3>REMETENTE:</h3>
        <p><?= $e($issuer['name']) ?></p>
        <?php foreach ($issuerLines as $line): ?><p><?= $e($line) ?></p><?php endforeach; ?>
    </div>
</div>

<!-- Página 2: parte interna -->
<div class="page break">
<div class="wrap">
    <table class="head"><tr>
        <td class="logo" style="width: 56mm;"><?php if ($logo !== ''): ?><img src="<?= $logo ?>" alt="Connect Condomínios"><?php endif; ?></td>
        <td class="meta">
            <strong><?= $e($letter['condominium']['name']) ?></strong><br>
            Nº da conta: <?= $e($c['our_number']) ?><br>
            Mês de referência: <?= $e(ucfirst($letter['competence_label'])) ?><br>
            Período: <?= $e($date($letter['period']['from'])) ?> a <?= $e($date($letter['period']['to'])) ?><br>
            Data de emissão: <?= $e($date($c['issued_on'])) ?>
        </td>
        <td class="issuer">
            <strong><?= $e($issuer['name']) ?></strong><br>
            <?php foreach ($issuerLines as $line): ?><?= $e($line) ?><br><?php endforeach; ?>
            <?= $e($issuer['site']) ?>
        </td>
    </tr></table>

    <table class="who"><tr>
        <td class="addr">
            <strong><?= $e($recipient) ?></strong><br>
            <?php foreach ($addressLines as $line): ?><?= $e($line) ?><br><?php endforeach; ?>
        </td>
        <td style="width: 46mm;">
            <div class="pill"><small>Vencimento</small><strong><?= $e($date($c['due_on'])) ?></strong></div>
            <div class="pill pill--gold"><small>Valor a pagar</small><strong><?= $e($brl($c['amount_cents'])) ?></strong></div>
        </td>
    </tr></table>

    <div class="colsbox" style="height: <?= is_array($notice) ? 104 : 122 ?>mm;">
    <table class="cols"><tr>
        <td class="l">
            <h4>Composição do boleto</h4>
            <table class="t">
                <tr><th>Item</th><th class="n">Condomínio</th><th class="n">Sua unidade</th></tr>
                <?php foreach ($letter['rateio'] as $r): ?>
                    <tr><td><?= $e($r['label']) ?></td><td class="n"><?= $r['condominium_cents'] !== null ? $num($r['condominium_cents']) : '—' ?></td><td class="n"><?= $num($r['unit_cents']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="sum"><td>Total do boleto</td><td></td><td class="n"><?= $num($c['amount_cents']) ?></td></tr>
            </table>
            <h4>Prestação de contas de <?= $e($st['label']) ?></h4>
            <table class="t"><?= $section('Receitas do mês', $sum['receita'], $fit['receita'], 'Nenhuma receita recebida no mês.') ?></table>
            <table class="t"><?= $section('Despesas do mês', $sum['despesa'], $fit['despesa'], 'Nenhuma despesa paga no mês.') ?></table>
            <table class="t">
                <tr class="sum"><td>Saldo do mês (receitas − despesas)</td><td class="n"><?= $num($sum['result_cents']) ?></td></tr>
            </table>
        </td>
        <td class="r">
            <h4>Contas bancárias</h4>
            <table class="t">
                <tr><th>Conta</th><th class="n">Anterior</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Atual</th></tr>
                <?php foreach ($st['banks'] as $b): ?>
                    <tr><td><?= $e($cut((string)$b['name'], 15)) ?></td><td class="n"><?= $num((int)$b['opening_cents']) ?></td><td class="n"><?= $num((int)$b['received_cents']) ?></td><td class="n"><?= $num((int)$b['paid_cents']) ?></td><td class="n"><?= $num((int)$b['closing_cents']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="sum"><td>Total</td><td class="n"><?= $num($bankTotals['opening_cents']) ?></td><td class="n"><?= $num($bankTotals['received_cents']) ?></td><td class="n"><?= $num($bankTotals['paid_cents']) ?></td><td class="n"><?= $num($bankTotals['closing_cents']) ?></td></tr>
            </table>
            <h4>Fundos</h4>
            <table class="t">
                <?php if ($st['funds']['accounts'] === []): ?>
                    <tr><td class="muted">Nenhum fundo com movimento.</td></tr>
                <?php else: ?>
                    <tr><th>Fundo</th><th class="n">Anterior</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Atual</th></tr>
                    <?php foreach ($st['funds']['accounts'] as $f): ?>
                        <tr><td><?= $e($cut((string)$f['name'], 15)) ?></td><td class="n"><?= $num((int)$f['opening_cents']) ?></td><td class="n"><?= $num((int)$f['in_cents']) ?></td><td class="n"><?= $num((int)$f['out_cents']) ?></td><td class="n"><?= $num((int)$f['closing_cents']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (count($st['funds']['accounts']) > 1): ?>
                        <tr class="sum"><td>Total</td><td class="n"><?= $num($st['funds']['totals']['opening_cents']) ?></td><td class="n"><?= $num($st['funds']['totals']['in_cents']) ?></td><td class="n"><?= $num($st['funds']['totals']['out_cents']) ?></td><td class="n"><?= $num($st['funds']['totals']['closing_cents']) ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
            </table>
            <div class="delq">
                <strong>Inadimplência</strong>
                <?php if ($letter['delinquency']['units'] > 0): ?>
                    Em <?= $e($date($letter['delinquency']['as_of'])) ?>: <?= $e($brl($letter['delinquency']['amount_cents'])) ?> em aberto, referente a <?= (int)$letter['delinquency']['units'] ?> unidade<?= $letter['delinquency']['units'] > 1 ? 's' : '' ?>.
                <?php else: ?>
                    Em <?= $e($date($letter['delinquency']['as_of'])) ?>: nenhuma unidade em atraso.
                <?php endif; ?>
            </div>
        </td>
    </tr></table>
    </div>
</div>

    <?php if (is_array($notice)): ?>
    <div class="notice">
        <?php if (!empty($notice['image'])): ?><img src="<?= $e($notice['image']) ?>" alt=""><?php endif; ?>
        <?php if (trim((string)($notice['title'] ?? '')) !== ''): ?><h5><?= $e($notice['title']) ?></h5><?php endif; ?>
        <p><?= nl2br($e($notice['body'] ?? '')) ?></p>
    </div>
    <?php endif; ?>
    <div class="access">Acesse seus boletos, a prestação de contas e os comunicados em <strong><?= $e($appLink) ?></strong>.</div>
    <div class="cut"></div>

    <div class="slip">
        <table>
            <tr class="bank"><td style="width: 32mm; font-size: 11pt; font-weight: bold;"><?= $e($c['bank_name']) ?></td><td class="code"><?= $e($c['bank_code']) ?></td><td class="line" colspan="2"><?= $e($c['digitable_line']) ?></td></tr>
            <tr><td colspan="3"><small>Beneficiário</small><?= $e($letter['condominium']['legal_name'] ?: $letter['condominium']['name']) ?><?= $letter['condominium']['document'] !== '' ? ' · CNPJ ' . $e($letter['condominium']['document']) : '' ?></td>
                <td style="width: 42mm;" class="v"><small>Nosso número</small><?= $e($c['our_number']) ?></td></tr>
            <tr><td colspan="2"><small>Unidade</small><?= $e($letter['unit']['label']) ?></td>
                <td><small>Pagador</small><?= $e($recipient) ?><?= !empty($payer['document']) ? ' · ' . $e($payer['document']) : '' ?></td>
                <td class="v"><small>Vencimento</small><strong><?= $e($date($c['due_on'])) ?></strong></td></tr>
            <tr><td colspan="3"><small>Instruções</small><?php foreach ($letter['instructions'] as $line): ?><?= $e($line) ?><br><?php endforeach; ?></td>
                <td class="v"><small>Valor do documento</small><strong><?= $e($brl($c['amount_cents'])) ?></strong></td></tr>
        </table>
        <span class="auth">Autenticação mecânica</span>
        <table class="pay"><tr>
            <td class="pay__bars"><div class="bars"><?php if ($barcode !== ''): ?><img src="<?= $barcode ?>" alt="Código de barras"><?php endif; ?></div></td>
            <?php if ($pixQr !== ''): ?>
            <td class="pay__pix"><img src="<?= $pixQr ?>" alt="QR code do Pix"></td>
            <td class="pay__pixtext"><strong>Pague com Pix</strong><br>Abra o app do seu banco, escolha Pix e leia o QR code. O pagamento cai na hora.</td>
            <?php endif; ?>
        </tr></table>
    </div>
</div>

</body>
</html>
