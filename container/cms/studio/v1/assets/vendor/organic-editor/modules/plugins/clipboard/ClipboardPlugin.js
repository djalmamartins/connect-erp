export class ClipboardPlugin {
    constructor() {
        this.name = "clipboard";
    }

    init(editor) {
        this.editor = editor;

        editor.element.addEventListener("paste", (event) => this.handlePaste(event));

        document.querySelector("#pastePlainButton")?.addEventListener("click", async () => {
            try {
                const text = await navigator.clipboard.readText();
                editor.focus();
                editor.domEngine.replaceSelection(text);
            } catch {
                editor.setStatus("Não foi possível acessar a área de transferência");
            }
        });
    }

    handlePaste(event) {
        const clipboard = event.clipboardData;
        if (!clipboard) return;

        const html = clipboard.getData("text/html");
        const text = clipboard.getData("text/plain");

        if (!html) return;

        event.preventDefault();
        const cleaned = this.editor.sanitizer.sanitize(this.cleanHTML(html)) || this.escape(text);

        this.editor.focus();
        this.editor.selection.restore();
        this.editor.insertContent(cleaned);
        this.editor.setStatus("Conteúdo colado e limpo");
    }

    cleanHTML(html) {
        const doc = new DOMParser().parseFromString(html, "text/html");

        doc.querySelectorAll("script, style, meta, link, title, xml").forEach((node) => node.remove());

        doc.body.querySelectorAll("*").forEach((node) => {
            [...node.attributes].forEach((attribute) => {
                const name = attribute.name.toLowerCase();

                if (
                    name.startsWith("on") ||
                    ["class", "id", "lang", "dir", "data-cke-saved-href"].includes(name)
                ) {
                    node.removeAttribute(attribute.name);
                }

                if (name === "style") {
                    const allowed = this.cleanStyle(attribute.value);
                    if (allowed) node.setAttribute("style", allowed);
                    else node.removeAttribute("style");
                }
            });

            if (node.tagName === "FONT") {
                this.unwrap(node);
            }
        });

        return doc.body.innerHTML
            .replace(/<!--[\s\S]*?-->/g, "")
            .replace(/\s*mso-[^:]+:[^;"]+;?/gi, "")
            .trim();
    }

    cleanStyle(style) {
        const allowedProperties = new Set([
            "font-weight", "font-style", "text-decoration",
            "text-align", "color", "background-color"
        ]);

        return style
            .split(";")
            .map((rule) => rule.trim())
            .filter(Boolean)
            .filter((rule) => allowedProperties.has(rule.split(":")[0]?.trim().toLowerCase()))
            .join("; ");
    }

    unwrap(node) {
        const parent = node.parentNode;
        if (!parent) return;
        while (node.firstChild) parent.insertBefore(node.firstChild, node);
        node.remove();
    }

    escape(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;");
    }
}
