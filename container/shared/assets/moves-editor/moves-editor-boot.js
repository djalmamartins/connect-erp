/*!
 * Liga o Moves Editor nos campos de texto rico do Connect (ADR 0601).
 *
 * Uso: <textarea name="content" data-moves-editor="full|basic"></textarea>
 *  - "full": artigos, páginas e matérias (títulos, imagens, HTML).
 *  - "basic": mensagens, chamados e comunicados (negrito, itálico, listas, link).
 * Opcionais: data-editor-height (px), data-editor-upload (endereço do envio de imagem;
 * se faltar, usa body[data-editor-upload]). O textarea continua no formulário e recebe o
 * HTML a cada alteração, então o envio do formulário não muda. O servidor limpa o HTML de
 * novo antes de gravar (Source\Support\RichHtml).
 */
(function (global) {
    'use strict';
    var editors = new Map();

    function escapeText(text) {
        return String(text).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
    }

    /** Texto antigo (sem marcação) vira parágrafos, para não perder as quebras de linha. */
    function toHtml(value) {
        var raw = String(value || '');
        if (raw.trim() === '') return '';
        if (/<\/?(p|br|div|h[1-6]|ul|ol|li|strong|em|b|i|u|s|a|img|figure|blockquote|pre|span|hr)\b/i.test(raw)) return raw;
        return raw.replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (block) {
            return '<p>' + escapeText(block).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function csrfToken(form) {
        var field = (form && form.querySelector('input[name="csrf"]')) || document.querySelector('input[name="csrf"]');
        return field ? field.value : '';
    }

    function uploader(textarea) {
        var endpoint = textarea.dataset.editorUpload || (document.body && document.body.dataset.editorUpload) || '';
        if (!endpoint) return null;
        return function (file) {
            var data = new FormData();
            data.append('csrf', csrfToken(textarea.form));
            data.append('image', file, file.name);
            return fetch(endpoint, {method: 'POST', body: data, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (result) {
                        if (!response.ok || !result.url) throw new Error(result.error || 'Não foi possível enviar a imagem. O texto não mudou.');
                        return result.url;
                    });
                });
        };
    }

    function isEmpty(html) {
        var probe = document.createElement('div');
        probe.innerHTML = html;
        return probe.textContent.replace(/ /g, ' ').trim() === '' && !probe.querySelector('img');
    }

    function mount(textarea) {
        if (!global.MovesEditor || textarea.dataset.movesEditorReady === '1') return null;
        textarea.dataset.movesEditorReady = '1';
        if (!textarea.id) textarea.id = 'moves-editor-' + (editors.size + 1);
        var profile = textarea.dataset.movesEditor === 'basic' ? 'basic' : 'full';
        var host = document.createElement('div');
        host.className = 'moves-editor-host moves-editor-host--' + profile;
        // Fora do <label>: o clique no editor não pode ser repassado ao textarea escondido.
        var wrappingLabel = textarea.closest('label');
        (wrappingLabel || textarea).insertAdjacentElement('afterend', host);
        var wasRequired = textarea.required;
        var maxLength = Number(textarea.getAttribute('maxlength') || 0);
        textarea.required = false;
        textarea.removeAttribute('maxlength');
        if (maxLength) textarea.dataset.maxLength = String(maxLength); // o limite vale para o texto, sem a formatação
        textarea.hidden = true;
        textarea.classList.add('moves-editor-source');
        var rows = Number(textarea.getAttribute('rows') || 0);
        var height = Number(textarea.dataset.editorHeight || 0) || Math.max(profile === 'basic' ? 140 : 320, rows * 24);
        var editor = new global.MovesEditor(host, {
            initialHTML: toHtml(textarea.value),
            placeholder: textarea.getAttribute('placeholder') || (profile === 'basic' ? 'Escreva a mensagem…' : 'Escreva o conteúdo…'),
            minHeight: height,
            toolbar: profile,
            html: profile === 'full',
            counter: profile === 'full',
            imageUploader: profile === 'full' ? uploader(textarea) : null,
            onChange: function (html) {
                var value = isEmpty(html) ? '' : html;
                if (textarea.value !== value) {
                    textarea.value = value;
                    textarea.dispatchEvent(new Event('input', {bubbles: true}));
                }
                host.classList.remove('is-invalid');
            }
        });
        if (textarea.getAttribute('aria-describedby')) host.querySelector('.me-content').setAttribute('aria-describedby', textarea.getAttribute('aria-describedby'));
        var label = textarea.closest('label') || (textarea.id && document.querySelector('label[for="' + CSS.escape(textarea.id) + '"]'));
        if (label) host.querySelector('.me-content').setAttribute('aria-label', label.textContent.trim().replace(/\s*\*$/, ''));
        host.querySelector('.me-content').setAttribute('role', 'textbox');
        host.querySelector('.me-content').setAttribute('aria-multiline', 'true');
        var form = textarea.form;
        if (form) {
            form.addEventListener('submit', function (event) {
                var html = editor.getHTML();
                textarea.value = isEmpty(html) ? '' : html;
                var problem = '';
                if (wasRequired && textarea.value === '') problem = 'Escreva o texto antes de enviar. Nada foi gravado.';
                else if (maxLength && editor.getText().length > maxLength) problem = 'O texto passou do tamanho máximo (' + maxLength.toLocaleString('pt-BR') + ' caracteres). Encurte e envie de novo.';
                if (problem) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    host.classList.add('is-invalid');
                    editor.notify(problem);
                    editor.focus();
                }
            }, true);
            form.addEventListener('reset', function () { setTimeout(function () { editor.setHTML(toHtml(textarea.defaultValue)); }, 0); });
        }
        editors.set(textarea.id, editor);
        return editor;
    }

    function mountAll(root) {
        (root || document).querySelectorAll('textarea[data-moves-editor]').forEach(mount);
    }

    global.MovesEditors = {
        mount: mountAll,
        get: function (id) { return editors.get(id) || null; },
        /** Tamanho do texto visível (sem a formatação), para contadores de caracteres. */
        textLength: function (id) { var editor = editors.get(id); return editor ? editor.getText().length : null; },
        /** Coloca HTML na posição do cursor (biblioteca de mídia, respostas rápidas). */
        insert: function (id, html) {
            var editor = editors.get(id);
            if (!editor) return false;
            editor.restore();
            document.execCommand('insertHTML', false, editor.sanitizeHTML(toHtml(html)));
            editor.change();
            return true;
        },
        /** Troca todo o conteúdo (ex.: limpar depois de enviar por AJAX). */
        set: function (id, html) {
            var editor = editors.get(id);
            if (!editor) return false;
            editor.setHTML(toHtml(html));
            editor.change();
            return true;
        },
        toHtml: toHtml
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mountAll(document); });
    else mountAll(document);
})(window);
