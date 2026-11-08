<?php
/**
 * Página do catálogo ainda sem interface específica (aguarda aprovação da identidade
 * visual). Diz o que a página terá e o que falta, sem dados simulados.
 * @var array $currentPage
 */
$this->layout('default');
$plans = [
    'list' => ['Listagem com filtros, ordenação e paginação', 'Cadastro e edição em formulário validado', 'Detalhe com abas e histórico', 'Ações por linha com confirmação'],
    'form' => ['Formulário com validação no navegador e no servidor', 'Seleção de condomínio, unidade e conta', 'Resumo antes de confirmar', 'Confirmação somente com o backend financeiro'],
    'closing' => ['Seleção de condomínio e competência', 'Checklist de pendências antes do fechamento', 'Resumo de receitas e despesas', 'Confirmação somente com o backend financeiro'],
    'import' => ['Envio de arquivo com validação de formato', 'Pré-visualização das linhas', 'Relatório de erros por linha', 'Importação somente com o backend do módulo'],
    'report' => ['Parâmetros (condomínio, período, critério)', 'Indicadores e tabela analítica', 'Exportação e impressão com os filtros aplicados'],
    'chart' => ['Parâmetros de período e condomínio', 'Gráfico com tabela acessível equivalente', 'Exportação'],
    'document' => ['Seleção de condomínio e unidade', 'Pré-visualização do documento', 'Emissão em PDF somente com dados reais'],
    'settings' => ['Formulário de parâmetros por condomínio', 'Histórico de alterações', 'Gravação somente com o backend de configurações'],
    'dashboard' => ['Indicadores do contexto', 'Gráficos e alertas', 'Atalhos para as páginas relacionadas'],
];
$plan = $plans[$currentPage['kind']] ?? $plans['list'];
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $currentPage['note'] !== '' ? $currentPage['note'] : null,
]);
?>
<section class="erp-card">
    <div class="erp-empty">
        <span class="erp-empty__icon"><?= $icon('sparkle') ?></span>
        <h3>Interface em construção</h3>
        <p>Esta rota já está registrada e protegida. A interface específica será construída depois da aprovação da identidade visual do login e do dashboard.</p>
    </div>
    <div class="erp-grid erp-grid--2">
        <div>
            <h2 style="font-size:14px;margin-bottom:8px">O que esta página terá</h2>
            <ul class="erp-list">
                <?php foreach ($plan as $item): ?><li><span class="erp-list__icon"><?= $icon('check', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e($item) ?></strong></div></li><?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h2 style="font-size:14px;margin-bottom:8px">Rastreabilidade</h2>
            <ul class="erp-list">
                <li><span class="erp-list__icon"><?= $icon('file', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e($currentPageId) ?> · especificação</strong><small><?= $this->e($currentPage['spec']) ?></small></div></li>
                <li><span class="erp-list__icon"><?= $icon('info', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Referência APControle</strong><small><?= $this->e($currentPage['reference']) ?> (não é rota do Connect)</small></div></li>
                <li><span class="erp-list__icon<?= $currentPage['status'] === 'PENDENTE' ? ' erp-list__icon--warning' : '' ?>"><?= $icon('shield', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Situação na auditoria: <?= $this->e($currentPage['status']) ?></strong><small>Campos e regras ainda precisam ser confirmados na ficha</small></div></li>
            </ul>
        </div>
    </div>
    <div class="erp-form__actions">
        <a class="erp-btn" href="<?= erp_url(($catalog['groups'][$currentPage['group']]['prefix'] ?: '/dashboard')) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar ao módulo</a>
    </div>
</section>
