/** Organic UI v2 — Form helpers */

function initCounters(root = document) {
    root.querySelectorAll('[data-org-counter]').forEach(counter => {
        const selector = counter.getAttribute('data-org-counter');
        const field = selector ? document.querySelector(selector) : counter.closest('.org-form-group')?.querySelector('input, textarea');
        if (!field) return;
        const max = field.maxLength > 0 ? field.maxLength : null;
        const render = () => {
            const length = field.value.length;
            counter.textContent = max ? `${length}/${max}` : String(length);
        };
        field.addEventListener('input', render);
        render();
    });
}

function initPasswordToggles(root = document) {
    root.querySelectorAll('[data-org-password-toggle]').forEach(button => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-org-password-toggle');
            const input = target ? document.querySelector(target) : button.closest('.org-password')?.querySelector('input');
            if (!input) return;
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            button.setAttribute('aria-pressed', String(!visible));
            button.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
            button.textContent = visible ? '◉' : '◎';
        });
    });
}

function initForms() {
    initCounters();
    initPasswordToggles();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForms, { once: true });
} else {
    initForms();
}

export { initForms, initCounters, initPasswordToggles };
