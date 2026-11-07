export class Commands {
    constructor(editor) {
        this.editor = editor;
    }

    exec(command, value = null) {
        try {
            this.editor.commandAdapter.execute(command, value);
        } catch (error) {
            console.error(`OrganicEditor command failed: ${command}`, error);
        }
        this.editor.focus();
    }

    formatBlock(tagName) {
        this.editor.focus();
        this.editor.selection.restore();
        this.editor.commandAdapter.formatBlock(tagName);
        this.editor.selection.save();
        this.editor.history.capture();
        this.editor.updateCounters();
        this.editor.updateCurrentBlock();
        this.editor.events.emit("change", this.editor.getContent());
    }
}
