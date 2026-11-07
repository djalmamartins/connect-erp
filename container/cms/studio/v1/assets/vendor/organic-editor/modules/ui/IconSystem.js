export class IconSystem {
    constructor(options = {}) {
        this.sprite = options.sprite || "./src/icons/organic-icons.svg";
        this.ready = null;
    }

    mount(root = document) {
        return this.init(root);
    }

    init(root = document) {
        this.ready = this.loadSprite().then(() => {
            root.querySelectorAll("[data-icon]").forEach((element) => this.render(element));
        }).catch((error) => {
            console.warn("[OrganicEditor] SVG sprite fallback enabled", error);
            root.querySelectorAll("[data-icon]").forEach((element) => this.renderFallback(element));
        });
        return this.ready;
    }

    async loadSprite() {
        if (document.querySelector("#organic-inline-icon-sprite")) return;
        const response = await fetch(this.sprite, { cache: "no-store" });
        if (!response.ok) throw new Error(`Falha ao carregar ícones: ${response.status}`);
        const text = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(text, "image/svg+xml");
        if (doc.querySelector("parsererror")) throw new Error("Sprite SVG inválido");
        const source = doc.documentElement;
        const inline = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        inline.id = "organic-inline-icon-sprite";
        inline.setAttribute("aria-hidden", "true");
        inline.setAttribute("width", "0");
        inline.setAttribute("height", "0");
        inline.style.position = "absolute";
        inline.style.overflow = "hidden";
        [...source.children].forEach((child) => inline.append(document.importNode(child, true)));
        document.body.prepend(inline);
    }

    render(element) {
        const name = element.dataset.icon;
        if (!name) return;
        element.querySelector(".organic-icon")?.remove();
        element.classList.remove("organic-icon-fallback");
        delete element.dataset.iconFallback;
        const symbol = document.querySelector(`#organic-inline-icon-sprite #${CSS.escape(name)}`);
        if (!symbol) { this.renderFallback(element); return; }
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "organic-icon");
        svg.setAttribute("viewBox", symbol.getAttribute("viewBox") || "0 0 24 24");
        svg.setAttribute("aria-hidden", "true");
        svg.setAttribute("focusable", "false");
        const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
        use.setAttribute("href", `#${name}`);
        svg.append(use);
        element.prepend(svg);
    }

    renderFallback(element) {
        element.querySelector(".organic-icon")?.remove();
        element.classList.add("organic-icon-fallback");
        element.dataset.iconFallback = this.fallback(element.dataset.icon);
    }

    fallback(name) {
        const map = { undo:"↶", redo:"↷", bold:"B", italic:"I", underline:"U", strike:"S", subscript:"x₂", superscript:"x²", search:"⌕", clipboard:"⧉", blocks:"▦", pilcrow:"¶", download:"↓", print:"P", x:"×", image:"▧", link:"↗", table:"▤", code:"</>", fullscreen:"⛶", save:"✓", eye:"◉", upload:"↑", library:"▦", video:"▶", audio:"♪", file:"▯", anchor:"⚓", smile:"☺", omega:"Ω", minus:"−", plus:"+", moon:"◐", embed:"<>" };
        return map[name] || "•";
    }
}
