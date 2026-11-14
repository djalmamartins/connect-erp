<?php
/**
 * Link público da prestação de contas (ADR 0301), sem login. Página própria (sem o layout do ERP), com a identidade da
 * Connect: faixa verde-escura da barra lateral, logo dourada e detalhes em dourado. CSS inline e nenhum recurso de fora
 * (a CSP só permite imagens em data: e o script do botão de imprimir com nonce).
 *
 * @var int $status 200, 404, 429 ou 503 @var ?array $view AccountabilityLinks::publicView() @var string $base endereço do link
 * @var string $nonce @var string $logo @var string $siteName
 */
use Source\Services\Erp\CashBookDocument as Doc;

$brl = static fn (int $cents): string => ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (string $d): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $d;
$annual = $view !== null && $view['kind'] === 'annual';
$condo = $view['condominium'] ?? [];
$title = $view === null ? 'Prestação de contas' : ($annual ? 'Livro anual de ' . $view['period'] : 'Prestação de contas de ' . Doc::monthLabel((string)$view['period']));
$docUrl = fn (array $doc): string => $base . '/documento/' . (int)$doc['id'];

/** Um mês agrupado (o mensal e cada mês do anual). */
$renderMonth = function (array $month) use ($brl, $date, $docUrl): void { ?>
    <div class="kpis">
        <?php foreach ([['Saldo anterior', $month['totals']['opening_cents'], ''], ['Entradas', $month['totals']['in_cents'], 'in'], ['Saídas', $month['totals']['out_cents'], 'out'], ['Saldo final', $month['totals']['closing_cents'], '']] as [$label, $cents, $tone]): ?>
            <div class="kpi"><small><?= $this->e($label) ?></small><strong class="<?= $tone ?>"><?= $this->e($brl((int)$cents)) ?></strong></div>
        <?php endforeach; ?>
    </div>
    <?php foreach ($month['sections'] as $section): if ($section['categories'] === []) { continue; } ?>
        <section class="section" aria-label="<?= $this->e($section['label']) ?>">
            <div class="section__head"><h3><?= $this->e($section['label']) ?></h3><span class="<?= $section['nature'] === 'receita' ? 'in' : 'out' ?>"><?= $this->e($brl((int)$section['total_cents'])) ?></span></div>
            <?php foreach ($section['categories'] as $category): ?>
                <article class="category">
                    <header class="category__head"><div><span class="code"><?= $this->e($category['code']) ?></span> <strong><?= $this->e($category['name']) ?></strong></div><span class="total"><?= $this->e($brl((int)$category['total_cents'])) ?></span></header>
                    <?php foreach ($category['groups'] as $group): ?>
                        <div class="group">
                            <div class="group__head"><span class="supplier"><?= $this->e($group['name']) ?></span><span><?= $this->e($brl((int)$group['total_cents'])) ?></span></div>
                            <?php if (isset($group['summary'])): ?>
                                <p class="muted small"><?= $this->e($group['summary']) ?> (o valor de cada unidade não aparece aqui).</p>
                            <?php endif; ?>
                            <?php foreach ($group['items'] as $item): ?>
                                <div class="item">
                                    <div class="item__row">
                                        <span class="item__date"><?= $this->e($date($item['paid_on'])) ?></span>
                                        <span class="item__desc"><?= $section['nature'] === 'despesa' ? 'Pagamento: ' : 'Recebimento: ' ?><?= $this->e($item['description']) ?><?php if (!empty($item['document_number'])): ?> <span class="muted">· doc. <?= $this->e((string)$item['document_number']) ?></span><?php endif; ?></span>
                                        <span class="item__value"><?= $this->e($brl((int)$item['amount_cents'])) ?></span>
                                    </div>
                                    <?php if ($item['documents'] !== []): ?>
                                        <ol class="docs">
                                            <?php foreach ($item['documents'] as $doc): ?>
                                                <li><a href="<?= $this->e($docUrl($doc)) ?>" target="_blank" rel="noopener noreferrer"><span class="docs__kind"><?= $this->e($doc['kind_label']) ?></span> <span class="docs__name"><?= $this->e($doc['name']) ?></span></a></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php elseif ($section['nature'] === 'despesa'): ?>
                                        <p class="missing">Sem comprovante anexado</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($month['sections'][0]['categories'] === [] && $month['sections'][1]['categories'] === []): ?>
        <p class="muted">Nenhum pagamento ou recebimento no mês.</p>
    <?php endif; ?>
    <?php if ($month['banks'] !== []): ?>
        <section class="section" aria-label="Contas bancárias">
            <div class="section__head"><h3>Contas bancárias</h3></div>
            <div class="table-wrap"><table>
                <thead><tr><th>Conta</th><th class="n">Saldo anterior</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Saldo final</th></tr></thead>
                <tbody><?php foreach ($month['banks'] as $bank): ?><tr><td><?= $this->e($bank['name']) ?></td><td class="n"><?= $this->e($brl((int)$bank['opening_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['received_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['paid_cents'])) ?></td><td class="n"><?= $this->e($brl((int)$bank['closing_cents'])) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="small <?= $month['totals']['reconciled'] ? 'ok' : 'warn' ?>"><?= $month['totals']['reconciled'] ? 'Conferido: o saldo final é igual à soma das contas bancárias.' : 'Atenção: o saldo final não bate com a soma das contas bancárias. A administração está conferindo.' ?></p>
        </section>
    <?php endif; ?>
<?php };
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= $this->e($title . ($condo !== [] ? ' · ' . ($condo['name'] ?? '') : '')) ?></title>
    <style>
        :root { --green: #0d2e27; --brand: #07884c; --brand-ink: #066f3e; --gold: #c5a131; --gold-ink: #7a5f12; --gold-soft: #fbf4dd;
            --canvas: #eef8f2; --surface: #fff; --line: #dfe8e3; --text: #121a31; --muted: #65746d; --in: #12805c; --out: #b42318; --warn: #9a6400; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--text); font: 15px/1.5 Inter, "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, Arial, sans-serif; }
        a { color: var(--brand-ink); }
        .top { background: var(--green); border-bottom: 4px solid var(--gold); color: #fff; }
        .top__in { max-width: 980px; margin: 0 auto; padding: 18px 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .top img { height: 40px; width: auto; display: block; }
        .top__tag { color: #f0d98a; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        main { max-width: 980px; margin: 0 auto; padding: 24px 16px 48px; }
        .hero { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 16px; }
        .hero h1 { margin: 0 0 4px; font-size: 24px; line-height: 1.25; color: var(--green); }
        .hero p { margin: 2px 0; color: var(--muted); }
        .pill { display: inline-block; margin-top: 10px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--gold-soft); color: var(--gold-ink); }
        .notice { margin: 0 0 16px; padding: 12px 14px; border-radius: 10px; background: #fff8e8; color: var(--warn); border: 1px solid #f1dfb2; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .btn { appearance: none; border: 1px solid var(--line); background: var(--surface); color: var(--green); border-radius: 8px; padding: 8px 14px; font: inherit; font-weight: 600; cursor: pointer; }
        .btn--primary { background: var(--green); color: #fff; border-color: var(--green); box-shadow: inset 0 -3px 0 var(--gold); }
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 0 0 16px; }
        .kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 12px; }
        .kpi small { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .kpi strong { display: block; font-size: 18px; color: var(--green); white-space: nowrap; }
        .in { color: var(--in) !important; } .out { color: var(--out) !important; }
        .section { margin: 0 0 18px; }
        .section__head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; border-bottom: 2px solid var(--gold); padding-bottom: 4px; margin-bottom: 10px; }
        .section__head h3, .section h2 { margin: 0; font-size: 17px; color: var(--green); }
        .section__head span { font-weight: 700; white-space: nowrap; }
        .category { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 10px; overflow: hidden; }
        .category__head { display: flex; justify-content: space-between; gap: 12px; padding: 10px 14px; background: #f6faf8; border-bottom: 1px solid var(--line); }
        .category__head .total { font-weight: 700; white-space: nowrap; }
        .code { color: var(--gold-ink); font-weight: 700; font-size: 13px; }
        .group { padding: 10px 14px; border-top: 1px solid var(--line); }
        .group:first-of-type { border-top: 0; }
        .group__head { display: flex; justify-content: space-between; gap: 12px; font-weight: 600; margin-bottom: 4px; }
        .supplier { color: var(--brand-ink); }
        .item { padding: 6px 0 6px 12px; border-left: 3px solid var(--line); margin: 6px 0; }
        .item__row { display: grid; grid-template-columns: 90px 1fr auto; gap: 10px; align-items: baseline; }
        .item__date { color: var(--muted); font-size: 13px; white-space: nowrap; }
        .item__value { white-space: nowrap; font-weight: 600; }
        .docs { margin: 6px 0 0; padding: 0 0 0 100px; list-style: none; counter-reset: doc; }
        .docs li { counter-increment: doc; margin: 3px 0; }
        .docs li::before { content: counter(doc); display: inline-block; min-width: 20px; margin-right: 6px; padding: 0 5px; border: 1px solid var(--gold); border-radius: 4px; color: var(--gold-ink); font-size: 12px; font-weight: 700; text-align: center; }
        .docs a { text-decoration: none; }
        .docs__kind { font-weight: 600; }
        .docs__name { color: var(--muted); font-size: 13px; overflow-wrap: anywhere; }
        .missing { margin: 4px 0 0 100px; color: var(--warn); font-size: 13px; }
        .muted { color: var(--muted); } .small { font-size: 13px; } .ok { color: var(--in); } .warn { color: var(--warn); }
        .table-wrap { overflow-x: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 8px 12px; border-bottom: 1px solid var(--line); text-align: left; }
        th { background: #f6faf8; font-size: 12px; text-transform: uppercase; color: var(--muted); }
        .n { text-align: right; white-space: nowrap; }
        details.month { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 10px; }
        details.month > summary { cursor: pointer; padding: 12px 14px; font-weight: 700; color: var(--green); display: flex; justify-content: space-between; gap: 12px; }
        details.month > div { padding: 0 14px 14px; }
        .empty { text-align: center; padding: 48px 16px; }
        footer { max-width: 980px; margin: 0 auto; padding: 0 16px 32px; color: var(--muted); font-size: 12px; }
        @media (max-width: 640px) {
            .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .item__row { grid-template-columns: 1fr auto; }
            .item__date { grid-column: 1 / -1; }
            .docs, .missing { padding-left: 0; margin-left: 0; }
            .hero h1 { font-size: 20px; }
        }
        @media print {
            body { background: #fff; font-size: 11px; }
            .actions { display: none; }
            .category, details.month, .kpi, .hero { break-inside: avoid-page; }
            .top { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<header class="top"><div class="top__in">
    <?php if ($logo !== ''): ?><img src="<?= $this->e($logo) ?>" alt="<?= $this->e($siteName) ?>" width="152" height="40"><?php else: ?><strong><?= $this->e($siteName) ?></strong><?php endif; ?>
    <span class="top__tag">Prestação de contas</span>
</div></header>
<main>
<?php if ($view === null): ?>
    <div class="hero empty">
        <?php if ($status === 429): ?>
            <h1>Muitos acessos em pouco tempo</h1><p>Aguarde alguns minutos e abra o link de novo.</p>
        <?php elseif ($status === 503): ?>
            <h1>Página indisponível agora</h1><p>Tente de novo em alguns minutos.</p>
        <?php else: ?>
            <h1>Link não encontrado</h1><p>Este link de prestação de contas não existe ou foi desativado pela administração. Peça o link atualizado à administração do condomínio.</p>
        <?php endif; ?>
    </div>
<?php else: $book = $view['book']; ?>
    <section class="hero" aria-labelledby="title">
        <h1 id="title"><?= $this->e($title) ?></h1>
        <p><strong><?= $this->e((string)($condo['name'] ?? '')) ?></strong><?php if (!empty($condo['legal_name'])): ?> · <?= $this->e((string)$condo['legal_name']) ?><?php endif; ?></p>
        <?php if (!empty($condo['document'])): ?><p>CNPJ <?= $this->e((string)$condo['document']) ?></p><?php endif; ?>
        <?php if (!empty($condo['address'])): ?><p><?= $this->e((string)$condo['address']) ?></p><?php endif; ?>
        <p class="small"><?= $annual ? 'Os doze meses do ano' : 'De ' . $this->e($date((string)$book['from'])) . ' a ' . $this->e($date((string)$book['to'])) ?> · regime de caixa (vale a data do pagamento) · <?= (int)$book['documents'] ?> documento(s) anexado(s)</p>
        <span class="pill">Versão <?= (int)$view['version'] ?> · gerada em <?= $this->e($date(substr((string)$view['generated_at'], 0, 10))) ?></span>
        <div class="actions"><button type="button" class="btn btn--primary" id="print">Imprimir ou salvar em PDF</button></div>
    </section>
    <?php if ($view['under_review']): ?>
        <p class="notice" role="note"><?= $annual ? 'Algum mês deste ano foi reaberto para correção.' : 'Este mês foi reaberto para correção.' ?> Os valores abaixo são os da versão <?= (int)$view['version'] ?>. Quando a correção terminar, este mesmo link mostra a versão nova.</p>
    <?php endif; ?>

    <?php if ($annual): ?>
        <div class="kpis">
            <?php foreach ([['Saldo no início do ano', $book['totals']['opening_cents'], ''], ['Entradas no ano', $book['totals']['in_cents'], 'in'], ['Saídas no ano', $book['totals']['out_cents'], 'out'], ['Saldo no fim do ano', $book['totals']['closing_cents'], '']] as [$label, $cents, $tone]): ?>
                <div class="kpi"><small><?= $this->e($label) ?></small><strong class="<?= $tone ?>"><?= $this->e($brl((int)$cents)) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <section class="section" aria-label="Resumo do ano por categoria">
            <div class="section__head"><h3>Resumo do ano por categoria</h3></div>
            <div class="table-wrap"><table>
                <thead><tr><th>Categoria</th><th class="n">Lançamentos</th><th class="n">Total no ano</th></tr></thead>
                <tbody><?php foreach ($book['categories'] as $category): ?><tr><td><span class="code"><?= $this->e($category['code']) ?></span> <?= $this->e($category['name']) ?></td><td class="n"><?= (int)$category['entries'] ?></td><td class="n <?= $category['nature'] === 'receita' ? 'in' : 'out' ?>"><?= $this->e($brl((int)$category['total_cents'])) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </section>
        <section class="section" aria-label="Meses do ano">
            <div class="section__head"><h3>Mês a mês, com os documentos</h3></div>
            <?php foreach ($book['months'] as $month): ?>
                <details class="month">
                    <summary><span><?= $this->e(ucfirst(Doc::monthLabel((string)$month['competence']))) ?></span><span class="small"><span class="in"><?= $this->e($brl((int)$month['totals']['in_cents'])) ?></span> · <span class="out"><?= $this->e($brl((int)$month['totals']['out_cents'])) ?></span></span></summary>
                    <div><?php $renderMonth($month); ?></div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <?php $renderMonth($book); ?>
    <?php endif; ?>
<?php endif; ?>
</main>
<footer>Prestação de contas preparada pela administração com o sistema da <?= $this->e($siteName) ?>. Os documentos são os anexados pela administração em cada lançamento.</footer>
<script nonce="<?= $this->e($nonce) ?>">
(function () {
    var button = document.getElementById('print');
    var open = function () { document.querySelectorAll('details.month').forEach(function (d) { d.open = true; }); };
    window.addEventListener('beforeprint', open);
    if (button) { button.addEventListener('click', function () { open(); window.print(); }); }
})();
</script>
</body>
</html>
