const editors = new Map();
// Distribuição ESM oficial do Organic Editor mantida dentro do próprio tema.
const editorModuleUrl = new URL("../vendor/organic-editor/organic-editor.min.js", import.meta.url).href;
const uploadUrl = document.body.dataset.editorUpload;

const uploadImage = async (file) => {
    const csrf = document.querySelector('input[name="csrf"]')?.value;
    const data = new FormData();
    data.append("csrf", csrf || "");
    data.append("image", file, file.name);
    const response = await fetch(uploadUrl, {method: "POST", body: data, headers: {"X-Requested-With": "XMLHttpRequest"}});
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.url) throw new Error(result.error || "Não foi possível enviar a imagem.");
    return {...result, size: file.size};
};

const editorButton = (label, title, action, icon = null) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "moves-organic-tool";
    button.title = title;
    button.setAttribute("aria-label", title);
    if (icon) button.innerHTML = `<ion-icon name="${icon}"></ion-icon><span class="moves-organic-tool-label">${label}</span>`;
    else button.textContent = label;
    button.addEventListener("mousedown", (event) => event.preventDefault());
    button.addEventListener("click", action);
    return button;
};

const installToolbar = (editor, textarea) => {
    const toolbar = document.createElement("div");
    toolbar.className = "moves-organic-toolbar";
    toolbar.setAttribute("role", "toolbar");
    toolbar.setAttribute("aria-label", "Formatação do conteúdo");
    const block = document.createElement("select");
    block.title = "Formato do texto";
    [["p", "Parágrafo"], ["h2", "Título 2"], ["h3", "Título 3"], ["h4", "Título 4"], ["blockquote", "Citação"], ["pre", "Código"]].forEach(([value, label]) => block.add(new Option(label, value)));
    block.addEventListener("change", () => { editor.commands.formatBlock(block.value); editor.focus(); });
    toolbar.append(block);
    [
        ["", "Desfazer", "undo", "arrow-undo-outline"], ["", "Refazer", "redo", "arrow-redo-outline"],
        ["", "Negrito", "bold", "bold-outline"], ["I", "Itálico", "italic", null], ["U", "Sublinhado", "underline", null], ["S", "Tachado", "strikeThrough", null],
        ["", "Lista com marcadores", "insertUnorderedList", "list-outline"], ["", "Lista numerada", "insertOrderedList", "list-circle-outline"],
        ["", "Diminuir recuo", "outdent", "arrow-back-outline"], ["", "Aumentar recuo", "indent", "arrow-forward-outline"],
        ["", "Alinhar à esquerda", "justifyLeft", "reorder-three-outline"], ["", "Centralizar", "justifyCenter", "menu-outline"], ["", "Alinhar à direita", "justifyRight", "reorder-two-outline"],
        ["Tx", "Limpar formatação", "removeFormat", null]
    ].forEach(([label, title, command, icon]) => toolbar.append(editorButton(label, title, () => editor.execCommand(command), icon)));
    toolbar.append(editorButton("", "Inserir link", () => { editor.selection.restore(); const href = window.prompt("URL do link (https://...)"); if (href) editor.execCommand("createLink", href.trim()); }, "link-outline"));
    const file = document.createElement("input");
    file.type = "file";
    file.accept = "image/jpeg,image/png,image/webp,image/gif";
    file.hidden = true;
    file.addEventListener("change", async () => {
        const selected = file.files?.[0];
        if (!selected) return;
        const button = toolbar.querySelector('[data-upload-tool="true"]');
        button?.classList.add("is-loading");
        try {
            const uploaded = await uploadImage(selected);
            editor.insertContent(`<p class="organic-image-block"><img src="${uploaded.url.replaceAll('"', '&quot;')}" alt="${selected.name.replaceAll('"', '&quot;')}" class="organic-content-image"></p><p><br></p>`);
        } catch (error) { window.alert(error.message); }
        finally { button?.classList.remove("is-loading"); file.value = ""; }
    });
    const imageButton = editorButton("", "Enviar imagem", () => file.click(), "cloud-upload-outline");
    imageButton.dataset.uploadTool = "true";
    toolbar.append(imageButton, file);
    toolbar.append(editorButton("", "Escolher imagem da biblioteca", () => {
        let picker = document.querySelector(`[data-media-picker][data-media-editor="${CSS.escape(textarea.id)}"]`);
        if (!picker) {
            picker = document.createElement("button");
            picker.type = "button";
            picker.hidden = true;
            picker.dataset.mediaPicker = "";
            picker.dataset.mediaEditor = textarea.id;
            toolbar.append(picker);
        }
        picker.click();
    }, "images-outline"));
    toolbar.append(editorButton("", "Inserir tabela", () => {
        const rowInput = window.prompt("Número de linhas:", "3");
        if (rowInput === null) return;
        const rows = Math.min(20, Math.max(1, Number(rowInput) || 1));
        const columnInput = window.prompt("Número de colunas:", "3");
        if (columnInput === null) return;
        const columns = Math.min(10, Math.max(1, Number(columnInput) || 1));
        const cells = (tag) => `<${tag}>Conteúdo</${tag}>`.repeat(columns);
        editor.insertContent(`<div class="organic-table-wrap"><table class="organic-content-table"><thead><tr>${cells("th")}</tr></thead><tbody>${`<tr>${cells("td")}</tr>`.repeat(rows)}</tbody></table></div><p><br></p>`);
    }, "grid-outline"));
    toolbar.append(editorButton("", "Inserir linha horizontal", () => editor.execCommand("insertHorizontalRule"), "remove-outline"));
    toolbar.append(editorButton("", "Editar HTML", () => { const html = window.prompt("Edite o HTML do conteúdo:", editor.getContent()); if (html !== null) editor.setExternalContent(html); }, "code-slash-outline"));
    toolbar.append(editorButton("", "Tela cheia", () => textarea.closest(".studio-panel")?.classList.toggle("moves-organic-fullscreen"), "expand-outline"));
    editor.element.before(toolbar);
};

let OrganicEditor;
try {
    ({default: OrganicEditor} = await import(editorModuleUrl));
} catch (error) {
    console.error("Organic Editor não pôde ser carregado.", error);
}

document.querySelectorAll("textarea[data-organic-editor]").forEach((textarea) => {
    if (!textarea.id) textarea.id = `organic-content-${editors.size + 1}`;
    if (!OrganicEditor) {
        textarea.hidden = false;
        textarea.removeAttribute("data-organic-editor");
        window.tinymce?.init({target: textarea, height: Number(textarea.dataset.editorHeight || 520), menubar: false, plugins: "link image lists table code fullscreen", toolbar: "undo redo | blocks | bold italic underline | bullist numlist | link image table | code fullscreen", relative_urls: false, remove_script_host: false});
        return;
    }
    const wasRequired = textarea.required;
    textarea.required = false;
    const storagePrefix = `moves-studio:${location.pathname}:${textarea.id}:`;
    const storage = {
        get(key, fallback = null) {
            try { const value = localStorage.getItem(storagePrefix + key); return value === null ? fallback : JSON.parse(value); }
            catch { return fallback; }
        },
        set(key, value) { localStorage.setItem(storagePrefix + key, JSON.stringify(value)); },
        remove(key) { localStorage.removeItem(storagePrefix + key); }
    };
    const editor = OrganicEditor.init({
        target: textarea,
        preset: "full",
        mode: "email",
        placeholder: "Comece a escrever o conteúdo...",
        upload: uploadImage,
        filePicker: async () => null,
        storage,
        plugins: "link image table media special workspace persistence export clipboard searchreplace visual pages",
        toolbar: "undo redo blocks fontfamily fontsize bold italic underline strikethrough alignleft aligncenter alignright alignjustify bullist numlist outdent indent link image table upload library video audio embed file emoji symbol anchor codeblock pasteplain visualblocks visualchars pdf word html print code",
        menubar: "file edit insert format view"
    });
    editors.set(textarea.id, editor);
    installToolbar(editor, textarea);
    const form = textarea.form;
    form?.addEventListener("submit", (event) => {
        textarea.value = editor.getContent();
        const plain = textarea.value.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim();
        if (wasRequired && !plain && !textarea.value.match(/<(img|video|audio|iframe)\b/i)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            editor.focus();
            window.alert("Preencha o conteúdo antes de salvar.");
        }
    }, true);
});

window.MovesOrganicEditor = {
    get(id) { return editors.get(id) || OrganicEditor.get(id) || null; },
    insert(id, html) {
        const editor = this.get(id);
        if (!editor) return false;
        editor.insertContent(html);
        return true;
    }
};
