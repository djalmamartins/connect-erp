<?php
/**
 * Configuração das vistorias: modelos de checklist, planos por condomínio e localização.
 * @var list<array> $templates @var ?array $editing @var string $editingText
 * @var list<array> $plans @var list<array> $condominiums
 * @var array<int, array{latitude: float, longitude: float, radius_m: int}> $geolocations
 * @var array $old @var array<string, string> $errors @var string $base
 */
use Source\Services\Inspections\Schedule;

$this->layout('default');
$this->insert('pages/inspections/partials/assets', ['flash' => $flash]);
$value = static fn(string $key, $default = '') => $old[$key] ?? $default;
$err = function (string $key) use ($errors): string {
    return isset($errors[$key]) ? '<span class="erp-field__error">' . $this->e($errors[$key]) . '</span>' : '';
};
$frequencyOptions = static function (string $selected): string {
    $html = '';
    foreach (Schedule::FREQUENCIES as $key => $label) {
        $html .= '<option value="' . $key . '"' . ($key === $selected ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html;
};
ob_start(); ?>
    <a class="erp-btn" href="<?= erp_url($base) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Vistorias</a>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Vistorias',
    'pageTitle' => 'Configurar vistorias',
    'pageLead' => 'Modelos de checklist, quais condomínios seguem cada modelo e onde fica cada condomínio para o check-in.',
    'pageActions' => $actions,
]);
?>
<section class="erp-card" id="modelos">
    <div class="erp-card__head"><div><h2>Modelos de checklist</h2><p>Mudanças valem para as próximas vistorias; as já iniciadas guardam a versão usada.</p></div>
        <a class="erp-btn erp-btn--sm" href="<?= erp_url($base . '/configurar#modelo') ?>"><?= $icon('plus', 'erp-icon--sm') ?>Novo modelo</a></div>
    <div class="erp-table-wrap">
        <table class="erp-table">
            <thead><tr><th scope="col">Modelo</th><th scope="col">Periodicidade</th><th scope="col">Itens</th><th scope="col">Situação</th><th scope="col"><span class="erp-sr-only">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($templates as $template): ?>
                <tr>
                    <td><?= $this->e($template['name']) ?><?php if ($template['description']): ?><br><small class="insp-muted"><?= $this->e($template['description']) ?></small><?php endif; ?></td>
                    <td><?= $this->e(Schedule::label($template['frequency'])) ?></td>
                    <td><?= (int)$template['items'] ?></td>
                    <td><span class="erp-badge erp-badge--<?= $template['status'] === 'active' ? 'success' : 'plain' ?>"><?= $template['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span></td>
                    <td><a class="erp-btn erp-btn--sm" href="<?= erp_url($base . '/configurar?modelo=' . (int)$template['id'] . '#modelo') ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form class="erp-form" id="modelo" method="post" action="<?= erp_url($base . '/modelos') ?>" style="margin-top:20px">
        <?= csrf_input() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
        <h3 style="font-size:15px;margin:0"><?= $editing ? 'Editar ' . $this->e($editing['name']) : 'Novo modelo' ?></h3>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-6"><label for="tpl-name">Nome</label>
                <input class="erp-input" id="tpl-name" name="name" maxlength="120" required value="<?= $this->e((string)$value('name', $editing['name'] ?? '')) ?>"><?= $err('name') ?></div>
            <div class="erp-field erp-col-3"><label for="tpl-frequency">Periodicidade padrão</label>
                <select class="erp-select" id="tpl-frequency" name="frequency"><?= $frequencyOptions((string)$value('frequency', $editing['frequency'] ?? 'monthly')) ?></select><?= $err('frequency') ?></div>
            <div class="erp-field erp-col-3"><label for="tpl-status">Situação</label>
                <select class="erp-select" id="tpl-status" name="status">
                    <?php $status = (string)$value('status', $editing['status'] ?? 'active'); ?>
                    <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Ativo</option>
                    <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inativo</option>
                </select></div>
            <div class="erp-field erp-col-12"><label for="tpl-description">Descrição</label>
                <input class="erp-input" id="tpl-description" name="description" maxlength="500" value="<?= $this->e((string)$value('description', $editing['description'] ?? '')) ?>"><?= $err('description') ?></div>
            <div class="erp-field erp-col-12"><label for="tpl-items">Itens do checklist</label>
                <textarea class="erp-textarea insp-template-text" id="tpl-items" name="items" required><?= $this->e((string)$value('items', $editingText)) ?></textarea>
                <span class="erp-field__hint">Um item por linha. Linha começando com # abre uma seção (ex.: # Garagem). Termine o item com [foto] para exigir foto.</span>
                <?= $err('items') ?></div>
        </div>
        <div class="insp-actions">
            <?php if ($editing): ?><a class="erp-btn" href="<?= erp_url($base . '/configurar#modelos') ?>">Cancelar edição</a><?php endif; ?>
            <button class="erp-btn erp-btn--primary" type="submit">Salvar modelo</button>
        </div>
    </form>
</section>

<section class="erp-card" id="planos">
    <div class="erp-card__head"><div><h2>Planos por condomínio</h2><p>Define o que cada condomínio precisa receber e com que frequência. A programação usa a data da última vistoria concluída.</p></div></div>
    <?php if ($plans !== []): ?>
        <div class="erp-table-wrap">
            <table class="erp-table">
                <thead><tr><th scope="col">Condomínio</th><th scope="col">Modelo</th><th scope="col">Periodicidade</th><th scope="col">Início</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><?= $this->e($plan['condominium']) ?></td><td><?= $this->e($plan['template']) ?></td>
                        <td><?= $this->e(Schedule::label($plan['frequency'])) ?></td><td><?= date('d/m/Y', strtotime($plan['starts_on'])) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $plan['status'] === 'active' ? 'success' : 'plain' ?>"><?= $plan['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <form class="erp-form" method="post" action="<?= erp_url($base . '/planos') ?>" style="margin-top:16px">
        <?= csrf_input() ?>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-4"><label for="plan-condo">Condomínio</label>
                <select class="erp-select" id="plan-condo" name="condominium_id" required><option value="">Selecione</option>
                    <?php foreach ($condominiums as $condominium): ?><option value="<?= (int)$condominium['id'] ?>"<?= (int)$value('condominium_id') === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option><?php endforeach; ?>
                </select><?= $err('condominium_id') ?></div>
            <div class="erp-field erp-col-4"><label for="plan-template">Modelo</label>
                <select class="erp-select" id="plan-template" name="template_id" required><option value="">Selecione</option>
                    <?php foreach ($templates as $template): if ($template['status'] !== 'active') { continue; } ?><option value="<?= (int)$template['id'] ?>"<?= (int)$value('template_id') === (int)$template['id'] ? ' selected' : '' ?>><?= $this->e($template['name']) ?></option><?php endforeach; ?>
                </select><?= $err('template_id') ?></div>
            <div class="erp-field erp-col-4"><label for="plan-frequency">Periodicidade</label>
                <select class="erp-select" id="plan-frequency" name="frequency"><option value="">Do modelo</option><?= $frequencyOptions((string)$value('frequency')) ?></select><?= $err('frequency') ?></div>
            <div class="erp-field erp-col-4"><label for="plan-start">Primeira vistoria em</label>
                <input class="erp-input" type="date" id="plan-start" name="starts_on" required value="<?= $this->e((string)$value('starts_on', date('Y-m-d'))) ?>"><?= $err('starts_on') ?></div>
            <div class="erp-field erp-col-4"><label for="plan-status">Situação</label>
                <select class="erp-select" id="plan-status" name="status"><option value="active">Ativo</option><option value="inactive"<?= $value('status') === 'inactive' ? ' selected' : '' ?>>Inativo</option></select></div>
        </div>
        <span class="erp-field__hint">Salvar de novo o mesmo condomínio e modelo altera o plano existente.</span>
        <div class="insp-actions"><button class="erp-btn erp-btn--primary" type="submit">Salvar plano</button></div>
    </form>
</section>

<section class="erp-card" id="localizacao">
    <div class="erp-card__head"><div><h2>Localização dos condomínios</h2><p>Ponto de referência e raio aceito no check-in. No próprio condomínio, use "Usar minha localização".</p></div></div>
    <?php if ($condominiums !== []): ?>
        <div class="erp-table-wrap">
            <table class="erp-table">
                <thead><tr><th scope="col">Condomínio</th><th scope="col">Coordenadas</th><th scope="col">Raio</th></tr></thead>
                <tbody>
                <?php foreach ($condominiums as $condominium): $geo = $geolocations[(int)$condominium['id']] ?? null; ?>
                    <tr>
                        <td><?= $this->e($condominium['name']) ?></td>
                        <td><?= $geo ? number_format($geo['latitude'], 6, '.', '') . ', ' . number_format($geo['longitude'], 6, '.', '') : '<span class="erp-badge erp-badge--warning">Não cadastrada</span>' ?></td>
                        <td><?= $geo ? (int)$geo['radius_m'] . ' m' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <form class="erp-form" method="post" action="<?= erp_url($base . '/localizacao') ?>" style="margin-top:16px">
        <?= csrf_input() ?>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-3"><label for="geo-condo">Condomínio</label>
                <select class="erp-select" id="geo-condo" name="condominium_id" required><option value="">Selecione</option>
                    <?php foreach ($condominiums as $condominium): ?><option value="<?= (int)$condominium['id'] ?>"<?= (int)$value('condominium_id') === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option><?php endforeach; ?>
                </select><?= $err('condominium_id') ?></div>
            <div class="erp-field erp-col-3"><label for="geo-lat">Latitude</label>
                <input class="erp-input" id="geo-lat" name="latitude" inputmode="decimal" placeholder="-22.9056000" required value="<?= $this->e((string)$value('latitude')) ?>"><?= $err('latitude') ?></div>
            <div class="erp-field erp-col-3"><label for="geo-lng">Longitude</label>
                <input class="erp-input" id="geo-lng" name="longitude" inputmode="decimal" placeholder="-47.0608000" required value="<?= $this->e((string)$value('longitude')) ?>"><?= $err('longitude') ?></div>
            <div class="erp-field erp-col-3"><label for="geo-radius">Raio (m)</label>
                <input class="erp-input" id="geo-radius" name="radius_m" type="number" min="30" max="2000" step="10" value="<?= $this->e((string)$value('radius_m', '150')) ?>"><?= $err('radius_m') ?></div>
        </div>
        <span class="erp-field__hint">Dica: no Google Maps, toque e segure no condomínio para copiar as coordenadas.</span>
        <div class="insp-actions">
            <button class="erp-btn" type="button" data-insp-use-here>Usar minha localização</button>
            <button class="erp-btn erp-btn--primary" type="submit">Salvar localização</button>
        </div>
    </form>
</section>
