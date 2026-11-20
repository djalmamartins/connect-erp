/**
 * Código da verificação em duas etapas em 6 campos (ADR 0620).
 * Cada campo guarda um dígito, avança sozinho, aceita colar o código inteiro,
 * apaga voltando e, completo, junta os dígitos no campo "code" e envia.
 * O envio continua com o login.js (AJAX); este arquivo só monta o valor.
 */
(function () {
    "use strict";

    var form = document.querySelector("[data-otp-form]");
    if (!form) return;

    var boxes = Array.prototype.slice.call(form.querySelectorAll("[data-otp-digit]"));
    var value = form.querySelector("[data-otp-value]");
    var otpPanel = form.querySelector("[data-otp-panel]");
    var recoveryPanel = form.querySelector("[data-recovery-panel]");
    var recoveryInput = form.querySelector("[data-recovery-input]");
    var toggle = form.querySelector("[data-otp-toggle]");
    var response = document.querySelector(".ajax_response");
    var usingRecovery = false;
    var sent = "";

    function digits(text) {
        return String(text || "").replace(/\D/g, "");
    }

    function code() {
        return boxes.map(function (box) { return box.value; }).join("");
    }

    function sync() {
        boxes.forEach(function (box) { box.classList.toggle("is-filled", box.value !== ""); });
        value.value = usingRecovery && recoveryInput ? recoveryInput.value.trim() : code();
    }

    /** Espalha os dígitos a partir do campo `start` e foca o próximo vazio. */
    function fill(start, text) {
        var list = digits(text).split("");
        var index = start;
        while (list.length && index < boxes.length) {
            boxes[index].value = list.shift();
            index++;
        }
        sync();
        var next = boxes[Math.min(index, boxes.length - 1)];
        next.focus();
        next.select();
        submitWhenComplete();
    }

    function submitWhenComplete() {
        var current = code();
        if (current.length !== boxes.length || current === sent) return;
        sent = current;
        if (typeof form.requestSubmit === "function") {
            form.requestSubmit();
        } else {
            form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
        }
    }

    function clear() {
        boxes.forEach(function (box) { box.value = ""; });
        sent = "";
        sync();
        boxes[0].focus();
    }

    boxes.forEach(function (box, index) {
        box.addEventListener("input", function () {
            var typed = digits(box.value);
            if (typed.length > 1) {
                // Colado ou preenchido pelo navegador (one-time-code) no mesmo campo.
                box.value = "";
                fill(index, typed);
                return;
            }
            box.value = typed;
            sync();
            if (typed && index < boxes.length - 1) {
                boxes[index + 1].focus();
                boxes[index + 1].select();
            }
            submitWhenComplete();
        });

        box.addEventListener("keydown", function (event) {
            if (event.key === "Backspace" && box.value === "" && index > 0) {
                event.preventDefault();
                boxes[index - 1].value = "";
                boxes[index - 1].focus();
                sent = "";
                sync();
            } else if (event.key === "ArrowLeft" && index > 0) {
                event.preventDefault();
                boxes[index - 1].focus();
            } else if (event.key === "ArrowRight" && index < boxes.length - 1) {
                event.preventDefault();
                boxes[index + 1].focus();
            } else if (event.key === "Backspace" || event.key === "Delete") {
                sent = "";
            }
        });

        box.addEventListener("paste", function (event) {
            var text = (event.clipboardData || window.clipboardData).getData("text");
            if (!digits(text)) return;
            event.preventDefault();
            fill(index, text);
        });

        box.addEventListener("focus", function () { box.select(); });
    });

    if (toggle && recoveryPanel && recoveryInput) {
        toggle.addEventListener("click", function () {
            usingRecovery = !usingRecovery;
            otpPanel.hidden = usingRecovery;
            recoveryPanel.hidden = !usingRecovery;
            toggle.setAttribute("aria-pressed", usingRecovery ? "true" : "false");
            toggle.textContent = usingRecovery ? "Usar código do aplicativo" : "Usar código de recuperação";
            sync();
            (usingRecovery ? recoveryInput : boxes[0]).focus();
        });
        recoveryInput.addEventListener("input", sync);
    }

    // Captura: roda antes do envio do login.js e barra código incompleto.
    form.addEventListener("submit", function (event) {
        sync();
        var ok = usingRecovery ? value.value.replace(/[^a-z0-9]/gi, "").length === 10 : /^\d{6}$/.test(value.value);
        if (ok) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (usingRecovery) {
            recoveryInput.focus();
            return;
        }
        var empty = boxes.filter(function (box) { return box.value === ""; })[0] || boxes[0];
        empty.focus();
    }, true);

    // Código recusado: limpa os campos e volta ao primeiro para digitar de novo.
    if (response && window.MutationObserver) {
        new MutationObserver(function () {
            if (!usingRecovery && response.textContent.trim() !== "") clear();
        }).observe(response, { childList: true, subtree: true });
    }
})();
