export class PageManagerPlugin {
    constructor() {
        this.name = "pages";
        this.enabled = false;
        this.timer = null;
        this.reflowing = false;
        this.maxPagesPerReflow = 20;
    }

    init(editor) {
        this.editor = editor;
        editor.element.addEventListener("input", () => this.scheduleReflow());

        window.addEventListener("resize", () => {
            if (this.enabled) this.scheduleReflow();
        });
    }

    enable() {
        if (this.enabled) return;

        const html = this.editor.element.innerHTML.trim() || "<p><br></p>";
        this.enabled = true;
        this.mount(html);
    }

    disable() {
        if (!this.enabled) return;

        const html = this.serialize() || "<p><br></p>";

        clearTimeout(this.timer);
        this.timer = null;
        this.reflowing = true;
        this.enabled = false;
        this.editor.element.innerHTML = html;
        this.reflowing = false;

        this.editor.updateCounters();
        this.editor.updateCurrentBlock();
    }

    resetFromHTML(html) {
        if (!this.enabled) {
            this.editor.element.innerHTML = html || "<p><br></p>";
            return;
        }

        this.mount(html || "<p><br></p>");
    }

    scheduleReflow() {
        if (!this.enabled || this.reflowing) return;

        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.reflow(), 160);
    }

    mount(html) {
        this.reflowing = true;
        this.editor.element.innerHTML = "";

        const page = this.createPage();
        page.querySelector(".organic-page-content").innerHTML = html;
        this.editor.element.append(page);

        this.reflowing = false;

        requestAnimationFrame(() => this.reflow());
    }

    createPage() {
        const page = document.createElement("section");
        page.className = "organic-document-page";

        const content = document.createElement("div");
        content.className = "organic-page-content";
        page.append(content);

        return page;
    }

    normalizeRootChildren() {
        const pageNodes = new Set(this.getPages());
        const stray = [...this.editor.element.childNodes].filter((node) => {
            return node.nodeType === Node.ELEMENT_NODE
                ? !pageNodes.has(node)
                : Boolean(node.textContent?.trim());
        });

        if (!stray.length) return;

        let pages = this.getPages();
        let target = pages.at(-1);

        if (!target) {
            target = this.createPage();
            this.editor.element.append(target);
            pages = [target];
        }

        const content = this.getContent(target);

        stray.forEach((node) => {
            content.append(node);
        });
    }

    getPages() {
        return [...this.editor.element.querySelectorAll(":scope > .organic-document-page")];
    }

    getContent(page) {
        return page.querySelector(":scope > .organic-page-content");
    }

    serialize() {
        const pages = this.getPages();

        if (!pages.length) return this.editor.element.innerHTML.trim();

        return pages
            .map((page) => this.getContent(page).innerHTML.trim())
            .filter(Boolean)
            .join("\n");
    }

    reflow() {
        if (!this.enabled || this.reflowing) return;

        this.reflowing = true;

        try {
            this.normalizeRootChildren();

            let pages = this.getPages();

            for (let pageIndex = 0; pageIndex < pages.length; pageIndex++) {
                if (pageIndex >= this.maxPagesPerReflow) break;

                const page = pages[pageIndex];
                const content = this.getContent(page);

                while (this.isOverflowing(content) && content.lastChild) {
                    let next = pages[pageIndex + 1];

                    if (!next) {
                        next = this.createPage();
                        page.after(next);
                        pages = this.getPages();
                    }

                    const node = content.lastChild;

                    if (this.isSingleOversizedMedia(node, content)) {
                        this.fitMedia(node, content);
                        break;
                    }

                    this.getContent(next).insertBefore(node, this.getContent(next).firstChild);
                }
            }

            this.removeEmptyTrailingPages();
            this.ensurePage();
            this.renumber();
        } finally {
            this.reflowing = false;
        }
    }

    isOverflowing(content) {
        return content && content.scrollHeight > content.clientHeight + 4;
    }

    isSingleOversizedMedia(node, content) {
        if (!node || !content || content.childNodes.length !== 1) return false;
        if (node.nodeType !== Node.ELEMENT_NODE) return false;

        const media = node.matches("img,table,video,iframe")
            ? node
            : node.querySelector("img,table,video,iframe");

        if (!media) return false;

        return node.getBoundingClientRect().height > content.clientHeight - 8;
    }

    fitMedia(node, content) {
        if (!node || node.nodeType !== Node.ELEMENT_NODE) return;

        const image = node.matches("img") ? node : node.querySelector("img");

        if (image) {
            image.style.maxWidth = "100%";
            image.style.maxHeight = `${Math.max(120, content.clientHeight - 16)}px`;
            image.style.width = "auto";
            image.style.height = "auto";
            return;
        }

        const table = node.matches("table") ? node : node.querySelector("table");
        if (table) {
            table.style.width = "100%";
            table.style.maxWidth = "100%";
            return;
        }

        const media = node.matches("video,iframe") ? node : node.querySelector("video,iframe");
        if (media) {
            media.style.maxWidth = "100%";
            media.style.maxHeight = `${Math.max(120, content.clientHeight - 16)}px`;
        }
    }

    removeEmptyTrailingPages() {
        const pages = this.getPages();

        for (let index = pages.length - 1; index > 0; index--) {
            const content = this.getContent(pages[index]);
            const text = content.textContent.replace(/\u200B/g, "").trim();
            const rich = content.querySelector("img,table,video,iframe,audio,hr");

            if (!text && !rich) {
                pages[index].remove();
            } else {
                break;
            }
        }
    }

    ensurePage() {
        if (this.getPages().length) return;

        const page = this.createPage();
        page.querySelector(".organic-page-content").innerHTML = "<p><br></p>";
        this.editor.element.append(page);
    }

    renumber() {
        this.getPages().forEach((page, index) => {
            page.dataset.page = String(index + 1);
        });
    }
}
