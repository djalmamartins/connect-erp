<?php
/** @var list<array{id:int,name:string,document:?string,status:string}> $condominiums */
$this->layout('default');
?>
<header class="erp-page-header">
    <h1>Condomínios</h1>
    <p>Condomínios administrados pela Connect aos quais você tem acesso.</p>
</header>

<?php if ($condominiums === []): ?>
    <section class="erp-state erp-state-empty" role="status">
        <h2>Nenhum condomínio disponível</h2>
        <p>Você ainda não tem acesso a nenhum condomínio ativo. Solicite o vínculo ao administrador do sistema.</p>
    </section>
<?php else: ?>
    <div class="erp-table-wrap">
        <table class="erp-table">
            <caption class="erp-visually-hidden">Condomínios acessíveis (<?= count($condominiums) ?>)</caption>
            <thead>
            <tr><th scope="col">Nome</th><th scope="col">CNPJ</th><th scope="col">Situação</th></tr>
            </thead>
            <tbody>
            <?php foreach ($condominiums as $condominium): ?>
                <tr>
                    <td><a href="<?= url('/erp/condominios/' . (int)$condominium['id']) ?>"><?= $this->e($condominium['name']) ?></a></td>
                    <td><?= $this->e(format_cnpj($condominium['document'])) ?></td>
                    <td><?= $condominium['status'] === 'active' ? 'Ativo' : 'Inativo' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
