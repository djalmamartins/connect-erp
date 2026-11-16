<?php
/**
 * Termo de entrada e de saída: texto por condomínio ou geral da Connect (AP-006, ADR 0311; modelo 2 da revisão de 15/11).
 * A escolha de condomínio e tipo recarrega o texto em vigor (GET); salvar é POST com CSRF. O texto é puro: as palavras-chave
 * são trocadas e escapadas quando o termo é gerado no painel da unidade.
 * @var list<array{id:int,name:string}> $termCondominiums @var ?int $termCondominium @var string $termKind @var bool $termGlobal
 * @var ?array $termCurrent @var ?string $termDraft @var ?string $termError @var ?\Source\Support\Message $flash
 */
use Source\Services\Erp\UnitMoveTerms;

$this->layout('default');
$route = erp_url(\Source\Controllers\Erp\Connect\UnitMoveTermScreen::ROUTE);
$sources = ['condominium' => 'Texto próprio deste condomínio.', 'general' => 'Este condomínio usa o texto geral da Connect. Ao salvar, ele passa a ter texto próprio.', 'default' => 'Ainda não há texto salvo: este é o texto padrão do sistema. Ajuste e salve.'];
ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e(erp_url('/condominios/unidades')) ?>">Voltar para Unidades</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups']['condominios']['label'] ?? 'Condomínios',
    'pageTitle' => 'Termo de entrada/saída',
    'pageLead' => 'Texto do termo que o morador assina ao entrar ou sair da unidade. Gere o termo de cada pessoa no painel da unidade.',
    'pageActions' => $actions,
]);
?>
<?php if ($flash !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : 'danger' ?>" role="status"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>
<div class="erp-stack">
    <div class="erp-alert erp-alert--info" role="note"><?= $icon('info') ?><div><strong>Condomínio "Geral (Connect)"</strong> salva o texto usado por todos os condomínios que não têm termo próprio.<?= $termGlobal ? '' : ' Só quem acessa todos os condomínios altera o texto geral.' ?></div></div>

    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Escolher o termo">
        <div class="erp-field"><label class="erp-field__label" for="mt-condominium">Condomínio</label>
            <select class="erp-select" id="mt-condominium" name="condominio" data-erp-autosubmit>
                <?php if ($termGlobal): ?><option value="geral"<?= $termCondominium === null ? ' selected' : '' ?>>Geral (Connect)</option><?php elseif ($termCondominium === null): ?><option value="" selected>Selecione</option><?php endif; ?>
                <?php foreach ($termCondominiums as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $termCondominium ? ' selected' : '' ?>><?= $this->e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="erp-field"><label class="erp-field__label" for="mt-kind">Tipo</label>
            <select class="erp-select" id="mt-kind" name="tipo" data-erp-autosubmit>
                <?php foreach (['entrada' => 'Entrada', 'saida' => 'Saída'] as $value => $label): ?><option value="<?= $value ?>"<?= $value === $termKind ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
        </div>
        <noscript><div class="erp-field"><span class="erp-field__label">&nbsp;</span><button class="erp-btn" type="submit">Abrir</button></div></noscript>
    </form>

    <?php if ($termCurrent === null): ?>
        <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong><?= $this->e($termError ?? 'Escolha um condomínio') ?></strong><p>Escolha o condomínio na barra superior ou no campo acima.</p></div></section>
    <?php else: ?>
    <form class="erp-card erp-form" method="post" action="<?= $this->e($route) ?>" data-erp-move-term aria-labelledby="mt-title">
        <?= csrf_input() ?>
        <input type="hidden" name="condominium_id" value="<?= $termCondominium === null ? '' : (int)$termCondominium ?>">
        <input type="hidden" name="kind" value="<?= $this->e($termKind) ?>">
        <div class="erp-card__head"><div><h2 id="mt-title"><?= $this->e(UnitMoveTerms::KINDS[$termKind]) ?></h2><p data-move-term-source="<?= $this->e($termCurrent['source']) ?>"><?= $this->e($termCondominium === null && $termCurrent['source'] === 'general' ? 'Texto geral da Connect.' : $sources[$termCurrent['source']]) ?></p></div></div>
        <?php if ($termError !== null): ?><div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($termError) ?></div></div><?php endif; ?>
        <div class="erp-field">
            <label class="erp-field__label" for="mt-body">Texto</label>
            <textarea class="erp-input erp-move-term__editor" id="mt-body" name="body" rows="16" maxlength="<?= UnitMoveTerms::MAX_LENGTH ?>" required aria-describedby="mt-keywords-title"><?= $this->e($termDraft ?? $termCurrent['body']) ?></textarea>
            <span class="erp-field__hint">Deixe uma linha em branco entre os parágrafos.</span>
        </div>
        <div class="erp-alert erp-alert--info erp-move-term__keywords" role="note">
            <?= $icon('info') ?>
            <div>
                <strong id="mt-keywords-title">Palavras-chave trocadas na hora de gerar o termo (clique para inserir no texto):</strong>
                <ul>
                    <?php foreach (UnitMoveTerms::KEYWORDS as $key => $label): ?>
                        <li><button type="button" class="erp-link-button" data-move-term-keyword="@<?= $key ?>">@<?= $key ?></button> <?= $this->e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="erp-form__actions">
            <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Salvar</button>
            <a class="erp-btn" href="<?= $this->e(erp_url('/condominios/unidades')) ?>">Ir para a lista de unidades</a>
        </div>
    </form>
    <?php endif; ?>
</div>
