<?php
/**
 * PDF da prestação de contas (ADR 0306), do mês ou do ano, a partir do mesmo livro congelado do link público
 * (ADR 0301). Tabelas e CSS simples porque o dompdf não entende flex/grid. Cada documento tem o endereço completo no
 * link público, então quem tem o PDF clica e abre a nota, a conta ou o comprovante.
 *
 * @var array $view AccountabilityLinks::publicBook() @var string $base endereço do link @var string $logo
 * @var string $siteName @var string $generatedAt
 */
use Source\Services\Erp\CashBookDocument as Doc;

$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $d;
$annual = $view['kind'] === 'annual';
$condo = $view['condominium'];
$book = $view['book'];
$title = $annual ? 'Livro anual de ' . $view['period'] : 'Prestação de contas de ' . Doc::monthLabel((string)$view['period']);
$docUrl = fn (array $doc): string => $base . '/documento/' . (int)$doc['id'];

$renderMonth = function (array $month) use ($brl, $date, $docUrl): void { ?>
    <table class="kpis"><tr>
        <?php foreach ([['Saldo anterior', $month['totals']['opening_cents'], ''], ['Entradas', $month['totals']['in_cents'], 'in'], ['Saídas', $month['totals']['out_cents'], 'out'], ['Saldo final', $month['totals']['closing_cents'], '']] as [$label, $cents, $tone]): ?>
            <td><small><?= $this->e($label) ?></small><strong class="<?= $tone ?>"><?= $this->e($brl((int)$cents)) ?></strong></td>
        <?php endforeach; ?>
    </tr></table>
    <?php foreach ($month['sections'] as $section): if ($section['categories'] === []) { continue; } ?>
        <h3 class="section"><?= $this->e($section['label']) ?> <span class="<?= $section['nature'] === 'receita' ? 'in' : 'out' ?>"><?= $this->e($brl((int)$section['total_cents'])) ?></span></h3>
        <?php foreach ($section['categories'] as $category): ?>
            <table class="lines">
                <thead><tr class="cat"><th colspan="2"><span class="code"><?= $this->e($category['code']) ?></span> <?= $this->e($category['name']) ?></th><th class="n"><?= $this->e($brl((int)$category['total_cents'])) ?></th></tr></thead>
                <tbody>
                <?php foreach ($category['groups'] as $group): ?>
                    <tr class="grp"><td colspan="2"><?= $this->e($group['name']) ?><?php if (isset($group['summary'])): ?> <span class="muted">· <?= $this->e($group['summary']) ?></span><?php endif; ?></td><td class="n"><?= $this->e($brl((int)$group['total_cents'])) ?></td></tr>
                    <?php foreach ($group['items'] as $item): ?>
                        <tr>
                            <td class="d"><?= $this->e($date($item['paid_on'])) ?></td>
                            <td>
                                <?= $section['nature'] === 'despesa' ? 'Pagamento: ' : 'Recebimento: ' ?><?= $this->e($item['description']) ?><?php if (!empty($item['document_number'])): ?> <span class="muted">· doc. <?= $this->e((string)$item['document_number']) ?></span><?php endif; ?>
                                <?php foreach ($item['documents'] as $i => $doc): ?>
                                    <div class="doc"><span class="num"><?= $i + 1 ?></span> <a href="<?= $this->e($docUrl($doc)) ?>"><strong><?= $this->e($doc['kind_label']) ?></strong> <?= $this->e($doc['name']) ?></a></div>
                                <?php endforeach; ?>
                                <?php if ($item['documents'] === [] && $section['nature'] === 'despesa'): ?><div class="missing">Sem comprovante anexado</div><?php endif; ?>
                            </td>
                            <td class="n"><?= $this->e($brl((int)$item['amount_cents'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($month['sections'][0]['categories'] === [] && $month['sections'][1]['categories'] === []): ?>
        <p class="muted">Nenhum pagamento ou recebimento no mês.</p>
    <?php endif; ?>
    <?php if ($month['banks'] !== []): ?>
        <h3 class="section">Contas bancárias</h3>
        <table class="lines">
            <thead><tr><th>Conta</th><th class="n">Saldo anterior</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Saldo final</th></tr></thead>
            <tbody><?php foreach ($month['banks'] as $bank): ?><tr><td><?= $this->e($bank['name']) ?></td><td class="n"><?= $this->e($brl((int)$bank['opening_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['received_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['paid_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['closing_cents'])) ?></td></tr><?php endforeach; ?></tbody>
        </table>
        <p class="<?= $month['totals']['reconciled'] ? 'in' : 'warn' ?>"><?= $month['totals']['reconciled'] ? 'Conferido: o saldo final é igual à soma das contas bancárias.' : 'Atenção: o saldo final não bate com a soma das contas bancárias.' ?></p>
    <?php endif; ?>
<?php };
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title><?= $this->e($title . ' · ' . ($condo['name'] ?? '')) ?></title>
    <style>
        @page { margin: 14mm 12mm 18mm; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #121a31; font-size: 8.5pt; line-height: 1.4; }
        a { color: #066f3e; text-decoration: none; }
        .top { width: 100%; border-collapse: collapse; background: #0d2e27; border-bottom: 4px solid #c5a131; }
        .top td { padding: 12px 14px; color: #fff; vertical-align: middle; }
        .top img { height: 40px; }
        .top .tag { text-align: right; color: #f0d98a; font-size: 8pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        h1 { margin: 14px 0 2px; font-size: 16pt; color: #0d2e27; }
        h2 { margin: 18px 0 6px; font-size: 13pt; color: #0d2e27; border-bottom: 2px solid #c5a131; padding-bottom: 3px; }
        .meta { margin: 1px 0; color: #65746d; }
        .pill { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 8px; font-size: 7.5pt; font-weight: bold; background: #fbf4dd; color: #7a5f12; }
        .notice { margin: 10px 0; padding: 6px 8px; background: #fff8e8; color: #9a6400; border: 1px solid #f1dfb2; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 4px 0; margin: 8px 0 10px; }
        .kpis td { width: 25%; border: 1px solid #dfe8e3; padding: 6px 8px; }
        .kpis small { display: block; color: #65746d; font-size: 7pt; text-transform: uppercase; }
        .kpis strong { display: block; font-size: 10.5pt; color: #0d2e27; }
        h3.section { margin: 12px 0 4px; font-size: 10.5pt; color: #0d2e27; border-bottom: 1.5px solid #c5a131; padding-bottom: 2px; }
        h3.section span { float: right; }
        .lines { width: 100%; border-collapse: collapse; margin: 0 0 8px; page-break-inside: auto; }
        .lines th, .lines td { padding: 4px 6px; border-bottom: 1px solid #e6ece9; text-align: left; vertical-align: top; }
        .lines thead th { background: #f6faf8; font-size: 7.5pt; color: #65746d; }
        .lines tr.cat th { font-size: 8.5pt; color: #121a31; }
        .lines tr.grp td { font-weight: bold; color: #066f3e; background: #fbfdfc; }
        .lines td.d { width: 62px; color: #65746d; white-space: nowrap; }
        .n { text-align: right; white-space: nowrap; width: 90px; }
        .code { color: #7a5f12; font-weight: bold; }
        .doc { margin: 2px 0 0; font-size: 7.5pt; }
        .doc .num { display: inline-block; min-width: 12px; padding: 0 3px; border: 1px solid #c5a131; color: #7a5f12; font-weight: bold; text-align: center; }
        .missing { margin: 2px 0 0; color: #9a6400; font-size: 7.5pt; }
        .muted { color: #65746d; font-weight: normal; } .in { color: #12805c; } .out { color: #b42318; } .warn { color: #9a6400; }
        .month { page-break-before: always; }
        .foot { margin-top: 14px; color: #65746d; font-size: 7pt; }
    </style>
</head>
<body>
<table class="top"><tr>
    <td><?php if ($logo !== ''): ?><img src="<?= $logo ?>" alt="<?= $this->e($siteName) ?>"><?php else: ?><strong><?= $this->e($siteName) ?></strong><?php endif; ?></td>
    <td class="tag">Prestação de contas</td>
</tr></table>
<h1><?= $this->e($title) ?></h1>
<p class="meta"><strong><?= $this->e((string)($condo['name'] ?? '')) ?></strong><?php if (!empty($condo['legal_name'])): ?> · <?= $this->e((string)$condo['legal_name']) ?><?php endif; ?><?php if (!empty($condo['document'])): ?> · CNPJ <?= $this->e((string)$condo['document']) ?><?php endif; ?></p>
<?php if (!empty($condo['address'])): ?><p class="meta"><?= $this->e((string)$condo['address']) ?></p><?php endif; ?>
<p class="meta"><?= $annual ? 'Os doze meses do ano' : 'De ' . $this->e($date((string)$book['from'])) . ' a ' . $this->e($date((string)$book['to'])) ?> · regime de caixa (vale a data do pagamento) · <?= (int)$book['documents'] ?> documento(s) anexado(s)</p>
<span class="pill">Versão <?= (int)$view['version'] ?> · gerada em <?= $this->e($date(substr((string)$view['generated_at'], 0, 10))) ?></span>
<p class="meta">Versão on-line com os documentos: <a href="<?= $this->e($base) ?>"><?= $this->e($base) ?></a></p>
<?php if ($view['under_review']): ?>
    <p class="notice"><?= $annual ? 'Algum mês deste ano foi reaberto para correção.' : 'Este mês foi reaberto para correção.' ?> Os valores são os da versão <?= (int)$view['version'] ?>.</p>
<?php endif; ?>

<?php if ($annual): ?>
    <table class="kpis"><tr>
        <?php foreach ([['Saldo no início do ano', $book['totals']['opening_cents'], ''], ['Entradas no ano', $book['totals']['in_cents'], 'in'], ['Saídas no ano', $book['totals']['out_cents'], 'out'], ['Saldo no fim do ano', $book['totals']['closing_cents'], '']] as [$label, $cents, $tone]): ?>
            <td><small><?= $this->e($label) ?></small><strong class="<?= $tone ?>"><?= $this->e($brl((int)$cents)) ?></strong></td>
        <?php endforeach; ?>
    </tr></table>
    <h2>Resumo do ano por categoria</h2>
    <table class="lines">
        <thead><tr><th>Categoria</th><th class="n">Lançamentos</th><th class="n">Total no ano</th></tr></thead>
        <tbody><?php foreach ($book['categories'] as $category): ?><tr><td><span class="code"><?= $this->e($category['code']) ?></span> <?= $this->e($category['name']) ?></td><td class="n"><?= (int)$category['entries'] ?></td><td class="n <?= $category['nature'] === 'receita' ? 'in' : 'out' ?>"><?= $this->e($brl((int)$category['total_cents'])) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <h2>Mês a mês</h2>
    <table class="lines">
        <thead><tr><th>Mês</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Saldo final</th></tr></thead>
        <tbody><?php foreach ($book['months'] as $month): ?><tr><td><?= $this->e(ucfirst(Doc::monthLabel((string)$month['competence']))) ?></td><td class="n in"><?= $this->e($brl((int)$month['totals']['in_cents'])) ?></td><td class="n out"><?= $this->e($brl((int)$month['totals']['out_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$month['totals']['closing_cents'])) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php foreach ($book['months'] as $month): ?>
        <div class="month">
            <h2><?= $this->e(ucfirst(Doc::monthLabel((string)$month['competence']))) ?></h2>
            <?php $renderMonth($month); ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <?php $renderMonth($book); ?>
<?php endif; ?>
<p class="foot">Prestação de contas preparada pela administração com o sistema da <?= $this->e($siteName) ?>. PDF gerado em <?= $this->e($generatedAt) ?>. Os documentos são os anexados pela administração em cada lançamento; clique no nome para abrir.</p>
</body>
</html>
