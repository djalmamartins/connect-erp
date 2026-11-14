<?php
/**
 * Estado vazio padrão do aplicativo do morador: ícone da função, uma frase e, se houver, um botão.
 *
 * @var string $text
 * @var ?string $icon
 * @var ?array{0:string,1:string} $action [texto, endereço já montado]
 */
$icon ??= 'check';
$action ??= null;
?>
<div class="app-card app-empty app-empty--icon">
    <span class="app-empty__icon" aria-hidden="true"><svg><use href="#i-<?= $this->e($icon) ?>"></use></svg></span>
    <p><?= $this->e($text) ?></p>
    <?php if ($action !== null): ?><a class="app-button app-button--ghost" href="<?= $this->e($action[1]) ?>"><?= $this->e($action[0]) ?></a><?php endif; ?>
</div>
