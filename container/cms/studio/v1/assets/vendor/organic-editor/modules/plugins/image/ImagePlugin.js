export class ImagePlugin {
    constructor() {
        this.name = "image";
        this.items = [];
        this.activeImage = null;
        this.naturalRatio = 1;
        this.sourceMode = "computer";
        this.selectedFile = null;
        this.selectedLibraryItem = null;
    }

    init(editor) {
        this.editor = editor;

        const imageButton = document.querySelector("#imageButton");
        const uploadButton = document.querySelector("#uploadButton");
        const libraryButton = document.querySelector("#libraryButton");
        const sidebarUpload = document.querySelector("#sidebarUpload");
        const fileInput = document.querySelector("#imageFileInput");
        const computerInput = document.querySelector("#imageComputerInput");
        const imageDialog = document.querySelector("#imageDialog");
        const imageForm = document.querySelector("#imageForm");

        imageButton?.addEventListener("mousedown", (event) => {
            event.preventDefault();
            editor.selection.save();
            this.openDialog();
        });

        this.bindSourceTabs();

        computerInput?.addEventListener("change", () => {
            this.selectedFile = computerInput.files?.[0] || null;
            const name = document.querySelector("#imageComputerName");
            if (name) name.textContent = this.selectedFile?.name || "Nenhum arquivo selecionado";
        });

        imageForm?.addEventListener("submit", async (event) => {
            event.preventDefault();

            const alt = document.querySelector("#imageAlt")?.value.trim() || "";
            const width = document.querySelector("#imageWidth")?.value.trim() || "";
            const height = document.querySelector("#imageHeight")?.value.trim() || "";
            const align = document.querySelector("#imageAlign")?.value || "none";

            let url = "";

            if (this.sourceMode === "computer") {
                if (!this.selectedFile) {
                    editor.setStatus("Escolha uma imagem do computador");
                    return;
                }
                const uploaded = await this.editor.uploadAdapter.upload(this.selectedFile, { type: "image" });
                url = uploaded?.url || uploaded;
                if (!url) {
                    editor.setStatus("Falha ao enviar imagem");
                    return;
                }
                this.addToLibrary(url, uploaded?.name || this.selectedFile.name);
            }

            if (this.sourceMode === "url") {
                url = document.querySelector("#imageUrl")?.value.trim() || "";
                if (!url) {
                    editor.setStatus("Informe a URL da imagem");
                    return;
                }
                this.addToLibrary(url, alt || "Imagem");
            }

            if (this.sourceMode === "library") {
                url = this.selectedLibraryItem?.url || "";
                if (!url) {
                    editor.setStatus("Escolha uma imagem da biblioteca");
                    return;
                }
            }

            imageDialog?.close();

            requestAnimationFrame(() => {
                editor.selection.restore();
                this.insert(url, { alt: alt || this.selectedLibraryItem?.name || "", width, height, align });
            });
        });

        document.querySelectorAll("[data-image-preset]").forEach((button) => {
            button.addEventListener("click", () => {
                const percent = Number(button.dataset.imagePreset);
                const width = document.querySelector("#imageWidth");
                const height = document.querySelector("#imageHeight");

                if (width) width.value = "";
                if (height) height.value = "";

                const dialog = document.querySelector("#imageDialog");
                if (dialog) dialog.dataset.widthPercent = String(percent);
                editor.setStatus(`Imagem definida para ${percent}%`);
            });
        });

        [uploadButton, sidebarUpload].forEach((button) => {
            button?.addEventListener("click", () => fileInput?.click());
        });

        libraryButton?.addEventListener("mousedown", () => {
            editor.selection.save();
        });

        libraryButton?.addEventListener("click", () => {
            document.querySelector('[data-tab="media"]')?.click();
            document.querySelector("#sidebar")?.scrollIntoView({ behavior: "smooth", block: "nearest" });
        });

        fileInput?.addEventListener("change", async () => {
            await this.handleFiles([...fileInput.files]);
            fileInput.value = "";
        });

        editor.on("image:drop", async (files) => {
            await this.handleFiles(files);
        });

        editor.element.addEventListener("click", (event) => {
            const image = event.target.closest("img");

            if (!image || !editor.element.contains(image)) {
                this.hideResizePanel();
                return;
            }

            this.selectImage(image);
        });

        this.bindResizePanel();
    }

    openDialog() {
        const form = document.querySelector("#imageForm");
        form?.reset();

        this.sourceMode = "computer";
        this.selectedFile = null;
        this.selectedLibraryItem = null;

        const name = document.querySelector("#imageComputerName");
        if (name) name.textContent = "Nenhum arquivo selecionado";

        document.querySelectorAll("[data-image-source]").forEach((button) => {
            const active = button.dataset.imageSource === "computer";
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", active ? "true" : "false");
        });

        document.querySelectorAll("[data-image-panel]").forEach((panel) => {
            panel.classList.toggle("is-hidden", panel.dataset.imagePanel !== "computer");
        });

        this.renderDialogLibrary();
        document.querySelector("#imageDialog")?.showModal();
    }

    bindSourceTabs() {
        document.querySelectorAll("[data-image-source]").forEach((button) => {
            button.addEventListener("click", () => {
                this.sourceMode = button.dataset.imageSource;

                document.querySelectorAll("[data-image-source]").forEach((item) => {
                    const active = item === button;
                    item.classList.toggle("is-active", active);
                    item.setAttribute("aria-selected", active ? "true" : "false");
                });

                document.querySelectorAll("[data-image-panel]").forEach((panel) => {
                    panel.classList.toggle("is-hidden", panel.dataset.imagePanel !== this.sourceMode);
                });

                if (this.sourceMode === "library") {
                    this.renderDialogLibrary();
                }
            });
        });
    }

    renderDialogLibrary() {
        const target = document.querySelector("#imageDialogLibrary");
        if (!target) return;

        target.innerHTML = "";

        if (!this.items.length) {
            target.innerHTML = '<div class="organic-empty-state">Nenhuma imagem na biblioteca.</div>';
            return;
        }

        this.items.forEach((item) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "organic-media-item";
            button.dataset.libraryImage = item.id;

            const img = document.createElement("img");
            img.src = item.url;
            img.alt = item.name;

            const span = document.createElement("span");
            span.textContent = item.name;

            button.append(img, span);

            button.addEventListener("click", () => {
                this.selectedLibraryItem = item;

                target.querySelectorAll(".organic-media-item").forEach((node) => {
                    node.classList.toggle("is-selected", node === button);
                });
            });

            target.append(button);
        });
    }

    bindResizePanel() {
        const widthInput = document.querySelector("#resizeImageWidth");
        const heightInput = document.querySelector("#resizeImageHeight");
        const keepRatio = document.querySelector("#resizeKeepRatio");

        widthInput?.addEventListener("input", () => {
            if (!keepRatio?.checked || !this.activeImage || !this.naturalRatio) return;
            const width = Number(widthInput.value);
            if (width && heightInput) heightInput.value = Math.round(width / this.naturalRatio);
        });

        heightInput?.addEventListener("input", () => {
            if (!keepRatio?.checked || !this.activeImage || !this.naturalRatio) return;
            const height = Number(heightInput.value);
            if (height && widthInput) widthInput.value = Math.round(height * this.naturalRatio);
        });

        document.querySelector("#applyImageResize")?.addEventListener("click", () => this.applyResize());

        document.querySelector("#resetImageSize")?.addEventListener("click", () => {
            if (!this.activeImage) return;

            this.activeImage.removeAttribute("width");
            this.activeImage.removeAttribute("height");
            this.activeImage.style.width = "";
            this.activeImage.style.height = "";

            this.editor.history.capture(true);
            this.editor.events.emit("change", this.editor.getContent());
            this.selectImage(this.activeImage);
            this.editor.setStatus("Tamanho da imagem restaurado");
        });

        document.querySelector("#closeImageResize")?.addEventListener("click", () => this.hideResizePanel());
    }

    selectImage(image) {
        this.activeImage = image;

        const naturalWidth = image.naturalWidth || image.width || 1;
        const naturalHeight = image.naturalHeight || image.height || 1;
        this.naturalRatio = naturalWidth / naturalHeight;

        document.querySelectorAll(".organic-content-image.is-selected").forEach((item) => {
            item.classList.remove("is-selected");
        });

        image.classList.add("is-selected");

        const widthInput = document.querySelector("#resizeImageWidth");
        const heightInput = document.querySelector("#resizeImageHeight");

        if (widthInput) widthInput.value = Math.round(image.getBoundingClientRect().width);
        if (heightInput) heightInput.value = Math.round(image.getBoundingClientRect().height);

        const panel = document.querySelector("#imageResizePanel");
        if (panel) panel.hidden = false;
    }

    hideResizePanel() {
        this.activeImage?.classList.remove("is-selected");
        this.activeImage = null;

        const panel = document.querySelector("#imageResizePanel");
        if (panel) panel.hidden = true;
    }

    applyResize() {
        if (!this.activeImage) return;

        const width = Number(document.querySelector("#resizeImageWidth")?.value || 0);
        const height = Number(document.querySelector("#resizeImageHeight")?.value || 0);

        if (width > 0) {
            this.activeImage.style.width = `${width}px`;
            this.activeImage.setAttribute("width", String(Math.round(width)));
        }

        if (height > 0) {
            this.activeImage.style.height = `${height}px`;
            this.activeImage.setAttribute("height", String(Math.round(height)));
        }

        this.editor.history.capture(true);
        this.editor.updateCounters();
        this.editor.events.emit("change", this.editor.getContent());
        this.editor.setStatus("Imagem redimensionada");
    }

    async handleFiles(files) {
        for (const file of files) {
            const uploaded = await this.editor.uploadAdapter.upload(file, { type: "image" });
            const url = uploaded?.url || uploaded;

            if (!url) continue;

            this.addToLibrary(url, uploaded?.name || file.name);

            this.editor.insertContent(
                `<p class="organic-image-block"><img src="${this.escape(url)}" alt="${this.escape(uploaded?.name || file.name)}" class="organic-content-image"></p>`
            );
        }
    }

    insert(url, options = {}) {
        const alt = this.escape(options.alt || "");
        const width = options.width ? ` width="${Number(options.width)}"` : "";
        const height = options.height ? ` height="${Number(options.height)}"` : "";

        let className = "organic-content-image";
        if (options.align === "left") className += " align-left";
        if (options.align === "right") className += " align-right";
        if (options.align === "center") className += " align-center";

        this.editor.insertContent(
            `<p class="organic-image-block"><img src="${this.escape(url)}" alt="${alt}"${width}${height} class="${className}"></p>`
        );

        const dialog = document.querySelector("#imageDialog");
        const percent = Number(dialog?.dataset.widthPercent || 0);
        if (percent) {
            const images = this.editor.element.querySelectorAll("img.organic-content-image");
            const last = images[images.length - 1];
            if (last) last.style.width = `${percent}%`;
            if (dialog) delete dialog.dataset.widthPercent;
        }
    }

    addToLibrary(url, name = "Imagem") {
        const item = {
            id: crypto.randomUUID?.() || String(Date.now() + Math.random()),
            url,
            name
        };

        this.items.unshift(item);
        this.render();
        this.renderDialogLibrary();
    }

    render() {
        const grid = document.querySelector("#mediaGrid");
        if (!grid) return;

        if (!this.items.length) {
            grid.innerHTML = '<div class="organic-empty-state">Nenhuma imagem adicionada.</div>';
            return;
        }

        grid.innerHTML = "";

        this.items.forEach((item) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "organic-media-item";
            button.title = item.name;

            const img = document.createElement("img");
            img.src = item.url;
            img.alt = item.name;

            const span = document.createElement("span");
            span.textContent = item.name;

            button.append(img, span);

            button.addEventListener("click", () => {
                this.editor.insertContent(
                    `<p class="organic-image-block"><img src="${this.escape(item.url)}" alt="${this.escape(item.name)}" class="organic-content-image"></p>`
                );
            });

            grid.append(button);
        });
    }

    fileToDataURL(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    escape(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }
}
