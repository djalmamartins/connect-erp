<?php
/** Sininho: avisos do vínculo (ADR 0302, ajustes de 14/11). @var list<array> $items ResidentFeed::notifications() @var ?string $seenAt */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$icons = ['charge' => 'receipt', 'delivery' => 'box', 'visit' => 'user-check', 'announcement' => 'megaphone', 'assembly' => 'users', 'reservation' => 'calendar', 'ticket' => 'chat'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Avisos</h1><p>O que pede a sua atenção: contas vencidas, encomendas, visitas, comunicados, assembleias e reservas.</p></header>
<?php if ($items === []): ?>
    <?php $this->insert('partials/empty', ['icon' => 'bell', 'text' => 'Nenhum aviso agora. Tudo em dia.']) ?>
<?php endif; ?>
<ul class="app-items">
<?php foreach ($items as $item):
    $this->insert('partials/item', ['icon' => $icons[$item['kind']] ?? 'bell', 'tone' => $item['tone'], 'title' => $item['title'],
        'lines' => [$item['detail'], F::date(substr($item['at'], 0, 10))], 'href' => app_url($item['path']),
        'flag' => \Source\Services\Resident\ResidentFeed::isNew($item, $seenAt) ? 'Novo' : null]);
endforeach; ?>
</ul>
