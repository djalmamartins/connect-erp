<?php
/**
 * Boas-vindas do primeiro acesso (revisão de 15/11, igual ao app de celular): foto e um passo a passo com dicas.
 * Aparece até a pessoa concluir ou pular; depois, só pelo Perfil.
 *
 * @var array $link @var int $step @var int $steps @var bool $hasPhoto @var ?string $photo
 */
$this->layout('default');
$first = explode(' ', trim((string)$link['name']))[0] ?? '';
$tips = [
    2 => ['home', 'Tudo começa no Início', 'No cartão verde você vê quanto está em aberto e tira a 2ª via do boleto. O olho esconde os valores. Logo abaixo ficam os serviços mais usados e as notificações.'],
    3 => ['grid', 'Resolva fácil e Unidade', 'Em Resolva fácil estão documentos, prestação de contas, assembleias, boletos, reservas e chamados. Em Unidade você autoriza a entrada de visitantes e vê encomendas, veículos e pets.'],
    4 => ['bell', 'Fique de olho no sino', 'O sino, ao lado da sua foto, avisa de conta vencida, encomenda na portaria, visita do dia, comunicado novo e assembleia. No Perfil você liga a verificação em duas etapas e instala o app no celular.'],
];
$next = app_url('/boas-vindas?passo=' . ($step + 1));
?>
<section class="app-card app-welcome" aria-labelledby="welcome-title">
    <ol class="app-welcome__dots" aria-label="Passo <?= $step ?> de <?= $steps ?>">
        <?php for ($i = 1; $i <= $steps; $i++): ?><li<?= $i === $step ? ' aria-current="step"' : '' ?><?= $i < $step ? ' class="is-done"' : '' ?>></li><?php endfor; ?>
    </ol>
    <?php if ($step === 1): ?>
        <h1 id="welcome-title">Boas-vindas, <?= $this->e($first) ?>!</h1>
        <p>Este é o aplicativo do <?= $this->e($link['condominium_name']) ?>. Para começar, coloque uma foto sua. Ela aparece só para você, no topo do aplicativo.</p>
        <div class="app-welcome__photo">
            <?php if ($hasPhoto && !empty($photo)): ?>
                <img src="<?= $this->e($photo) ?>" alt="Sua foto" width="120" height="120">
            <?php else: ?>
                <span class="app-welcome__placeholder" aria-hidden="true"><svg><use href="#i-user"></use></svg></span>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= app_url('/perfil/foto') ?>" enctype="multipart/form-data" class="app-form app-welcome__form">
            <?= csrf_input() ?>
            <input type="hidden" name="voltar" value="boas-vindas">
            <label class="app-button app-button--ghost app-button--block app-file">
                <svg aria-hidden="true"><use href="#i-camera"></use></svg><?= $hasPhoto ? 'Trocar a foto' : 'Tirar ou escolher uma foto' ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required data-app-autosubmit-file>
            </label>
            <noscript><button class="app-button app-button--block" type="submit">Enviar foto</button></noscript>
        </form>
        <a class="app-button app-button--block" href="<?= $next ?>"><?= $hasPhoto ? 'Continuar' : 'Pular por enquanto' ?></a>
    <?php else: [$icon, $title, $text] = $tips[$step]; ?>
        <span class="app-welcome__icon" aria-hidden="true"><svg><use href="#i-<?= $icon ?>"></use></svg></span>
        <h1 id="welcome-title"><?= $this->e($title) ?></h1>
        <p><?= $this->e($text) ?></p>
        <?php if ($step < $steps): ?>
            <a class="app-button app-button--block" href="<?= $next ?>">Próxima dica</a>
        <?php else: ?>
            <form method="post" action="<?= app_url('/boas-vindas') ?>"><?= csrf_input() ?><button class="app-button app-button--block" type="submit">Começar a usar</button></form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($step < $steps): ?>
        <form method="post" action="<?= app_url('/boas-vindas') ?>" class="app-welcome__skip"><?= csrf_input() ?><button class="app-link-button" type="submit">Pular as dicas</button></form>
    <?php endif; ?>
</section>
