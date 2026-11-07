export class ShortcutManager {
    constructor(editor) {
        this.editor = editor;
        this.shortcuts = new Map();
    }

    register(combo, callback) {
        this.shortcuts.set(this.normalize(combo), callback);
    }

    init() {
        this.editor.element.addEventListener("keydown", (event) => {
            const combo = this.fromEvent(event);
            const callback = this.shortcuts.get(combo);

            if (!callback) return;

            event.preventDefault();
            callback(event);
        });
    }

    fromEvent(event) {
        const parts = [];
        if (event.metaKey || event.ctrlKey) parts.push("mod");
        if (event.shiftKey) parts.push("shift");
        if (event.altKey) parts.push("alt");
        parts.push(event.key.toLowerCase());
        return parts.join("+");
    }

    normalize(combo) {
        return combo.toLowerCase().replace(/\s+/g, "");
    }
}
