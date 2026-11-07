/**
 * Organic UI v2 — Form masks
 * Zero dependencies. Brazilian presets + custom masks.
 */

const digits = value => String(value ?? '').replace(/\D/g, '');

const maskCPF = value => {
    const v = digits(value).slice(0, 11);
    return v
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1-$2');
};

const maskCNPJ = value => {
    const v = digits(value).slice(0, 14);
    return v
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\/\d{4})(\d)/, '$1-$2');
};

const maskCpfCnpj = value => digits(value).length <= 11 ? maskCPF(value) : maskCNPJ(value);

const maskPhone = value => {
    const v = digits(value).slice(0, 11);
    if (v.length <= 10) {
        return v.replace(/^(\d{0,2})(\d{0,4})(\d{0,4}).*/, (_, ddd, a, b) =>
            [ddd && `(${ddd}`, ddd.length === 2 ? ')' : '', a && ` ${a}`, b && `-${b}`].join('')
        ).trim();
    }
    return v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
};

const maskCEP = value => digits(value).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');

const maskDate = value => {
    const v = digits(value).slice(0, 8);
    return v.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2})\/(\d{2})(\d)/, '$1/$2/$3');
};

const maskTime = value => {
    const v = digits(value).slice(0, 4);
    return v.replace(/^(\d{2})(\d)/, '$1:$2');
};

const maskInteger = value => {
    const sign = String(value).trim().startsWith('-') ? '-' : '';
    return sign + digits(value);
};

const maskDecimal = (value, field) => {
    const decimals = Math.max(0, Number(field?.dataset.orgDecimals ?? 2));
    let v = String(value ?? '').replace(/[^\d,.-]/g, '').replace(/\./g, ',');
    const negative = v.startsWith('-') ? '-' : '';
    v = v.replace(/-/g, '');
    const [integer = '', ...parts] = v.split(',');
    const fraction = parts.join('').slice(0, decimals);
    return negative + digits(integer) + (parts.length && decimals ? `,${fraction}` : '');
};

const maskMoney = (value, field) => {
    const decimals = Math.max(0, Number(field?.dataset.orgDecimals ?? 2));
    const raw = digits(value);
    if (!raw) return '';
    const number = Number(raw) / (10 ** decimals);
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    }).format(number);
};

const maskPercent = (value, field) => maskDecimal(value, field);

function applyCustomMask(value, pattern) {
    if (!pattern) return value;
    const source = String(value ?? '').replace(/[^\p{L}\p{N}]/gu, '');
    let si = 0;
    let out = '';

    for (let pi = 0; pi < pattern.length && si < source.length; pi += 1) {
        const token = pattern[pi];
        const char = source[si];
        if (token === '0') {
            if (/\d/.test(char)) { out += char; si += 1; }
            else { si += 1; pi -= 1; }
        } else if (token === 'A') {
            if (/\p{L}/u.test(char)) { out += char; si += 1; }
            else { si += 1; pi -= 1; }
        } else if (token === '*') {
            out += char;
            si += 1;
        } else {
            out += token;
        }
    }
    return out;
}

const formatters = {
    cpf: maskCPF,
    cnpj: maskCNPJ,
    'cpf-cnpj': maskCpfCnpj,
    phone: maskPhone,
    cep: maskCEP,
    date: maskDate,
    time: maskTime,
    money: maskMoney,
    percent: maskPercent,
    integer: maskInteger,
    decimal: maskDecimal,
    custom: (value, field) => applyCustomMask(value, field.dataset.orgMaskPattern || '')
};

function applyMask(field) {
    const name = field.dataset.orgMask;
    const formatter = formatters[name];
    if (!formatter) return;

    const oldValue = field.value;
    const end = field.selectionStart === oldValue.length;
    field.value = formatter(oldValue, field);
    if (end && field.setSelectionRange) {
        const pos = field.value.length;
        requestAnimationFrame(() => field.setSelectionRange(pos, pos));
    }
    field.dispatchEvent(new CustomEvent('organic:mask', { bubbles: true, detail: { mask: name, value: field.value } }));
}

function initMasks(root = document) {
    root.querySelectorAll('[data-org-mask]').forEach(field => {
        if (field.dataset.orgMaskReady === 'true') return;
        field.dataset.orgMaskReady = 'true';
        field.setAttribute('inputmode', ['cpf','cnpj','cpf-cnpj','phone','cep','date','time','money','percent','integer','decimal'].includes(field.dataset.orgMask) ? 'numeric' : field.getAttribute('inputmode') || 'text');
        field.addEventListener('input', () => applyMask(field));
        field.addEventListener('blur', () => applyMask(field));
        if (field.value) applyMask(field);
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initMasks(), { once: true });
else initMasks();

export { initMasks, applyMask, applyCustomMask, formatters };
