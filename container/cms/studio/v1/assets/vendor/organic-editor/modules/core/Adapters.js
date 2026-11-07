export class UploadAdapter {
    constructor(options = {}) {
        this.options = options;
    }

    async upload(file, context = {}) {
        if (typeof this.options.upload === "function") {
            return await this.options.upload(file, context);
        }

        return await this.toDataURL(file);
    }

    async pick(context = {}) {
        if (typeof this.options.filePicker === "function") {
            return await this.options.filePicker(context);
        }

        return null;
    }

    async toDataURL(file) {
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });

        return {
            url: dataUrl,
            name: file.name,
            type: file.type,
            size: file.size
        };
    }
}

export class StorageAdapter {
    constructor(options = {}) {
        this.options = options;
    }

    get(key, fallback = null) {
        if (typeof this.options.get === "function") {
            return this.options.get(key, fallback);
        }

        try {
            const value = localStorage.getItem(key);
            return value === null ? fallback : JSON.parse(value);
        } catch {
            return fallback;
        }
    }

    set(key, value) {
        if (typeof this.options.set === "function") {
            return this.options.set(key, value);
        }

        localStorage.setItem(key, JSON.stringify(value));
    }

    remove(key) {
        if (typeof this.options.remove === "function") {
            return this.options.remove(key);
        }

        localStorage.removeItem(key);
    }
}
