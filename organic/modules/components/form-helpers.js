/** Organic UI v2 — Small form helpers */

function autoGrow(field) {
    field.style.height = 'auto';
    field.style.height = `${field.scrollHeight}px`;
}

function initHelpers(root = document) {
    root.querySelectorAll('[data-org-uppercase]').forEach(field => {
        if (field.dataset.orgUppercaseReady) return;
        field.dataset.orgUppercaseReady = 'true';
        field.addEventListener('input', () => { field.value = field.value.toLocaleUpperCase('pt-BR'); });
    });

    root.querySelectorAll('[data-org-lowercase]').forEach(field => {
        if (field.dataset.orgLowercaseReady) return;
        field.dataset.orgLowercaseReady = 'true';
        field.addEventListener('input', () => { field.value = field.value.toLocaleLowerCase('pt-BR'); });
    });

    root.querySelectorAll('[data-org-trim]').forEach(field => {
        if (field.dataset.orgTrimReady) return;
        field.dataset.orgTrimReady = 'true';
        field.addEventListener('blur', () => { field.value = field.value.trim(); });
    });

    root.querySelectorAll('textarea[data-org-autogrow]').forEach(field => {
        if (field.dataset.orgAutogrowReady) return;
        field.dataset.orgAutogrowReady = 'true';
        field.style.overflowY = 'hidden';
        field.addEventListener('input', () => autoGrow(field));
        autoGrow(field);
    });

    root.querySelectorAll('[data-org-clear]').forEach(button => {
        if (button.dataset.orgClearReady) return;
        button.dataset.orgClearReady = 'true';
        button.addEventListener('click', () => {
            const selector = button.dataset.orgClear;
            const field = selector ? document.querySelector(selector) : button.closest('.org-form-group, .org-input-group')?.querySelector('input,textarea,select');
            if (!field) return;
            field.value = '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            field.focus();
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initHelpers(), { once: true });
else initHelpers();

export { initHelpers, autoGrow };
