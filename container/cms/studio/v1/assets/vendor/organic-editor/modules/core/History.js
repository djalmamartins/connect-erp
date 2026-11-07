export class History {
    constructor(editor) {
        this.editor = editor;
        this.stack = [];
        this.index = -1;
        this.timer = null;
        this.limit = 100;
        this.restoring = false;
    }

    capture(force = false) {
        if (this.restoring) return;

        const html = this.editor.getContent();
        const current = this.stack[this.index];

        if (!force && current === html) return;

        if (this.index < this.stack.length - 1) {
            this.stack = this.stack.slice(0, this.index + 1);
        }

        this.stack.push(html);

        if (this.stack.length > this.limit) {
            this.stack.shift();
        }

        this.index = this.stack.length - 1;
    }

    scheduleCapture() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.capture(), 600);
    }

    undo() {
        if (this.index <= 0) return;
        this.index -= 1;
        this.restoreCurrent();
    }

    redo() {
        if (this.index >= this.stack.length - 1) return;
        this.index += 1;
        this.restoreCurrent();
    }

    restoreCurrent() {
        this.restoring = true;
        this.editor.element.innerHTML = this.stack[this.index];
        this.restoring = false;

        this.editor.updateCounters();
        this.editor.updateCurrentBlock();
        this.editor.setStatus("Histórico restaurado");
        this.editor.events.emit("change", this.editor.getContent());
        this.editor.focus();
    }
}
