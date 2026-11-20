<?php
/**
 * Integrações (AP-171, ADR 0091 e 0161), com o mesmo IntegrationStatus de GET /api/v1/integrations.
 * Mostra banco, e-mail, fila de avisos, antivírus e índices de correção. Só números e estados, sem endereço do
 * antivírus nem da caixa de teste. A fila e o antivírus contam só os condomínios do escopo do usuário.
 * Banco Inter (ADR 0158) e Cora (ADR 0322): quem tem erp.settings.manage e erp.bank_accounts.manage envia o pacote do
 * banco (ZIP ou certificado e chave), o client ID e, só no Inter, o secret para /api/v1/bank-credentials/{id}
 * (erp.js, data-erp-bank-credentials). O banco sai do código da conta: 077 = Inter, 403 = Cora.
 * Tokens de acesso (ADR 0601): quem tem erp.settings.manage cria e revoga o token do Connect Talk, com escopos,
 * validade e IPs, por /api/v1/integration-tokens (erp.js, data-erp-integration-tokens). O token aparece uma vez.
 * @var array $currentPage @var array $integrations @var ?list<array> $bankCredentialAccounts @var bool $canManageBankCredentials
 * @var ?array $integrationTokens @var bool $canManageIntegrationTokens
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
$integrationTokens ??= null;
$canManageIntegrationTokens ??= false;
$brDateTime = static fn (?string $d): string => $d !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $d, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] : '—';
$accountsPage = $catalog['pages']['CN-003'] ?? null;
$brDate = static fn (?string $d): string => $d !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] : '—';

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. Aqui você também liga uma conta do Banco Inter ou da Cora (ambiente de testes) à cobrança.',
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
    <div class="erp-card__head"><div><h2 id="int-inter-title">Banco Inter e Cora</h2><p>Ligue uma conta do Inter ou da Cora à cobrança: envie o certificado do banco e o client ID (no Inter, também o secret). O sistema testa a conexão antes de gravar.</p></div></div>
    <div class="erp-alert erp-alert--warning" role="note"><?= $icon('info') ?><div><strong>Só o ambiente de testes dos bancos.</strong> <strong>Inter:</strong> crie a aplicação no portal de sandbox do Inter (developers.inter.co/sandbox), não no Internet Banking; o sandbox funciona de segunda a sexta, das 8h às 20h. <strong>Cora:</strong> gere a credencial na Cora Web em Conta &gt; Integrações via APIs &gt; Integração Direta &gt; Ambiente de teste; a credencial de produção é outra e o ambiente de teste recusa.</div></div>
    <?php if ($bankCredentialAccounts === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhuma conta do Inter ou da Cora cadastrada</strong><p>Cadastre a conta bancária do condomínio com o banco 077 (Inter) ou 403 (Cora) em <?= $accountsPage !== null ? '<a href="' . $this->e(erp_url($accountsPage['route'])) . '">Contas bancárias</a>' : 'Contas bancárias' ?> e volte aqui.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="int-inter-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Conta</th><th scope="col">Banco</th><th scope="col">Condomínio</th><th scope="col">Credencial</th><th scope="col">Certificado vence</th><?php if ($canManageBankCredentials): ?><th scope="col"><span class="erp-sr-only">Ações</span></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($bankCredentialAccounts as $acc): $cred = $acc['credential']; ?>
                <tr data-bank-credential-row="<?= (int)$acc['id'] ?>">
                    <td><strong><?= $this->e($acc['name']) ?></strong></td>
                    <td><?= ($acc['bank'] ?? 'inter') === 'cora' ? 'Cora' : 'Banco Inter' ?></td>
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
                <div class="erp-field erp-col-4"><label for="inter-account">Conta bancária</label><select class="erp-select" id="inter-account" name="bank_account_id" required data-bank-credential-account><?php foreach ($bankCredentialAccounts as $acc): ?><option value="<?= (int)$acc['id'] ?>" data-needs-secret="<?= !empty($acc['needs_secret']) || !isset($acc['needs_secret']) ? '1' : '0' ?>"><?= $this->e($acc['name'] . ' · ' . (($acc['bank'] ?? 'inter') === 'cora' ? 'Cora' : 'Inter') . ' · ' . $acc['condominium']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field erp-col-4"><label for="inter-client">Client ID</label><input class="erp-input" id="inter-client" name="client_id" required maxlength="200" spellcheck="false" autocomplete="off"></div>
                <div class="erp-field erp-col-4" data-bank-credential-secret><label for="inter-secret">Client secret</label><input class="erp-input" type="password" id="inter-secret" name="client_secret" required maxlength="500" autocomplete="new-password"><p class="erp-field__hint">Só o Inter usa. Não aparece de novo depois de gravado.</p></div>
                <div class="erp-field erp-col-8"><label for="inter-files">Pacote do banco</label><input class="erp-input" type="file" id="inter-files" name="files" accept=".zip,.crt,.key,.pem" multiple required><p class="erp-field__hint">O ZIP baixado do banco, ou o certificado (.crt/.pem) e a chave (.key) juntos. Nada é guardado em disco.</p></div>
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

<?php if ($integrationTokens !== null): $scopeLabels = array_column($integrationTokens['scopes'], 'label', 'scope'); ?>
<section class="erp-card" aria-labelledby="int-tokens-title" data-erp-integration-tokens data-api="<?= $this->e(url('/api/v1/integration-tokens')) ?>">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="int-tokens-title">Tokens de acesso (Connect Talk)</h2><p>O Connect Talk entra na API do ERP com um token. Cada token tem escopos (o que ele pode ver ou fazer), é de um usuário de integração e só enxerga os condomínios desse usuário.</p></div></div>
    <?php if ($integrationTokens['tokens'] === []): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('lock') ?></span><strong>Nenhum token criado</strong><p>Crie um token abaixo e cole no Connect Talk como ERP_API_TOKEN.</p></div>
    <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="int-tokens-title" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Token</th><th scope="col">Usuário de integração</th><th scope="col">Escopos</th><th scope="col">Validade</th><th scope="col">Último uso</th><th scope="col">Situação</th><?php if ($canManageIntegrationTokens): ?><th scope="col"><span class="erp-sr-only">Ações</span></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($integrationTokens['tokens'] as $tok): $expired = $tok['expires_at'] !== null && strtotime($tok['expires_at']) <= time(); ?>
                <tr data-integration-token-row="<?= (int)$tok['id'] ?>">
                    <td><strong><?= $this->e($tok['name']) ?></strong> <span class="erp-muted">final <?= $this->e($tok['hint']) ?></span></td>
                    <td><?= $this->e($tok['user']) ?></td>
                    <td><?php foreach ($tok['scopes'] as $scope): ?><span class="erp-badge erp-badge--plain" title="<?= $this->e($scopeLabels[$scope] ?? $scope) ?>"><?= $this->e($scope) ?></span> <?php endforeach; ?></td>
                    <td><?= $tok['expires_at'] === null ? 'Sem validade' : $this->e($brDate($tok['expires_at'])) ?></td>
                    <td><?= $tok['last_used_at'] === null ? 'Nunca' : $this->e($brDateTime($tok['last_used_at'])) ?><?= $tok['last_ip'] !== null ? '<br><span class="erp-muted">IP ' . $this->e($tok['last_ip']) . '</span>' : '' ?></td>
                    <td><?php if ($tok['revoked_at'] !== null): ?><span class="erp-badge erp-badge--danger">Revogado</span><?php elseif ($expired): ?><span class="erp-badge erp-badge--warning">Vencido</span><?php else: ?><span class="erp-badge erp-badge--success">Ativo</span><?php endif; ?></td>
                    <?php if ($canManageIntegrationTokens): ?>
                    <td class="erp-table__actions"><?php if ($tok['revoked_at'] === null): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-integration-token-revoke="<?= (int)$tok['id'] ?>" data-label="<?= $this->e($tok['name']) ?>"><?= $icon('trash', 'erp-icon--sm') ?>Revogar</button><?php endif; ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($canManageIntegrationTokens): ?>
        <?php if ($integrationTokens['users'] === []): ?>
            <p class="erp-field__hint"><?= $icon('info', 'erp-icon--sm') ?> Para criar um token, cadastre antes um usuário ativo com a permissão de atender chamados (support.manage), por exemplo "Connect Talk (integração)", e libere os condomínios que ele vai atender.</p>
        <?php else: ?>
        <form data-integration-token-form autocomplete="off">
            <div class="erp-form__grid">
                <div class="erp-field erp-col-4"><label for="tok-name">Nome</label><input class="erp-input" id="tok-name" name="name" required minlength="3" maxlength="80" value="Connect Talk"></div>
                <div class="erp-field erp-col-4"><label for="tok-user">Usuário de integração</label><select class="erp-select" id="tok-user" name="user_id" required><?php foreach ($integrationTokens['users'] as $u): ?><option value="<?= (int)$u['id'] ?>"><?= $this->e($u['name'] . ' · ' . $u['email']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field erp-col-4"><label for="tok-expiry">Validade</label><select class="erp-select" id="tok-expiry" name="expires_in_days"><option value="0">Sem validade (até revogar)</option><option value="30">30 dias</option><option value="90">90 dias</option><option value="180">6 meses</option><option value="365" selected>1 ano</option></select></div>
                <fieldset class="erp-field erp-col-8"><legend>Escopos</legend>
                    <?php foreach ($integrationTokens['scopes'] as $sc): ?>
                        <label class="erp-check"><input type="checkbox" name="scopes" value="<?= $this->e($sc['scope']) ?>"<?= $sc['kind'] === 'read' ? ' checked' : ' data-write-scope' ?>> <?= $this->e($sc['label']) ?> <span class="erp-muted">(<?= $sc['kind'] === 'read' ? 'leitura' : 'escrita' ?>)</span></label>
                    <?php endforeach; ?>
                    <p class="erp-field__hint">Leitura vem marcada. Escrita deixa o Talk mexer nos chamados; marque só se ele for abrir e responder chamados.</p>
                </fieldset>
                <div class="erp-field erp-col-4"><label for="tok-ips">IPs permitidos</label><input class="erp-input" id="tok-ips" name="allowed_ips" maxlength="255" spellcheck="false" placeholder="Vazio = qualquer IP"><p class="erp-field__hint">Separados por vírgula. Com o Talk atrás do Cloudflare Tunnel, deixe vazio.</p></div>
            </div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('plus', 'erp-icon--sm') ?>Criar token</button></div>
        </form>
        <div class="erp-alert" role="status" data-integration-token-result hidden></div>
        <?php endif; ?>
    <?php else: ?>
        <p class="erp-field__hint"><?= $icon('lock', 'erp-icon--sm') ?> Criar e revogar tokens exige a permissão de gerenciar configurações.</p>
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
