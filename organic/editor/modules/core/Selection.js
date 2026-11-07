export class Selection {
    constructor(editor) {
        this.editor = editor;
        this.range = null;
    }

    save() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);

        if (!this.editor.element.contains(range.commonAncestorContainer)) return;

        this.range = range.cloneRange();
    }

    restore() {
        if (!this.range) return;

        const selection = window.getSelection();
        if (!selection) return;

        selection.removeAllRanges();

        try {
            selection.addRange(this.range);
        } catch {
            this.range = null;
        }
    }

    getSelectedText() {
        const selection = window.getSelection();
        return selection?.toString() || "";
    }

    getCurrentBlock() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;

        let node = selection.anchorNode;
        if (!node) return null;

        if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;

        while (node && node !== this.editor.element) {
            if (/^(P|H1|H2|H3|H4|H5|H6|BLOCKQUOTE|PRE|LI)$/.test(node.tagName)) {
                return node;
            }
            node = node.parentElement;
        }

        return null;
    }
}
