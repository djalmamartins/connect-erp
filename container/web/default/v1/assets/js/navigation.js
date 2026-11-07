$(function () {
    const $menu = $(".j_menu_mobile_tab");
    const $open = $(".j_menu_mobile_open");

    $open.on("click", function () {
        $(this).attr("aria-expanded", "true");
        $("body").css("overflow", "hidden");
        window.setTimeout(function () { $(".j_menu_mobile_close").trigger("focus"); }, 220);
    });

    function closeMobileMenu() {
        if (window.innerWidth <= 1000) {
            $menu.stop(true).animate({left: "100%"}, 200, function () {
                $menu.css({right: "auto", left: "auto", display: "none"});
            });
            $open.attr("aria-expanded", "false").trigger("focus");
            $("body").css("overflow", "");
        }
    }

    $(".j_menu_mobile_close").on("click", function () {
        $open.attr("aria-expanded", "false");
        $("body").css("overflow", "");
    });
    $menu.find("a").on("click", closeMobileMenu);
    $(document).on("keydown", function (event) {
        if (event.key === "Escape" && $open.attr("aria-expanded") === "true") closeMobileMenu();
    });
});

(function () {
    "use strict";

    function hashTarget() {
        if (!window.location.hash || window.location.hash === "#") return null;

        try {
            return document.getElementById(decodeURIComponent(window.location.hash.substring(1)));
        } catch (error) {
            return null;
        }
    }

    function scrollToCurrentSection(behavior) {
        const target = hashTarget();
        if (!target) return;

        const header = document.querySelector(".main_header");
        const headerHeight = header ? header.getBoundingClientRect().height : 90;
        const targetTop = target.getBoundingClientRect().top + window.pageYOffset - headerHeight;

        window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: behavior || "auto"
        });
    }

    if ("scrollRestoration" in window.history) {
        window.history.scrollRestoration = "manual";
    }

    document.addEventListener("DOMContentLoaded", function () {
        window.requestAnimationFrame(function () {
            scrollToCurrentSection("auto");
        });
    });

    window.addEventListener("load", function () {
        scrollToCurrentSection("auto");
        window.setTimeout(function () {
            scrollToCurrentSection("auto");
        }, 250);
    });

    window.addEventListener("hashchange", function () {
        scrollToCurrentSection("smooth");
    });
})();
