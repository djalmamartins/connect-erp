<?php
/** Minha unidade: dados da pessoa, vínculo, pets e veículos (ADR 0302). @var array $link @var array $profile ResidentPortal::profile() @var array{pets:list<array>, vehicles:list<array>} $petsVehicles */
$this->layout('default');
$species = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'outro' => 'Outro'];
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Minha unidade</h1><p><?= $this->e($link['unit_label']) ?> · <?= $this->e($link['condominium_name']) ?></p></header>
<div class="app-card app-profile-card">
    <span class="app-profile-card__avatar" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($profile['name'], 0, 1) . (str_contains($profile['name'], ' ') ? mb_substr((string)strrchr($profile['name'], ' '), 1, 1) : ''))) ?></span>
    <div><strong><?= $this->e($profile['name']) ?></strong><br><span class="app-muted"><?= $this->e($link['relationship_label']) ?> · <?= $this->e($link['unit_label']) ?></span></div>
    <dl>
        <div><dt>E-mail de acesso</dt><dd><?= $this->e($profile['login_email']) ?></dd></div>
        <?php if ($profile['email'] !== '' && $profile['email'] !== $profile['login_email']): ?><div><dt>E-mail no cadastro</dt><dd><?= $this->e($profile['email']) ?></dd></div><?php endif; ?>
        <div><dt>Telefone</dt><dd><?= $this->e($profile['phone'] ?: 'Não informado') ?></dd></div>
        <div><dt>CPF</dt><dd><?= $this->e($profile['document'] ?? 'Não informado') ?></dd></div>
        <div><dt>Condomínio</dt><dd><?= $this->e($link['condominium_name']) ?></dd></div>
        <?php if ($profile['since']): ?><div><dt>Na unidade desde</dt><dd><?= \Source\Controllers\App\Resident\Format::date($profile['since']) ?></dd></div><?php endif; ?>
    </dl>
    <p class="app-hint" style="grid-column:1/-1">Algum dado errado? Fale com a administração do condomínio para corrigir o cadastro.</p>
</div>
<section class="app-section">
    <h2>Veículos</h2>
    <?php if ($petsVehicles['vehicles'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'car', 'text' => 'Nenhum veículo cadastrado.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($petsVehicles['vehicles'] as $v):
        $this->insert('partials/item', ['icon' => 'car', 'title' => $v['plate'], 'lines' => [trim(($v['model'] ?? '') . ' ' . ($v['color'] ?? '')) . ($v['parking_spot'] ? ' · vaga ' . $v['parking_spot'] : '')]]);
    endforeach; ?>
    </ul>
</section>
<section class="app-section">
    <h2>Pets</h2>
    <?php if ($petsVehicles['pets'] === []): ?><?php $this->insert('partials/empty', ['icon' => 'paw', 'text' => 'Nenhum pet cadastrado.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($petsVehicles['pets'] as $p):
        $this->insert('partials/item', ['icon' => 'paw', 'title' => $p['name'], 'lines' => [($species[$p['species']] ?? $p['species']) . ($p['breed'] ? ' · ' . $p['breed'] : '')],
            'badge' => $p['vaccinated'] ? ['Vacinado', 'success'] : ['Vacina pendente', 'warning']]);
    endforeach; ?>
    </ul>
</section>
<section class="app-section">
    <h2>Segurança da conta</h2>
    <ul class="app-items">
    <?php $this->insert('partials/item', ['icon' => 'key', 'title' => 'Verificação em duas etapas', 'lines' => ['Opcional. Ligue se quiser mais proteção ao entrar.'], 'href' => app_url('/seguranca')]); ?>
    </ul>
</section>
<section class="app-section">
    <h2>Instalar no celular</h2>
    <div class="app-install">
        <details>
            <summary><svg aria-hidden="true"><use href="#i-phone"></use></svg>Android</summary>
            <p>Toque em <strong>Instalar</strong> quando o navegador oferecer, ou no menu ⋮ › Adicionar à tela inicial.</p>
            <button type="button" class="app-button" data-app-install hidden>Instalar agora</button>
        </details>
        <details>
            <summary><svg aria-hidden="true"><use href="#i-phone"></use></svg>iPhone</summary>
            <p>No Safari, toque em Compartilhar › Adicionar à Tela de Início.</p>
        </details>
    </div>
</section>
