<?php
/** @var array{id:int,name:string,document:?string,status:string} $condominium */
$this->layout('default');
?>
<nav class="erp-breadcrumb" aria-label="Trilha">
    <a href="<?= url('/erp/condominios') ?>">Condomínios</a> <span aria-hidden="true">/</span> <span aria-current="page"><?= $this->e($condominium['name']) ?></span>
</nav>
<header class="erp-page-header">
    <h1><?= $this->e($condominium['name']) ?></h1>
    <p><a href="<?= url('/erp/condominios/' . (int)$condominium['id'] . '/checklist') ?>">Checklist de chegada</a>: documentos, Bombeiros, manutenção e seguros a regularizar.</p>
</header>
<dl class="erp-details">
    <div><dt>CNPJ</dt><dd><?= $this->e(format_cnpj($condominium['document'])) ?></dd></div>
    <div><dt>Situação</dt><dd><?= $condominium['status'] === 'active' ? 'Ativo' : 'Inativo' ?></dd></div>
</dl>
