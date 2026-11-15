<?php
/** Unidade (revisão de 15/11): atalhos da unidade, veículos e pets. Os dados da pessoa ficam no Perfil. @var array $link @var array{pets:list<array>, vehicles:list<array>} $petsVehicles */
$this->layout('default');
$species = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'outro' => 'Outro'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Unidade</h1><p><?= $this->e($link['unit_label']) ?> · <?= $this->e($link['condominium_name']) ?></p></header>
<?php
$on = static fn (string $feature): bool => in_array($feature, $link['features'], true);
$count = static fn (int $n): string => $n === 0 ? 'Nenhum cadastrado' : ($n === 1 ? '1 cadastrado' : $n . ' cadastrados');
$this->insert('partials/actions', ['label' => 'Unidade', 'items' => array_values(array_filter([
    $on('household') ? ['Moradores', app_url('/minha-unidade/moradores'), 'users', 'Família e inquilinos'] : null,
    ['Veículos', '#veiculos', 'car', $count(count($petsVehicles['vehicles']))],
    $on('deliveries') ? ['Correspondências', app_url('/encomendas'), 'box', 'Encomendas na portaria'] : null,
    ['Pets', '#pets', 'paw', $count(count($petsVehicles['pets']))],
    $on('visitors') ? ['Autorizar entrada', app_url('/visitantes'), 'user-check', 'Visitantes e prestadores'] : null,
    $on('occurrences') ? ['Chamados', app_url('/ocorrencias'), 'alert', 'Consultar e abrir'] : null,
]))]);
?>
<section class="app-section" id="veiculos">
    <h2>Veículos</h2>
    <?php if ($petsVehicles['vehicles'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'car', 'text' => 'Nenhum veículo cadastrado.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($petsVehicles['vehicles'] as $v):
        $this->insert('partials/item', ['icon' => 'car', 'title' => $v['plate'], 'lines' => [trim(($v['model'] ?? '') . ' ' . ($v['color'] ?? '')) . ($v['parking_spot'] ? ' · vaga ' . $v['parking_spot'] : '')]]);
    endforeach; ?>
    </ul>
</section>
<section class="app-section" id="pets">
    <h2>Pets</h2>
    <?php if ($petsVehicles['pets'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'paw', 'text' => 'Nenhum pet cadastrado.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($petsVehicles['pets'] as $p):
        $this->insert('partials/item', ['icon' => 'paw', 'title' => $p['name'], 'lines' => [($species[$p['species']] ?? $p['species']) . ($p['breed'] ? ' · ' . $p['breed'] : '')],
            'badge' => $p['vaccinated'] ? ['Vacinado', 'success'] : ['Vacina pendente', 'warning']]);
    endforeach; ?>
    </ul>
</section>
