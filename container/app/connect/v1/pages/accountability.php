<?php
/** Prestação de contas (links públicos do ADR 0301) no app do morador (ADR 0302). @var list<array> $publications */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?>
    <h1>Prestação de contas</h1>
    <p>As contas de cada mês fechado, com pagamentos, contas e notas fiscais.</p>
</header>
<?php if ($publications === []): ?>
    <?php $this->insert('partials/empty', ['icon' => 'chart', 'text' => 'Nenhuma prestação de contas publicada ainda. Ela aparece aqui quando a administração fecha o mês.']) ?>
<?php endif; ?>
<ul class="app-items">
<?php foreach ($publications as $item):
    $this->insert('partials/item', ['icon' => 'chart', 'href' => $item['url'], 'external' => true,
        'title' => $item['kind'] === 'annual' ? 'Livro do ' . F::competence($item['competence']) : ucfirst(F::competence($item['competence'])),
        'lines' => ['Publicada em ' . F::date($item['generated_at']) . ($item['version'] > 1 ? ' · versão ' . (int)$item['version'] : '')],
        'badge' => $item['kind'] === 'annual' ? ['Anual', 'info'] : null]);
endforeach; ?>
</ul>
