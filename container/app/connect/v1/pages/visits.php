<?php
/** Visitas avisadas à portaria (ADR 0302). @var list<array> $visits */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$today = date('Y-m-d');
?>
<header class="app-page-head"><h1>Visitantes</h1><p>Avise a portaria de quem vem visitar você ou prestar um serviço.</p></header>
<details class="app-card app-form-card" open>
    <summary><h2>Avisar visita</h2></summary>
    <form method="post" action="<?= app_url('/visitantes') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-field">Nome do visitante <input type="text" name="visitor_name" maxlength="160" required autocomplete="off"></label>
        <label class="app-field">CPF ou RG (opcional) <input type="text" name="visitor_document" maxlength="30" inputmode="numeric" autocomplete="off"></label>
        <div class="app-field-row">
            <label class="app-field">Dia <input type="date" name="expected_on" min="<?= $today ?>" value="<?= $today ?>" required></label>
            <label class="app-field">Até (opcional) <input type="date" name="expected_until" min="<?= $today ?>"></label>
        </div>
        <label class="app-field">Observação <textarea name="notes" rows="2" maxlength="1000" placeholder="Ex.: técnico da internet, chega à tarde"></textarea></label>
        <button class="app-button" type="submit">Avisar a portaria</button>
    </form>
</details>
<section class="app-section">
    <h2>Avisadas</h2>
    <?php if ($visits === []): ?><p class="app-muted">Nenhuma visita avisada nos últimos 30 dias.</p><?php endif; ?>
    <ul class="app-list">
    <?php foreach ($visits as $v): [$label, $tone] = F::badge(F::VISIT, $v['status']); ?>
        <li class="app-card app-row">
            <span><strong><?= $this->e($v['visitor_name']) ?></strong>
                <small><?= F::date($v['expected_on']) ?><?= $v['expected_until'] && $v['expected_until'] !== $v['expected_on'] ? ' a ' . F::date($v['expected_until']) : '' ?><?= $v['visitor_document'] ? ' · doc. ' . $this->e($v['visitor_document']) : '' ?><?= $v['notes'] !== '' ? ' · ' . $this->e($v['notes']) : '' ?></small></span>
            <span class="app-row__end">
                <span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span>
                <?php if ($v['status'] === 'active'): ?>
                    <form method="post" action="<?= app_url('/visitantes/' . $v['id'] . '/cancelar') ?>" data-app-confirm="Cancelar o aviso desta visita?">
                        <?= csrf_input() ?><button class="app-link-button" type="submit">Cancelar</button>
                    </form>
                <?php endif; ?>
            </span>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
