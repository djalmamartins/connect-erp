export class PersistencePlugin {
    constructor(options = {}) {
        this.name = "persistence";
        this.options = {
            storageKey: "organic-editor-v0100",
            autosaveDelay: 2200,
            maxDrafts: 30,
            maxRevisions: 30,
            ...options
        };

        this.timer = null;
        this.drafts = [];
        this.revisions = [];
    }

    init(editor) {
        this.editor = editor;

        this.loadCollections();
        this.bindUI();
        this.restoreLastAutosave();

        editor.on("change", () => {
            this.scheduleAutosave();
        });

        this.updateRevisionBadge();
    }

    bindUI() {
        document.querySelector("#draftButton")?.addEventListener("click", () => {
            this.createDraft("Rascunho manual");
        });

        document.querySelector("#revisionButton")?.addEventListener("click", () => {
            this.openManager();
        });

        document.querySelector("#revisionList")?.addEventListener("click", (event) => {
            const restore = event.target.closest("[data-restore-item]");
            if (restore) {
                this.restoreItem(
                    restore.dataset.collection,
                    restore.dataset.restoreItem
                );
                return;
            }

            const remove = event.target.closest("[data-delete-item]");
            if (remove) {
                this.deleteItem(
                    remove.dataset.collection,
                    remove.dataset.deleteItem
                );
            }
        });
    }

    scheduleAutosave() {
        clearTimeout(this.timer);

        this.timer = setTimeout(() => {
            this.saveAutosave();
        }, this.options.autosaveDelay);
    }

    saveAutosave() {
        const payload = {
            html: this.editor.getContent(),
            updatedAt: new Date().toISOString()
        };

        this.editor.storageAdapter.set(this.autosaveKey(), payload);
        this.editor.setStatus("Salvo automaticamente");
        this.updateDraftTime(payload.updatedAt);
    }

    createDraft(label = "Rascunho") {
        const item = this.createItem(label);
        this.drafts.unshift(item);
        this.drafts = this.drafts.slice(0, this.options.maxDrafts);

        this.persistCollections();
        this.updateRevisionBadge();
        this.editor.setStatus("Rascunho criado");
    }

    createRevision(label = "Revisão") {
        const item = this.createItem(label);
        this.revisions.unshift(item);
        this.revisions = this.revisions.slice(0, this.options.maxRevisions);

        this.persistCollections();
        this.updateRevisionBadge();
        this.editor.setStatus("Revisão criada");
    }

    createItem(label) {
        return {
            id: crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`,
            label,
            html: this.editor.getContent(),
            createdAt: new Date().toISOString()
        };
    }

    openManager() {
        this.renderManager();
        document.querySelector("#revisionDialog")?.showModal();
    }

    renderManager() {
        const target = document.querySelector("#revisionList");
        if (!target) return;

        target.innerHTML = "";

        if (!this.drafts.length && !this.revisions.length) {
            target.innerHTML = '<div class="organic-empty-state">Nenhum rascunho ou revisão.</div>';
            return;
        }

        this.renderSection(target, "Rascunhos", "drafts", this.drafts);
        this.renderSection(target, "Revisões", "revisions", this.revisions);
    }

    renderSection(target, title, collectionName, items) {
        if (!items.length) return;

        const section = document.createElement("section");
        section.className = "organic-revision-section";

        const heading = document.createElement("div");
        heading.className = "organic-revision-section-title";
        heading.textContent = title;
        section.append(heading);

        items.forEach((item) => {
            const row = document.createElement("div");
            row.className = "organic-revision-item";

            const restore = document.createElement("button");
            restore.type = "button";
            restore.className = "organic-revision-restore";
            restore.dataset.restoreItem = item.id;
            restore.dataset.collection = collectionName;
            restore.innerHTML = `
                <strong>${this.escape(item.label)}</strong>
                <span>${this.formatDate(item.createdAt)}</span>
            `;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "organic-revision-delete";
            remove.dataset.deleteItem = item.id;
            remove.dataset.collection = collectionName;
            remove.textContent = "×";
            remove.title = `Apagar ${item.label}`;
            remove.setAttribute("aria-label", `Apagar ${item.label}`);

            row.append(restore, remove);
            section.append(row);
        });

        target.append(section);
    }

    restoreItem(collectionName, id) {
        const item = this.getCollection(collectionName).find((entry) => entry.id === id);
        if (!item) return;

        this.editor.setContent(item.html);
        this.editor.setStatus(`${item.label} restaurado`);
        document.querySelector("#revisionDialog")?.close();
    }

    deleteItem(collectionName, id) {
        const collection = this.getCollection(collectionName);
        const item = collection.find((entry) => entry.id === id);
        if (!item) return;

        if (!window.confirm(`Apagar "${item.label}"?`)) return;

        const filtered = collection.filter((entry) => entry.id !== id);

        if (collectionName === "drafts") {
            this.drafts = filtered;
        } else {
            this.revisions = filtered;
        }

        this.persistCollections();
        this.updateRevisionBadge();
        this.renderManager();
        this.editor.setStatus(`${item.label} apagado`);
    }

    getCollection(name) {
        return name === "drafts" ? this.drafts : this.revisions;
    }

    restoreLastAutosave() {
        const autosave = this.editor.storageAdapter.get(this.autosaveKey(), null);
        if (!autosave?.html) return;

        this.updateDraftTime(autosave.updatedAt);
    }

    loadCollections() {
        this.drafts = this.readArray(this.draftsKey());
        this.revisions = this.readArray(this.revisionsKey());
    }

    persistCollections() {
        this.editor.storageAdapter.set(this.draftsKey(), this.drafts);
        this.editor.storageAdapter.set(this.revisionsKey(), this.revisions);
    }

    readArray(key) {
        try {
            const value = this.editor.storageAdapter.get(key, []);
            return Array.isArray(value) ? value : [];
        } catch {
            return [];
        }
    }

    updateRevisionBadge() {
        const badge = document.querySelector("#revisionCount");
        if (badge) {
            badge.textContent = String(this.drafts.length + this.revisions.length);
        }
    }

    updateDraftTime(value) {
        const target = document.querySelector("#draftTime");
        if (!target || !value) return;
        target.textContent = this.formatDate(value);
    }

    autosaveKey() {
        return `${this.options.storageKey}:autosave`;
    }

    draftsKey() {
        return `${this.options.storageKey}:drafts`;
    }

    revisionsKey() {
        return `${this.options.storageKey}:revisions`;
    }

    formatDate(value) {
        try {
            return new Intl.DateTimeFormat("pt-BR", {
                dateStyle: "short",
                timeStyle: "short"
            }).format(new Date(value));
        } catch {
            return value;
        }
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
