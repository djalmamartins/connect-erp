export class LinkPlugin {
    constructor() {
        this.name = "link";
    }

    init(editor) {
        const button = document.querySelector("#linkButton");
        const dialog = document.querySelector("#linkDialog");
        const form = document.querySelector("#linkForm");
        const urlInput = document.querySelector("#linkUrl");
        const textInput = document.querySelector("#linkText");
        const newTab = document.querySelector("#linkNewTab");

        button?.addEventListener("mousedown", (event) => {
            event.preventDefault();
            editor.selection.save();
            textInput.value = editor.selection.getSelectedText();
            urlInput.value = "";
            newTab.checked = false;
            dialog.showModal();
            setTimeout(() => urlInput.focus(), 0);
        });

        form?.addEventListener("submit", () => {
            const url = urlInput.value.trim();
            if (!url) return;

            editor.selection.restore();

            const text = textInput.value.trim();
            const target = newTab.checked ? ' target="_blank" rel="noopener noreferrer"' : "";

            if (text) {
                editor.insertContent(`<a href="${this.escape(url)}"${target}>${this.escape(text)}</a>`);
            } else {
                editor.execCommand("createLink", url);
            }
        });
    }

    escape(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }
}
