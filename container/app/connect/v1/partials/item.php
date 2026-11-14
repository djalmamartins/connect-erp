<?php
/**
 * Linha padrão das listas do aplicativo do morador (ajustes de 14/11): cartão com ícone (ou caixa de data), título,
 * linhas de detalhe, selo e seta. Todas as telas usam esta peça para ficarem iguais.
 *
 * @var string $title
 * @var list<string> $lines        textos simples (escapados aqui)
 * @var ?string $icon              id do ícone sem "i-" (box, file, chart...)
 * @var ?string $tone              brand|success|warning|danger|info|neutral (cor do ícone)
 * @var ?string $date              Y-m-d[...] mostra a caixa de data no lugar do ícone
 * @var ?string $href
 * @var bool $external             abre em outra aba
 * @var ?array{0:string,1:string} $badge [texto, tom]
 * @var ?string $flag              selo pequeno "Novo"
 * @var ?string $end               chevron|download|null
 * @var ?string $actions           HTML pronto (formulário de cancelar etc.)
 * @var ?string $body              HTML pronto: a linha abre e fecha para mostrar este conteúdo
 * @var ?string $id
 */
$lines ??= [];
$icon ??= null;
$tone ??= 'brand';
$date ??= null;
$href ??= null;
$external ??= false;
$badge ??= null;
$flag ??= null;
$end ??= $href !== null ? 'chevron' : null;
$actions ??= null;
$body ??= null;
$id ??= null;
$months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
$tag = $body !== null ? 'summary' : ($href !== null ? 'a' : 'div');
$attrs = $tag === 'a' ? ' href="' . $this->e($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') : '';
?>
<li class="app-item-wrap"<?= $id !== null ? ' id="' . $this->e($id) . '"' : '' ?>>
<?php if ($body !== null): ?><details class="app-card app-item-card"><?php endif; ?>
<<?= $tag ?> class="<?= $body !== null ? 'app-item' : 'app-card app-item' ?>"<?= $attrs ?>>
    <?php if ($date !== null): ?>
        <span class="app-date" aria-hidden="true"><strong><?= substr($date, 8, 2) ?></strong><?= $months[(int)substr($date, 5, 2) - 1] ?? '' ?></span>
    <?php elseif ($icon !== null): ?>
        <span class="app-item__icon app-item__icon--<?= $this->e($tone) ?>" aria-hidden="true"><svg><use href="#i-<?= $this->e($icon) ?>"></use></svg></span>
    <?php endif; ?>
    <span class="app-item__text">
        <strong><?= $this->e($title) ?></strong>
        <?php foreach ($lines as $line): if ($line === '') { continue; } ?><small><?= $this->e($line) ?></small><?php endforeach; ?>
    </span>
    <?php if ($badge !== null || $flag !== null || $actions !== null): ?>
        <span class="app-item__end">
            <?php if ($flag !== null): ?><span class="app-feed__new"><?= $this->e($flag) ?></span><?php endif; ?>
            <?php if ($badge !== null): ?><span class="app-badge app-badge--<?= $this->e($badge[1]) ?>"><?= $this->e($badge[0]) ?></span><?php endif; ?>
            <?= $actions ?? '' ?>
        </span>
    <?php endif; ?>
    <?php if ($body !== null): ?>
        <svg class="app-item__go app-item__toggle" aria-hidden="true"><use href="#i-chevron"></use></svg>
    <?php elseif ($end !== null): ?>
        <svg class="app-item__go" aria-hidden="true"><use href="#i-<?= $end === 'download' ? 'download' : 'chevron' ?>"></use></svg>
    <?php endif; ?>
</<?= $tag ?>>
<?php if ($body !== null): ?><div class="app-item__body"><?= $body ?></div></details><?php endif; ?>
</li>
