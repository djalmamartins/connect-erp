export const presets = {
    full: {
        mode: "document",
        plugins: "link image table media special workspace persistence export clipboard searchreplace visual pages",
        toolbar: "undo redo blocks fontfamily fontsize bold italic underline strikethrough alignleft aligncenter alignright alignjustify bullist numlist outdent indent link image table upload library video audio embed file emoji symbol anchor codeblock searchreplace pasteplain visualblocks visualchars pdf word html print code",
        menubar: "file edit insert format view"
    },

    minimal: {
        mode: "document",
        plugins: "link image pages",
        toolbar: "bold italic underline link image",
        menubar: "insert format"
    },

    email: {
        mode: "email",
        plugins: "link image workspace persistence clipboard searchreplace",
        toolbar: "bold italic underline link image searchreplace",
        menubar: "edit insert format"
    },

    readonly: {
        readonly: true,
        plugins: "",
        toolbar: "",
        menubar: ""
    }
};

export function resolvePreset(name) {
    return presets[name] ? structuredClone(presets[name]) : {};
}
