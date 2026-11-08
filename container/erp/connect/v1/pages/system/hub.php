<?php
/**
 * Visão geral de um módulo: todas as páginas do grupo, organizadas por seção, com filtro.
 * @var string $hubGroup
 * @var array $hubPages
 */
$this->layout('default');
$group = $catalog['groups'][$hubGroup];
$kindLabels = ['dashboard' => 'Painel', 'list' => 'Cadastro e listagem', 'form' => 'Formulário', 'closing' => 'Fechamento', 'import' => 'Importação', 'report' => 'Relatório', 'document' => 'Documento', 'chart' => 'Gráfico', 'settings' => 'Configuração'];
$sections = [];
foreach ($hubPages as $id => $page) {
    $sections[$page['section'] !== '' ? $page['section'] : $group['label']][$id] = $page;
}
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Módulo',
    'pageTitle' => $group['label'],
    'pageLead' => count($hubPages) . ' páginas neste módulo. Escolha uma para abrir.',
]);
?>
<div class="erp-filters" role="search">
    <div class="erp-field erp-field--grow">
        <label for="hub-filter">Filtrar páginas</label>
        <input id="hub-filter" class="erp-input" type="search" placeholder="Digite parte do nome" data-erp-hub-filter>
    </div>
</div>
<?php foreach ($sections as $section => $pages): ?>
    <section class="erp-hub-section" id="<?= $this->e($anchor($section)) ?>" aria-labelledby="<?= $this->e($anchor($section)) ?>-title" data-erp-hub-section>
        <h2 id="<?= $this->e($anchor($section)) ?>-title"><?= $this->e($section) ?></h2>
        <div class="erp-grid erp-grid--cards">
            <?php foreach ($pages as $id => $page): ?>
                <a class="erp-card erp-card-link erp-hub-card" href="<?= erp_url($page['route']) ?>" data-erp-hub-item="<?= $this->e(mb_strtolower($page['name'])) ?>">
                    <h3><?= $this->e($page['name']) ?></h3>
                    <p><?= $this->e($kindLabels[$page['kind']] ?? 'Página') ?></p>
                    <span class="erp-hub-card__foot"><span class="erp-badge erp-badge--plain"><?= $this->e($id) ?></span><?= $icon('chevron-right', 'erp-icon--sm') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
<?php $this->start('scripts'); ?>
<script>
(function () {
  var input = document.querySelector('[data-erp-hub-filter]');
  if (!input) return;
  input.addEventListener('input', function () {
    var term = input.value.trim().toLowerCase();
    document.querySelectorAll('[data-erp-hub-section]').forEach(function (section) {
      var visible = 0;
      section.querySelectorAll('[data-erp-hub-item]').forEach(function (card) {
        var on = card.getAttribute('data-erp-hub-item').indexOf(term) !== -1;
        card.hidden = !on; if (on) visible++;
      });
      section.hidden = visible === 0;
    });
  });
})();
</script>
<?php $this->stop(); ?>
