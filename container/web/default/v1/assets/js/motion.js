(function () {
    "use strict";
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const selectors = [".solutions-header", ".solution-card", ".app-copy", ".phones", ".benefit", ".method-content > *", ".method-step", ".method-result", ".about-team-content > *", ".about-team-image", ".testimonial-card", ".section__head", ".cards--blog > article", ".localizacao-info > *", ".localizacao-map"];
    const items = Array.from(document.querySelectorAll(selectors.join(",")));
    items.forEach(function (item, index) {
        item.classList.add("connect-motion");
        item.style.setProperty("--motion-delay", Math.min(index % 7, 5) * 70 + "ms");
    });
    if (reducedMotion || !("IntersectionObserver" in window)) {
        items.forEach(function (item) { item.classList.add("connect-motion-visible"); });
        return;
    }
    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            entry.target.classList.add("connect-motion-visible");
            observer.unobserve(entry.target);
        });
    }, {rootMargin: "0px 0px -8%", threshold: 0.12});
    items.forEach(function (item) { observer.observe(item); });

    const hero = document.querySelector(".home_featured");
    const heroContent = document.querySelector(".home_featured_title");
    const phone = document.querySelector(".phones");
    let ticking = false;
    function paint() {
        const y = window.scrollY || 0;
        if (hero && y < window.innerHeight * 1.25) {
            hero.style.setProperty("--connect-hero-y", Math.min(90, y * 0.12) + "px");
            if (heroContent) heroContent.style.setProperty("--connect-copy-y", Math.min(34, y * 0.045) + "px");
        }
        if (phone) {
            const rect = phone.getBoundingClientRect();
            const center = rect.top + rect.height / 2 - window.innerHeight / 2;
            phone.style.setProperty("--connect-phone-y", Math.max(-24, Math.min(24, center * -0.045)) + "px");
        }
        ticking = false;
    }
    window.addEventListener("scroll", function () {
        if (!ticking) { ticking = true; window.requestAnimationFrame(paint); }
    }, {passive: true});
    window.addEventListener("resize", paint);
    paint();
})();
