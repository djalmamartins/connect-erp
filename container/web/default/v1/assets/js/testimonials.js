$(function () {
    $(".cards--testimonials").each(function () {
        const track = this;
        const slides = Array.from(track.querySelectorAll("article"));
        if (slides.length < 2 || track.dataset.carouselReady) return;
        track.dataset.carouselReady = "true";

        // Extra slides keep the three-card viewport filled during each transition.
        slides.forEach(function (slide) {
            const clone = slide.cloneNode(true);
            clone.setAttribute("aria-hidden", "true");
            track.appendChild(clone);
        });

        const viewport = document.createElement("div");
        viewport.className = "testimonial-viewport";
        track.parentNode.insertBefore(viewport, track);
        viewport.appendChild(track);
        viewport.setAttribute("role", "region");
        viewport.setAttribute("aria-label", "Depoimentos de clientes");

        const dots = document.createElement("div");
        dots.className = "testimonial-dots";
        dots.setAttribute("role", "tablist");
        dots.setAttribute("aria-label", "Selecionar depoimento");

        slides.forEach(function (_, index) {
            const dot = document.createElement("button");
            dot.type = "button";
            dot.className = "testimonial-dot";
            dot.setAttribute("aria-label", "Ver depoimento " + (index + 1));
            dot.addEventListener("click", function () { goTo(index); });
            dots.appendChild(dot);
        });
        viewport.insertAdjacentElement("afterend", dots);

        let current = 0;
        let animating = false;
        let timer;
        let visible = false;
        let hovered = false;
        let focused = false;
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        function updateDots() {
            Array.from(dots.children).forEach(function (dot, index) {
                const active = index === current;
                dot.classList.toggle("is-active", active);
                dot.setAttribute("aria-current", active ? "true" : "false");
            });
        }

        function advance(done) {
            if (animating) return;
            animating = true;
            const first = track.querySelector("article");
            const gap = parseFloat(getComputedStyle(track).gap) || 0;
            const distance = first.getBoundingClientRect().width + gap;
            const animation = track.animate([
                {transform: "translateX(0)"},
                {transform: "translateX(-" + distance + "px)"}
            ], {duration: reduceMotion ? 0 : 420, easing: "cubic-bezier(.4,0,.2,1)"});

            animation.onfinish = function () {
                track.appendChild(first);
                current = (current + 1) % slides.length;
                updateDots();
                animating = false;
                if (done) done();
            };
        }

        function goTo(target) {
            window.clearInterval(timer);
            let steps = (target - current + slides.length) % slides.length;
            function next() {
                if (steps-- <= 0) {
                    startAutoPlay();
                    return;
                }
                advance(next);
            }
            next();
        }

        function startAutoPlay() {
            window.clearInterval(timer);
            if (!visible || hovered || focused) return;
            timer = window.setInterval(function () { advance(); }, 5000);
        }

        viewport.addEventListener("mouseenter", function () {
            hovered = true;
            window.clearInterval(timer);
        });
        viewport.addEventListener("mouseleave", function () {
            hovered = false;
            startAutoPlay();
        });
        viewport.addEventListener("focusin", function () {
            focused = true;
            window.clearInterval(timer);
        });
        viewport.addEventListener("focusout", function () {
            focused = false;
            startAutoPlay();
        });

        if ("IntersectionObserver" in window) {
            const observer = new IntersectionObserver(function (entries) {
                visible = entries[0].isIntersecting;
                if (visible) {
                    startAutoPlay();
                } else {
                    window.clearInterval(timer);
                }
            }, {threshold: 0.2});
            observer.observe(viewport);
        } else {
            visible = true;
        }
        updateDots();
        startAutoPlay();
    });
});
