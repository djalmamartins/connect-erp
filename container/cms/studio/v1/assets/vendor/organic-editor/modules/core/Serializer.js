export class Serializer {
    serialize(element) {
        return element?.innerHTML?.trim() || "";
    }
}
