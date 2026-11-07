import { Schema } from "./Schema.js";

export class Sanitizer {
    constructor(schema = new Schema()) {
        this.schema = schema;
    }

    sanitize(html) {
        const doc = new DOMParser().parseFromString(String(html || ""), "text/html");

        doc.querySelectorAll("script, style, meta, link, object, embed").forEach(node => node.remove());

        [...doc.body.querySelectorAll("*")].forEach((node) => {
            if (!this.schema.isTagAllowed(node.tagName)) {
                node.replaceWith(...node.childNodes);
                return;
            }

            [...node.attributes].forEach((attribute) => {
                const name = attribute.name.toLowerCase();

                if (name.startsWith("on")) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if (!this.schema.isAttributeAllowed(node.tagName, name)) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if (name === "style") {
                    const cleaned = this.schema.cleanStyle(attribute.value);
                    if (cleaned) node.setAttribute("style", cleaned);
                    else node.removeAttribute("style");
                }

                if (["href","src"].includes(name)) {
                    const value = attribute.value.trim();
                    if (/^javascript:/i.test(value)) node.removeAttribute(attribute.name);
                }
            });
        });

        return doc.body.innerHTML.trim();
    }
}
