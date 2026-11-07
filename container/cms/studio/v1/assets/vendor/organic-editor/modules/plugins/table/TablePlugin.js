export class TablePlugin {
    constructor() {
        this.name = "table";
        this.editor = null;
        this.contextMenu = null;
    }

    init(editor) {
        this.editor = editor;
        this.bindDialog();
        this.createContextMenu();
        this.bindEditorContext();
    }

    bindDialog() {
        const button = document.querySelector("#tableButton");
        const dialog = document.querySelector("#tableDialog");
        const form = document.querySelector("#tableForm");

        button?.addEventListener("mousedown", (event) => {
            event.preventDefault();
            this.editor.selection.save();
            form?.reset();
            dialog?.showModal();
        });

        button?.addEventListener("click", () => {
            this.editor.selection.save();
        });

        form?.addEventListener("submit", () => {
            const rows = Math.max(1, Math.min(50, Number(document.querySelector("#tableRows")?.value || 3)));
            const cols = Math.max(1, Math.min(20, Number(document.querySelector("#tableCols")?.value || 3)));
            const header = Boolean(document.querySelector("#tableHeader")?.checked);
            const striped = Boolean(document.querySelector("#tableStriped")?.checked);

            dialog?.close();
            requestAnimationFrame(() => {
                this.editor.selection.restore();
                this.insertTable(rows, cols, { header, striped });
            });
        });
    }

    insertTable(rows, cols, options = {}) {
        const classes = ["organic-content-table"];
        if (options.striped) classes.push("is-striped");

        let html = `<div class="organic-table-wrap"><table class="${classes.join(" ")}">`;

        if (options.header) {
            html += "<thead><tr>";
            for (let c = 0; c < cols; c++) html += `<th>Coluna ${c + 1}</th>`;
            html += "</tr></thead>";
            rows -= 1;
        }

        html += "<tbody>";
        for (let r = 0; r < rows; r++) {
            html += "<tr>";
            for (let c = 0; c < cols; c++) html += "<td><br></td>";
            html += "</tr>";
        }
        html += "</tbody></table></div><p><br></p>";

        const inserted = this.editor.insertContent(html);
        if (inserted) {
            this.editor.setStatus("Tabela inserida");
        }
    }

    createContextMenu() {
        const menu = document.createElement("div");
        menu.className = "organic-table-menu";
        menu.innerHTML = `
            <button type="button" data-table-action="row-above">Linha acima</button>
            <button type="button" data-table-action="row-below">Linha abaixo</button>
            <button type="button" data-table-action="col-left">Coluna à esquerda</button>
            <button type="button" data-table-action="col-right">Coluna à direita</button>
            <hr>
            <button type="button" data-table-action="toggle-header">Alternar cabeçalho</button>
            <button type="button" data-table-action="toggle-striped">Alternar listras</button>
            <hr>
            <button type="button" data-table-action="delete-row" class="danger">Excluir linha</button>
            <button type="button" data-table-action="delete-col" class="danger">Excluir coluna</button>
            <button type="button" data-table-action="delete-table" class="danger">Excluir tabela</button>
        `;
        document.body.appendChild(menu);
        this.contextMenu = menu;

        menu.addEventListener("mousedown", (event) => {
            event.preventDefault();
            const action = event.target.closest("[data-table-action]")?.dataset.tableAction;
            if (!action || !this.activeCell) return;
            this.runAction(action, this.activeCell);
            this.hideMenu();
        });

        document.addEventListener("mousedown", (event) => {
            if (!menu.contains(event.target)) this.hideMenu();
        });
        window.addEventListener("blur", () => this.hideMenu());
        window.addEventListener("scroll", () => this.hideMenu(), true);
    }

    bindEditorContext() {
        this.editor.element.addEventListener("contextmenu", (event) => {
            const cell = event.target.closest("td, th");
            if (!cell || !this.editor.element.contains(cell)) return;

            event.preventDefault();
            this.activeCell = cell;
            this.contextMenu.style.left = `${event.clientX}px`;
            this.contextMenu.style.top = `${event.clientY}px`;
            this.contextMenu.classList.add("is-open");
        });
    }

    hideMenu() {
        this.contextMenu?.classList.remove("is-open");
    }

    runAction(action, cell) {
        const row = cell.closest("tr");
        const table = cell.closest("table");
        const index = [...row.cells].indexOf(cell);

        switch (action) {
            case "row-above":
                this.addRow(row, true);
                break;
            case "row-below":
                this.addRow(row, false);
                break;
            case "col-left":
                this.addColumn(table, index, true);
                break;
            case "col-right":
                this.addColumn(table, index, false);
                break;
            case "delete-row":
                row.remove();
                break;
            case "delete-col":
                [...table.rows].forEach(r => r.cells[index]?.remove());
                break;
            case "delete-table":
                table.closest(".organic-table-wrap")?.remove();
                break;
            case "toggle-header":
                this.toggleHeader(table);
                break;
            case "toggle-striped":
                table.classList.toggle("is-striped");
                break;
        }

        this.cleanup(table);
        this.commitChange();
    }

    addRow(referenceRow, before) {
        const table = referenceRow.closest("table");
        const cols = referenceRow.cells.length;
        const newRow = document.createElement("tr");
        const useHeader = referenceRow.parentElement.tagName === "THEAD";

        for (let i = 0; i < cols; i++) {
            const cell = document.createElement(useHeader ? "th" : "td");
            cell.innerHTML = "<br>";
            newRow.appendChild(cell);
        }

        if (before) referenceRow.before(newRow);
        else referenceRow.after(newRow);
    }

    addColumn(table, index, before) {
        const targetIndex = before ? index : index + 1;
        [...table.rows].forEach((row) => {
            const isHeader = row.parentElement.tagName === "THEAD";
            const cell = document.createElement(isHeader ? "th" : "td");
            cell.innerHTML = "<br>";
            row.insertBefore(cell, row.cells[targetIndex] || null);
        });
    }

    toggleHeader(table) {
        let thead = table.querySelector("thead");
        const tbody = table.querySelector("tbody") || table;

        if (thead) {
            const row = thead.rows[0];
            if (row) {
                [...row.cells].forEach((cell) => {
                    const td = document.createElement("td");
                    td.innerHTML = cell.innerHTML;
                    cell.replaceWith(td);
                });
                tbody.insertBefore(row, tbody.firstChild);
            }
            thead.remove();
            return;
        }

        const firstRow = tbody.rows[0];
        if (!firstRow) return;

        thead = table.createTHead();
        [...firstRow.cells].forEach((cell) => {
            const th = document.createElement("th");
            th.innerHTML = cell.innerHTML || "Cabeçalho";
            cell.replaceWith(th);
        });
        thead.appendChild(firstRow);
    }

    cleanup(table) {
        if (!table?.isConnected) return;
        const rows = [...table.rows];
        if (!rows.length || rows.every(r => r.cells.length === 0)) {
            table.closest(".organic-table-wrap")?.remove();
        }
    }

    commitChange() {
        this.editor.history.capture(true);
        this.editor.updateCounters();
        this.editor.events.emit("change", this.editor.getContent());
        this.editor.setStatus("Tabela alterada");
    }
}
