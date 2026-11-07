export class ExportPlugin {
    constructor() {
        this.name = "export";
    }

    init(editor) {
        this.editor = editor;

        document.querySelector("#exportPdfButton")?.addEventListener("click", () => {
            this.exportPDF();
        });

        document.querySelector("#exportWordButton")?.addEventListener("click", () => {
            this.exportWord();
        });

        document.querySelector("#exportHtmlButton")?.addEventListener("click", () => {
            this.downloadHTML();
        });

        document.querySelector("#printButton")?.addEventListener("click", () => {
            this.print();
        });

        document.querySelectorAll("[data-preview-size]").forEach((button) => {
            button.addEventListener("click", () => {
                this.setPreviewSize(button.dataset.previewSize);
            });
        });
    }

    buildDocumentHTML({ title = "Documento Organic", word = false } = {}) {
        const content = this.editor.getContent();
        const mode = this.editor.element.dataset.mode || "document";

        const pageCSS = mode === "document"
            ? "@page{size:A4;margin:18mm 16mm}"
            : "@page{size:auto;margin:15mm}";

        const namespaces = word
            ? ' xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"'
            : "";

        return `<!DOCTYPE html>
<html lang="pt-BR"${namespaces}>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${title}</title>
<style>
${pageCSS}
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;color:#111827;line-height:1.6;margin:0;background:#fff}
img{max-width:100%;height:auto}
table{width:100%;border-collapse:collapse}
td,th{border:1px solid #dfe3e8;padding:8px;vertical-align:top}
blockquote{border-left:4px solid #16a34a;padding:8px 16px;color:#475569}
pre{white-space:pre-wrap;word-break:break-word;background:#f3f4f6;padding:12px;font-family:Consolas,monospace}
a{color:#16a34a}
</style>
</head>
<body>${content}</body>
</html>`;
    }

    exportPDF() {
        const printable = this.buildDocumentHTML({ title: "Organic Editor PDF" });
        const blob = new Blob([printable], { type: "text/html;charset=utf-8" });
        const url = URL.createObjectURL(blob);

        const popup = window.open(url, "_blank");

        if (!popup) {
            URL.revokeObjectURL(url);
            this.editor.setStatus("Permita pop-ups para abrir o PDF");
            return;
        }

        const release = () => setTimeout(() => URL.revokeObjectURL(url), 3000);
        popup.addEventListener("load", () => {
            setTimeout(() => {
                try {
                    popup.focus();
                    popup.print();
                } finally {
                    release();
                }
            }, 250);
        }, { once: true });

        this.editor.setStatus("PDF preparado — escolha Salvar como PDF");
    }

    exportWord() {
        const html = this.buildDocumentHTML({
            title: "Documento Organic",
            word: true
        });

        const blob = new Blob(["\ufeff", html], {
            type: "application/msword;charset=utf-8"
        });

        this.downloadBlob(
            blob,
            `organic-document-${new Date().toISOString().slice(0, 10)}.doc`
        );

        this.editor.setStatus("Arquivo Word baixado");
    }

    downloadHTML() {
        const html = this.buildDocumentHTML({ title: "Documento Organic" });
        const blob = new Blob([html], {
            type: "text/html;charset=utf-8"
        });

        this.downloadBlob(
            blob,
            `organic-document-${new Date().toISOString().slice(0, 10)}.html`
        );

        this.editor.setStatus("HTML baixado");
    }

    downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");

        link.href = url;
        link.download = filename;
        link.style.display = "none";

        document.body.append(link);
        link.click();

        setTimeout(() => {
            link.remove();
            URL.revokeObjectURL(url);
        }, 1200);
    }

    print() {
        const printable = this.buildDocumentHTML({ title: "Impressão" });
        const blob = new Blob([printable], { type: "text/html;charset=utf-8" });
        const url = URL.createObjectURL(blob);
        const popup = window.open(url, "_blank");

        if (!popup) {
            URL.revokeObjectURL(url);
            this.editor.setStatus("Permita pop-ups para imprimir");
            return;
        }

        popup.addEventListener("load", () => {
            setTimeout(() => {
                try {
                    popup.focus();
                    popup.print();
                } finally {
                    setTimeout(() => URL.revokeObjectURL(url), 3000);
                }
            }, 250);
        }, { once: true });
    }

    setPreviewSize(size) {
        const allowed = new Set(["desktop", "tablet", "mobile"]);
        const normalized = allowed.has(size) ? size : "desktop";

        const frame = document.querySelector("#previewFrame");
        if (!frame) return;

        frame.dataset.size = normalized;

        document.querySelectorAll("[data-preview-size]").forEach((button) => {
            const active = button.dataset.previewSize === normalized;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", active ? "true" : "false");
        });
    }
}
