<!--UPKEEP-->
<div class="empty-account-page">
    <div class="container">
        <div class="row congratz">
            <h1> Estamos atualizando o sistema!</h1><em></em>
            <h2 id="pathName"><em></em></h2>
        </div>
        <div class="row message">
            <p>O sistema <span id="website" style="word-break:break-all;"></span> está ganhando novos recursos! Por favor aguarde voltaremos em breve.</p>
        </div>

        <div class="footer">
            <div class="row">

                <div class="copyright text-center">
                    MOVES © <?php echo date("Y"); ?>. Todos os direitos reservados
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    var pathName = window.location.hostname;
    var account = document.getElementById("pathName");
    var accountText = document.getElementById("website");
    account.innerHTML = pathName;
    accountText.innerHTML = pathName;
</script>