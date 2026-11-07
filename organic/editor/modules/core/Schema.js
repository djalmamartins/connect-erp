export class Schema {
    constructor() {
        this.allowedTags = new Set([
            "P","BR","H1","H2","H3","H4","H5","H6",
            "STRONG","B","EM","I","U","S","SUB","SUP",
            "BLOCKQUOTE","PRE","CODE","A","SPAN",
            "UL","OL","LI",
            "TABLE","THEAD","TBODY","TFOOT","TR","TH","TD",
            "IMG","VIDEO","AUDIO","SOURCE","IFRAME",
            "HR","DIV","SECTION"
        ]);

        this.globalAttributes = new Set(["class","style","title","id"]);
        this.attributes = {
            A: new Set(["href","target","rel","name"]),
            IMG: new Set(["src","alt","width","height","loading"]),
            VIDEO: new Set(["src","controls","width","height","poster"]),
            AUDIO: new Set(["src","controls"]),
            SOURCE: new Set(["src","type"]),
            IFRAME: new Set(["src","width","height","allow","allowfullscreen","frameborder"]),
            TD: new Set(["colspan","rowspan"]),
            TH: new Set(["colspan","rowspan","scope"])
        };

        this.allowedStyles = new Set([
            "color","background-color","font-weight","font-style",
            "text-decoration","text-align","font-size","font-family",
            "width","height","margin-left","margin-right","float"
        ]);
    }

    isTagAllowed(tagName) {
        return this.allowedTags.has(String(tagName).toUpperCase());
    }

    isAttributeAllowed(tagName, attributeName) {
        const tag = String(tagName).toUpperCase();
        const attr = String(attributeName).toLowerCase();

        if (this.globalAttributes.has(attr)) return true;
        return this.attributes[tag]?.has(attr) || false;
    }

    cleanStyle(styleText) {
        return String(styleText)
            .split(";")
            .map(rule => rule.trim())
            .filter(Boolean)
            .filter(rule => this.allowedStyles.has(rule.split(":")[0]?.trim().toLowerCase()))
            .join("; ");
    }
}
