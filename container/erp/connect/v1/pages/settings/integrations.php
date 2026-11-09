<?php
/**
 * Integrações (AP-171, ADR 0091 e 0161), só leitura, com o mesmo IntegrationStatus de GET /api/v1/integrations.
 * Mostra banco, e-mail, fila de avisos, antivírus e índices de correção. Só números e estados, sem endereço do
 * antivírus nem da caixa de teste. A fila e o antivírus contam só os condomínios do escopo do usuário.
 * @var array $currentPage @var array $integrations
 */
$this->layout('default');
$bank = $integrations['bank'] ?? ['adapters' => [], 'note' => ''];
$mail = $integrations['mail'] ?? ['mode' => 'log', 'sandbox_inbox_configured' => false, 'reaches_residents' => false];
$outbox = $integrations['outbox'] ?? ['pending' => 0, 'failed' => 0];
$indexes = $integrations['price_indexes'] ?? [];
$av = $integrations['antivirus'] ?? null;
$mailModes = ['log' => 'Só registro (nada é enviado)', 'sandbox' => 'Caixa de teste', 'production' => 'Envio real'];
$scanLabels = ['clean' => ['Limpos', 'success'], 'infected' => ['Infectados', 'danger'], 'unscanned' => ['Não verificados', 'plain'], 'error' => ['Erro na verificação', 'warning']];
$indexLabels = ['ok' => ['Em dia', 'success'], 'stale' => ['Atrasado', 'warning'], 'missing' => ['Nunca carregado', 'danger']];
$month = static fn (?string $ym): string => $ym !== null && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) === 1 ? $m[2] . '/' . $m[1] : '—';
$queueRoute = erp_url('/configuracoes/fila-de-avisos');
$diskPage = $catalog['pages']['AP-122'] ?? null;

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. Esta tela só consulta; a configuração fica no servidor.',
    'pageActions' => '',
]);
?>
<section class="erp-grid erp-grid--kpi" aria-label="Resumo das integrações">
    <?php foreach ([
        ['label' => 'E-mail', 'value' => $mailModes[$mail['mode']] ?? (string)$mail['mode'], 'meta' => $mail['reaches_residents'] ? 'Os moradores recebem os avisos' : 'Nenhum morador recebe', 'icon' => 'bell', 'tone' => $mail['reaches_residents'] ? 'warning' : 'info', 'trend' => null],
        ['label' => 'Fila de avisos', 'value' => (int)$outbox['pending'] . ' pendente(s)', 'meta' => (int)$outbox['failed'] . ' com falha', 'icon' => 'clock', 'tone' => (int)$outbox['failed'] > 0 ? 'danger' : '', 'trend' => null],
        ['label' => 'Antivírus', 'value' => $av === null ? '—' : ($av['mode'] === 'enforce' ? 'Ligado' : 'Desligado'), 'meta' => $av === null ? 'Sem informação' : ($av['scanner_configured'] ? 'Antivírus configurado' : 'Nenhum antivírus configurado'), 'icon' => 'shield', 'tone' => $av !== null && $av['mode'] === 'enforce' && $av['scanner_configured'] ? 'info' : 'warning', 'trend' => null],
        ['label' => 'Banco', 'value' => count($bank['adapters']) . ' adaptador(es)', 'meta' => 'Só ambiente de teste (sandbox)', 'icon' => 'wallet', 'tone' => '', 'trend' => null],
    ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
</section>

<div class="erp-grid erp-grid--main">
    <section class="erp-card" aria-labelledby="int-av-title" data-integrations-antivirus>
        <div class="erp-card__head"><div><h2 id="int-av-title">Antivírus dos arquivos</h2><p>Arquivos do <?= $diskPage !== null ? '<a href="' . $this->e(erp_url($diskPage['route'])) . '">Disco Virtual</a>' : 'Disco Virtual' ?> por situação da verificação, nos condomínios que você acessa.</p></div></div>
        <?php if ($av === null): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('shield') ?></span><strong>Sem informação do antivírus</strong><p>Não foi possível ler a situação agora.</p></div>
        <?php else: ?>
            <?php if ($av['mode'] !== 'enforce'): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Antivírus desligado</strong>Os arquivos enviados entram como "não verificados". Quando o antivírus for ligado, a reverificação passa por eles em lotes.</div></div>
            <?php elseif (!$av['scanner_configured']): ?>
                <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong>Antivírus ligado sem endereço configurado</strong>Os envios são recusados até o antivírus responder.</div></div>
            <?php endif; ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="int-av-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Situação</th><th scope="col" class="is-num">Arquivos</th></tr></thead>
                <tbody>
                <?php foreach ($scanLabels as $status => [$label, $tone]): ?>
                    <tr data-scan-status="<?= $this->e($status) ?>"><td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($label) ?></span></td><td class="is-num"><?= (int)($av['documents'][$status] ?? 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="erp-field__hint"><?= (int)$av['pending_rescan'] ?> arquivo(s) aguardando reverificação (não verificados ou com erro). Arquivos infectados ou com erro não podem ser baixados.</p>
        <?php endif; ?>
    </section>

    <section class="erp-card" aria-labelledby="int-mail-title">
        <div class="erp-card__head"><div><h2 id="int-mail-title">E-mail e banco</h2><p>Como os avisos saem e quais bancos estão ligados.</p></div></div>
        <dl class="erp-queue-facts">
            <dt>Entrega de e-mail</dt><dd><?= $this->e($mailModes[$mail['mode']] ?? (string)$mail['mode']) ?></dd>
            <dt>Caixa de teste</dt><dd><?= $mail['sandbox_inbox_configured'] ? 'Configurada' : 'Não configurada' ?></dd>
            <dt>Fila de avisos</dt><dd><a href="<?= $this->e($queueRoute) ?>"><?= (int)$outbox['pending'] ?> pendente(s), <?= (int)$outbox['failed'] ?> com falha</a></dd>
            <?php foreach ($bank['adapters'] as $adapter): ?>
                <dt>Banco: <?= $this->e((string)$adapter['adapter']) ?></dt><dd><span class="erp-badge erp-badge--<?= $adapter['available'] ? 'success' : 'danger' ?>"><?= $adapter['available'] ? 'Disponível' : 'Indisponível' ?></span><?= $adapter['production'] ? '' : ' <span class="erp-muted">ambiente de teste</span>' ?></dd>
            <?php endforeach; ?>
        </dl>
        <?php if (($bank['note'] ?? '') !== ''): ?><p class="erp-field__hint"><?= $this->e((string)$bank['note']) ?></p><?php endif; ?>
    </section>
</div>

<section class="erp-card" aria-labelledby="int-idx-title">
    <div class="erp-card__head"><div><h2 id="int-idx-title">Índices de correção</h2><p>Última competência carregada de cada índice. Atrasado = mais de dois meses sem carga.</p></div></div>
    <?php if ($indexes === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('chart') ?></span><strong>Nenhum índice carregado</strong><p>Sem índices, a correção monetária dos acordos e da cobrança não pode ser calculada.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="int-idx-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Índice</th><th scope="col">Última competência</th><th scope="col">Situação</th></tr></thead>
            <tbody><?php foreach ($indexes as $index): [$label, $tone] = $indexLabels[$index['state']] ?? [$index['state'], 'plain']; ?>
                <tr><td><strong><?= $this->e((string)$index['code']) ?></strong></td><td><?= $this->e($month($index['last_competence'])) ?></td><td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($label) ?></span></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
