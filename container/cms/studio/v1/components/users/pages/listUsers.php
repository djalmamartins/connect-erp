<html>
<head>

    <title>Lista de Usuários
    </title>
    <style>
        @page {
            margin: 30px 0;
        }

        body {
            margin: 2em;
            padding: 0;
            font-family: "Open Sans", serif;
        }

        .header {
            position: fixed;
            top: -30px;
            left: 0;
            right: 0;

            width: 100%;
            text-align: center;
            padding: 2em;
        }

        .header img {
            width: 120px;
        }


        .header p {
            font-size: small;
            float: left;
        }

        .footer {
            position: fixed;

            bottom: 0px;
            left: 0;

            width: 100%;
            padding: 1em 1em;
            text-align: left;
            background: #E4E4E4;
            color: #000000;
            font-size: 10px;
            text-transform: uppercase;
        }

        .footer span {
            float: right;
        }

        .footer .page:after {
            content: counter(page);
        }

        table {
            width: 100%;
            border: 0px solid #555555;
            margin: 0;
            padding: 0;
        }

        th {
            text-transform: uppercase;
        }

        table, th, td {
            border: 0px solid #555555;
            border-collapse: collapse;
            text-align: left;
            padding: 10px;
        }

        tr {
            background: #FFF;
        }

        tr:nth-child(2n+1) {
            background: #F8F8F8;
        }

        p {
            color: #888888;
            margin: 0;
            text-align: center;
            font-size: 0.875em;
        }

        span {
            display: block;
            text-transform: uppercase;
            font-size: 0.875em;
        }


    </style>
</head>
<body>
<div class="header">
    Lista de Usuários
</div>

<div class="footer">
    Gerado dia <?= date_long(); ?> <span class="page">Página </span>
</div>

<div class="content">

    <?php if (!$users): ?>
        <div class="message info icon-info">Ainda não existem usuários cadastrados.</div>
    <?php else: ?>
        <table>
            <?php foreach ($users as $user):
                $userPhoto = ($user->photo() ? image($user->photo, 354, 472) :
                    theme("/assets/images/avatar.jpg", CONF_VIEW_STUDIO)); ?>
                <?php if ($user->id == 1): ?>


            <?php else: ?>
                <?php if (!$user->addresses()->count()): ?>

                <?php else: ?>
                    <tr>
                        <td>
                            <span>Nome: <?= $user->first_name; ?></span>
                            <span>Registro: <?= str_pad($user->id, 6, '0', STR_PAD_LEFT); ?> </span>
                            <span>Data Nasc: <?= date_br_fmt($user->datebirth); ?></span>
                            <span>CPF: <?= fmt_cpf_cnpj($user->document); ?></span>
                            <span>Telefone: <?= fmt_phone($user->phone); ?></span>
                            <span>Celular: <?= fmt_phone($user->cell); ?></span>
                            <span>E-mail: <?= $user->email; ?></span>
                            <span>Pombal: <?= $user->loft; ?></span>
                        </td>
                        <td>
                            <?php foreach ($user->addresses()->fetch(true) as $address): ?>
                                <span>CEP: <?= fmt_cep($address->code); ?></span>
                                <span>Cidade: <?= $address->city; ?> - <?= $address->state; ?></span>
                                <span>Bairro: <?= $address->district; ?></span>
                                <span>Logradouro: <?= $address->street; ?></span>
                                <span>Numero: <?= $address->number; ?></span>
                                <span>Complemento: <?= $address->complement; ?></span>
                            <?php endforeach; ?>

                        </td>
                        <td>
                            <?php if ($user->photo()): ?>
                                <a href="<?= $userPhoto ?>">Baixar Foto</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
            <?php endforeach; ?></table>
    <?php endif; ?>

</div>

</body>
</html>
