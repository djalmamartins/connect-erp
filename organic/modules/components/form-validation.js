/** Organic UI v2 — Form validation */

const onlyDigits = value => String(value ?? '').replace(/\D/g, '');

function validCPF(value) {
    const cpf = onlyDigits(value);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    const calc = length => {
        let sum = 0;
        for (let i = 0; i < length; i += 1) sum += Number(cpf[i]) * (length + 1 - i);
        const digit = (sum * 10) % 11;
        return digit === 10 ? 0 : digit;
    };
    return calc(9) === Number(cpf[9]) && calc(10) === Number(cpf[10]);
}

function validCNPJ(value) {
    const cnpj = onlyDigits(value);
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
    const calc = base => {
        let factor = base.length - 7;
        let total = 0;
        for (const char of base) {
            total += Number(char) * factor--;
            if (factor < 2) factor = 9;
        }
        const mod = total % 11;
        return mod < 2 ? 0 : 11 - mod;
    };
    const d1 = calc(cnpj.slice(0, 12));
    const d2 = calc(cnpj.slice(0, 12) + d1);
    return `${d1}${d2}` === cnpj.slice(-2);
}

function validDateBR(value) {
    const match = String(value).match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!match) return false;
    const [, dd, mm, yyyy] = match;
    const date = new Date(Number(yyyy), Number(mm) - 1, Number(dd));
    return date.getFullYear() === Number(yyyy) && date.getMonth() === Number(mm) - 1 && date.getDate() === Number(dd);
}

function validTime(value) {
    const match = String(value).match(/^(\d{2}):(\d{2})$/);
    return !!match && Number(match[1]) <= 23 && Number(match[2]) <= 59;
}

const validators = {
    required: field => String(field.value).trim().length > 0,
    email: field => !field.value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value),
    cpf: field => !field.value || validCPF(field.value),
    cnpj: field => !field.value || validCNPJ(field.value),
    'cpf-cnpj': field => !field.value || (onlyDigits(field.value).length <= 11 ? validCPF(field.value) : validCNPJ(field.value)),
    phone: field => !field.value || [10,11].includes(onlyDigits(field.value).length),
    cep: field => !field.value || onlyDigits(field.value).length === 8,
    date: field => !field.value || validDateBR(field.value),
    time: field => !field.value || validTime(field.value),
    minlength: field => !field.value || field.value.length >= Number(field.dataset.orgMinlength || field.minLength || 0),
    maxlength: field => !field.value || field.value.length <= Number(field.dataset.orgMaxlength || field.maxLength || Infinity),
    min: field => !field.value || Number(String(field.value).replace(',', '.')) >= Number(field.dataset.orgMin ?? field.min),
    max: field => !field.value || Number(String(field.value).replace(',', '.')) <= Number(field.dataset.orgMax ?? field.max),
    match: field => {
        const selector = field.dataset.orgMatch;
        const other = selector ? document.querySelector(selector) : null;
        return !other || field.value === other.value;
    }
};

const defaultMessages = {
    required: 'Este campo é obrigatório.',
    email: 'Informe um e-mail válido.',
    cpf: 'Informe um CPF válido.',
    cnpj: 'Informe um CNPJ válido.',
    'cpf-cnpj': 'Informe um CPF ou CNPJ válido.',
    phone: 'Informe um telefone válido.',
    cep: 'Informe um CEP válido.',
    date: 'Informe uma data válida.',
    time: 'Informe uma hora válida.',
    minlength: 'O valor informado é muito curto.',
    maxlength: 'O valor informado excede o limite.',
    min: 'O valor está abaixo do mínimo permitido.',
    max: 'O valor está acima do máximo permitido.',
    match: 'Os campos não coincidem.'
};

function rulesFor(field) {
    const rules = (field.dataset.orgValidate || '').split(/[\s,|]+/).filter(Boolean);
    if (field.required && !rules.includes('required')) rules.unshift('required');
    return rules;
}

function feedbackFor(field) {
    const group = field.closest('.org-form-group') || field.parentElement;
    let feedback = group?.querySelector('[data-org-validation-message]');
    if (!feedback && group) {
        feedback = document.createElement('span');
        feedback.className = 'org-error';
        feedback.dataset.orgValidationMessage = '';
        feedback.setAttribute('role', 'alert');
        group.appendChild(feedback);
    }
    return { group, feedback };
}

function validateField(field, { show = true } = {}) {
    const rules = rulesFor(field);
    if (!rules.length) return true;

    let failed = null;
    for (const rule of rules) {
        const validator = validators[rule];
        if (validator && !validator(field)) { failed = rule; break; }
    }

    const valid = !failed;
    if (show) {
        const { group, feedback } = feedbackFor(field);
        group?.classList.toggle('is-danger', !valid);
        group?.classList.toggle('is-success', valid && String(field.value).length > 0);
        field.classList.toggle('is-danger', !valid);
        field.classList.toggle('is-success', valid && String(field.value).length > 0);
        field.setAttribute('aria-invalid', String(!valid));
        if (feedback) {
            const custom = failed ? field.dataset[`orgMessage${failed.replace(/(^|-)(\w)/g, (_, __, c) => c.toUpperCase())}`] : '';
            feedback.textContent = valid ? '' : (custom || field.dataset.orgValidationMessage || defaultMessages[failed] || 'Valor inválido.');
            feedback.hidden = valid;
        }
    }

    field.dispatchEvent(new CustomEvent('organic:validate', { bubbles: true, detail: { valid, rule: failed } }));
    return valid;
}

function validateForm(form) {
    const fields = [...form.querySelectorAll('[data-org-validate], [required]')];
    const invalid = fields.filter(field => !validateField(field));
    if (invalid.length) invalid[0].focus();
    form.dispatchEvent(new CustomEvent('organic:formvalidate', { bubbles: true, detail: { valid: invalid.length === 0, invalid } }));
    return invalid.length === 0;
}

function initValidation(root = document) {
    root.querySelectorAll('[data-org-validate], [required]').forEach(field => {
        if (field.dataset.orgValidationReady) return;
        field.dataset.orgValidationReady = 'true';
        field.addEventListener('blur', () => validateField(field));
        field.addEventListener('input', () => {
            if (field.getAttribute('aria-invalid') === 'true') validateField(field);
        });
    });

    root.querySelectorAll('form[data-org-validate-form]').forEach(form => {
        if (form.dataset.orgValidateFormReady) return;
        form.dataset.orgValidateFormReady = 'true';
        form.setAttribute('novalidate', 'novalidate');
        form.addEventListener('submit', event => {
            if (!validateForm(form)) event.preventDefault();
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initValidation(), { once: true });
else initValidation();

export { initValidation, validateField, validateForm, validators, validCPF, validCNPJ };
