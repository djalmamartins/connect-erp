/**
 * Organic UI v2
 * Carousel — dependency-free, responsive and accessible.
 */

const carouselInstances = new WeakMap();

class OrganicCarousel {
    constructor(root, options = {}) {
        if (!root || carouselInstances.has(root)) return carouselInstances.get(root);

        this.root = root;
        this.viewport = root.querySelector('.org-carousel-viewport');
        this.track = root.querySelector('.org-carousel-track');
        this.slides = Array.from(root.querySelectorAll('.org-carousel-slide'));
        this.prevButton = root.querySelector('[data-org-carousel-prev]');
        this.nextButton = root.querySelector('[data-org-carousel-next]');
        this.dotsContainer = root.querySelector('[data-org-carousel-dots]');
        this.progress = root.querySelector('[data-org-carousel-progress]');
        this.counter = root.querySelector('[data-org-carousel-counter]');

        if (!this.viewport || !this.track || !this.slides.length) return null;

        const d = root.dataset;
        this.options = {
            items: Math.max(1, Number(d.orgItems) || 1),
            mobile: Number(d.orgItemsMobile) || null,
            tablet: Number(d.orgItemsTablet) || null,
            desktop: Number(d.orgItemsDesktop) || null,
            gap: Number(d.orgGap) || 16,
            loop: d.orgLoop === 'true',
            drag: d.orgDrag !== 'false',
            keyboard: d.orgKeyboard !== 'false',
            autoplay: Number(d.orgAutoplay) || 0,
            pauseHover: d.orgPauseHover !== 'false',
            pauseFocus: d.orgPauseFocus !== 'false',
            autoHeight: d.orgAutoHeight === 'true',
            start: Math.max(0, Number(d.orgStart) || 0),
            ...options,
        };

        this.items = this.options.items;
        this.index = this.options.start;
        this.timer = null;
        this.dragging = false;
        this.startX = 0;
        this.currentX = 0;
        this.destroyed = false;
        this.listeners = [];

        this.root.setAttribute('role', this.root.getAttribute('role') || 'region');
        if (!this.root.hasAttribute('aria-roledescription')) this.root.setAttribute('aria-roledescription', 'carousel');
        if (!this.root.hasAttribute('tabindex') && this.options.keyboard) this.root.tabIndex = 0;

        this.bind();
        this.refresh();
        this.goTo(this.index, { emit: false });
        this.play();

        carouselInstances.set(root, this);
        root.orgCarousel = this;
    }

    on(target, event, handler, options) {
        target?.addEventListener(event, handler, options);
        this.listeners.push([target, event, handler, options]);
    }

    getItems() {
        const width = window.innerWidth;
        if (width < 576 && this.options.mobile) return this.options.mobile;
        if (width < 992 && this.options.tablet) return this.options.tablet;
        if (width >= 992 && this.options.desktop) return this.options.desktop;
        return this.options.items;
    }

    maxIndex() {
        return Math.max(0, this.slides.length - this.items);
    }

    slideWidth() {
        return (this.viewport.clientWidth - this.options.gap * (this.items - 1)) / this.items;
    }

    position() {
        const offset = this.index * (this.slideWidth() + this.options.gap);
        this.track.style.transform = `translate3d(-${offset}px,0,0)`;
        if (this.options.autoHeight) this.updateHeight();
    }

    updateHeight() {
        const visible = this.slides.slice(this.index, this.index + this.items);
        const height = Math.max(0, ...visible.map(slide => slide.offsetHeight));
        if (height) this.viewport.style.height = `${height}px`;
    }

    createDots() {
        if (!this.dotsContainer) return;
        this.dotsContainer.innerHTML = '';
        for (let i = 0; i <= this.maxIndex(); i += 1) {
            const dot = document.createElement('button');
            dot.className = 'org-carousel-dot';
            dot.type = 'button';
            dot.setAttribute('aria-label', `Ir para posição ${i + 1}`);
            dot.addEventListener('click', () => this.goTo(i));
            this.dotsContainer.appendChild(dot);
        }
    }

    updateA11y() {
        this.slides.forEach((slide, i) => {
            const visible = i >= this.index && i < this.index + this.items;
            slide.setAttribute('aria-hidden', visible ? 'false' : 'true');
            slide.setAttribute('role', slide.getAttribute('role') || 'group');
            slide.setAttribute('aria-roledescription', 'slide');
            if (!slide.hasAttribute('aria-label')) slide.setAttribute('aria-label', `${i + 1} de ${this.slides.length}`);
        });
    }

    updateControls() {
        const max = this.maxIndex();
        if (!this.options.loop) {
            if (this.prevButton) this.prevButton.disabled = this.index <= 0;
            if (this.nextButton) this.nextButton.disabled = this.index >= max;
        }

        this.dotsContainer?.querySelectorAll('.org-carousel-dot').forEach((dot, i) => {
            const active = i === this.index;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });

        if (this.counter) this.counter.textContent = `${this.index + 1} / ${max + 1}`;
        if (this.progress) {
            const ratio = max === 0 ? 1 : (this.index + 1) / (max + 1);
            this.progress.style.setProperty('--org-carousel-progress', `${ratio * 100}%`);
            this.progress.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
        }

        this.updateA11y();
    }

    normalize(target) {
        const max = this.maxIndex();
        if (this.options.loop) {
            if (target < 0) return max;
            if (target > max) return 0;
        }
        return Math.max(0, Math.min(target, max));
    }

    goTo(target, { emit = true } = {}) {
        if (this.destroyed) return this;
        this.index = this.normalize(Number(target) || 0);
        this.position();
        this.updateControls();
        if (emit) {
            this.root.dispatchEvent(new CustomEvent('organic:carousel:change', {
                bubbles: true,
                detail: { index: this.index, items: this.items, instance: this },
            }));
        }
        return this;
    }

    next() { return this.goTo(this.index + 1); }
    prev() { return this.goTo(this.index - 1); }

    pause() {
        if (this.timer) window.clearInterval(this.timer);
        this.timer = null;
        this.root.classList.add('is-paused');
        return this;
    }

    play() {
        this.pause();
        this.root.classList.remove('is-paused');
        if (this.options.autoplay > 0 && this.slides.length > this.items && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.timer = window.setInterval(() => this.next(), this.options.autoplay);
        }
        return this;
    }

    refresh() {
        if (this.destroyed) return this;
        this.items = Math.max(1, this.getItems());
        this.root.style.setProperty('--org-carousel-items', this.items);
        this.root.style.setProperty('--org-carousel-gap', `${this.options.gap}px`);
        this.index = this.normalize(this.index);
        this.createDots();
        this.position();
        this.updateControls();
        return this;
    }

    bind() {
        this.on(this.prevButton, 'click', () => this.prev());
        this.on(this.nextButton, 'click', () => this.next());
        this.on(window, 'resize', () => this.refresh());

        if (this.options.pauseHover) {
            this.on(this.root, 'mouseenter', () => this.pause());
            this.on(this.root, 'mouseleave', () => this.play());
        }
        if (this.options.pauseFocus) {
            this.on(this.root, 'focusin', () => this.pause());
            this.on(this.root, 'focusout', event => {
                if (!this.root.contains(event.relatedTarget)) this.play();
            });
        }

        if (this.options.keyboard) {
            this.on(this.root, 'keydown', event => {
                if (event.key === 'ArrowLeft') { event.preventDefault(); this.prev(); }
                if (event.key === 'ArrowRight') { event.preventDefault(); this.next(); }
                if (event.key === 'Home') { event.preventDefault(); this.goTo(0); }
                if (event.key === 'End') { event.preventDefault(); this.goTo(this.maxIndex()); }
            });
        }

        if (this.options.drag) {
            this.root.dataset.orgDrag = 'true';
            this.on(this.viewport, 'pointerdown', event => {
                if (event.button !== undefined && event.button !== 0) return;
                this.dragging = true;
                this.startX = event.clientX;
                this.currentX = event.clientX;
                this.root.classList.add('is-dragging');
                this.pause();
                this.viewport.setPointerCapture?.(event.pointerId);
            });
            this.on(this.viewport, 'pointermove', event => {
                if (!this.dragging) return;
                this.currentX = event.clientX;
                const delta = this.currentX - this.startX;
                const base = this.index * (this.slideWidth() + this.options.gap);
                this.track.style.transform = `translate3d(${delta - base}px,0,0)`;
            });
            const end = () => {
                if (!this.dragging) return;
                const delta = this.currentX - this.startX;
                const threshold = Math.min(80, this.slideWidth() * 0.2);
                this.dragging = false;
                this.root.classList.remove('is-dragging');
                if (delta > threshold) this.prev();
                else if (delta < -threshold) this.next();
                else this.position();
                this.play();
            };
            this.on(this.viewport, 'pointerup', end);
            this.on(this.viewport, 'pointercancel', end);
        }
    }

    destroy() {
        this.pause();
        this.listeners.forEach(([target, event, handler, options]) => target?.removeEventListener(event, handler, options));
        this.listeners = [];
        this.track.style.transform = '';
        this.viewport.style.height = '';
        this.dotsContainer && (this.dotsContainer.innerHTML = '');
        this.root.removeAttribute('data-org-carousel-ready');
        delete this.root.orgCarousel;
        carouselInstances.delete(this.root);
        this.destroyed = true;
    }
}

function initCarousel(target = document) {
    const roots = target.matches?.('[data-org-carousel]') ? [target] : target.querySelectorAll?.('[data-org-carousel]') || [];
    return Array.from(roots).map(root => {
        if (carouselInstances.has(root)) return carouselInstances.get(root);
        const instance = new OrganicCarousel(root);
        if (instance) root.dataset.orgCarouselReady = 'true';
        return instance;
    }).filter(Boolean);
}

function getCarousel(target) {
    const root = typeof target === 'string' ? document.querySelector(target) : target;
    return root ? carouselInstances.get(root) || root.orgCarousel || null : null;
}

window.Organic = window.Organic || {};
window.Organic.Carousel = {
    init: initCarousel,
    get: getCarousel,
    next(target) { return getCarousel(target)?.next(); },
    prev(target) { return getCarousel(target)?.prev(); },
    goTo(target, index) { return getCarousel(target)?.goTo(index); },
    play(target) { return getCarousel(target)?.play(); },
    pause(target) { return getCarousel(target)?.pause(); },
    refresh(target) { return getCarousel(target)?.refresh(); },
    destroy(target) { return getCarousel(target)?.destroy(); },
};

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', () => initCarousel())
    : initCarousel();

export { OrganicCarousel, initCarousel, getCarousel };
