export class PluginManager {
    constructor(editor) {
        this.editor = editor;
        this.plugins = new Map();
        this.errors = [];
    }

    register(plugin) {
        if (!plugin?.name) {
            throw new Error("OrganicEditor plugin precisa possuir uma propriedade name.");
        }

        this.plugins.set(plugin.name, plugin);
    }

    initAll() {
        this.plugins.forEach((plugin, name) => {
            try {
                plugin.init?.(this.editor);
            } catch (error) {
                this.errors.push({ name, error });
                console.error(`[OrganicEditor] Falha ao iniciar plugin "${name}"`, error);
            }
        });
    }

    get(name) {
        return this.plugins.get(name);
    }
}
