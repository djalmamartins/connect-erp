export class MediaPlugin {
    constructor() { this.name = "media"; }

    init(editor) {
        this.editor = editor;
        this.bindDialog("videoButton", "videoDialog", "videoForm", () => this.insertVideo());
        this.bindDialog("audioButton", "audioDialog", "audioForm", () => this.insertAudio());
        this.bindDialog("embedButton", "embedDialog", "embedForm", () => this.insertEmbed());
        this.bindDialog("fileButton", "fileDialog", "fileForm", () => this.insertFileLink());
    }

    bindDialog(buttonId, dialogId, formId, handler) {
        document.getElementById(buttonId)?.addEventListener("mousedown", (event) => {
            event.preventDefault();
            this.editor.selection.save();
            document.getElementById(formId)?.reset();
            document.getElementById(dialogId)?.showModal();
        });
        document.getElementById(formId)?.addEventListener("submit", () => {
            this.editor.selection.restore();
            handler();
        });
    }

    insertVideo() {
        const url = document.getElementById("videoUrl").value.trim();
        const title = document.getElementById("videoTitle").value.trim();
        if (!url) return;
        const embed = this.toVideoEmbed(url, title);
        this.editor.insertContent(embed);
    }

    toVideoEmbed(url, title = "Vídeo") {
        const youtube = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
        if (youtube) {
            return `<div class="organic-embed organic-video"><iframe src="https://www.youtube-nocookie.com/embed/${this.escape(youtube[1])}" title="${this.escape(title)}" loading="lazy" allowfullscreen></iframe></div><p><br></p>`;
        }
        const vimeo = url.match(/vimeo\.com\/(\d+)/);
        if (vimeo) {
            return `<div class="organic-embed organic-video"><iframe src="https://player.vimeo.com/video/${this.escape(vimeo[1])}" title="${this.escape(title)}" loading="lazy" allowfullscreen></iframe></div><p><br></p>`;
        }
        return `<video class="organic-media" controls preload="metadata" src="${this.escape(url)}"></video><p><br></p>`;
    }

    insertAudio() {
        const url = document.getElementById("audioUrl").value.trim();
        if (!url) return;
        this.editor.insertContent(`<audio class="organic-audio" controls preload="metadata" src="${this.escape(url)}"></audio><p><br></p>`);
    }

    insertEmbed() {
        const url = document.getElementById("embedUrl").value.trim();
        const title = document.getElementById("embedTitle").value.trim() || "Conteúdo incorporado";
        if (!/^https?:\/\//i.test(url)) return;
        this.editor.insertContent(`<div class="organic-embed"><iframe src="${this.escape(url)}" title="${this.escape(title)}" loading="lazy"></iframe></div><p><br></p>`);
    }

    insertFileLink() {
        const url = document.getElementById("fileUrl").value.trim();
        const name = document.getElementById("fileName").value.trim() || "Baixar arquivo";
        if (!url) return;
        this.editor.insertContent(`<p><a class="organic-file-link" href="${this.escape(url)}" target="_blank" rel="noopener noreferrer">📎 ${this.escape(name)}</a></p>`);
    }

    escape(value) {
        return String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
    }
}
