export class WorkspacePlugin {
    constructor() {
        this.name = "workspace";

        this.blocks = [
            { id: "highlight", name: "Destaque", description: "Título e texto em evidência.", html: '<section class="organic-block organic-block-highlight"><h2>Destaque</h2><p>Adicione aqui sua mensagem principal.</p></section>' },
            { id: "notice", name: "Aviso", description: "Mensagem informativa.", html: '<blockquote><strong>Aviso:</strong> escreva aqui uma informação importante.</blockquote>' },
            { id: "columns", name: "Duas colunas", description: "Estrutura simples com duas colunas.", html: '<table class="organic-table"><tbody><tr><td><h3>Coluna 1</h3><p>Conteúdo</p></td><td><h3>Coluna 2</h3><p>Conteúdo</p></td></tr></tbody></table>' }
        ];

        this.templates = [
            { id: "document-basic", mode: "document", name: "Documento básico", description: "Título, introdução e seções.", html: '<h1>Título do documento</h1><p>Introdução do documento.</p><h2>Primeira seção</h2><p>Escreva o conteúdo aqui.</p><h2>Segunda seção</h2><p>Continue o conteúdo.</p>' },
            { id: "announcement", mode: "document", name: "Comunicado", description: "Modelo para comunicados.", html: '<h1>Comunicado</h1><p>Olá {{user.name}},</p><p>Temos uma informação importante para você.</p><p>Atenciosamente,<br>{{company.name}}</p>' },
            { id: "email-simple", mode: "email", name: "E-mail simples", description: "Estrutura curta para e-mails.", html: '<h2>Olá {{user.name}}</h2><p>Escreva sua mensagem aqui.</p><p>Atenciosamente,<br>{{signature.default}}</p>' }
        ];

        this.variables = [
            ["Usuário", "{{user.name}}"],
            ["E-mail", "{{user.email}}"],
            ["Empresa", "{{company.name}}"],
            ["Condomínio", "{{condominium.name}}"],
            ["Vencimento", "{{invoice.due_date}}"],
            ["Valor", "{{invoice.total}}"],
            ["Data", "{{date}}"],
            ["Assinatura", "{{signature.default}}"]
        ];

        this.components = [
            { id: "button", name: "Botão", description: "Botão de ação Organic.", html: '<p><a href="#" class="organic-content-button">Acessar</a></p>' },
            { id: "card", name: "Card", description: "Card simples de conteúdo.", html: '<section class="organic-content-card"><h3>Título do card</h3><p>Conteúdo do card.</p></section>' },
            { id: "signature", name: "Assinatura", description: "Assinatura reutilizável.", html: '<div class="organic-signature-preview"><strong>{{user.name}}</strong><br>{{company.name}}<br>{{user.email}}</div>' }
        ];
    }

    init(editor) {
        this.editor = editor;
        this.renderAll();
        this.bindSearch();
    }

    renderAll() {
        this.renderCollection("#blockList", this.blocks, (item) => {
            this.editor.insertContent(item.html);
            this.editor.setStatus(`Bloco "${item.name}" inserido`);
        });

        this.renderCollection("#templateList", this.templates, (item) => {
            const confirmed = window.confirm(`Aplicar o template "${item.name}" e substituir o conteúdo atual?`);
            if (!confirmed) return;

            if (item.mode) {
                this.editor.setEditorMode?.(item.mode);
            }

            this.editor.setContent(item.html);
            this.editor.focus();
            this.editor.setStatus(`Template "${item.name}" aplicado`);
        });

        this.renderVariables();

        this.renderCollection("#componentList", this.components, (item) => {
            this.editor.insertContent(item.html);
            this.editor.setStatus(`Componente "${item.name}" inserido`);
        });
    }

    renderCollection(selector, items, action) {
        const target = document.querySelector(selector);
        if (!target) return;

        target.innerHTML = "";

        items.forEach((item) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "organic-library-item";
            button.dataset.search = `${item.name} ${item.description}`.toLowerCase();
            button.dataset.itemId = item.id || item.name.toLowerCase().replace(/\s+/g, "-");

            const title = document.createElement("strong");
            title.textContent = item.name;

            const description = document.createElement("span");
            description.textContent = item.description;

            button.append(title, description);
            button.addEventListener("mousedown", () => {
                this.editor.selection.save();
            });

            button.addEventListener("click", () => action(item));

            target.append(button);
        });
    }

    renderVariables() {
        const target = document.querySelector("#variableList");
        if (!target) return;

        target.innerHTML = "";

        this.variables.forEach(([name, variable]) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "organic-library-item organic-variable";
            button.dataset.search = `${name} ${variable}`.toLowerCase();

            const label = document.createElement("strong");
            label.textContent = name;

            const code = document.createElement("code");
            code.textContent = variable;

            button.append(label, code);
            button.addEventListener("mousedown", () => {
                this.editor.selection.save();
            });

            button.addEventListener("click", () => {
                this.editor.insertContent(variable);
                this.editor.setStatus(`Variável ${variable} inserida`);
            });

            target.append(button);
        });
    }

    bindSearch() {
        [
            ["#blockSearch", "#blockList"],
            ["#templateSearch", "#templateList"],
            ["#variableSearch", "#variableList"],
            ["#componentSearch", "#componentList"]
        ].forEach(([inputSelector, listSelector]) => {
            const input = document.querySelector(inputSelector);
            const list = document.querySelector(listSelector);

            if (!input || !list) return;

            input.addEventListener("input", () => {
                const query = input.value.trim().toLowerCase();

                list.querySelectorAll(".organic-library-item").forEach((item) => {
                    item.hidden = Boolean(query) && !item.dataset.search.includes(query);
                });
            });
        });
    }
}
