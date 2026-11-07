import { Editor } from "../Editor.js";
import { LinkPlugin } from "../plugins/link/LinkPlugin.js";
import { ImagePlugin } from "../plugins/image/ImagePlugin.js";
import { TablePlugin } from "../plugins/table/TablePlugin.js";
import { MediaPlugin } from "../plugins/media/MediaPlugin.js";
import { SpecialPlugin } from "../plugins/special/SpecialPlugin.js";
import { WorkspacePlugin } from "../plugins/workspace/WorkspacePlugin.js";
import { PersistencePlugin } from "../plugins/persistence/PersistencePlugin.js";
import { ExportPlugin } from "../plugins/export/ExportPlugin.js";
import { ClipboardPlugin } from "../plugins/clipboard/ClipboardPlugin.js";
import { SearchReplacePlugin } from "../plugins/searchreplace/SearchReplacePlugin.js";
import { VisualPlugin } from "../plugins/visual/VisualPlugin.js";
import { PageManagerPlugin } from "../plugins/pages/PageManagerPlugin.js";
import { UIConfigurator } from "./UIConfigurator.js";
import { UploadAdapter, StorageAdapter } from "./Adapters.js";

export class EditorManager {
    constructor() {
        this.instances = new Map();
        this.counter = 0;
        this.registry = new Map([
            ["link", LinkPlugin],
            ["image", ImagePlugin],
            ["table", TablePlugin],
            ["media", MediaPlugin],
            ["special", SpecialPlugin],
            ["workspace", WorkspacePlugin],
            ["persistence", PersistencePlugin],
            ["export", ExportPlugin],
            ["clipboard", ClipboardPlugin],
            ["searchreplace", SearchReplacePlugin],
            ["visual", VisualPlugin],
            ["pages", PageManagerPlugin]
        ]);
    }

    init(options = {}) {
        const targets = this.resolveTargets(options);

        if (!targets.length) {
            throw new Error("OrganicEditor: nenhum elemento encontrado.");
        }

        return targets.map((target) => this.create(target, options));
    }

    create(target, options = {}) {
        if (!(target instanceof HTMLElement)) {
            throw new Error("OrganicEditor: target inválido.");
        }

        const existingId = target.dataset.organicEditorId;
        if (existingId && this.instances.has(existingId)) {
            return this.instances.get(existingId);
        }

        const id = options.id || target.id || `organic-editor-${++this.counter}`;
        const textarea = target.tagName === "TEXTAREA" ? target : null;
        const mount = textarea ? this.createMount(textarea, id) : target;

        mount.classList.add("organic-editor");
        mount.setAttribute("contenteditable", options.readonly ? "false" : "true");
        mount.dataset.mode = options.mode === "email" ? "email" : "document";

        if (options.placeholder) {
            mount.dataset.placeholder = options.placeholder;
        }

        if (options.readonly) {
            mount.dataset.readonly = "true";
        }

        const editor = new Editor(mount, {
            ...options,
            id
        });

        editor.uploadAdapter = new UploadAdapter({
            upload: options.upload,
            filePicker: options.filePicker
        });

        editor.storageAdapter = new StorageAdapter(options.storage || {});

        this.registerPlugins(editor, options.plugins);
        editor.init();

        const ui = new UIConfigurator(editor, options);
        ui.apply();
        editor.uiConfigurator = ui;

        if (textarea) {
            editor.setContent(textarea.value || options.content || "");
            this.bindTextarea(editor, textarea);
            textarea.hidden = true;
        } else if (typeof options.content === "string") {
            editor.setContent(options.content);
        }

        editor.setEditorMode(options.mode || "document");

        editor.id = id;
        editor.sourceElement = target;
        editor.textarea = textarea;

        editor.destroy = () => this.destroy(id);

        this.instances.set(id, editor);
        target.dataset.organicEditorId = id;

        return editor;
    }

    resolveTargets(options) {
        if (options.target instanceof HTMLElement) {
            return [options.target];
        }

        if (typeof options.selector === "string") {
            return [...document.querySelectorAll(options.selector)];
        }

        return [];
    }

    registerPlugins(editor, requested) {
        const defaults = [
            "link",
            "image",
            "table",
            "media",
            "special",
            "workspace",
            "persistence",
            "export",
            "clipboard",
            "searchreplace",
            "visual",
            "pages"
        ];

        const names = Array.isArray(requested)
            ? requested
            : typeof requested === "string"
                ? requested.split(/\s+/).filter(Boolean)
                : defaults;

        names.forEach((name) => {
            const Plugin = this.registry.get(name);
            if (!Plugin) return;
            editor.use(new Plugin());
        });
    }

    registerPlugin(name, PluginClass) {
        if (!name || typeof PluginClass !== "function") {
            throw new Error("OrganicEditor: plugin inválido.");
        }
        this.registry.set(name, PluginClass);
    }

    createMount(textarea, id) {
        const mount = document.createElement("div");
        mount.id = `${id}-mount`;
        mount.className = "organic-editor";
        textarea.insertAdjacentElement("afterend", mount);
        return mount;
    }

    bindTextarea(editor, textarea) {
        const sync = () => {
            textarea.value = editor.getContent();
        };

        editor.on("change", sync);
        sync();

        textarea.form?.addEventListener("submit", sync);
    }

    get(id) {
        return this.instances.get(id) || null;
    }

    getAll() {
        return [...this.instances.values()];
    }

    destroy(id) {
        const editor = this.instances.get(id);
        if (!editor) return false;

        if (editor.textarea) {
            editor.textarea.value = editor.getContent();
            editor.textarea.hidden = false;
            editor.element.remove();
        } else {
            editor.element.removeAttribute("contenteditable");
            editor.element.classList.remove("organic-editor");
        }

        editor.sourceElement?.removeAttribute("data-organic-editor-id");
        this.instances.delete(id);
        return true;
    }
}
