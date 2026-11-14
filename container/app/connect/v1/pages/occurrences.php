<?php
/** Ocorrências da unidade (ADR 0302). @var list<array> $occurrences */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><h1>Ocorrências</h1><p>Barulho, vazamento, dano ou qualquer problema no condomínio.</p></header>
<details class="app-card app-form-card"<?= $occurrences === [] ? ' open' : '' ?>>
    <summary><h2>Registrar ocorrência</h2></summary>
    <form method="post" action="<?= app_url('/ocorrencias') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-field">Assunto <input type="text" name="subject" maxlength="160" required></label>
        <label class="app-field">O que aconteceu <textarea name="description" rows="4" maxlength="4000" required></textarea></label>
        <div class="app-field-row">
            <label class="app-field">Data <input type="date" name="occurred_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
            <label class="app-field">Urgência
                <select name="priority"><?php foreach (F::PRIORITIES as $key => $label): ?><option value="<?= $key ?>"<?= $key === 'media' ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button class="app-button" type="submit">Registrar</button>
    </form>
</details>
<section class="app-section">
    <h2>Da minha unidade</h2>
    <?php if ($occurrences === []): ?><p class="app-muted">Nenhuma ocorrência registrada.</p><?php endif; ?>
    <?php foreach ($occurrences as $o): [$label, $tone] = F::badge(F::OCCURRENCE, $o['status']); ?>
        <details class="app-card app-area">
            <summary><span><strong><?= $this->e($o['subject']) ?></strong><small><?= F::date($o['occurred_on']) ?> · urgência <?= strtolower(F::PRIORITIES[$o['priority']] ?? $o['priority']) ?></small></span><span class="app-badge app-badge--<?= $tone ?>"><?= $label ?></span></summary>
            <p class="app-notice__body"><?= nl2br($this->e($o['description'])) ?></p>
            <?php if ($o['has_ticket']): ?><p class="app-hint">A administração abriu um atendimento para esta ocorrência.</p><?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>
