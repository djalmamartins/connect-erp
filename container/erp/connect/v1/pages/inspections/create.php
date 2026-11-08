<?php
/**
 * Check-in: condomínio, modelo e posição do aparelho.
 * @var list<array> $condominiums @var list<array> $templates
 * @var array<int, array{latitude: float, longitude: float, radius_m: int}> $geolocations
 * @var array{condominium_id: int, template_id: int} $selected
 * @var array $old @var array<string, string> $errors @var string $base
 */
use Source\Services\Inspections\Schedule;

$this->layout('default');
$this->insert('pages/inspections/partials/assets', ['flash' => $flash]);
$condoId = (int)($old['condominium_id'] ?? $selected['condominium_id']);
$templateId = (int)($old['template_id'] ?? $selected['template_id']);
$error = static fn(string $key): string => $errors[$key] ?? '';
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Vistorias',
    'pageTitle' => 'Nova vistoria',
    'pageLead' => 'Chegou ao condomínio? Confirme a localização e comece o checklist.',
]);
?>
<?php if ($condominiums === [] || $templates === []): ?>
    <section class="erp-card">
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'info', 'emptyTitle' => $condominiums === [] ? 'Nenhum condomínio no seu acesso' : 'Nenhum modelo de checklist ativo',
            'emptyText' => $condominiums === [] ? 'Peça ao administrador o vínculo com os condomínios que você visita.' : 'Cadastre um modelo em Vistorias › Configurar.']); ?>
    </section>
<?php else: ?>
<form class="erp-card erp-form" method="post" action="<?= erp_url($base) ?>" data-insp-checkin data-insp-references="<?= $this->e(json_encode((object)$geolocations, JSON_THROW_ON_ERROR)) ?>" novalidate>
    <?= csrf_input() ?>
    <input type="hidden" name="latitude" value="">
    <input type="hidden" name="longitude" value="">
    <input type="hidden" name="accuracy" value="">
    <div class="erp-form__grid">
        <div class="erp-field erp-col-6">
            <label for="insp-condo">Condomínio <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <select class="erp-select" id="insp-condo" name="condominium_id" required<?= $error('condominium_id') ? ' aria-invalid="true" aria-describedby="insp-condo-error"' : '' ?>>
                <option value="">Selecione</option>
                <?php foreach ($condominiums as $condominium): ?>
                    <option value="<?= (int)$condominium['id'] ?>"<?= $condoId === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?><?= isset($geolocations[(int)$condominium['id']]) ? '' : ' (sem localização)' ?></option>
                <?php endforeach; ?>
            </select>
            <span class="erp-field__error" id="insp-condo-error"><?= $this->e($error('condominium_id')) ?></span>
        </div>
        <div class="erp-field erp-col-6">
            <label for="insp-template">Checklist <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label>
            <select class="erp-select" id="insp-template" name="template_id" required<?= $error('template_id') ? ' aria-invalid="true" aria-describedby="insp-template-error"' : '' ?>>
                <option value="">Selecione</option>
                <?php foreach ($templates as $template): ?>
                    <option value="<?= (int)$template['id'] ?>"<?= $templateId === (int)$template['id'] ? ' selected' : '' ?>><?= $this->e($template['name']) ?> · <?= $this->e(Schedule::label($template['frequency'])) ?> · <?= (int)$template['items'] ?> itens</option>
                <?php endforeach; ?>
            </select>
            <span class="erp-field__error" id="insp-template-error"><?= $this->e($error('template_id')) ?></span>
        </div>
        <div class="erp-col-12">
            <div class="insp-gps" data-insp-gps data-state="idle" aria-live="polite">
                <strong data-insp-gps-status>Localização não verificada</strong>
                <span class="insp-muted" data-insp-gps-detail>Ative a localização do celular. Sem ela, você precisará justificar o check-in.</span>
                <div><button type="button" class="erp-btn erp-btn--sm" data-insp-locate>Atualizar localização</button></div>
            </div>
        </div>
        <div class="erp-field erp-col-12" data-insp-note<?= $error('checkin_note') || !empty($old['checkin_note']) ? '' : ' hidden' ?>>
            <label for="insp-note">Justificativa do check-in</label>
            <textarea class="erp-textarea" id="insp-note" name="checkin_note" maxlength="500" rows="3"<?= $error('checkin_note') ? ' aria-invalid="true" aria-describedby="insp-note-error"' : '' ?>><?= $this->e((string)($old['checkin_note'] ?? '')) ?></textarea>
            <span class="erp-field__hint">Obrigatória quando você está fora do raio do condomínio ou sem localização. Vai para o relatório.</span>
            <span class="erp-field__error" id="insp-note-error"><?= $this->e($error('checkin_note')) ?></span>
        </div>
    </div>
    <div class="erp-form__actions insp-actions">
        <a class="erp-btn" href="<?= erp_url($base) ?>">Voltar</a>
        <button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Fazer check-in e começar</button>
    </div>
</form>
<?php endif; ?>
