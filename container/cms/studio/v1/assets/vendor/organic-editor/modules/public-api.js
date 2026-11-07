import { EditorManager } from "./core/EditorManager.js";
import { presets, resolvePreset } from "./core/Presets.js";

const manager = new EditorManager();

export const OrganicEditor = {
    init(options = {}) {
        const preset = options.preset ? resolvePreset(options.preset) : {};
        const merged = { ...preset, ...options };

        const instances = manager.init(merged);
        return instances.length === 1 ? instances[0] : instances;
    },

    get(id) {
        return manager.get(id);
    },

    getAll() {
        return manager.getAll();
    },

    remove(id) {
        return manager.destroy(id);
    },

    registerPlugin(name, PluginClass) {
        manager.registerPlugin(name, PluginClass);
    },

    presets,

    version: "1.0.0"
};

if (typeof window !== "undefined") {
    window.OrganicEditor = OrganicEditor;
}

export default OrganicEditor;
