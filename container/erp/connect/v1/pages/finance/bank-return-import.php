<?php
/**
 * Importar arquivo de retorno (AP-036, ADR 0035 e 0036): o navegador lê o arquivo como texto e
 * envia para POST /api/v1/bank-returns (erp.js, data-erp-bank-return). O resultado mostra o resumo
 * do arquivo e cada ocorrência (aplicada, repetida, divergência). O mesmo arquivo não é processado
 * duas vezes. Só o formato do sandbox é aceito até o primeiro banco homologado.
 * @var array $currentPage @var bool $canManageCharges @var list<array> $condoOptions
 */
$this->layout('default');
$context = \Source\Support\Erp\CondominiumContext::id();
$canManageCharges ??= false;
$divergencesRoute = erp_url($catalog['pages']['AP-038']['route'] ?? '/cobranca/retornos');

ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($divergencesRoute) ?>"><?= $icon('alert', 'erp-icon--sm') ?>Divergências</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Importe o arquivo de retorno do banco para registrar os boletos e dar baixa nos pagamentos. Pagamento de valor diferente ou título desconhecido vai para a fila de divergências.',
    'pageActions' => $actions,
]);
?>
<div class="erp-alert erp-alert--warning" role="note"><div><strong>Ambiente de testes.</strong> Só o retorno do banco de testes (sandbox, primeira linha <code>SANDBOX-RETORNO;v1</code>) é aceito. Arquivos CNAB de bancos reais ainda não são lidos.</div></div>
<div class="erp-stack" data-erp-bank-return data-api="<?= $this->e(url('/api/v1/bank-returns')) ?>" data-accounts-api="<?= $this->e(url('/api/v1/bank-accounts')) ?>">
    <?= csrf_input() ?>
    <?php if (!$canManageCharges): ?>
        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('lock') ?></span><strong>Importar exige permissão de gestão de boletos</strong><p>Você pode consultar os boletos e as divergências. Peça a quem administra o ERP a permissão para importar retornos.</p></div>
    <?php else: ?>
    <section class="erp-card" aria-labelledby="ret-form-title">
        <div class="erp-card__head"><div><h2 id="ret-form-title">Arquivo de retorno</h2><p>Texto com até 2 MB. A conta bancária precisa ser a mesma dos boletos do arquivo.</p></div></div>
        <form data-bank-return-form>
            <div class="erp-form__grid">
                <div class="erp-field erp-col-4"><label for="ret-condo">Condomínio</label>
                    <?php if ($context !== null): ?>
                        <input type="hidden" name="condominium_id" value="<?= (int)$context ?>"><input class="erp-input" id="ret-condo" value="<?= $this->e((string)(\Source\Support\Erp\CondominiumContext::current()['name'] ?? '')) ?>" readonly>
                    <?php else: ?>
                        <select class="erp-select" id="ret-condo" name="condominium_id" required><option value="">Escolha</option><?php foreach ($condoOptions ?? [] as $condo): ?><option value="<?= (int)$condo['id'] ?>"><?= $this->e((string)$condo['name']) ?></option><?php endforeach; ?></select>
                    <?php endif; ?>
                </div>
                <div class="erp-field erp-col-4"><label for="ret-account">Conta bancária</label><select class="erp-select" id="ret-account" name="bank_account_id" required data-return-accounts><option value="">Escolha o condomínio</option></select></div>
                <div class="erp-field erp-col-4"><label for="ret-file"><?= $this->e($spec['fileLabel'] ?? 'Arquivo de retorno') ?></label><input class="erp-input" type="file" id="ret-file" name="file" accept=".ret,.txt" required></div>
            </div>
            <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('upload', 'erp-icon--sm') ?>Importar retorno</button></div>
        </form>
    </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="ret-result-title" data-bank-return-result hidden>
        <div class="erp-card__head"><div><h2 id="ret-result-title">Resultado da importação</h2><p data-bank-return-summary></p></div></div>
        <div data-bank-return-events aria-live="polite"></div>
    </section>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
