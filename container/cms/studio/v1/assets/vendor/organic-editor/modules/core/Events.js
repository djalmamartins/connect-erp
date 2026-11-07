export class Events {
    constructor() {
        this.listeners = new Map();
    }

    on(eventName, callback) {
        if (!this.listeners.has(eventName)) {
            this.listeners.set(eventName, new Set());
        }
        this.listeners.get(eventName).add(callback);
    }

    off(eventName, callback) {
        this.listeners.get(eventName)?.delete(callback);
    }

    emit(eventName, payload) {
        this.listeners.get(eventName)?.forEach(callback => callback(payload));
    }
}
