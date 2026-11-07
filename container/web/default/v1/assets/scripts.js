/var/www/html/container/shared/assets/vendor/scripts/jquery.min.js
/var/www/html/container/shared/assets/vendor/owl/owl.carousel.js
/var/www/html/container/shared/assets/vendor/scripts/jquery.form.js
/var/www/html/container/shared/assets/vendor/scripts/jquery-ui.js
/var/www/html/container/shared/assets/vendor/scripts/jquery.mask.js
/var/www/html/container/shared/assets/vendor/scripts/highcharts.js
/var/www/html/container/shared/assets/vendor/scripts/tracker.js
/var/www/html/container/shared/assets/vendor/scripts/validation.js
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
$(function () {
$(".j_menu_mobile_open").click(function (e) {
e.preventDefault();
$(".j_menu_mobile_tab").css("left", "auto").fadeIn(1).animate({"right": "0"}, 200);
});
$(".j_menu_mobile_close").click(function (e) {
e.preventDefault();
$(".j_menu_mobile_tab").animate({"left": "100%"}, 200, function () {
$(".j_menu_mobile_tab").css({
"right": "auto",
"display": "none"
});
});
});
$("[data-go]").click(function (e) {
e.preventDefault();
var goto = $($(this).data("go")).offset().top;
$("html, body").animate({scrollTop: goto}, goto / 2, "easeOutBounce");
});
$("[data-modal]").click(function (e) {
e.preventDefault();
var modal = $(this).data("modal");
$(modal).fadeIn(200).css("display", "flex");
});
$(".j_modal_close").click(function (e) {
e.preventDefault();
if ($(e.target).hasClass("j_modal_close")) {
$(".j_modal_close").fadeOut(200);
}
var iframe = $(this).find("iframe");
if (iframe) {
iframe.attr("src", iframe.attr("src"));
}
});
$(".mask-date").mask('00/00/0000');
$(".mask-datetime").mask('00/00/0000 00:00');
$(".mask-month").mask('00/0000', {reverse: true});
$(".mask-doc").mask('000.000.000-00', {reverse: true});
$(".mask-pj").mask('00.000.000/0000-00', {reverse: true});
$(".mask-card").mask('0000  0000  0000  0000', {reverse: true});
$(".mask-money").mask('000.000.000.000.000,00', {reverse: true, placeholder: "0,00"});
$(".mask-cep").mask('00000-000', {reverse: true});
$(".mask-phone").mask('00 0000-0000', {reverse: true});
$(".mask-cell").mask('00 00000-0000', {reverse: true});
$('#cpf-email').on('input', function() {
let input = $(this);
let cpfEmail = input.val();
let pjCpf = cpfEmail.length;
if(!cpfEmail.replace(/[^\d]/gi, '')) {
$("#cpf-email").unmask();
} else {
if(pjCpf < 14){
$("#cpf-email").mask('000.000.000-00', {reverse: true});
}else{
$("#cpf-email").mask('00.000.000/0000-00', {reverse: true});
}
}
});
$(".j_collapse").click(function () {
var collapse = $(this);
collapse.parents().find(".j_collapse_icon").removeClass("icon-minus").addClass("icon-plus");
collapse.find(".j_collapse_icon").removeClass("icon-plus").addClass("icon-minus");
if (collapse.find(".j_collapse_box").is(":visible")) {
collapse.find(".j_collapse_box").slideUp(200);
} else {
collapse.parent().find(".j_collapse_box").slideUp(200);
collapse.find(".j_collapse_box").slideDown(200);
}
});
$("form:not('.ajax_off')").submit(function (e) {
e.preventDefault();
var form = $(this);
var load = $(".ajax_load");
var flashClass = "ajax_response";
var flash = $("." + flashClass);
form.ajaxSubmit({
url: form.attr("action"),
type: "POST",
dataType: "json",
beforeSend: function () {
load.fadeIn(200).css("display", "flex");
},
success: function (response) {
if (response.redirect) {
window.location.href = response.redirect;
} else {
load.fadeOut(200);
}
if (response.message) {
if (flash.length) {
flash.html(response.message).fadeIn(100).effect("bounce", 300);
} else {
form.prepend("<div class='" + flashClass + "'>" + response.message + "</div>")
.find("." + flashClass).effect("bounce", 300);
}
} else {
flash.fadeOut(100);
}
},
complete: function () {
if (form.data("reset") === true) {
form.trigger("reset");
}
}
});
});
$(window).on("scroll", function () {
if ($(window).scrollTop() > 50) {
$(".main_header").addClass("main_header_fixed");
} else {
$(".main_header").removeClass("main_header_fixed");
}
});
$('.view-password').on('click', function (e) {
e.preventDefault();
var passwordField = $('#password');
var passrewordField = $('#password_re');
var passwordFieldType = passwordField.attr('type');
var passrewordFieldType = passrewordField.attr('type');
if (passwordFieldType == 'password' || passrewordFieldType == 'password_re') {
passwordField.attr('type', 'text');
passrewordField.attr('type', 'text');
$(this).attr("class", "view-password icon-notext icon-unlock-alt");
} else {
passwordField.attr('type', 'password');
passrewordField.attr('type', 'password');
$(this).attr("class", "view-password icon-notext icon-lock");
}
});
});
$(document).ready(function () {
$('a[href^="#"]').on('click', function (e) {
e.preventDefault();
const target = $(this).attr('href');
const headerHeight = 90;
$('html, body').animate({
scrollTop: $(target).offset().top - headerHeight
}, 800);
});
});
$(function () {
$(".cards--testimonials").each(function () {
const track = this;
const slides = Array.from(track.querySelectorAll("article"));
if (slides.length < 2 || track.dataset.carouselReady) return;
track.dataset.carouselReady = "true";
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