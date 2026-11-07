export class SearchReplacePlugin {
    constructor() {
        this.name = "searchreplace";
    }

    init(editor) {
        this.editor = editor;

        document.querySelector("#searchReplaceButton")?.addEventListener("click", () => {
            document.querySelector("#searchTerm").value = "";
            document.querySelector("#replaceTerm").value = "";
            document.querySelector("#searchResult").textContent = "";
            document.querySelector("#searchReplaceDialog")?.showModal();
        });

        document.querySelector("#findButton")?.addEventListener("click", () => this.find());
        document.querySelector("#replaceOneButton")?.addEventListener("click", () => this.replaceOne());
        document.querySelector("#replaceAllButton")?.addEventListener("click", () => this.replaceAll());
    }

    find() {
        const term = document.querySelector("#searchTerm")?.value || "";
        if (!term) return;

        const text = this.editor.getText();
        const count = text.toLowerCase().split(term.toLowerCase()).length - 1;

        document.querySelector("#searchResult").textContent =
            count ? `${count} ocorrência(s) encontrada(s)` : "Nenhuma ocorrência encontrada";
    }

    replaceOne() {
        const term = document.querySelector("#searchTerm")?.value || "";
        const replacement = document.querySelector("#replaceTerm")?.value || "";
        if (!term) return;

        const html = this.editor.getContent();
        const regex = new RegExp(this.escapeRegex(term), "i");

        if (!regex.test(html)) {
            document.querySelector("#searchResult").textContent = "Nenhuma ocorrência encontrada";
            return;
        }

        this.editor.setContent(html.replace(regex, replacement));
        this.editor.setStatus("Uma ocorrência substituída");
        this.find();
    }

    replaceAll() {
        const term = document.querySelector("#searchTerm")?.value || "";
        const replacement = document.querySelector("#replaceTerm")?.value || "";
        if (!term) return;

        const html = this.editor.getContent();
        const regex = new RegExp(this.escapeRegex(term), "gi");
        const matches = html.match(regex) || [];

        this.editor.setContent(html.replace(regex, replacement));
        this.editor.setStatus(`${matches.length} ocorrência(s) substituída(s)`);
        this.find();
    }

    escapeRegex(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    }
}
