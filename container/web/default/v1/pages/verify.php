<?php $this->layout("layouts/error"); ?>
<?php /** Verificação pública de documento (ADRs 0098 e 0115), no endereço fixo /verificar-certidao-debitos. Só mostra autêntico, tipo, data de emissão e condomínio. ajax_off: o scripts.js do site não intercepta este formulário. */ ?>
<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1>Verificar certidão ou documento</h1>
                <p>Digite o código de verificação impresso no rodapé do documento.</p>
            </header>
            <div class="main_login_form">
                <article>
                    <?php if ($result !== null): ?>
                        <div class="ajax_response verify_result verify_result--<?= $result['ok'] ? 'ok' : 'fail' ?>" role="status" data-verify-result="<?= $result['ok'] ? 'ok' : 'fail' ?>">
                            <?php if ($result['ok'] && is_array($result['document'])): ?>
                                <p><strong>✔ <?= $this->e($result['message']); ?></strong></p>
                                <p>Tipo: <?= $this->e($result['document']['kind']); ?><br>
                                    Emitido em: <?= $this->e($result['document']['issued_on']); ?><br>
                                    Condomínio: <?= $this->e($result['document']['condominium']); ?></p>
                            <?php else: ?>
                                <p><strong>✘ <?= $this->e($result['message']); ?></strong></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <form class="ajax_off" action="<?= $this->e($action); ?>" method="post" autocomplete="off">
                        <?= csrf_input(); ?>
                        <label>
                            <div><span class="icon-check-square-o">Código de verificação do documento:</span></div>
                            <input type="text" name="code" id="verify-code" maxlength="40" placeholder="XXXX-XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false" required/>
                        </label>
                        <button class="btn btn-full gradient gradient-web gradient-hover transition icon-check-square-o">Verificar</button>
                    </form>
                </article>
            </div>
        </div>
    </div>
</article>
<style>
.verify_result { border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
.verify_result--ok { background: #e8f5ee; border: 1px solid #2e7d4f; color: #1d5235; }
.verify_result--fail { background: #fdecec; border: 1px solid #b3261e; color: #7a1a14; }
.verify_result p { margin: 0 0 4px; }
</style>
<script>
/* ADR 0099 (documentos antigos): o link impresso traz o código no fragmento (/verificar#ABCD-EFGH-JKMN-PQRS), que o navegador não
   manda ao servidor. Aqui só preenche o campo e limpa o endereço; quem envia é a pessoa, pelo botão. */
(function () {
    var field = document.getElementById('verify-code');
    var hash = window.location.hash ? window.location.hash.slice(1) : '';
    if (!field || hash === '') { return; }
    var code;
    try { code = decodeURIComponent(hash); } catch (e) { code = ''; }
    code = code.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 24);
    if (/^[A-HJ-NP-Z2-9]{4}(-?[A-HJ-NP-Z2-9]{4}){3}$/.test(code) && field.value === '') {
        field.value = code;
        field.focus();
    }
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
})();
</script>
