export class Tooltip {
    constructor(root = document) {
        this.root = root;
        this.node = document.createElement("div");
        this.node.className = "organic-tooltip";
        this.node.setAttribute("role", "tooltip");
        document.body.appendChild(this.node);
        this.bind();
    }

    bind() {
        this.root.addEventListener("pointerover", (event) => {
            const target = event.target.closest("[data-tooltip]");
            if (!target) return;
            this.show(target, target.dataset.tooltip);
        });
        this.root.addEventListener("pointerout", (event) => {
            if (event.target.closest("[data-tooltip]")) this.hide();
        });
        this.root.addEventListener("focusin", (event) => {
            const target = event.target.closest("[data-tooltip]");
            if (target) this.show(target, target.dataset.tooltip);
        });
        this.root.addEventListener("focusout", () => this.hide());
    }

    show(target, text) {
        this.node.textContent = text;
        this.node.classList.add("is-visible");
        requestAnimationFrame(() => {
            const rect = target.getBoundingClientRect();
            const tip = this.node.getBoundingClientRect();
            this.node.style.left = `${Math.max(8, rect.left + rect.width / 2 - tip.width / 2)}px`;
            this.node.style.top = `${Math.max(8, rect.bottom + 8)}px`;
        });
    }

    hide() {
        this.node.classList.remove("is-visible");
    }
}
