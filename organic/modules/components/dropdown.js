/** Organic UI v2 - Dropdown */
const instances=new WeakMap();
class OrganicDropdown{
 constructor(element){this.element=element;this.trigger=element.querySelector('[data-org-dropdown-toggle]');this.menu=element.querySelector('.org-dropdown-menu');if(!this.trigger||!this.menu)return;instances.set(element,this);this.trigger.setAttribute('aria-haspopup','menu');this.trigger.setAttribute('aria-expanded','false');this.bind()}
 items(){return [...this.menu.querySelectorAll('a[href],button:not([disabled]),[role="menuitem"]')].filter(x=>!x.hidden)}
 open(focusFirst=false){document.querySelectorAll('[data-org-dropdown].is-open').forEach(x=>{if(x!==this.element)instances.get(x)?.close()});this.element.classList.add('is-open');this.trigger.setAttribute('aria-expanded','true');if(focusFirst)this.items()[0]?.focus();this.element.dispatchEvent(new CustomEvent('organic:dropdown:open',{bubbles:true}))}
 close(returnFocus=false){if(!this.element.classList.contains('is-open'))return;this.element.classList.remove('is-open');this.trigger.setAttribute('aria-expanded','false');if(returnFocus)this.trigger.focus();this.element.dispatchEvent(new CustomEvent('organic:dropdown:close',{bubbles:true}))}
 toggle(){this.element.classList.contains('is-open')?this.close():this.open()}
 bind(){this.trigger.addEventListener('click',e=>{e.stopPropagation();this.toggle()});this.trigger.addEventListener('keydown',e=>{if(['ArrowDown','Enter',' '].includes(e.key)){e.preventDefault();this.open(true)}});this.menu.addEventListener('keydown',e=>{const items=this.items(),i=items.indexOf(document.activeElement);if(e.key==='ArrowDown'){e.preventDefault();items[(i+1)%items.length]?.focus()}if(e.key==='ArrowUp'){e.preventDefault();items[(i-1+items.length)%items.length]?.focus()}if(e.key==='Home'){e.preventDefault();items[0]?.focus()}if(e.key==='End'){e.preventDefault();items.at(-1)?.focus()}if(e.key==='Escape'){e.preventDefault();this.close(true)}})}
}
function init(root=document){root.querySelectorAll('[data-org-dropdown]').forEach(el=>{if(!instances.has(el))new OrganicDropdown(el)})}
function get(target){const el=typeof target==='string'?document.querySelector(target):target;return el?(instances.get(el)||new OrganicDropdown(el)):null}
init();document.addEventListener('click',e=>document.querySelectorAll('[data-org-dropdown].is-open').forEach(x=>{if(!x.contains(e.target))instances.get(x)?.close()}));
window.Organic=window.Organic||{};window.Organic.Dropdown={init,get,open:t=>get(t)?.open(),close:t=>get(t)?.close(),toggle:t=>get(t)?.toggle()};
