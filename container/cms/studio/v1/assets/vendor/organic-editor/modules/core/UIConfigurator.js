export class UIConfigurator {
    constructor(editor, options = {}) {
        this.editor = editor;
        this.options = options;
    }

    apply() {
        this.applyToolbar();
        this.applyMenubar();
    }

    applyToolbar() {
        const configured = this.normalizeTokens(this.options.toolbar);

        if (!configured.length) return;

        const allowed = new Set(configured);

        document.querySelectorAll("[data-toolbar-token]").forEach((node) => {
            const token = node.dataset.toolbarToken;
            node.hidden = !allowed.has(token);
        });

        document.querySelectorAll("[data-toolbar-group]").forEach((group) => {
            const visibleChildren = [...group.querySelectorAll("[data-toolbar-token]")]
                .some((child) => !child.hidden);

            group.hidden = !visibleChildren;
        });
    }

    applyMenubar() {
        const configured = this.normalizeTokens(this.options.menubar);

        if (!configured.length) return;

        const allowed = new Set(configured);

        document.querySelectorAll("[data-menu-trigger]").forEach((trigger) => {
            const name = trigger.dataset.menuTrigger;
            const wrapper = trigger.closest(".organic-menu");

            if (wrapper) {
                wrapper.hidden = !allowed.has(name);
            }
        });
    }

    normalizeTokens(value) {
        if (Array.isArray(value)) {
            return value.flatMap((item) => String(item).split(/\s+/)).filter(Boolean);
        }

        if (typeof value === "string") {
            return value.split(/\s+/).filter(Boolean);
        }

        return [];
    }
}
