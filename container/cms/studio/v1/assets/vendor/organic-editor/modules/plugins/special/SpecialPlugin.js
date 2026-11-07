export class SpecialPlugin {
    constructor() { this.name = "special"; }

    init(editor) {
        this.editor = editor;
        document.getElementById("emojiButton")?.addEventListener("mousedown", e => { e.preventDefault(); editor.selection.save(); document.getElementById("emojiDialog").showModal(); });
        document.getElementById("specialButton")?.addEventListener("mousedown", e => { e.preventDefault(); editor.selection.save(); document.getElementById("specialDialog").showModal(); });
        document.getElementById("anchorButton")?.addEventListener("mousedown", e => { e.preventDefault(); editor.selection.save(); document.getElementById("anchorDialog").showModal(); });
        document.getElementById("codeBlockButton")?.addEventListener("mousedown", e => { e.preventDefault(); editor.selection.save(); document.getElementById("codeDialog").showModal(); });

        document.querySelectorAll("[data-insert-emoji]").forEach(btn => btn.addEventListener("click", () => this.insertText(btn.dataset.insertEmoji)));
        document.querySelectorAll("[data-insert-special]").forEach(btn => btn.addEventListener("click", () => this.insertText(btn.dataset.insertSpecial)));

        document.getElementById("anchorForm")?.addEventListener("submit", () => {
            editor.selection.restore();
            const id = this.slug(document.getElementById("anchorName").value);
            if (id) editor.insertContent(`<span id="${this.escape(id)}" class="organic-anchor">⚑</span>`);
        });

        document.getElementById("codeForm")?.addEventListener("submit", () => {
            editor.selection.restore();
            const language = document.getElementById("codeLanguage").value.trim() || "text";
            const code = document.getElementById("codeContent").value;
            editor.insertContent(`<pre class="organic-code-block" data-language="${this.escape(language)}"><code>${this.escape(code)}</code></pre><p><br></p>`);
        });
    }

    insertText(value) {
        this.editor.selection.restore();
        this.editor.insertContent(this.escape(value));
    }

    slug(value) {
        return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
    }

    escape(value) {
        return String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
    }
}
