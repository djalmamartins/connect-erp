<?php
/** Minha unidade: vínculo, pets e veículos (ADR 0302). @var array $link @var array{pets:list<array>, vehicles:list<array>} $petsVehicles */
$this->layout('default');
$species = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'outro' => 'Outro'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Minha unidade</h1><p><?= $this->e($link['unit_label']) ?> · <?= $this->e($link['condominium_name']) ?></p></header>
<div class="app-card">
    <ul class="app-facts">
        <li>Nome no cadastro: <?= $this->e($link['name']) ?></li>
        <li>Vínculo: <?= $this->e($link['relationship_label']) ?></li>
        <li>E-mail de acesso: <?= $this->e((string)($user->email ?? '')) ?></li>
    </ul>
    <p class="app-hint">Algum dado errado? Fale com a administração do condomínio para corrigir o cadastro.</p>
</div>
<section class="app-section">
    <h2>Veículos</h2>
    <?php if ($petsVehicles['vehicles'] === []): ?><p class="app-muted">Nenhum veículo cadastrado.</p><?php endif; ?>
    <?php foreach ($petsVehicles['vehicles'] as $v): ?>
        <div class="app-card app-row"><span><strong><?= $this->e($v['plate']) ?></strong><small><?= $this->e(trim(($v['model'] ?? '') . ' ' . ($v['color'] ?? ''))) ?><?= $v['parking_spot'] ? ' · vaga ' . $this->e($v['parking_spot']) : '' ?></small></span></div>
    <?php endforeach; ?>
</section>
<section class="app-section">
    <h2>Pets</h2>
    <?php if ($petsVehicles['pets'] === []): ?><p class="app-muted">Nenhum pet cadastrado.</p><?php endif; ?>
    <?php foreach ($petsVehicles['pets'] as $p): ?>
        <div class="app-card app-row"><span><strong><?= $this->e($p['name']) ?></strong><small><?= $this->e($species[$p['species']] ?? $p['species']) ?><?= $p['breed'] ? ' · ' . $this->e($p['breed']) : '' ?></small></span>
            <span class="app-badge app-badge--<?= $p['vaccinated'] ? 'success' : 'warning' ?>"><?= $p['vaccinated'] ? 'Vacinado' : 'Vacina pendente' ?></span></div>
    <?php endforeach; ?>
</section>
<section class="app-section">
    <h2>Instalar no celular</h2>
    <div class="app-card"><p>No Android, toque em <strong>Instalar</strong> quando o navegador oferecer, ou no menu ⋮ › Adicionar à tela inicial. No iPhone, toque em Compartilhar › Adicionar à Tela de Início.</p>
        <button type="button" class="app-button app-button--ghost" data-app-install hidden>Instalar o aplicativo</button></div>
</section>
