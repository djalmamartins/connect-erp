import { Commands } from "./core/Commands.js";
import { Selection } from "./core/Selection.js";
import { History } from "./core/History.js";
import { Events } from "./core/Events.js";
import { PluginManager } from "./core/PluginManager.js";
import { CommandAdapter } from "./core/CommandAdapter.js";
import { DOMEngine } from "./core/DOMEngine.js";
import { ShortcutManager } from "./core/ShortcutManager.js";
import { Sanitizer } from "./core/Sanitizer.js";
import { Parser } from "./core/Parser.js";
import { Serializer } from "./core/Serializer.js";
import { StorageAdapter } from "./core/Adapters.js";

export class OrganicEditor {
    constructor(targetOrOptions = {}, options = {}) {
        const target = targetOrOptions?.nodeType === 1 ? targetOrOptions : null;
        const resolvedOptions = target ? options : targetOrOptions;

        this.options = {
            selector: "#editor",
            toolbar: "#organic-toolbar",
            wordCount: "#wordCount",
            charCount: "#charCount",
            currentBlock: "#currentBlock",
            saveStatus: "#saveStatus",
            ...resolvedOptions
        };

        this.element = target;
        this.toolbar = null;

        this.commands = new Commands(this);
        this.selection = new Selection(this);
        this.history = new History(this);
        this.events = new Events();
        this.plugins = new PluginManager(this);
        this.commandAdapter = new CommandAdapter(this);
        this.domEngine = new DOMEngine(this);
        this.shortcuts = new ShortcutManager(this);
        this.tabExitArmed = false;
        this.sanitizer = new Sanitizer();
        this.parser = new Parser();
        this.serializer = new Serializer();
        this.storageAdapter = new StorageAdapter(this.options.storage || {});
    }

    use(plugin) {
        this.plugins.register(plugin);
        return this;
    }

    init() {
        this.element ||= this.queryOption("selector");
        this.toolbar = this.queryOption("toolbar");

        if (!this.element) {
            throw new Error(`OrganicEditor: elemento não encontrado: ${this.options.selector}`);
        }

        this.bindToolbar();
        this.bindEditorEvents();
        this.registerDefaultShortcuts();
        this.shortcuts.init();
        this.plugins.initAll();

        this.history.capture();
        this.updateCounters();
        this.updateCurrentBlock();

        this.events.emit("init", this);
        return this;
    }

    bindToolbar() {
        this.toolbar?.querySelectorAll("[data-command]").forEach((button) => {
            button.addEventListener("mousedown", (event) => {
                event.preventDefault();
                this.execCommand(button.dataset.command);
            });
        });

        document.querySelector("#blockFormat")?.addEventListener("change", (event) => {
            this.focus();
            this.commands.formatBlock(event.target.value);
        });

        document.querySelector("#fontName")?.addEventListener("change", (event) => {
            this.execCommand("fontName", event.target.value);
        });

        document.querySelector("#fontSize")?.addEventListener("change", (event) => {
            this.execCommand("fontSize", event.target.value);
        });

        const foreColor = document.querySelector("#foreColor");
        const hiliteColor = document.querySelector("#hiliteColor");

        [foreColor, hiliteColor].forEach((input) => {
            input?.addEventListener("mousedown", () => this.selection.save());
            input?.addEventListener("click", () => this.selection.save());
        });

        foreColor?.addEventListener("input", (event) => {
            this.domEngine.applyInlineStyle("color", event.target.value);
            this.setStatus("Cor do texto aplicada");
        });

        hiliteColor?.addEventListener("input", (event) => {
            this.domEngine.applyInlineStyle("backgroundColor", event.target.value);
            this.setStatus("Cor de fundo aplicada");
        });
    }

    bindEditorEvents() {
        this.element.addEventListener("input", () => {
            this.updateCounters();
            this.history.scheduleCapture();
            this.setStatus("Alterado");
            this.events.emit("change", this.getContent());
        });

        ["keyup", "mouseup", "focus"].forEach((eventName) => {
            this.element.addEventListener(eventName, () => {
                this.selection.save();
                this.updateCurrentBlock();
                this.updateToolbarState();
            });
        });

        this.element.addEventListener("keydown", (event) => {
            if (event.key === "Tab" && !event.shiftKey) {
                const block = this.selection.getCurrentBlock();

                if (block && /^(PRE|BLOCKQUOTE|LI)$/.test(block.tagName)) {
                    event.preventDefault();
                    this.tabExitArmed = true;
                    this.setStatus("Enter para sair do bloco");
                    return;
                }

                this.tabExitArmed = false;
            }

            if (event.key === "Enter" && this.tabExitArmed) {
                const block = this.selection.getCurrentBlock();

                if (block && /^(PRE|BLOCKQUOTE|LI)$/.test(block.tagName)) {
                    event.preventDefault();
                    this.exitBlockToParagraph(block);
                    this.tabExitArmed = false;
                    return;
                }

                this.tabExitArmed = false;
            }

            if (!["Tab", "Enter"].includes(event.key)) {
                this.tabExitArmed = false;
            }

            const modifier = event.metaKey || event.ctrlKey;
            if (!modifier) return;

            const key = event.key.toLowerCase();

            if (key === "z" && !event.shiftKey) {
                event.preventDefault();
                this.history.undo();
            }

            if ((key === "z" && event.shiftKey) || key === "y") {
                event.preventDefault();
                this.history.redo();
            }
        });

        this.element.addEventListener("drop", (event) => {
            const files = [...(event.dataTransfer?.files || [])].filter(file => file.type.startsWith("image/"));
            if (!files.length) return;

            event.preventDefault();
            this.events.emit("image:drop", files);
        });
    }

    exitBlockToParagraph(block) {
        const paragraph = document.createElement("p");
        paragraph.innerHTML = "<br>";

        if (block.tagName === "LI") {
            const list = block.parentElement;

            if (list && /^(UL|OL)$/.test(list.tagName)) {
                list.after(paragraph);
            } else {
                block.after(paragraph);
            }
        } else {
            block.after(paragraph);
        }

        const range = document.createRange();
        range.setStart(paragraph, 0);
        range.collapse(true);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        this.selection.save();
        this.history.capture(true);
        this.updateCounters();
        this.updateCurrentBlock();
        this.events.emit("change", this.getContent());
        this.setStatus("Parágrafo iniciado");
    }

    registerDefaultShortcuts() {
        this.shortcuts.register("mod+b", () => this.execCommand("bold"));
        this.shortcuts.register("mod+i", () => this.execCommand("italic"));
        this.shortcuts.register("mod+u", () => this.execCommand("underline"));
        this.shortcuts.register("mod+f", () => document.querySelector("#searchReplaceButton")?.click());
        this.shortcuts.register("mod+shift+s", () => document.querySelector("#draftButton")?.click());
    }

    execCommand(command, value = null) {
        this.focus();
        this.selection.restore();

        if (command === "undo") {
            this.history.undo();
            return;
        }

        if (command === "redo") {
            this.history.redo();
            return;
        }

        this.commands.exec(command, value);
        this.selection.save();
        this.history.capture();
        this.updateCounters();
        this.updateToolbarState();
        this.events.emit("change", this.getContent());
    }

    setReadonly(value = true) {
        const readonly = Boolean(value);
        this.element.setAttribute("contenteditable", readonly ? "false" : "true");
        this.element.dataset.readonly = readonly ? "true" : "false";
        this.options.readonly = readonly;
        this.events.emit("readonly", readonly);
        return this;
    }

    setPlaceholder(value = "") {
        this.element.dataset.placeholder = String(value || "");
        return this;
    }

    insertText(text = "") {
        this.focus();
        this.ensureInsertionRange();
        return this.domEngine.replaceSelection(String(text));
    }

    getJSON() {
        return {
            id: this.id,
            mode: this.element.dataset.mode || "document",
            readonly: this.element.dataset.readonly === "true",
            html: this.getContent(),
            text: this.getText()
        };
    }

    setEditorMode(mode) {
        const normalized = mode === "email" ? "email" : "document";
        const pages = this.plugins.get("pages");
        const workspace = document.querySelector(".organic-workspace");
        const editorMode = document.querySelector("#editorMode");

        if (normalized === "document" && !pages?.enabled) {
            pages?.enable();
        }

        if (normalized === "email" && pages?.enabled) {
            pages?.disable();
        }

        this.element.dataset.mode = normalized;
        workspace?.setAttribute("data-mode", normalized);

        if (editorMode) editorMode.value = normalized;

        const sidebarMode = document.querySelector("#sidebarMode");
        if (sidebarMode) {
            sidebarMode.textContent = normalized === "email" ? "E-mail" : "Documento";
        }

        const status = document.querySelector("#pageModeStatus");
        if (status) {
            status.textContent = normalized === "email" ? "Contínuo · E-mail" : "A4 · Documento";
        }

        localStorage.setItem("organic-editor-mode", normalized);

        requestAnimationFrame(() => {
            if (normalized === "document") {
                pages?.scheduleReflow();
            }
        });
    }

    getActivePage() {
        const selection = window.getSelection();

        if (selection?.rangeCount) {
            let node = selection.getRangeAt(0).commonAncestorContainer;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;

            const page = node?.closest?.(".organic-document-page");
            if (page && this.element.contains(page)) return page;
        }

        const pages = this.element.querySelectorAll(":scope > .organic-document-page");
        return pages[pages.length - 1] || null;
    }

    getActivePageContent() {
        const page = this.getActivePage();
        return page?.querySelector(":scope > .organic-page-content") || null;
    }

    ensureInsertionRange() {
        this.selection.restore();

        const selection = window.getSelection();

        if (selection?.rangeCount) {
            const range = selection.getRangeAt(0);
            let node = range.commonAncestorContainer;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;

            if (node && this.element.contains(node)) {
                const pageContent = node.closest?.(".organic-page-content");
                if (pageContent) return range;
            }
        }

        const target = this.getActivePageContent() || this.element;
        const range = document.createRange();
        range.selectNodeContents(target);
        range.collapse(false);

        const current = window.getSelection();
        current.removeAllRanges();
        current.addRange(range);

        this.selection.save();
        return range;
    }

    insertContent(html) {
        this.focus();

        const pages = this.plugins.get("pages");
        const isDocument = this.element.dataset.mode === "document" && pages?.enabled;

        if (!isDocument) {
            return this.domEngine.insertHTML(html);
        }

        this.ensureInsertionRange();

        const inserted = this.domEngine.insertHTML(html);

        if (inserted) {
            requestAnimationFrame(() => {
                pages.scheduleReflow();
            });
        }

        return inserted;
    }

    insertHTML(html) {
        return this.insertContent(html);
    }

    getContent() {
        const pages = this.plugins.get("pages");

        if (pages?.enabled) {
            return pages.serialize();
        }

        return this.serializer.serialize(this.element);
    }

    setContent(html = "") {
        this.element.innerHTML = String(html || "");
        this.history.capture(true);
        this.updateCounters();
        this.updateCurrentBlock();
        this.events.emit("change", this.getContent());
    }

    setExternalContent(html = "") {
        this.setContent(this.sanitizer.sanitize(html));
    }

    getText() {
        return this.element.innerText;
    }

    on(event, callback) {
        this.events.on(event, callback);
        return this;
    }

    off(event, callback) {
        this.events.off?.(event, callback);
        return this;
    }

    focus() {
        this.element.focus();
    }

    queryOption(name) {
        const selector = this.options[name];
        return typeof selector === "string" && selector.trim()
            ? document.querySelector(selector)
            : null;
    }

    setStatus(message) {
        const status = this.queryOption("saveStatus");
        if (status) status.textContent = message;
    }

    updateCounters() {
        const text = this.getText().replace(/\s+/g, " ").trim();
        const words = text ? text.split(" ").filter(Boolean).length : 0;
        const chars = this.getText().length;

        const wordCount = this.queryOption("wordCount");
        const charCount = this.queryOption("charCount");

        if (wordCount) wordCount.textContent = words;
        if (charCount) charCount.textContent = chars;
    }

    updateCurrentBlock() {
        const block = this.selection.getCurrentBlock();
        const target = this.queryOption("currentBlock");
        if (target) target.textContent = block ? block.tagName.toUpperCase() : "P";
    }

    updateToolbarState() {
        const states = [
            "bold", "italic", "underline", "strikeThrough",
            "subscript", "superscript",
            "justifyLeft", "justifyCenter", "justifyRight", "justifyFull",
            "insertUnorderedList", "insertOrderedList"
        ];

        states.forEach((command) => {
            const button = this.toolbar?.querySelector(`[data-command="${command}"]`);
            if (!button) return;

            let active = false;
            try {
                active = this.commandAdapter.queryState(command);
            } catch {}

            button.classList.toggle("is-active", active);
        });
    }
}

// Public package manager compatibility. The standalone demo historically uses
// OrganicEditor, while the multi-instance API imports Editor.
export { OrganicEditor as Editor };
