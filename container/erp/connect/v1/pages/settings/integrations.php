<?php
/**
 * Integrações (AP-171, ADR 0091 e 0161), com o mesmo IntegrationStatus de GET /api/v1/integrations.
 * Mostra banco, e-mail, fila de avisos, antivírus e índices de correção. Só números e estados, sem endereço do
 * antivírus nem da caixa de teste. A fila e o antivírus contam só os condomínios do escopo do usuário.
 * Banco Inter (ADR 0158): quem tem erp.settings.manage e erp.bank_accounts.manage envia o pacote do banco
 * (ZIP ou .crt/.key), o client ID e o secret para /api/v1/bank-credentials/{id} (erp.js, data-erp-bank-credentials).
 * @var array $currentPage @var array $integrations @var ?list<array> $bankCredentialAccounts @var bool $canManageBankCredentials
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
$bankCredentialAccounts ??= null;
$canManageBankCredentials ??= false;
$accountsPage = $catalog['pages']['CN-003'] ?? null;
$brDate = static fn (?string $d): string => $d !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] : '—';

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. Aqui você também liga uma conta do Banco Inter (ambiente de testes) à cobrança.',
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

<?php if ($bankCredentialAccounts !== null): ?>
<section class="erp-card" aria-labelledby="int-inter-title" data-erp-bank-credentials data-api="<?= $this->e(url('/api/v1/bank-credentials')) ?>">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="int-inter-title">Banco Inter</h2><p>Ligue uma conta do Inter à cobrança: envie o pacote da aplicação do banco, o client ID e o secret. O sistema testa a conexão antes de gravar.</p></div></div>
    <div class="erp-alert erp-alert--warning" role="note"><?= $icon('info') ?><div><strong>Só o ambiente de testes (sandbox) do Inter.</strong> Crie a aplicação no portal de sandbox do Inter (developers.inter.co/sandbox), não no Internet Banking: o certificado do Internet Banking é de produção e o sandbox recusa. O sandbox funciona de segunda a sexta, das 8h às 20h.</div></div>
    <?php if ($bankCredentialAccounts === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhuma conta do Inter cadastrada</strong><p>Cadastre a conta bancária do condomínio com o banco 077 em <?= $accountsPage !== null ? '<a href="' . $this->e(erp_url($accountsPage['route'])) . '">Contas bancárias</a>' : 'Contas bancárias' ?> e volte aqui.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="int-inter-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Conta</th><th scope="col">Condomínio</th><th scope="col">Credencial</th><th scope="col">Certificado vence</th><?php if ($canManageBankCredentials): ?><th scope="col"><span class="erp-sr-only">Ações</span></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($bankCredentialAccounts as $acc): $cred = $acc['credential']; ?>
                <tr data-bank-credential-row="<?= (int)$acc['id'] ?>">
                    <td><strong><?= $this->e($acc['name']) ?></strong></td>
                    <td><?= $this->e($acc['condominium']) ?></td>
                    <td><?php if ($cred === null): ?><span class="erp-badge erp-badge--plain">Sem credencial</span><?php else: ?><span class="erp-badge erp-badge--success">Gravada</span> <span class="erp-muted">client ID <?= $this->e((string)$cred['client_id_hint']) ?></span><?php endif; ?></td>
                    <td><?= $cred === null ? '—' : $this->e($brDate($cred['certificate_expires_on'])) ?></td>
                    <?php if ($canManageBankCredentials): ?>
                    <td class="erp-table__actions">
                        <?php if ($cred !== null): ?>
                            <button type="button" class="erp-btn erp-btn--sm" data-bank-credential-test="<?= (int)$acc['id'] ?>"><?= $icon('check', 'erp-icon--sm') ?>Testar</button>
                            <button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-bank-credential-remove="<?= (int)$acc['id'] ?>" data-label="<?= $this->e($acc['name']) ?>"><?= $icon('trash', 'erp-icon--sm') ?>Remover</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($canManageBankCredentials): ?>
        <form data-bank-credential-form autocomplete="off">
            <div class="erp-form__grid">
                <div class="erp-field erp-col-4"><label for="inter-account">Conta do Inter</label><select class="erp-select" id="inter-account" name="bank_account_id" required><?php foreach ($bankCredentialAccounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= $this->e($acc['name'] . ' · ' . $acc['condominium']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field erp-col-4"><label for="inter-client">Client ID</label><input class="erp-input" id="inter-client" name="client_id" required maxlength="200" spellcheck="false" autocomplete="off"></div>
                <div class="erp-field erp-col-4"><label for="inter-secret">Client secret</label><input class="erp-input" type="password" id="inter-secret" name="client_secret" required maxlength="500" autocomplete="new-password"><p class="erp-field__hint">Não aparece de novo depois de gravado.</p></div>
                <div class="erp-field erp-col-8"><label for="inter-files">Pacote do banco</label><input class="erp-input" type="file" id="inter-files" name="files" accept=".zip,.crt,.key,.pem" multiple required><p class="erp-field__hint">O ZIP baixado do Inter, ou os arquivos .crt e .key juntos. Nada é guardado em disco.</p></div>
                <div class="erp-field erp-col-4"><label class="erp-check"><input type="checkbox" name="save_anyway" value="1"> Gravar mesmo se o teste falhar</label></div>
            </div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('upload', 'erp-icon--sm') ?>Testar e gravar</button></div>
        </form>
        <div class="erp-alert" role="status" data-bank-credential-result hidden></div>
        <?php else: ?>
            <p class="erp-field__hint"><?= $icon('lock', 'erp-icon--sm') ?> Enviar a credencial exige as permissões de gerenciar configurações e contas bancárias.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

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
