export class VisualPlugin {
    constructor() {
        this.name = "visual";
        this.blocks = false;
        this.invisibles = false;
    }

    init(editor) {
        this.editor = editor;

        document.querySelector("#visualBlocksButton")?.addEventListener("click", (event) => {
            this.blocks = !this.blocks;
            editor.element.classList.toggle("show-visual-blocks", this.blocks);
            event.currentTarget.classList.toggle("is-active", this.blocks);
        });

        document.querySelector("#invisibleCharsButton")?.addEventListener("click", (event) => {
            this.invisibles = !this.invisibles;
            editor.element.classList.toggle("show-invisibles", this.invisibles);
            event.currentTarget.classList.toggle("is-active", this.invisibles);
        });
    }
}
