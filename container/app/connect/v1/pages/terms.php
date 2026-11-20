<?php
/**
 * Aceite dos Termos de Uso e da Política de Privacidade (LGPD, ADR 0603). Aparece no primeiro acesso e quando a versão muda;
 * sem o aceite, as outras telas do aplicativo voltam para cá.
 *
 * @var array $privacy (PrivacyCenter::status)
 */
$this->layout('default');
$updated = \Source\Services\Privacy\LegalDocuments::label($privacy['terms_version']);
?>
<section class="app-card app-terms" aria-labelledby="terms-title">
    <span class="app-welcome__icon" aria-hidden="true"><svg><use href="#i-shield"></use></svg></span>
    <h1 id="terms-title">Termos e privacidade</h1>
    <p>Para usar o aplicativo, leia e aceite os Termos de Uso e a Política de Privacidade (versão de <?= $this->e($updated) ?>).</p>
    <ul class="app-terms__list">
        <li>Usamos seus dados para administrar o condomínio: boletos, avisos, reservas, chamados e portaria.</li>
        <li>Não vendemos seus dados nem fazemos propaganda sem a sua autorização.</li>
        <li>Você pode pedir acesso, correção ou exclusão dos seus dados em Perfil &gt; Privacidade.</li>
    </ul>
    <p class="app-terms__links">
        <a href="<?= $this->e($privacy['terms_url']) ?>" target="_blank" rel="noopener">Ler os Termos de Uso</a>
        <a href="<?= $this->e($privacy['privacy_url']) ?>" target="_blank" rel="noopener">Ler a Política de Privacidade</a>
    </p>
    <form method="post" action="<?= app_url('/termos') ?>" class="app-form">
        <?= csrf_input() ?>
        <label class="app-check"><input type="checkbox" name="terms" value="1" required> Li e aceito os Termos de Uso e a Política de Privacidade.</label>
        <label class="app-check"><input type="checkbox" name="marketing" value="1"<?= $privacy['marketing'] ? ' checked' : '' ?>> Quero receber novidades da administradora (opcional).</label>
        <button class="app-button app-button--block" type="submit">Aceitar e continuar</button>
    </form>
    <form method="post" action="<?= app_url('/sair') ?>" class="app-welcome__skip"><?= csrf_input() ?><button class="app-link-button" type="submit">Não aceito, sair</button></form>
</section>
