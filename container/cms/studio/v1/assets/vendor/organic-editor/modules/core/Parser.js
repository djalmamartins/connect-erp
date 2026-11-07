export class Parser {
    parse(html) {
        return new DOMParser().parseFromString(String(html || ""), "text/html").body;
    }
}
