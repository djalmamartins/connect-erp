// JQUERY INIT

window.StudioModal = {
    get(target) {
        const element = typeof target === "string" ? document.querySelector(target) : target;
        if (!element) return null;
        return {
            open() {
                element.classList.add("is-open");
                element.setAttribute("aria-hidden", "false");
                document.body.classList.add("studio-modal-open");
                element.querySelector("input:not([type=hidden]),select,textarea,button")?.focus();
            },
            close() {
                element.classList.remove("is-open");
                element.setAttribute("aria-hidden", "true");
                if (!document.querySelector(".studio-modal.is-open")) document.body.classList.remove("studio-modal-open");
            }
        };
    }
};

document.addEventListener("click", function (event) {
    const opener = event.target.closest("[data-studio-modal-open]");
    if (opener) {
        event.preventDefault();
        window.StudioModal.get(opener.dataset.studioModalOpen)?.open();
        return;
    }
    const closer = event.target.closest("[data-studio-modal-close]");
    if (closer) window.StudioModal.get(closer.closest(".studio-modal"))?.close();
    if (event.target.classList.contains("studio-modal")) window.StudioModal.get(event.target)?.close();
});

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") window.StudioModal.get(document.querySelector(".studio-modal.is-open"))?.close();
});

$(function () {
    var ajaxResponseBaseTime = 3;
    var ajaxResponseRequestError = "<div class='message error icon-warning' data-notice>Desculpe mas não foi possível processar sua requisição...</div>";

    // MOBILE MENU

    $(".mobile_menu").click(function (e) {
        e.preventDefault();

        var menu = $(".dash_sidebar");
        menu.animate({right: 0}, 200, function (e) {
            $("body").css("overflow", "hidden");
        });

        menu.one("mouseleave", function () {
            $(this).animate({right: '-260'}, 200, function (e) {
                $("body").css("overflow", "auto");
            });
        });
    });

    //DATA SET

    $("[data-post]").click(function (e) {
        e.preventDefault();

        var clicked = $(this);
        var data = clicked.data();
        var load = $(".ajax_load");

        if (data.confirm) {
            var deleteConfirm = confirm(data.confirm);
            if (!deleteConfirm) {
                return;
            }
        }

        $.ajax({
            url: data.post,
            type: "POST",
            data: data,
            dataType: "json",
            beforeSend: function () {
                load.fadeIn(200).css("display", "flex");
            },
            success: function (response) {
                //redirect
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else {
                    load.fadeOut(200);
                }

                //reload
                if (response.reload) {
                    window.location.reload();
                } else {
                    load.fadeOut(200);
                }

                //message
                if (response.message) {
                    ajaxMessage(response.message, ajaxResponseBaseTime);
                }
            },
            error: function () {
                ajaxMessage(ajaxResponseRequestError, 5);
                load.fadeOut();
            }
        });
    });

    //FORMS

    $("form:not('.ajax_off')").submit(function (e) {
        e.preventDefault();

        var form = $(this);
        var load = $(".ajax_load");
        var submitter = e.originalEvent && e.originalEvent.submitter;
        var submitterData = {};
        if (submitter && submitter.name) {
            submitterData[submitter.name] = submitter.value;
        }

        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        form.ajaxSubmit({
            url: form.attr("action"),
            type: "POST",
            dataType: "json",
            data: submitterData,
            beforeSend: function () {
                load.fadeIn(200).css("display", "flex");
            },
            uploadProgress: function (event, position, total, completed) {
                var loaded = completed;
                var load_title = $(".ajax_load_box_title");
                load_title.text("Enviando (" + loaded + "%)");

                if (completed >= 100) {
                    load_title.text("Aguarde, carregando...");
                }
            },
            success: function (response) {
                //redirect
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else {
                    form.find("input[type='file']").val(null);
                    load.fadeOut(200);
                }

                //reload
                if (response.reload) {
                    window.location.reload();
                } else {
                    load.fadeOut(200);
                }

                //message
                if (response.message) {
                    ajaxMessage(response.message, ajaxResponseBaseTime);
                }

                //image by fsphp mce upload
                if (response.mce_image) {
                    $('.mce_upload').fadeOut(200);
                    tinyMCE.activeEditor.insertContent(response.mce_image);
                }
            },
            complete: function () {
                if (form.data("reset") === true) {
                    form.trigger("reset");
                }
            },
            error: function () {
                ajaxMessage(ajaxResponseRequestError, 5);
                load.fadeOut();
            }
        });
    });

    // AJAX RESPONSE

    function ajaxMessage(message, time) {
        var ajaxMessage = $(message);

        ajaxMessage.append("<div class='message_time'></div>");
        ajaxMessage.find(".message_time").animate({"width": "100%"}, time * 1000, function () {
            $(this).parents(".message").fadeOut(200);
        });

        $(".ajax_response").append(ajaxMessage);
        ajaxMessage.effect("bounce");
    }

    // AJAX RESPONSE MONITOR

    $(".ajax_response .message").each(function (e, m) {
        ajaxMessage(m, ajaxResponseBaseTime += 1);
    });

    // AJAX MESSAGE CLOSE ON CLICK

    $(".ajax_response").on("click", ".message", function (e) {
        $(this).effect("bounce").fadeOut(1);
    });

    // MAKS

    $(".mask-date").mask('00/00/0000');
    $(".mask-datetime").mask('00/00/0000 00:00');
    $(".mask-month").mask('00/0000', {reverse: true});
    $(".mask-doc").mask('000.000.000-00', {reverse: true});
    $(".mask-pj").mask('00.000.000/0000-00', {reverse: true});
    $(".mask-card").mask('0000  0000  0000  0000', {reverse: true});
    $(".mask-money").mask('000.000.000.000.000,00', {reverse: true, placeholder: "0,00"});
    $(".mask-cep").mask('00000-000', {reverse: true});
    $(".mask-phone").mask('00 0000-0000', {reverse: true});
    $(".mask-cell").mask('00 00000-0000', {reverse: true});
});

// Biblioteca de mídia reutilizável em capas, configurações e editores.
document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("studio-library-picker");
    if (!modal) return;
    const grid = modal.querySelector("[data-library-grid]");
    const search = modal.querySelector("[data-library-search]");
    const count = modal.querySelector("[data-library-count]");
    let images = [];
    let activeTrigger = null;
    let loaded = false;
    const close = function () { modal.classList.remove("open"); modal.setAttribute("aria-hidden", "true"); activeTrigger = null; };
    const choose = function (image) {
        if (!activeTrigger) return;
        if (activeTrigger.dataset.mediaEditor) {
            const editorId = activeTrigger.dataset.mediaEditor;
            const html = '<img src="' + image.url.replace(/"/g, '&quot;') + '" alt="' + image.name.replace(/"/g, '&quot;') + '"><p><br></p>';
            const inserted = window.MovesOrganicEditor?.insert(editorId, html);
            if (!inserted) {
                const editor = window.tinymce?.get(editorId) || window.tinymce?.activeEditor;
                if (editor) editor.insertContent(html);
            }
            document.querySelector(".mce_upload")?.style.setProperty("display", "none");
        } else if (activeTrigger.dataset.mediaTarget) {
            const target = document.querySelector(activeTrigger.dataset.mediaTarget);
            if (target) { target.value = image.path; target.dispatchEvent(new Event("change", {bubbles: true})); }
            if (activeTrigger.dataset.mediaPreview) {
                const preview = document.querySelector(activeTrigger.dataset.mediaPreview);
                if (preview) { preview.src = image.url; preview.classList.remove("is-empty"); }
            }
        }
        close();
    };
    const render = function () {
        const term = (search.value || "").trim().toLowerCase();
        const filtered = images.filter(function (image) { return image.name.toLowerCase().includes(term); });
        grid.innerHTML = "";
        filtered.forEach(function (image) {
            const button = document.createElement("button");
            button.type = "button";
            button.title = image.name;
            const img = document.createElement("img"); img.src = image.url; img.alt = image.name; img.loading = "lazy";
            const span = document.createElement("span"); span.textContent = image.name;
            button.append(img, span); button.addEventListener("click", function () { choose(image); }); grid.appendChild(button);
        });
        if (!filtered.length) grid.innerHTML = "<p>Nenhuma imagem encontrada.</p>";
        count.textContent = filtered.length + " imagem(ns)";
    };
    document.addEventListener("click", function (event) {
        const trigger = event.target.closest("[data-media-picker]");
        if (!trigger) return;
        event.preventDefault(); activeTrigger = trigger; modal.classList.add("open"); modal.setAttribute("aria-hidden", "false"); search.focus();
        if (loaded) { render(); return; }
        fetch(modal.dataset.libraryUrl, {headers: {"X-Requested-With": "XMLHttpRequest"}}).then(function (response) { if (!response.ok) throw new Error("library"); return response.json(); }).then(function (data) { images = data.images || []; loaded = true; render(); }).catch(function () { grid.innerHTML = "<p>Não foi possível carregar a biblioteca.</p>"; });
    });
    modal.querySelector("[data-library-close]")?.addEventListener("click", close);
    modal.addEventListener("click", function (event) { if (event.target === modal) close(); });
    search.addEventListener("input", render);
    document.addEventListener("keydown", function (event) { if (event.key === "Escape" && modal.classList.contains("open")) close(); });
});

// TINYMCE INIT

tinyMCE.init({
    selector: "textarea.mce:not([data-organic-editor])",
    language: 'pt_BR',
    menubar: false,
    theme: "modern",
    height: 400,
    skin: 'light',
    entity_encoding: "raw",
    theme_advanced_resizing: true,
    plugins: [
        "advlist autolink link image lists charmap print preview hr anchor pagebreak spellchecker",
        "searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media nonbreaking",
        "save table contextmenu directionality emoticons template paste textcolor media"
    ],
    toolbar: "styleselect | pastetext | removeformat |  bold | italic | underline | strikethrough | bullist | numlist | alignleft | aligncenter | alignright |  link | unlink | fsphpimage | code | fullscreen",
    style_formats: [
        {title: 'Normal', block: 'p'},
        {title: 'Titulo 3', block: 'h3'},
        {title: 'Titulo 4', block: 'h4'},
        {title: 'Titulo 5', block: 'h5'},
        {title: 'Código', block: 'pre', classes: 'brush: php;'}
    ],
    link_class_list: [
        {title: 'None', value: ''},
        {title: 'Blue CTA', value: 'btn btn_cta_blue'},
        {title: 'Green CTA', value: 'btn btn_cta_green'},
        {title: 'Yellow CTA', value: 'btn btn_cta_yellow'},
        {title: 'Red CTA', value: 'btn btn_cta_red'}
    ],
    setup: function (editor) {
        editor.addButton('fsphpimage', {
            title: 'Enviar Imagem',
            icon: 'image',
            onclick: function () {
                $('.mce_upload').fadeIn(200, function (e) {
                    $("body").click(function (e) {
                        if ($(e.target).attr("class") === "mce_upload") {
                            $('.mce_upload').fadeOut(200);
                        }
                    });
                }).css("display", "flex");
            }
        });
    },
    link_title: false,
    target_list: false,
    theme_advanced_blockformats: "h1,h2,h3,h4,h5,p,pre",
    media_dimensions: false,
    media_poster: false,
    media_alt_source: false,
    media_embed: false,
    extended_valid_elements: "a[href|target=_blank|rel|class]",
    imagemanager_insert_template: '<img src="{$url}" title="{$title}" alt="{$title}" />',
    image_dimensions: false,
    relative_urls: false,
    remove_script_host: false,
    paste_as_text: true
});

$(document).ready(function() {

    function limpa_formulário_cep() {
        // Limpa valores do formulário de cep.
        $("#rua").val("");
        $("#bairro").val("");
        $("#cidade").val("");
        $("#uf").val("");
        $("#ibge").val("");
    }

    //Quando o campo cep perde o foco.
    $("#cep").blur(function() {

        //Nova variável "cep" somente com dígitos.
        var cep = $(this).val().replace(/\D/g, '');

        //Verifica se campo cep possui valor informado.
        if (cep != "") {

            //Expressão regular para validar o CEP.
            var validacep = /^[0-9]{8}$/;

            //Valida o formato do CEP.
            if(validacep.test(cep)) {

                //Preenche os campos com "..." enquanto consulta webservice.
                $("#rua").val("...");
                $("#bairro").val("...");
                $("#cidade").val("...");
                $("#uf").val("...");
                $("#ibge").val("...");

                //Consulta o webservice viacep.com.br/
                $.getJSON("https://viacep.com.br/ws/"+ cep +"/json/?callback=?", function(dados) {

                    if (!("erro" in dados)) {
                        //Atualiza os campos com os valores da consulta.
                        $("#rua").val(dados.logradouro);
                        $("#bairro").val(dados.bairro);
                        $("#cidade").val(dados.localidade);
                        $("#uf").val(dados.uf);
                        $("#ibge").val(dados.ibge);
                    } //end if.
                    else {
                        //CEP pesquisado não foi encontrado.
                        limpa_formulário_cep();
                    }
                });
            } //end if.
            else {
                //cep é inválido.
                limpa_formulário_cep();
            }
        } //end if.
        else {
            //cep sem valor, limpa formulário.
            limpa_formulário_cep();
        }
    });
});

// MovesOS media library: attachment details modal
document.addEventListener("DOMContentLoaded", function () {
    var source = document.getElementById("studio-media-data");
    var modal = document.getElementById("studio-media-modal");
    if (!source || !modal) return;
    var media = JSON.parse(source.textContent || "[]");
    var preview = modal.querySelector(".studio-media-preview img");
    var title = modal.querySelector("#media-modal-title");
    var usage = modal.querySelector("#media-modal-use");
    var urlInput = modal.querySelector(".studio-media-url input");
    var original = modal.querySelector(".studio-media-actions>a");
    var pathInput = modal.querySelector('input[name="path"]');

    function closeMedia() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }
    document.querySelectorAll(".studio-media-open").forEach(function (button) {
        button.addEventListener("click", function () {
            var item = media[Number(button.dataset.mediaIndex)];
            if (!item) return;
            preview.src = item.url; preview.alt = item.name; title.textContent = item.name;
            usage.textContent = item.usage ? "Em uso: " + item.usage_labels.join(", ") : "Arquivo sem vínculos — pode ser excluído.";
            modal.querySelector('[data-field="mime"]').textContent = item.mime || "Não identificado";
            modal.querySelector('[data-field="size"]').textContent = new Intl.NumberFormat("pt-BR", {maximumFractionDigits: 2}).format(item.size / 1024) + " KB";
            modal.querySelector('[data-field="dimensions"]').textContent = item.width + " × " + item.height + " px";
            modal.querySelector('[data-field="extension"]').textContent = item.extension;
            modal.querySelector('[data-field="date"]').textContent = item.date;
            modal.querySelector('[data-field="path"]').textContent = item.path;
            urlInput.value = item.url; original.href = item.url; pathInput.value = item.path;
            modal.classList.add("open"); modal.setAttribute("aria-hidden", "false"); document.body.style.overflow = "hidden";
        });
    });
    modal.querySelector(".studio-media-close").addEventListener("click", closeMedia);
    modal.addEventListener("click", function (event) { if (event.target === modal) closeMedia(); });
    document.addEventListener("keydown", function (event) { if (event.key === "Escape") closeMedia(); });
    modal.querySelector("[data-copy-url]").addEventListener("click", function () {
        navigator.clipboard.writeText(urlInput.value).then(function () {
            var icon = modal.querySelector("[data-copy-url] ion-icon"); icon.setAttribute("name", "checkmark-outline");
            setTimeout(function () { icon.setAttribute("name", "copy-outline"); }, 1400);
        });
    });
});
