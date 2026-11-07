<div class="one">
    <?php if (!$users): ?>
        <div class="message info icon-info">Ainda não existem usuários cadastrados neste mês.</div>
    <?php else: ?>
    <?php foreach ($users as $user):
    $userPhoto = ($user->photo() ? image($user->photo, 354, 472) :
        theme("/assets/images/avatar.jpg", CONF_VIEW_STUDIO));
    ?>
    <article class="app_launch_item">
        <div class="dados">
            <span>Nome: <?= $user->first_name; ?></span>
            <span>Registro: <?= str_pad($user->id, 6, '0', STR_PAD_LEFT); ?> </span>
            <span>Data Nasc: <?= date_br_fmt($user->datebirth); ?></span>
            <span>CPF: <?= fmt_cpf_cnpj($user->document); ?></span>
            <span>Telefone: <?= fmt_phone($user->phone); ?></span>
            <span>Celular: <?= fmt_phone($user->cell); ?></span>
            <span>E-mail: <?= $user->email; ?></span>
            <span>Pombal: <?= $user->loft; ?></span>

            <?php if (!$user->addresses()->count()): ?>
                <div class="msg icon-info al-center">
                    Ainda não cadastrou endereço.
                </div>
            <?php else: ?>
                <?php foreach ($user->addresses()->fetch(true) as $address): ?>
                    <span>CEP: <?= fmt_cep($address->code); ?></span>
                    <span>Cidade: <?= $address->city; ?> - <?= $address->state; ?></span>
                    <span>Bairro: <?= $address->district; ?></span>
                    <span>Logradouro: <?= $address->street; ?></span>
                    <span>Numero: <?= $address->number; ?></span>
                    <span>Complemento: <?= $address->complement; ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="img">
            <?php if ($user->photo()): ?>
                <img src="<?= $userPhoto ?>" alt="<?= $user->first_name; ?>">
            <?php endif; ?>
        </div>
    </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
