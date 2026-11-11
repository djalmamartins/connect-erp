<?php
/**
 * Campo de um cadastro real (Definitions + RegistryValidator). Valor e erro vêm do servidor.
 * @var array $field @var mixed $value @var ?string $error @var list<array> $condominiums @var bool $readonly
 * @var list<array{id:int,condominium_id:int,label:string}> $units (campos do tipo unit)
 * @var ?array $attachment campo de anexo do Disco Virtual (ADR 0050 e 0052): file, canBrowse, canUpload
 */
$id = 'f-' . $field['name'];
$name = $field['name'];
$required = $field['required'] ? ' required' : '';
$disabled = $readonly ? ' disabled' : '';
$invalid = $error !== null ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$star = $field['required'] ? ' <span aria-hidden="true" style="color:var(--erp-danger)">*</span>' : '';
$text = $value === null || is_array($value) || is_bool($value) ? '' : (string)$value;
// Valores gravados chegam normalizados; mostra-os no formato de digitação.
$display = match ($field['type']) {
    'cpf', 'cnpj', 'document' => \Source\Support\Erp\BrDocument::format($text),
    'id_document' => strlen($text) === 11 && ctype_digit($text) ? \Source\Support\Erp\BrDocument::format($text) : $text,
    'money' => is_int($value) ? number_format($value / 100, 2, ',', '.') : $text,
    'percent' => is_float($value) ? number_format($value, 2, ',', '') : $text,
    'decimal' => is_float($value) ? number_format($value, (int)($field['scale'] ?? 2), ',', '') : $text,
    'phone' => \Source\Support\Erp\RegistryFormat::phone($text),
    'time' => substr($text, 0, 5),
    default => $text,
};
$units ??= [];
$attachment ??= null;
?>
<?php $onlyIf = ''; foreach ($field['only_if'] ?? [] as $other => $expected) { $onlyIf = ' data-only-if="' . $this->e('f-' . $other . '=' . $expected) . '"'; } ?>
<div class="erp-field erp-col-<?= $attachment !== null ? max(6, (int)$field['span']) : (int)$field['span'] ?>"<?= $onlyIf ?>>
    <?php if ($field['type'] === 'bool'): ?>
        <span class="erp-field__label" aria-hidden="true">&nbsp;</span>
        <input type="hidden" name="<?= $this->e($name) ?>" value="0"<?= $disabled ?>>
        <label class="erp-check" for="<?= $id ?>"><input type="checkbox" id="<?= $id ?>" name="<?= $this->e($name) ?>" value="1"<?= filter_var($value, FILTER_VALIDATE_BOOL) ? ' checked' : '' ?><?= $disabled ?>><?= $this->e($field['label']) ?></label>
    <?php else: ?>
        <label for="<?= $id ?><?= $attachment !== null && !$readonly && $attachment['canBrowse'] ? '-pick' : '' ?>"><?= $this->e($field['label']) ?><?= $star ?></label>
        <?php if ($attachment !== null):
            // Anexo do Disco Virtual (ADR 0050 e 0052): guarda o nº do arquivo; escolhe-se na lista do
            // condomínio do formulário ou envia-se um arquivo novo, sem sair do cadastro.
            $file = $attachment['file'];
            $api = url('/api/v1/documents');
            $folders = \Source\Services\Erp\VirtualDisk::FOLDERS; ?>
            <input type="hidden" id="<?= $id ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($text) ?>" data-attachment-value<?= $disabled ?>>
            <div class="erp-attachment<?= $error !== null ? ' is-invalid' : '' ?>" data-erp-attachment data-api="<?= $this->e($api) ?>" data-dialog="<?= $id ?>-dialog"<?= $invalid ?>>
                <div class="erp-attachment__current">
                    <span class="erp-attachment__icon"><?= $icon('file', 'erp-icon--sm') ?></span>
                    <span class="erp-attachment__label" data-attachment-label><?php if ($file !== null): ?><strong><?= $this->e((string)$file['title']) ?></strong> <span class="erp-muted"><?= $this->e((string)$file['original_name']) ?></span><?php elseif ($text !== ''): ?>Arquivo nº <?= $this->e($text) ?><?= $attachment['canBrowse'] ? ' (não encontrado no Disco Virtual)' : '' ?><?php else: ?><span class="erp-muted">Nenhum arquivo anexado</span><?php endif; ?></span>
                    <a class="erp-btn erp-btn--sm" data-attachment-download href="<?= $text !== '' ? $this->e($api . '/' . (int)$text . '/download') : '#' ?>"<?= $file === null ? ' hidden' : '' ?> download><?= $icon('download', 'erp-icon--sm') ?>Baixar</a>
                </div>
                <?php if (!$readonly && $attachment['canBrowse']): ?>
                    <div class="erp-attachment__actions">
                        <button type="button" class="erp-btn erp-btn--sm" id="<?= $id ?>-pick" data-attachment-open><?= $icon('search', 'erp-icon--sm') ?>Escolher do Disco Virtual</button>
                        <?php if ($attachment['canUpload']): ?><button type="button" class="erp-btn erp-btn--sm" data-attachment-open="upload"><?= $icon('upload', 'erp-icon--sm') ?>Enviar arquivo</button><?php endif; ?>
                        <button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-attachment-clear<?= $text === '' ? ' hidden' : '' ?>>Remover</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$readonly && $attachment['canBrowse']): ?>
                <dialog class="erp-modal erp-modal--wide" id="<?= $id ?>-dialog" aria-labelledby="<?= $id ?>-dialog-title">
                    <div class="erp-modal__head"><h2 id="<?= $id ?>-dialog-title"><?= $this->e($field['label']) ?></h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
                    <div class="erp-modal__body erp-stack">
                        <p class="erp-muted" data-attachment-condo></p>
                        <div class="erp-attachment__search">
                            <label class="erp-sr-only" for="<?= $id ?>-q">Buscar no Disco Virtual</label>
                            <input class="erp-input" type="search" id="<?= $id ?>-q" data-attachment-q maxlength="100" placeholder="Buscar por título ou nome">
                            <label class="erp-sr-only" for="<?= $id ?>-folder">Pasta</label>
                            <select class="erp-select" id="<?= $id ?>-folder" data-attachment-folder><option value="">Todas as pastas</option><?php foreach ($folders as $key => $label): ?><option value="<?= $key ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="erp-attachment__results" data-attachment-results aria-live="polite"></div>
                        <?php if ($attachment['canUpload']): ?>
                            <fieldset class="erp-attachment__upload" data-attachment-upload>
                                <legend>Enviar um arquivo novo para o Disco Virtual</legend>
                                <div class="erp-form__grid">
                                    <div class="erp-field erp-col-4"><label for="<?= $id ?>-up-folder">Pasta</label><select class="erp-select" id="<?= $id ?>-up-folder" data-attachment-up-folder><?php foreach ($folders as $key => $label): ?><option value="<?= $key ?>"<?= $key === 'outros' ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                                    <div class="erp-field erp-col-8"><label for="<?= $id ?>-up-title">Título <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="<?= $id ?>-up-title" data-attachment-up-title maxlength="160" placeholder="Vazio = nome do arquivo"></div>
                                    <div class="erp-field erp-col-12"><label for="<?= $id ?>-up-file">Arquivo</label><input class="erp-input" type="file" id="<?= $id ?>-up-file" data-attachment-up-file accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.odt,.ods,.csv,.txt,.xml" data-max-bytes="<?= \Source\Services\Erp\VirtualDisk::MAX_BYTES ?>"><span class="erp-field__hint">PDF, imagem, DOCX, XLSX, ODT, ODS, CSV, TXT ou XML, até 20 MB.</span></div>
                                </div>
                                <div class="erp-progress" data-attachment-progress hidden role="progressbar" aria-label="Envio do arquivo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-attachment-bar style="width:0%"></span></div>
                                <div class="erp-form__actions"><button type="button" class="erp-btn erp-btn--primary" data-attachment-send><?= $icon('upload', 'erp-icon--sm') ?>Enviar e anexar</button></div>
                            </fieldset>
                        <?php endif; ?>
                    </div>
                    <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Fechar</button></div>
                </dialog>
            <?php endif; ?>
        <?php elseif ($field['type'] === 'select' || $field['type'] === 'condominium'):
            $options = $field['type'] === 'condominium'
                ? array_column($condominiums, 'name', 'id')
                : $field['options']; ?>
            <select class="erp-select" id="<?= $id ?>" name="<?= $this->e($name) ?>"<?= $required . $disabled . $invalid ?>>
                <option value=""><?= $field['type'] === 'condominium' && !$field['required'] ? 'Todos os condomínios' : 'Selecione' ?></option>
                <?php foreach ($options as $key => $label): ?>
                    <option value="<?= $this->e((string)$key) ?>"<?= (string)$key === $text ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field['type'] === 'unit'):
            // Unidades ativas do escopo, agrupadas por condomínio (ADR 0017); o servidor confere o condomínio.
            $condominiumNames = array_column($condominiums, 'name', 'id');
            $byCondominium = [];
            foreach ($units as $unitOption) {
                $byCondominium[$unitOption['condominium_id']][] = $unitOption;
            } ?>
            <select class="erp-select" id="<?= $id ?>" name="<?= $this->e($name) ?>"<?= $required . $disabled . $invalid ?>>
                <option value=""><?= $units === [] ? 'Nenhuma unidade cadastrada' : 'Selecione' ?></option>
                <?php foreach ($byCondominium as $condominiumId => $group): ?>
                    <optgroup label="<?= $this->e((string)($condominiumNames[$condominiumId] ?? ('Condomínio ' . $condominiumId))) ?>">
                        <?php foreach ($group as $unitOption): ?>
                            <option value="<?= (int)$unitOption['id'] ?>" data-condominium="<?= (int)$condominiumId ?>"<?= (string)$unitOption['id'] === $text ? ' selected' : '' ?>><?= $this->e($unitOption['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field['type'] === 'ref'):
            // Registro de outro cadastro (ADR 0020): padrão da Connect primeiro, depois por condomínio.
            $condominiumNames = array_column($condominiums, 'name', 'id');
            $refGroups = [];
            foreach ($refOptions ?? [] as $refOption) {
                $refGroups[$refOption['condominium_id'] === null ? 'padrao' : (int)$refOption['condominium_id']][] = $refOption;
            } ?>
            <?php // ADR 0152: cada opção diz o condomínio e os valores de 'ref_match'; o erp.js esconde as que não
            // combinam com o condomínio e a natureza escolhidos (o servidor confere de novo). ?>
            <select class="erp-select" id="<?= $id ?>" name="<?= $this->e($name) ?>"<?= $required . $disabled . $invalid ?> data-erp-ref-filter>
                <option value=""><?= ($refOptions ?? []) === [] ? 'Nenhuma opção cadastrada' : 'Selecione' ?></option>
                <?php foreach ($refGroups as $groupKey => $group): ?>
                    <optgroup label="<?= $this->e($groupKey === 'padrao' ? 'Padrão da Connect' : (string)($condominiumNames[$groupKey] ?? ('Condomínio ' . $groupKey))) ?>">
                        <?php foreach ($group as $refOption):
                            $optionData = $refOption['condominium_id'] !== null ? ' data-condominium="' . (int)$refOption['condominium_id'] . '"' : '';
                            foreach ($refOption['match'] ?? [] as $matchField => $matchValue) {
                                $optionData .= ' data-match-' . $this->e(str_replace('_', '-', (string)$matchField)) . '="' . $this->e((string)$matchValue) . '"';
                            } ?>
                            <option value="<?= (int)$refOption['id'] ?>"<?= $optionData ?><?= (string)$refOption['id'] === $text ? ' selected' : '' ?>><?= $this->e($refOption['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?php if (($refOptions ?? []) === [] && isset($field['ref_empty']) && !$readonly): ?>
                <span class="erp-field__hint erp-field__hint--warning"><?= $this->e($field['ref_empty']['text']) ?> <a href="<?= erp_url($field['ref_empty']['route']) ?>">Abrir</a></span>
            <?php endif; ?>
        <?php elseif ($field['type'] === 'longtext'): ?>
            <textarea class="erp-textarea" id="<?= $id ?>" name="<?= $this->e($name) ?>" maxlength="<?= (int)($field['max'] ?? 2000) ?>"<?= $required . $disabled . $invalid ?>><?= $this->e($text) ?></textarea>
        <?php else:
            [$type, $extra, $placeholder] = match ($field['type']) {
                'email' => ['email', ' autocomplete="off" maxlength="254"', 'nome@exemplo.com'],
                'phone' => ['tel', ' maxlength="20"', '(19) 99999-0000'],
                'date' => ['date', ' min="1900-01-01" max="2100-12-31"', ''],
                'time' => ['time', '', ''],
                'int' => ['number', ' step="1" inputmode="numeric" min="' . (int)($field['min'] ?? 0) . '" max="' . (int)($field['max_value'] ?? 999999999) . '"', ''],
                'money' => ['text', ' inputmode="decimal" maxlength="18"', '0,00'],
                'percent' => ['text', ' inputmode="decimal" maxlength="6"', '0,00'],
                'decimal' => ['text', ' inputmode="decimal" maxlength="20"', '0,' . str_repeat('0', (int)($field['scale'] ?? 2))],
                'cpf' => ['text', ' inputmode="numeric" maxlength="14"', '000.000.000-00'],
                'cnpj' => ['text', ' inputmode="numeric" maxlength="18"', '00.000.000/0000-00'],
                'document' => ['text', ' inputmode="numeric" maxlength="18"', 'CPF ou CNPJ'],
                'id_document' => ['text', ' maxlength="20"', 'CPF ou RG'],
                'plate' => ['text', ' maxlength="8" style="text-transform:uppercase"', 'ABC1D23'],
                'oab' => ['text', ' maxlength="12"', 'SP 123456'],
                default => ['text', ' maxlength="' . (int)($field['max'] ?? 160) . '"', ''],
            }; ?>
            <?php if (!empty($field['month'])) { $extra .= ' data-erp-month'; } // ADR 0152: seletor de mês e ano (erp.js) ?>
            <input class="erp-input" type="<?= $type ?>" id="<?= $id ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($display) ?>"<?= $extra ?><?= $placeholder !== '' ? ' placeholder="' . $this->e($placeholder) . '"' : '' ?><?= $required . $disabled . $invalid ?>>
        <?php endif; ?>
        <?php $hint = $attachment !== null ? 'Arquivo do Disco Virtual do mesmo condomínio do cadastro.' : ($field['hint'] ?? null); ?>
        <?php if (!empty($hint)): ?><span class="erp-field__hint"><?= $this->e($hint) ?></span><?php endif; ?>
        <span class="erp-field__error" id="<?= $id ?>-error" aria-live="polite"><?= $error !== null ? $this->e($error) : '' ?></span>
    <?php endif; ?>
</div>
