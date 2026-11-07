export class CommandAdapter {
    constructor(editor) { this.editor = editor; }

    execute(command, value = null) {
        document.execCommand("styleWithCSS", false, false);
        return document.execCommand(command, false, value);
    }

    queryState(command) {
        try { return document.queryCommandState(command); } catch { return false; }
    }

    formatBlock(tagName) {
        const normalized = String(tagName).replace(/[<>]/g, "");
        return document.execCommand("formatBlock", false, normalized);
    }
}
