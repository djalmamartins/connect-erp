<?php
/** Visitas avisadas à portaria (ADR 0302). @var list<array> $visits */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$today = date('Y-m-d');
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Visitantes</h1><p>Avise a portaria de quem vem visitar você ou prestar um serviço.</p></header>
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
    <?php if ($visits === []): ?><?php $this->insert('partials/empty', ['icon' => 'user-check', 'text' => 'Nenhuma visita avisada nos últimos 30 dias.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($visits as $v): [$label, $tone] = F::badge(F::VISIT, $v['status']);
        $cancel = $v['status'] === 'active' ? '<form method="post" action="' . app_url('/visitantes/' . $v['id'] . '/cancelar') . '" data-app-confirm="Cancelar o aviso desta visita?">' . csrf_input() . '<button class="app-link-button" type="submit">Cancelar</button></form>' : null;
        $this->insert('partials/item', ['icon' => 'user-check', 'tone' => $v['status'] === 'active' ? 'brand' : 'neutral', 'title' => $v['visitor_name'],
            'lines' => [F::date($v['expected_on']) . ($v['expected_until'] && $v['expected_until'] !== $v['expected_on'] ? ' a ' . F::date($v['expected_until']) : '') . ($v['visitor_document'] ? ' · doc. ' . $v['visitor_document'] : ''), (string)$v['notes']],
            'badge' => [$label, $tone], 'actions' => $cancel]);
    endforeach; ?>
    </ul>
</section>
