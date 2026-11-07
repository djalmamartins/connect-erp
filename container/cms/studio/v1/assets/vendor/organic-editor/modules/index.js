import { OrganicEditor } from "./Editor.js";
import { ImagePlugin } from "./plugins/image/ImagePlugin.js";
import { LinkPlugin } from "./plugins/link/LinkPlugin.js";
import { TablePlugin } from "./plugins/table/TablePlugin.js";
import { MediaPlugin } from "./plugins/media/MediaPlugin.js";
import { SpecialPlugin } from "./plugins/special/SpecialPlugin.js";
import { IconSystem } from "./ui/IconSystem.js";
import { Tooltip } from "./ui/Tooltip.js";
import { WorkspacePlugin } from "./plugins/workspace/WorkspacePlugin.js";
import { PersistencePlugin } from "./plugins/persistence/PersistencePlugin.js";
import { ExportPlugin } from "./plugins/export/ExportPlugin.js";
import { ClipboardPlugin } from "./plugins/clipboard/ClipboardPlugin.js";
import { SearchReplacePlugin } from "./plugins/searchreplace/SearchReplacePlugin.js";
import { VisualPlugin } from "./plugins/visual/VisualPlugin.js";
import { PageManagerPlugin } from "./plugins/pages/PageManagerPlugin.js";

const editor = new OrganicEditor({
    selector: "#editor",
    toolbar: "#organic-toolbar",
    wordCount: "#wordCount",
    charCount: "#charCount",
    currentBlock: "#currentBlock",
    saveStatus: "#saveStatus"
});

editor.use(new ImagePlugin());
editor.use(new LinkPlugin());
editor.use(new TablePlugin());
editor.use(new MediaPlugin());
editor.use(new SpecialPlugin());
editor.use(new WorkspacePlugin());
editor.use(new PersistencePlugin());
editor.use(new ExportPlugin());
editor.use(new ClipboardPlugin());
editor.use(new SearchReplacePlugin());
editor.use(new VisualPlugin());
editor.use(new PageManagerPlugin());
editor.init();

window.OrganicEditor = editor;

const icons = new IconSystem();
icons.mount();
new Tooltip();

const $ = (selector) => document.querySelector(selector);

const previewDialog = $("#previewDialog");
const previewContent = $("#previewContent");
const sourceDialog = $("#sourceDialog");
const sourceTextarea = $("#sourceTextarea");
const editorCard = $("#editorCard");

const setEditorFullscreen = (enabled) => {
    editorCard?.classList.toggle("is-fullscreen", Boolean(enabled));
    document.body.classList.toggle("organic-editor-fullscreen-open", Boolean(enabled));
};

const isEditorFullscreen = () => editorCard?.classList.contains("is-fullscreen") === true;
const themeButton = $("#themeButton");
const savedTheme = localStorage.getItem("organic-editor-theme") || "light";
document.documentElement.dataset.theme = savedTheme;

const syncThemeIcon = () => {
    themeButton.dataset.icon = document.documentElement.dataset.theme === "dark" ? "sun" : "moon";
    themeButton.querySelector("svg")?.remove();
    icons.mount(themeButton.parentElement);
};

if (themeButton) syncThemeIcon();

themeButton?.addEventListener("click", () => {
    const next = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    localStorage.setItem("organic-editor-theme", next);
    syncThemeIcon();
});


$("#previewButton")?.addEventListener("click", () => {
    if (isEditorFullscreen()) setEditorFullscreen(false);
    const html = editor.getContent();
    if (previewContent) previewContent.innerHTML = html;

    const frame = document.querySelector("#previewFrame");
    if (frame) {
        frame.innerHTML = html;
        frame.scrollTop = 0;
    }

    if (previewDialog && !previewDialog.open) {
        previewDialog.showModal();
    }
});

$("#fullscreenButton")?.addEventListener("click", () => {
    setEditorFullscreen(!isEditorFullscreen());
});

$("#saveButton")?.addEventListener("click", () => {
    localStorage.setItem("organic-editor-v097-content", editor.getContent());
    editor.setStatus("Salvo localmente");
    editor.plugins.get("persistence")?.createRevision("Salvamento manual");
});

$("#sourceButton")?.addEventListener("click", () => {
    sourceTextarea.value = editor.getContent();
    sourceDialog.showModal();
});

$("#sourceForm")?.addEventListener("submit", () => {
    editor.setContent(sourceTextarea.value);
});

document.querySelectorAll("[data-close-dialog]").forEach((button) => {
    button.addEventListener("click", () => {
        document.getElementById(button.dataset.closeDialog)?.close();
    });
});

$("#hrButton")?.addEventListener("mousedown", (event) => {
    event.preventDefault();
    editor.execCommand("insertHorizontalRule");
});

const editorMode = $("#editorMode");
const workspace = document.querySelector(".organic-workspace");

const applyEditorMode = (mode) => {
    editor.setEditorMode(mode);
    editor.focus();
};

editorMode?.addEventListener("change", (event) => {
    applyEditorMode(event.target.value);
});

applyEditorMode(localStorage.getItem("organic-editor-mode") || "document");

document.querySelectorAll("[data-tab]").forEach((tab) => {
    tab.addEventListener("click", () => {
        document.querySelectorAll("[data-tab]").forEach((item) => {
            item.classList.remove("is-active");
            item.setAttribute("aria-selected", "false");
        });

        tab.classList.add("is-active");
        tab.setAttribute("aria-selected", "true");

        const panels = {
            media: "#sidebarMedia",
            blocks: "#sidebarBlocks",
            templates: "#sidebarTemplates",
            variables: "#sidebarVariables",
            components: "#sidebarComponents",
            info: "#sidebarInfo"
        };

        Object.entries(panels).forEach(([name, selector]) => {
            const panel = document.querySelector(selector);
            panel?.classList.toggle("is-hidden", tab.dataset.tab !== name);
            panel?.setAttribute("aria-hidden", tab.dataset.tab === name ? "false" : "true");
        });
    });

    tab.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;

        const tabs = [...document.querySelectorAll("[data-tab]")];
        const index = tabs.indexOf(tab);
        let nextIndex = index;

        if (event.key === "ArrowLeft") nextIndex = (index - 1 + tabs.length) % tabs.length;
        if (event.key === "ArrowRight") nextIndex = (index + 1) % tabs.length;
        if (event.key === "Home") nextIndex = 0;
        if (event.key === "End") nextIndex = tabs.length - 1;

        event.preventDefault();
        tabs[nextIndex].focus();
        tabs[nextIndex].click();
    });
});

editor.on("change", () => {
    const sideWords = $("#sidebarWordCount");
    const sideChars = $("#sidebarCharCount");
    const words = $("#wordCount");
    const chars = $("#charCount");

    if (sideWords && words) sideWords.textContent = words.textContent;
    if (sideChars && chars) sideChars.textContent = chars.textContent;
});

const saved = localStorage.getItem("organic-editor-v097-content");
if (saved) editor.setContent(saved);

const foreColorInput = document.querySelector("#foreColor");
const hiliteColorInput = document.querySelector("#hiliteColor");
const foreColorSwatch = document.querySelector("#foreColorSwatch");
const hiliteColorSwatch = document.querySelector("#hiliteColorSwatch");

const syncColorSwatches = () => {
    if (foreColorInput && foreColorSwatch) foreColorSwatch.style.background = foreColorInput.value;
    if (hiliteColorInput && hiliteColorSwatch) hiliteColorSwatch.style.background = hiliteColorInput.value;
};

foreColorInput?.addEventListener("input", syncColorSwatches);
hiliteColorInput?.addEventListener("input", syncColorSwatches);
syncColorSwatches();


if (new URLSearchParams(window.location.search).get("qa") === "1") {
    import("../tests/functional-qa.js");
}


document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;

    if (isEditorFullscreen()) {
        event.preventDefault();
        setEditorFullscreen(false);
        editor.focus();
        return;
    }

    const openDialog = [...document.querySelectorAll("dialog[open]")].at(-1);
    if (openDialog) {
        event.preventDefault();
        openDialog.close();
        editor.focus();
    }
});

const closeAllMenus = () => {
    document.querySelectorAll("[data-menu-panel]").forEach((panel) => panel.hidden = true);
    document.querySelectorAll("[data-menu-trigger]").forEach((trigger) => trigger.setAttribute("aria-expanded", "false"));
};

document.querySelectorAll("[data-menu-trigger]").forEach((trigger) => {
    trigger.setAttribute("aria-haspopup", "menu");
    trigger.setAttribute("aria-expanded", "false");
    trigger.addEventListener("click", (event) => {
        event.stopPropagation();
        const panel = document.querySelector(`[data-menu-panel="${trigger.dataset.menuTrigger}"]`);
        const open = panel?.hidden !== false;
        closeAllMenus();
        if (panel && open) {
            panel.hidden = false;
            trigger.setAttribute("aria-expanded", "true");
        }
    });
});

document.addEventListener("click", (event) => {
    if (!event.target.closest(".organic-menu")) closeAllMenus();
});

const menuActions = {
    save: () => document.querySelector("#saveButton")?.click(),
    draft: () => document.querySelector("#draftButton")?.click(),
    "export-pdf": () => document.querySelector("#exportPdfButton")?.click(),
    "export-word": () => document.querySelector("#exportWordButton")?.click(),
    "export-html": () => document.querySelector("#exportHtmlButton")?.click(),
    print: () => document.querySelector("#printButton")?.click(),
    undo: () => editor.history.undo(),
    redo: () => editor.history.redo(),
    search: () => document.querySelector("#searchReplaceButton")?.click(),
    "select-all": () => editor.execCommand("selectAll"),
    link: () => document.querySelector("#linkButton")?.dispatchEvent(new MouseEvent("mousedown", { bubbles: true })),
    image: () => document.querySelector("#imageButton")?.dispatchEvent(new MouseEvent("mousedown", { bubbles: true })),
    table: () => document.querySelector("#tableButton")?.dispatchEvent(new MouseEvent("mousedown", { bubbles: true })),
    video: () => document.querySelector("#videoButton")?.click(),
    audio: () => document.querySelector("#audioButton")?.click(),
    code: () => document.querySelector("#codeBlockButton")?.click(),
    bold: () => editor.execCommand("bold"),
    italic: () => editor.execCommand("italic"),
    underline: () => editor.execCommand("underline"),
    "clear-format": () => editor.execCommand("removeFormat"),
    preview: () => document.querySelector("#previewButton")?.click(),
    fullscreen: () => document.querySelector("#fullscreenButton")?.click(),
    "visual-blocks": () => document.querySelector("#visualBlocksButton")?.click(),
    invisibles: () => document.querySelector("#invisibleCharsButton")?.click()
};

document.querySelectorAll("[data-menu-action]").forEach((button) => {
    button.addEventListener("click", () => {
        menuActions[button.dataset.menuAction]?.();
        closeAllMenus();
    });
});


document.querySelector("#sidebar")?.addEventListener("mousedown", (event) => {
    if (event.target.closest("button, input, select, textarea")) {
        editor.selection.save();
    }
});


window.ORGANIC_DEMO_CONFIG = {
    mode: "document",
    plugins: [
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
    ],
    toolbar: [
        "undo", "redo", "blocks", "fontfamily", "fontsize",
        "bold", "italic", "underline", "strikethrough",
        "alignleft", "aligncenter", "alignright", "alignjustify",
        "bullist", "numlist", "outdent", "indent",
        "link", "image", "table",
        "upload", "library", "video", "audio", "embed", "file",
        "emoji", "symbol", "anchor", "codeblock",
        "searchreplace", "pasteplain", "visualblocks", "visualchars",
        "pdf", "word", "html", "print", "code"
    ],
    menubar: ["file", "edit", "insert", "format", "view"]
};
