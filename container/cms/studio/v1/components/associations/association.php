<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/associations/sidebar.php"); ?>

<section class="dash_content_app">
    <?php if (!$club): ?>
        <header class="dash_content_app_header">
            <h2 class="icon-plus-circle">Nova associação</h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/associations/association"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="create"/>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Clube:</span>
                        <input type="text" name="company_name" placeholder="Nome do clube" required/>
                    </label>
                    <label class="label">
                        <span class="legend">*Sigla:</span>
                        <input type="text" name="initials_name" placeholder="Sigla" required/>
                    </label>

                </div>
                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Foto: (600x600px)</span>
                        <input type="file" name="photo"/>
                    </label>
                    <label class="label">
                        <span class="legend">CNPJ:</span>
                        <input type="text" class="mask-pj" name="document" placeholder="Apenas números:"/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Nome do presidente:</span>
                        <input type="text" name="president" placeholder="Nome do presidente:" required/>
                    </label>

                    <label class="label">
                        <span class="legend">*CPF:</span>
                        <input class="mask-doc" type="text" name="cpf" placeholder="CPF do presidente" required/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*E-mail:</span>
                        <input type="email" name="email" placeholder="Melhor e-mail" required/>
                    </label>

                    <label class="label">
                        <span class="legend">*Fundação:</span>
                        <input type="text" class="mask-date" name="datebirth" placeholder="dd/mm/yyyy" required/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Telefone:</span>
                        <input type="text"class="mask-phone" name="phone" placeholder="(00) 0000-0000"/>
                    </label>

                    <label class="label">
                        <span class="legend">*Celular:</span>
                        <input type="text" name="cell" class="mask-cell" placeholder="(00) 00000-0000" required/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend"></span>
                    </label>

                    <label class="label">
                        <span class="legend">*Status:</span>
                        <select name="status" required>
                            <option value="registered">Bloqueado</option>
                            <option value="confirmed">Confirmado</option>
                        </select>
                    </label>
                </div>

                <div class="al-right">
                    <button class="btn btn-green icon-check-square-o">Cadastrar Clube</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <header class="dash_content_app_header">
            <h2 class="icon-user"><?= $club->company_name; ?></h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/associations/association/{$club->id}"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="update"/>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Clube:</span>
                        <input type="text" name="company_name" value="<?= $club->company_name; ?>"  required/>
                    </label>
                    <label class="label">
                        <span class="legend">*Sigla:</span>
                        <input type="text" name="initials_name" value="<?= $club->initials_name; ?>" required/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Foto: (600x600px)</span>
                        <input type="file" name="photo"/>
                    </label>
                    <label class="label">
                        <span class="legend">CNPJ:</span>
                        <input type="text" class="mask-pj" name="document" value="<?= $club->document; ?>"  />
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*Nome do presidente:</span>
                        <input type="text" name="president" value="<?= $club->president; ?>" />
                    </label>

                    <label class="label">
                        <span class="legend">*CPF:</span>
                        <input class="mask-doc" type="text" name="cpf" value="<?= $club->cpf; ?>" />
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">*E-mail:</span>
                        <input type="email" name="email" cpf" value="<?= $club->email; ?>"  required/>
                    </label>

                    <label class="label">
                        <span class="legend">*Fundação:</span>
                        <input type="text"  class="mask-date" value="<?= date_fmt($club->datebirth, "d/m/Y"); ?>"
                               name="datebirth" placeholder="dd/mm/yyyy"/>
                    </label>
                </div>

                <div class="label_g2">
                    <label class="label">
                        <span class="legend">Telefone:</span>
                        <input type="text"class="mask-phone" name="phone" value="<?= $club->phone; ?>" placeholder="(00) 0000-0000" />
                    </label>

                    <label class="label">
                        <span class="legend">*Celular:</span>
                        <input type="text" name="cell" class="mask-cell" value="<?= $club->cell; ?>" placeholder="(00) 00000-0000"  required/>
                    </label>
                </div>


                <div class="label_g2">
                    <label class="label">
                        <span class="legend"></span>
                    </label>

                    <label class="label">
                        <span class="legend">*Status:</span>
                        <select name="status" required>
                            <?php
                            $status = $club->status;
                            $select = function ($value) use ($status) {
                                return ($status == $value ? "selected" : "");
                            };
                            ?>
                            <option <?= $select("registered"); ?> value="registered">Bloqueado</option>
                            <option <?= $select("confirmed"); ?> value="confirmed">Confirmado</option>
                        </select>
                    </label>
                </div>

                <div class="app_form_footer">
                    <button class="btn btn-blue icon-check-square-o">Atualizar</button>
                    <a href="#" class="remove_link icon-warning"
                       data-post="<?= url("/studio/associations/association/{$club->id}"); ?>"
                       data-action="delete"
                       data-confirm="ATENÇÃO: Tem certeza que deseja excluir o usuário e todos os dados relacionados a ele? Essa ação não pode ser feita!"
                       data-user_id="<?= $club->id; ?>">Excluir Usuário</a>
                </div>
            </form>
        </div>
        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/app/address"); ?>" method="post">
                <main>
                    <?php if (!$address): ?>
                        <input type="hidden" name="create" value="true"/>
                        <input type="hidden" name="association_id" value="<?= $club->id; ?>"/>
                    <?php else: ?>
                        <input type="hidden" name="update" value="true"/>
                        <input type="hidden" name="id" value="<?= $address->id; ?>"/>
                        <input type="hidden" name="association_id" value="<?= $club->id; ?>"/>
                    <?php endif; ?>
                    <div class="label_g2">
                        <label class="label">
                            <span class="legend icon-map">*CEP:</span>
                            <input class="radius mask-cep" type="text" name="code" id="cep" placeholder="00000-000" required
                                   value="<?=($address->code ?? ""); ?>"/>
                        </label>

                        <label class="label">
                            <span class="legend icon-map">*Estado:</span>
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
                            <span class="legend icon-map">*Cidade:</span>
                            <input class="radius" type="text" name="city" id="cidade" placeholder="Cidade" required
                                   value="<?=($address->city ?? ""); ?>"/>
                        </label>
                        <label class="label">
                            <span class="legend icon-map">*Bairro:</span>
                            <input class="radius" type="text" name="district" id="bairro" placeholder="Bairro" required
                                   value="<?=($address->district ?? ""); ?>"/>
                        </label>
                    </div>
                    <label class="label">
                        <span class="legend icon-map">*Logradouro:</span>
                        <input class="radius" type="text" name="street" id="rua" placeholder="Avenida, Rua..." required
                               value="<?=($address->street ?? ""); ?>"/>
                    </label>
                    <div class="label_g2">
                        <label class="label">
                            <span class="legend icon-map">*Numero:</span>
                            <input class="radius" type="text" name="number" placeholder="Numero" required
                                   value="<?=($address->number ?? ""); ?>"/>
                        </label>
                        <label class="label">
                            <span class="legend icon-map">Complemento:</span>
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
