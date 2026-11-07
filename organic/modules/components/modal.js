/** Organic UI v2 - Modal */
const instances=new WeakMap();
class OrganicModal{
  constructor(element){this.element=element;this.previousFocus=null;this.bound=false;instances.set(element,this);this.bindEvents()}
  getFocusable(){return [...this.element.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(x=>!x.hidden)}
  open(){this.previousFocus=document.activeElement;this.element.classList.add('is-open');this.element.setAttribute('aria-hidden','false');this.element.setAttribute('role',this.element.getAttribute('role')||'dialog');this.element.setAttribute('aria-modal','true');document.body.classList.add('org-modal-open');document.body.style.overflow='hidden';this.getFocusable()[0]?.focus();this.element.dispatchEvent(new CustomEvent('organic:modal:open',{bubbles:true}))}
  close(){if(!this.element.classList.contains('is-open'))return;this.element.classList.remove('is-open');this.element.setAttribute('aria-hidden','true');if(!document.querySelector('[data-org-modal].is-open')){document.body.classList.remove('org-modal-open');document.body.style.overflow=''};this.previousFocus?.focus?.();this.element.dispatchEvent(new CustomEvent('organic:modal:close',{bubbles:true}))}
  toggle(){this.element.classList.contains('is-open')?this.close():this.open()}
  bindEvents(){if(this.bound)return;this.bound=true;this.element.querySelectorAll('[data-org-modal-close]').forEach(b=>b.addEventListener('click',()=>this.close()));this.element.querySelector('.org-modal-backdrop')?.addEventListener('click',()=>{if(this.element.dataset.orgModalStatic===undefined)this.close()});this.element.addEventListener('keydown',e=>{if(e.key==='Escape'&&this.element.dataset.orgModalEscape!=='false'){e.preventDefault();this.close()}if(e.key==='Tab'&&this.element.classList.contains('is-open')){const f=this.getFocusable();if(!f.length)return;const first=f[0],last=f[f.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}})}
}
function resolve(target){const el=typeof target==='string'?document.querySelector(target.startsWith('#')?target:`#${CSS.escape(target)}`):target;return el?instances.get(el)||new OrganicModal(el):null}
function init(root=document){root.querySelectorAll('[data-org-modal]').forEach(el=>{if(!instances.has(el))new OrganicModal(el)})}
init();
document.addEventListener('click',e=>{const t=e.target.closest('[data-org-modal-open]');if(t){e.preventDefault();resolve(t.dataset.orgModalOpen)?.open()}});
window.Organic=window.Organic||{};window.Organic.Modal={init,get:resolve,open:t=>resolve(t)?.open(),close:t=>resolve(t)?.close(),toggle:t=>resolve(t)?.toggle()};
