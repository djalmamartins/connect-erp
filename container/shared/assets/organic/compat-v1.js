/** Organic UI v2 - v1 compatibility layer. Optional. */
window.Organic=window.Organic||{};Organic.ready=Organic.ready||function(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn()};Organic.v2=true;
function byId(id){return document.querySelector(`#${CSS.escape(id)},[data-organic-modal="${CSS.escape(id)}"]`)}
const legacy={
 Modal:{open(id){Organic.Modal?.open?Organic.Modal.open(id):byId(id)?.classList.add('is-open')},close(id){Organic.Modal?.close?Organic.Modal.close(id):byId(id)?.classList.remove('is-open')}},
 Editor:{init(selector='.organic-editor'){document.querySelectorAll(selector).forEach(el=>{if(window.tinymce)window.tinymce.init({target:el});else el.dataset.orgEditorReady='fallback'})}}
};
Organic.Legacy=legacy;
document.addEventListener('click',e=>{const o=e.target.closest('[data-organic-modal-open]');if(o)legacy.Modal.open(o.dataset.organicModalOpen);const c=e.target.closest('[data-organic-modal-close]');if(c)c.closest('.organic-modal')?.classList.remove('is-open')});
