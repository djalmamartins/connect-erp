<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/users/sidebar.php"); ?>

<section class="dash_content_app">
    <?php if (!$user): ?>
        <header class="dash_content_app_header">
            <h2 class="icon-plus-circle">Novo Usuário</h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url($adminBase . "/users/user"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="create"/>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Nome Completo:</span>
                        <input type="text" name="first_name" placeholder="Primeiro nome" required/>
                    </label>


                <label class="label">
                    <span class="legend">Genero:</span>
                    <select name="genre">
                        <option value="male">Masculino</option>
                        <option value="female">Feminino</option>
                        <option value="other">Outros</option>
                    </select>
                </label>
                </div>

                <label class="label">
                    <span class="legend">Foto: (600x600px)</span>
                    <input type="file" name="photo"/>
                </label>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Nascimento:</span>
                        <input type="text" class="mask-date" name="datebirth" placeholder="dd/mm/yyyy"/>
                    </label>

                    <label class="label">
                        <span class="legend">Documento:</span>
                        <input class="mask-doc" type="text" name="document" placeholder="CPF do usuário"/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*E-mail:</span>
                        <input type="email" name="email" placeholder="Melhor e-mail" required/>
                    </label>

                    <label class="label">
                        <span class="legend">*Senha:</span>
                        <input type="password" name="password" placeholder="Senha de acesso" required/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Telefone:</span>
                        <input type="text"class="mask-phone" name="phone" placeholder="(00) 0000-0000"/>
                    </label>

                    <label class="label">
                        <span class="legend">*Celular:</span>
                        <input type="text" name="cell" class="mask-cell" placeholder="(00) 00000-0000"/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Level:</span>
                        <select name="level" required>
                            <option value="1">Usuário</option>
                            <option value="2">Usuário - Editor do seu Clube</option>
                            <option value="5">Administrador</option>
                        </select>
                    </label>

                    <label class="label">
                        <span class="legend">*Status:</span>
                        <select name="status" required>
                            <option value="registered">Registrado</option>
                            <option value="confirmed">Confirmado</option>
                        </select>
                    </label>
                </div>

                <div class="al-right">
                    <button class="btn btn-green icon-check-square-o">Criar Usuário</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <header class="dash_content_app_header">
            <h2 class="icon-user"><?= $user->first_name; ?></h2>
            <?php if($user->level == 10 ) : ?>

            <?php else : ?>
                <?php if (!$user->forget): ?>
                    <form class="auth_form" data-reset="true" action="<?= url($adminBase . "/users/forgets"); ?>" method="post">
                        <?= csrf_input(); ?>
                        <label>
                            <input type='hidden' name="id" value="<?= $user->id; ?>" required/>
                            <input type='hidden' name="email" value="<?= $user->email; ?>" required/>
                        </label>
                        <button class="btn btn-red icon-unlock-alt">Recuperar Senha</button>
                    </form>
                <?php else: ?>
                    <a href="#" style="text-decoration: none;"
                       onclick="window.open(
                               'https://api.whatsapp.com/send?text=Perdeu sua senha <?= $user->first_name; ?>?%0AVocê está recebendo está messagem pois foi solicitado a recuperação de senha.%0A➥ <?= url("/recuperar/{$user->forget}-{$user->email}"); ?> %0A● IMPORTANTE:%0ASe não foi você que solicitou ignore o e-mail.%0ASeus dados permanecem seguros.%0AAtenciosamente, equipe CBC.',
                               '_system', 'location=yes'); return false;">
                        <button class="btn btn-green icon-whatsapp">Enviar WhatsApp</button>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url($adminBase . "/users/user/{$user->id}"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="update"/>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Nome Completo:</span>
                        <input type="text" name="first_name" value="<?= $user->first_name; ?>"
                               placeholder="Primeiro nome" required/>
                    </label>


                <label class="label">
                    <span class="legend">Genero:</span>
                    <select name="genre">
                        <?php
                        $genre = $user->genre;
                        $select = function ($value) use ($genre) {
                            return ($genre == $value ? "selected" : "");
                        };
                        ?>
                        <option <?= $select("male"); ?> value="male">Masculino</option>
                        <option <?= $select("female"); ?> value="female">Feminino</option>
                        <option <?= $select("other"); ?> value="other">Outros</option>
                    </select>
                </label>
                </div>

                <label class="label">
                    <span class="legend">Foto: (600x600px)</span>
                    <input type="file" name="photo"/>
                </label>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Nascimento:</span>
                        <input type="text" class="mask-date" value="<?= date_fmt($user->datebirth, "d/m/Y"); ?>"
                               name="datebirth" placeholder="dd/mm/yyyy"/>
                    </label>

                    <label class="label">
                        <span class="legend">Documento:</span>
                        <input class="mask-doc" type="text" value="<?= $user->document; ?>" name="document"
                               placeholder="CPF do usuário"/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*E-mail:</span>
                        <input type="email" name="email" value="<?= $user->email; ?>" placeholder="Melhor e-mail"
                               required/>
                    </label>

                    <label class="label">
                        <span class="legend">Alterar Senha:</span>
                        <?php if($user->level == 10 ) : ?>
                            <input type="password" name="password" placeholder="Não pode ser alterado" disabled=""/>
                        <?php else : ?>
                            <input type="password" name="password" placeholder="Senha de acesso"/>
                        <?php endif; ?>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Telefone:</span>
                        <input type="text"class="mask-phone" name="phone" value="<?= $user->phone; ?>" placeholder="(00) 0000-0000"/>
                    </label>

                    <label class="label">
                        <span class="legend">Celular:</span>
                        <input type="text" name="cell" class="mask-cell" value="<?= $user->cell; ?>" placeholder="(00) 00000-0000"/>
                    </label>
                </div>


                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Level:</span>
                        <?php if($user->level == 10 ) : ?>

                            <select name="level" required>
                                <?php
                                $level = $user->level;
                                $select = function ($value) use ($level) {
                                    return ($level == $value ? "selected" : "");
                                };
                                ?>
                                <option <?= $select(10); ?> value="5">Agência Moves</option>
                            </select>
                        <?php else : ?>
                            <select name="level" required>
                                <?php
                                $level = $user->level;
                                $select = function ($value) use ($level) {
                                    return ($level == $value ? "selected" : "");
                                };
                                ?>
                                <option <?= $select(1); ?> value="1">Usuário</option>
                                <option <?= $select(2); ?> value="2">Usuário - Editor do seu Clube</option>
                                <option <?= $select(5); ?> value="5">Administrador</option>
                            </select>

                        <?php endif; ?>
                    </label>

                    <label class="label">
                        <span class="legend">*Status:</span>
                        <select name="status" required>
                            <?php
                            $status = $user->status;
                            $select = function ($value) use ($status) {
                                return ($status == $value ? "selected" : "");
                            };
                            ?>
                            <option <?= $select("registered"); ?> value="registered">Registrado</option>
                            <option <?= $select("confirmed"); ?> value="confirmed">Confirmado</option>
                        </select>
                    </label>
                </div>

                <div class="app_form_footer">
                    <button class="btn btn-blue icon-check-square-o">Atualizar</button>

                    <?php if($user->level == 10 ) : ?>

                    <?php else : ?>
                        <a href="#" class="remove_link icon-warning"
                           data-post="<?= url($adminBase . "/users/user/{$user->id}"); ?>"
                           data-action="delete"
                           data-confirm="ATENÇÃO: Tem certeza que deseja excluir o usuário e todos os dados relacionados a ele? Essa ação não pode ser feita!"
                           data-user_id="<?= $user->id; ?>">Excluir Usuário</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url($adminBase . "/users/address"); ?>" method="post">
                <main>
                    <?php if (!$address): ?>
                        <input type="hidden" name="create" value="true"/>
                        <input type="hidden" name="user_id" value="<?= $user->id; ?>"/>
                    <?php else: ?>
                        <input type="hidden" name="update" value="true"/>
                        <input type="hidden" name="id" value="<?= $address->id; ?>"/>
                        <input type="hidden" name="user_id" value="<?= $user->id; ?>"/>
                    <?php endif; ?>
                    <div class="label_g2">
                        <label class="label">
                            <span class="field icon-map">*CEP:</span>
                            <input class="radius mask-cep" type="text" name="code" id="cep" placeholder="00000-000" required
                                   value="<?=($address->code ?? ""); ?>"/>
                        </label>

                        <label class="label">
                            <span class="field icon-map">*Estado:</span>
                            <select name="state" id="uf">

                                <?php if (!$address): ?>
                                    <option value="">Selecione</option>
                                    <option value="AC">&ofcir; Acre</option>
                                    <option value="AL">&ofcir; Alagoas</option>
                                    <option value="AP">&ofcir; Amapá</option>
                                    <option value="AM">&ofcir; Amazonas</option>
                                    <option value="BA">&ofcir; Bahia</option>
                                    <option value="CE">&ofcir; Ceará</option>
                                    <option value="DF">&ofcir; Distrito Federal</option>
                                    <option value="ES">&ofcir; Espírito Santo</option>
                                    <option value="GO">&ofcir; Goiás</option>
                                    <option value="MA">&ofcir; Maranhão</option>
                                    <option value="MT">&ofcir; Mato Grosso</option>
                                    <option value="MS">&ofcir; Mato Grosso do Sul</option>
                                    <option value="MG">&ofcir; Minas Gerais</option>
                                    <option value="PA">&ofcir; Pará</option>
                                    <option value="PR">&ofcir; Paraná</option>
                                    <option value="PE">&ofcir; Pernambuco</option>
                                    <option value="PI">&ofcir; Piauí</option>
                                    <option value="RJ">&ofcir; Rio de Janeiro</option>
                                    <option value="RN">&ofcir; Rio Grande do Norte</option>
                                    <option value="RS">&ofcir; Rio Grande do Sul</option>
                                    <option value="RO">&ofcir; Rondônia</option>
                                    <option value="RR">&ofcir; Roraima</option>
                                    <option value="SC">&ofcir; Santa Catarina</option>
                                    <option value="SP">&ofcir; São Paulo</option>
                                    <option value="SE">&ofcir; Sergipe</option>
                                    <option value="TO">&ofcir; Tocantins</option>
                                <?php else: ?>
                                    <option <?= ($address->state == "AC" ? "selected" : ""); ?> value="AC">&ofcir; Acre</option>
                                    <option <?= ($address->state == "AL" ? "selected" : ""); ?> value="AL">&ofcir; Alagoas</option>
                                    <option <?= ($address->state == "AP" ? "selected" : ""); ?> value="AP">&ofcir; Amapá</option>
                                    <option <?= ($address->state == "AM" ? "selected" : ""); ?> value="AM">&ofcir; Amazonas</option>
                                    <option <?= ($address->state == "BA" ? "selected" : ""); ?> value="BA">&ofcir; Bahia</option>
                                    <option <?= ($address->state == "CE" ? "selected" : ""); ?> value="CE">&ofcir; Ceará</option>
                                    <option <?= ($address->state == "DF" ? "selected" : ""); ?> value="DF">&ofcir; Distrito Federal</option>
                                    <option <?= ($address->state == "ES" ? "selected" : ""); ?> value="ES">&ofcir; Espírito Santo</option>
                                    <option <?= ($address->state == "GO" ? "selected" : ""); ?> value="GO">&ofcir; Goiás</option>
                                    <option <?= ($address->state == "MA" ? "selected" : ""); ?> value="MA">&ofcir; Maranhão</option>
                                    <option <?= ($address->state == "MT" ? "selected" : ""); ?> value="MT">&ofcir; Mato Grosso</option>
                                    <option <?= ($address->state == "MS" ? "selected" : ""); ?> value="MS">&ofcir; Mato Grosso do Sul</option>
                                    <option <?= ($address->state == "MG" ? "selected" : ""); ?> value="MG">&ofcir; Minas Gerais</option>
                                    <option <?= ($address->state == "PA" ? "selected" : ""); ?> value="PA">&ofcir; Pará</option>
                                    <option <?= ($address->state == "PB" ? "selected" : ""); ?> value="PB">&ofcir; Paraíba</option>
                                    <option <?= ($address->state == "PR" ? "selected" : ""); ?> value="PR">&ofcir; Paraná</option>
                                    <option <?= ($address->state == "PE" ? "selected" : ""); ?> value="PE">&ofcir; Pernambuco</option>
                                    <option <?= ($address->state == "PI" ? "selected" : ""); ?> value="PI">&ofcir; Piauí</option>
                                    <option <?= ($address->state == "RJ" ? "selected" : ""); ?> value="RJ">&ofcir; Rio de Janeiro</option>
                                    <option <?= ($address->state == "RN" ? "selected" : ""); ?> value="RN">&ofcir; Rio Grande do Norte</option>
                                    <option <?= ($address->state == "RS" ? "selected" : ""); ?> value="RS">&ofcir; Rio Grande do Sul</option>
                                    <option <?= ($address->state == "RO" ? "selected" : ""); ?> value="RO">&ofcir; Rondônia</option>
                                    <option <?= ($address->state == "PR" ? "selected" : ""); ?> value="RR">&ofcir; Roraima</option>
                                    <option <?= ($address->state == "SC" ? "selected" : ""); ?> value="SC">&ofcir; Santa Catarina</option>
                                    <option <?= ($address->state == "SP" ? "selected" : ""); ?> value="SP">&ofcir; São Paulo</option>
                                    <option <?= ($address->state == "SE" ? "selected" : ""); ?> value="SE">&ofcir; Sergipe</option>
                                    <option <?= ($address->state == "TO" ? "selected" : ""); ?> value="TO">&ofcir; Tocantins</option>
                                <?php endif; ?>
                            </select>
                        </label>
                    </div>
                    <div class="label_g2">
                        <label class="label">
                            <span class="field icon-map">*Cidade:</span>
                            <input class="radius" type="text" name="city" id="cidade" placeholder="Cidade" required
                                   value="<?=($address->city ?? ""); ?>"/>
                        </label>
                        <label class="label">
                            <span class="field icon-map">*Bairro:</span>
                            <input class="radius" type="text" name="district" id="bairro" placeholder="Bairro" required
                                   value="<?=($address->district ?? ""); ?>"/>
                        </label>
                    </div>
                    <label class="label">
                        <span class="field icon-map">*Logradouro:</span>
                        <input class="radius" type="text" name="street" id="rua" placeholder="Avenida, Rua..." required
                               value="<?=($address->street ?? ""); ?>"/>
                    </label>
                    <div class="label_g2">
                        <label class="label">
                            <span class="field icon-map">*Numero:</span>
                            <input class="radius" type="text" name="number" placeholder="Numero" required
                                   value="<?=($address->number ?? ""); ?>"/>
                        </label>
                        <label class="label">
                            <span class="field icon-map">Complemento:</span>
                            <input class="radius" type="text" name="complement" placeholder="Bloco, Apto..."
                                   value="<?=($address->complement ?? ""); ?>"/>
                        </label>
                    </div>
                </main>

                <div class="app_form_footer">
                    <?php if (!$address): ?>
                        <button class="btn btn-green icon-check-square-o">Cadastrar Endereço</button>
                    <?php else: ?>
                        <button class="btn btn-blue icon-check-square-o">Atualizar</button>
                    <?php endif; ?>

                </div>
            </form>
        </div>
    <?php endif; ?>
</section>