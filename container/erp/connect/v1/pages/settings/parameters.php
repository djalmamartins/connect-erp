<?php
/**
 * Parâmetros de uma página de configurações (ADR 0144, sobre o ADR 0085) com dados reais: cada chave de
 * ErpSettings::CATALOG desta página, com o valor que vale no condomínio da barra superior ("Todos" = padrão
 * da Connect) e de onde ele vem. Salvar e voltar ao padrão usam a API /api/v1/settings (erp.js,
 * data-erp-settings); a API confere a permissão e o escopo de novo.
 * @var array $currentPage @var array $settingsGroup @var ?int $settingsContext @var bool $canManageSettings @var bool $canEditDefault
 */
use Source\Controllers\Erp\Connect\SettingsScreens as Screens;

$this->layout('default');
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condoName ?? 'o condomínio');
$context = $settingsContext;
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $context !== null
        ? 'Parâmetros que valem para ' . $condoName . '. O que não for próprio do condomínio vem do padrão da Connect.'
        : 'Padrão da Connect: vale para todos os condomínios que não têm valor próprio. Escolha um condomínio na barra superior para dar a ele um valor próprio.',
    'pageActions' => '',
]);
?>
<div class="erp-stack" data-erp-settings data-api="<?= $this->e(url('/api/v1/settings')) ?>" data-condominium="<?= $context !== null ? (int)$context : '' ?>">
    <?= csrf_input() ?>
    <?php if ($context === null && !$canEditDefault): ?>
        <div class="erp-alert" role="note"><?= $icon('lock') ?><div>O padrão da Connect só muda com acesso a todos os condomínios. Escolha um condomínio na barra superior para ajustar o valor dele.</div></div>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="set-title">
        <div class="erp-card__head"><div><h2 id="set-title"><?= $this->e($settingsGroup['group'] ?: $currentPage['name']) ?></h2><p><?= count($settingsGroup['settings']) ?> parâmetro(s) · <?= $this->e($context !== null ? $condoName : 'padrão da Connect') ?></p></div></div>
        <?php if ($settingsGroup['settings'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('settings') ?></span><strong>Sem parâmetros nesta página</strong><p>Nada para configurar aqui por enquanto.</p></div>
        <?php else: ?>
            <?php foreach ($settingsGroup['settings'] as $i => $setting):
                $field = Screens::field($setting);
                [$sourceLabel, $sourceTone] = Screens::SOURCES[$setting['source']] ?? Screens::SOURCES['code'];
                $editable = $canManageSettings && $setting['editable_here'] && ($context !== null || $canEditDefault);
                $own = $context !== null ? $setting['source'] === 'condominium' : $setting['source'] === 'connect';
                $fid = 'set-' . $i;
                $attrs = '';
                foreach ($field['attrs'] as $name => $value) { $attrs .= ' ' . $name . '="' . $this->e($value) . '"'; }
            ?>
                <form class="erp-form" data-setting-form data-key="<?= $this->e($setting['key']) ?>" aria-label="<?= $this->e($setting['label']) ?>">
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-6">
                            <label for="<?= $fid ?>"><?= $this->e($setting['label']) ?><?= $setting['type'] === 'money' ? ' (R$)' : '' ?></label>
                            <?php if ($field['tag'] === 'select'): ?>
                                <select class="erp-select" id="<?= $fid ?>" name="value"<?= $editable ? '' : ' disabled' ?>>
                                    <?php foreach ($field['type'] === 'bool' ? ['1' => 'Sim', '0' => 'Não'] : (array)$setting['options'] as $value => $label): ?>
                                        <option value="<?= $this->e((string)$value) ?>"<?= (string)$value === $field['value'] ? ' selected' : '' ?>><?= $this->e((string)$label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="erp-input" type="<?= $field['type'] ?>" id="<?= $fid ?>" name="value" value="<?= $this->e($field['value']) ?>"<?= $attrs ?><?= $editable ? '' : ' disabled' ?>>
                            <?php endif; ?>
                            <small class="erp-muted"><span class="erp-badge erp-badge--<?= $sourceTone ?>"><?= $this->e($sourceLabel) ?></span> <?= $setting['scope'] === 'global' ? 'Vale para todos os condomínios.' : 'Cada condomínio pode ter o seu.' ?> Padrão do sistema: <?= $this->e(Screens::display(['value' => $setting['default']] + $setting)) ?>.</small>
                        </div>
                        <?php if ($editable): ?>
                            <div class="erp-col-6 erp-setting-actions">
                                <button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar</button>
                                <?php if ($own): ?><button type="button" class="erp-btn" data-setting-reset><?= $icon('close', 'erp-icon--sm') ?>Voltar ao padrão</button><?php endif; ?>
                            </div>
                        <?php elseif (!$setting['editable_here']): ?>
                            <div class="erp-field erp-col-6"><p class="erp-muted">Este parâmetro vale para todos os condomínios: ajuste com "Todos os condomínios" na barra superior.</p></div>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
