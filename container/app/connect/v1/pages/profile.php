<?php
/** Perfil: dados da pessoa, segurança da conta, instalar no celular e sair (revisão de 15/11). @var array $link @var array $profile ResidentPortal::profile() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span><h1>Perfil</h1><p>Seus dados de acesso e a segurança da conta.</p></header>
<div class="app-card app-profile-card">
    <span class="app-profile-card__avatar" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($profile['name'], 0, 1) . (str_contains($profile['name'], ' ') ? mb_substr((string)strrchr($profile['name'], ' '), 1, 1) : ''))) ?></span>
    <div><strong><?= $this->e($profile['name']) ?></strong><br><span class="app-muted"><?= $this->e($link['relationship_label']) ?> · <?= $this->e($link['unit_label']) ?></span></div>
    <dl>
        <div><dt>E-mail de acesso</dt><dd><?= $this->e($profile['login_email']) ?></dd></div>
        <?php if ($profile['email'] !== '' && $profile['email'] !== $profile['login_email']): ?><div><dt>E-mail no cadastro</dt><dd><?= $this->e($profile['email']) ?></dd></div><?php endif; ?>
        <div><dt>Telefone</dt><dd><?= $this->e($profile['phone'] ?: 'Não informado') ?></dd></div>
        <div><dt>CPF</dt><dd><?= $this->e($profile['document'] ?? 'Não informado') ?></dd></div>
        <div><dt>Condomínio</dt><dd><?= $this->e($link['condominium_name']) ?></dd></div>
        <?php if ($profile['since']): ?><div><dt>Na unidade desde</dt><dd><?= F::date($profile['since']) ?></dd></div><?php endif; ?>
    </dl>
    <p class="app-hint" style="grid-column:1/-1">Algum dado errado? Fale com a administração do condomínio para corrigir o cadastro.</p>
</div>
<section class="app-section">
    <h2>Conta</h2>
    <ul class="app-items">
    <?php $this->insert('partials/item', ['icon' => 'key', 'title' => 'Verificação em duas etapas', 'lines' => ['Opcional. Ligue se quiser mais proteção ao entrar.'], 'href' => app_url('/seguranca')]); ?>
    <?php $this->insert('partials/item', ['icon' => 'bell', 'title' => 'Avisos', 'lines' => ['Contas, encomendas, visitas e comunicados.'], 'href' => app_url('/avisos')]); ?>
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
<form method="post" action="<?= app_url('/sair') ?>" class="app-section">
    <?= csrf_input() ?>
    <button type="submit" class="app-button app-button--ghost app-button--block"><svg aria-hidden="true"><use href="#i-logout"></use></svg>Sair do aplicativo</button>
</form>
