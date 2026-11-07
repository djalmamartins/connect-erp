export class DOMEngine {
    constructor(editor) {
        this.editor = editor;
    }

    getEditorRange() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;

        const range = selection.getRangeAt(0);
        const container = range.commonAncestorContainer;

        if (!this.editor.element.contains(container)) return null;

        if (this.editor.element.dataset.mode === "document") {
            let node = container;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;

            const pageContent = node?.closest?.(".organic-page-content");
            if (!pageContent) return null;
        }

        return range;
    }

    wrapSelection(tagName, attributes = {}) {
        const range = this.getEditorRange();
        if (!range || range.collapsed) return false;

        const wrapper = document.createElement(tagName);
        Object.entries(attributes).forEach(([key, value]) => wrapper.setAttribute(key, value));

        try {
            range.surroundContents(wrapper);
        } catch {
            const fragment = range.extractContents();
            wrapper.append(fragment);
            range.insertNode(wrapper);
        }

        this.selectNodeContents(wrapper);
        this.commit();
        return true;
    }

    toggleInlineTag(tagName) {
        this.editor.selection.restore();
        const range = this.getEditorRange();
        if (!range || range.collapsed) return false;

        const wrapper = document.createElement(tagName);
        try {
            range.surroundContents(wrapper);
        } catch {
            const fragment = range.extractContents();
            wrapper.append(fragment);
            range.insertNode(wrapper);
        }

        this.selectNodeContents(wrapper);
        this.editor.selection.save();
        this.commit();
        return true;
    }

    createLink(url, options = {}) {
        this.editor.selection.restore();
        const range = this.getEditorRange();
        if (!range) return false;

        const anchor = document.createElement("a");
        anchor.href = url;

        if (options.targetBlank) {
            anchor.target = "_blank";
            anchor.rel = "noopener noreferrer";
        }

        if (range.collapsed) {
            anchor.textContent = url;
            range.insertNode(anchor);
            const caret = document.createRange();
            caret.setStartAfter(anchor);
            caret.collapse(true);
            this.setRange(caret);
        } else {
            try {
                range.surroundContents(anchor);
            } catch {
                const fragment = range.extractContents();
                anchor.append(fragment);
                range.insertNode(anchor);
            }
            this.selectNodeContents(anchor);
        }

        this.editor.selection.save();
        this.commit();
        return true;
    }

    applyInlineStyle(property, value) {
        this.editor.selection.restore();
        const range = this.getEditorRange();
        if (!range) return false;

        if (range.collapsed) {
            const span = document.createElement("span");
            span.style[property] = value;
            span.appendChild(document.createTextNode("\u200B"));
            range.insertNode(span);

            const caret = document.createRange();
            caret.setStart(span.firstChild, 1);
            caret.collapse(true);
            this.setRange(caret);
        } else {
            const span = document.createElement("span");
            span.style[property] = value;

            try {
                range.surroundContents(span);
            } catch {
                const fragment = range.extractContents();
                span.appendChild(fragment);
                range.insertNode(span);
            }

            this.selectNodeContents(span);
        }

        this.editor.selection.save();
        this.commit();
        return true;
    }

    insertHTML(html) {
        this.editor.selection.restore();
        const range = this.getEditorRange() || this.fallbackRange();
        if (!range) return false;

        const template = document.createElement("template");
        template.innerHTML = html.trim();
        const fragment = template.content;
        const lastNode = fragment.lastChild;

        range.deleteContents();
        range.insertNode(fragment);

        if (lastNode) {
            const caret = document.createRange();
            caret.setStartAfter(lastNode);
            caret.collapse(true);
            this.setRange(caret);
        }

        this.editor.selection.save();
        this.commit();
        return true;
    }

    insertNode(node) {
        this.editor.selection.restore();
        const range = this.getEditorRange() || this.fallbackRange();
        if (!range) return false;

        range.deleteContents();
        range.insertNode(node);

        const caret = document.createRange();
        caret.setStartAfter(node);
        caret.collapse(true);
        this.setRange(caret);

        this.editor.selection.save();
        this.commit();
        return true;
    }

    replaceSelection(text) {
        this.editor.selection.restore();
        const range = this.getEditorRange();
        if (!range) return false;

        range.deleteContents();
        const node = document.createTextNode(text);
        range.insertNode(node);

        const caret = document.createRange();
        caret.setStartAfter(node);
        caret.collapse(true);
        this.setRange(caret);

        this.editor.selection.save();
        this.commit();
        return true;
    }

    fallbackRange() {
        const range = document.createRange();
        range.selectNodeContents(this.editor.element);
        range.collapse(false);
        this.setRange(range);
        return range;
    }

    selectNodeContents(node) {
        const range = document.createRange();
        range.selectNodeContents(node);
        this.setRange(range);
    }

    setRange(range) {
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    commit() {
        this.editor.history.capture(true);
        this.editor.updateCounters();
        this.editor.updateCurrentBlock();
        this.editor.events.emit("change", this.editor.getContent());
    }
}
