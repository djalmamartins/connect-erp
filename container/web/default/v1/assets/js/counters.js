function initNumberCounters() {
    const bar = document.querySelector(".numbers-bar");
    if (!bar) return;

    const counters = Array.from(bar.querySelectorAll(".number-content strong")).map(function (element) {
        const label = element.textContent.trim();
        const match = label.match(/^(\D*)(\d+)(\D*)$/);
        if (!match) return null;

        const target = Number(element.dataset.numberTarget || match[2]);
        element.dataset.numberTarget = String(target);

        element.textContent = match[1] + "0" + match[3];
        return {element: element, prefix: match[1], target: target, suffix: match[3]};
    }).filter(Boolean);

    if (!counters.length) return;

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let started = false;
    let animationFrame = null;

    function setValues(progress) {
        const eased = 1 - Math.pow(1 - progress, 3);
        counters.forEach(function (counter) {
            const value = Math.round(counter.target * eased);
            counter.element.textContent = counter.prefix + value.toLocaleString("pt-BR") + counter.suffix;
        });
    }

    function startCounters() {
        if (started) return;
        started = true;

        if (reduceMotion) {
            setValues(1);
            return;
        }

        const duration = 1800;
        const startTime = performance.now();

        function animate(currentTime) {
            const progress = Math.min(1, (currentTime - startTime) / duration);
            setValues(progress);
            if (progress < 1) {
                animationFrame = window.requestAnimationFrame(animate);
            } else {
                animationFrame = null;
            }
        }

        animationFrame = window.requestAnimationFrame(animate);
    }

    function resetCounters() {
        if (animationFrame !== null) window.cancelAnimationFrame(animationFrame);
        animationFrame = null;
        started = false;
        setValues(0);
    }

    function checkVisibility() {
        const rect = bar.getBoundingClientRect();
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
        const visible = rect.bottom > 0 && rect.top < viewportHeight;

        if (visible) {
            startCounters();
        } else if (rect.bottom <= 0 || rect.top >= viewportHeight) {
            resetCounters();
        }
    }

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) {
                startCounters();
            } else {
                resetCounters();
            }
        }, {threshold: 0.1});
        observer.observe(bar);
    } else {
        checkVisibility();
    }

    // Fallback for restored scroll positions, delayed layouts and observer failures.
    window.addEventListener("scroll", checkVisibility, {passive: true});
    window.addEventListener("resize", checkVisibility);
    window.addEventListener("pageshow", checkVisibility);
    window.addEventListener("load", checkVisibility);
    window.setTimeout(checkVisibility, 150);
    window.setTimeout(checkVisibility, 800);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNumberCounters, {once: true});
} else {
    initNumberCounters();
}
