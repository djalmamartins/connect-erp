<?php
/** Prestação de contas (links públicos do ADR 0301) no app do morador (ADR 0302). @var list<array> $links */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?>
    <h1>Prestação de contas</h1>
    <p>As contas de cada mês fechado, com pagamentos, contas e notas fiscais.</p>
</header>
<?php if ($links === []): ?>
    <div class="app-card app-empty"><p>Nenhuma prestação de contas publicada ainda.</p></div>
<?php endif; ?>
<ul class="app-list">
<?php foreach ($links as $item): ?>
    <li><a class="app-card app-row" href="<?= $this->e($item['url']) ?>" target="_blank" rel="noopener">
        <span><strong><?= $item['kind'] === 'annual' ? 'Livro do ' . $this->e(F::competence($item['competence'])) : ucfirst($this->e(F::competence($item['competence']))) ?></strong>
            <small>Publicada em <?= F::date($item['generated_at']) ?><?= $item['version'] > 1 ? ' · versão ' . (int)$item['version'] : '' ?></small></span>
        <span class="app-row__go" aria-hidden="true">›</span>
    </a></li>
<?php endforeach; ?>
</ul>
