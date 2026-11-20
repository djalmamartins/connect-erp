<?php
/**
 * Privacidade e meus dados (LGPD, ADR 0600): o que foi aceito, novidades (consentimento separado, dá para retirar) e
 * pedidos do titular (art. 18), que viram chamado LGPD no Help Desk com resposta em até 15 dias.
 *
 * @var array $privacy (PrivacyCenter::status)
 */
$this->layout('default');
?>
<section class="app-section">
    <h1>Privacidade e meus dados</h1>
    <p class="app-hint">Aceito em <?= $privacy['accepted_at'] ? $this->e(date('d/m/Y', strtotime((string)$privacy['accepted_at']))) : '—' ?>.
        <a href="<?= $this->e($privacy['terms_url']) ?>" target="_blank" rel="noopener">Termos de Uso</a> ·
        <a href="<?= $this->e($privacy['privacy_url']) ?>" target="_blank" rel="noopener">Política de Privacidade</a></p>
</section>
<section class="app-section">
    <h2>Novidades</h2>
    <form method="post" action="<?= app_url('/privacidade/novidades') ?>" class="app-form">
        <?= csrf_input() ?>
        <input type="hidden" name="marketing" value="<?= $privacy['marketing'] ? '0' : '1' ?>">
        <p><?= $privacy['marketing'] ? 'Você recebe novidades da administradora.' : 'Você não recebe novidades da administradora. Avisos do condomínio (boletos, assembleias, encomendas) continuam chegando.' ?></p>
        <button class="app-button app-button--ghost" type="submit"><?= $privacy['marketing'] ? 'Não quero mais receber novidades' : 'Quero receber novidades' ?></button>
    </form>
</section>
<section class="app-section">
    <h2>Pedir sobre meus dados</h2>
    <p class="app-hint">O pedido vira um chamado para a equipe da administradora, que responde em até <?= (int)$privacy['answer_days'] ?> dias. Dados que a lei manda guardar (por exemplo, a contabilidade do condomínio) não podem ser apagados; nesse caso, explicamos o motivo.</p>
    <form method="post" action="<?= app_url('/privacidade/pedido') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-field">O que você quer
            <select name="type" required>
                <option value="">Escolha</option>
                <?php foreach ($privacy['requests'] as $key => $label): ?><option value="<?= $this->e($key) ?>"><?= $this->e($label) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="app-field">Detalhes (opcional) <textarea name="details" rows="3" maxlength="2000"></textarea></label>
        <button class="app-button" type="submit">Enviar pedido</button>
    </form>
</section>
