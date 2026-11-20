/**
 * Banner de cookies (LGPD, ADR 0600).
 * - Nada opcional carrega antes da escolha: o mapa só entra pelo [data-consent-src] e o GA4 só com data-ga-id e "stats".
 * - A escolha fica no cookie necessário (180 dias) e vai para o servidor como prova (POST /cookies).
 * - O link [data-cookie-open] reabre o banner; "Mostrar mapa" libera só o mapa.
 */
(function () {
    "use strict";
    var root = document.querySelector("[data-cookie-consent]");
    if (!root) return;
    var name = root.getAttribute("data-cookie-name");
    var version = root.getAttribute("data-cookie-version");
    var form = root.querySelector("[data-cookie-form]");
    var choices = root.querySelector("[data-cookie-choices]");
    var boxes = Array.prototype.slice.call(root.querySelectorAll("[data-cookie-category]"));
    var categories = boxes.map(function (box) { return box.getAttribute("data-cookie-category"); });

    function read() {
        var match = document.cookie.match(new RegExp("(?:^|; )" + name + "=([^;]*)"));
        if (!match) return null;
        var parts = decodeURIComponent(match[1]).split("|");
        if (parts[0] !== "v" + version) return null;
        var granted = {};
        (parts[1] || "").split(",").forEach(function (pair) {
            var kv = pair.split("=");
            if (kv[0]) granted[kv[0]] = kv[1] === "1";
        });
        return granted;
    }

    function write(granted) {
        var value = "v" + version + "|" + categories.map(function (key) { return key + "=" + (granted[key] ? "1" : "0"); }).join(",");
        var secure = location.protocol === "https:" ? "; Secure" : "";
        document.cookie = name + "=" + encodeURIComponent(value) + "; Max-Age=" + (180 * 24 * 3600) + "; Path=/; SameSite=Lax" + secure;
        var data = new FormData(form);
        categories.forEach(function (key) { data.set(key, granted[key] ? "1" : "0"); });
        data.set("version", version);
        if (window.fetch) {
            fetch(root.getAttribute("data-cookie-endpoint"), {method: "POST", body: data, credentials: "same-origin"}).catch(function () {});
        }
    }

    function apply(granted) {
        if (granted.maps) {
            document.querySelectorAll("[data-consent-src]").forEach(loadFrame);
        }
        var ga = root.getAttribute("data-ga-id");
        if (granted.stats && ga && !window.__connectGa) {
            window.__connectGa = true;
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
            window.gtag("js", new Date());
            window.gtag("config", ga, {anonymize_ip: true});
            var s = document.createElement("script");
            s.async = true;
            s.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(ga);
            document.head.appendChild(s);
        }
    }

    function loadFrame(holder) {
        if (holder.querySelector("iframe")) return;
        var frame = document.createElement("iframe");
        frame.src = holder.getAttribute("data-consent-src");
        frame.title = holder.getAttribute("data-consent-title") || "Conteúdo externo";
        frame.loading = "lazy";
        frame.setAttribute("referrerpolicy", "no-referrer-when-downgrade");
        frame.setAttribute("allowfullscreen", "");
        holder.innerHTML = "";
        holder.appendChild(frame);
        holder.classList.add("is-loaded");
    }

    function show() {
        var granted = read() || {};
        boxes.forEach(function (box) { box.checked = !!granted[box.getAttribute("data-cookie-category")]; });
        root.hidden = false;
    }

    function hide() { root.hidden = true; }

    function decide(granted) {
        write(granted);
        apply(granted);
        hide();
    }

    root.addEventListener("click", function (event) {
        var button = event.target.closest("[data-cookie-action]");
        if (!button) return;
        var action = button.getAttribute("data-cookie-action");
        var all = {};
        if (action === "accept") {
            categories.forEach(function (key) { all[key] = true; });
            decide(all);
        } else if (action === "reject") {
            decide(all);
        } else if (action === "choose") {
            choices.hidden = !choices.hidden;
            button.setAttribute("aria-expanded", choices.hidden ? "false" : "true");
            root.querySelector('[data-cookie-action="save"]').hidden = choices.hidden;
            if (!choices.hidden && boxes[0]) boxes[0].focus();
        } else if (action === "save") {
            boxes.forEach(function (box) { all[box.getAttribute("data-cookie-category")] = box.checked; });
            decide(all);
        }
    });

    document.addEventListener("click", function (event) {
        var open = event.target.closest("[data-cookie-open]");
        if (open) {
            event.preventDefault();
            show();
            return;
        }
        var map = event.target.closest("[data-consent-load]");
        if (map) {
            var granted = read() || {};
            granted.maps = true;
            write(granted);
            apply(granted);
        }
    });

    var current = read();
    if (current === null) {
        show();
    } else {
        apply(current);
    }
})();
