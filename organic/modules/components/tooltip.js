/** Organic UI v2 - Tooltip public API + accessibility */
function initTooltip(el){
  if(!el || el.dataset.orgTooltipReady) return el;
  el.dataset.orgTooltipReady='1';
  if(!el.hasAttribute('tabindex') && !/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) el.tabIndex=0;
  if(!el.getAttribute('aria-label') && el.dataset.orgTooltip) el.setAttribute('aria-label',el.dataset.orgTooltip);
  return el;
}
function scan(root=document){root.querySelectorAll('[data-org-tooltip]').forEach(initTooltip)}
scan();
new MutationObserver(ms=>ms.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType!==1)return;if(n.matches?.('[data-org-tooltip]'))initTooltip(n);scan(n)}))).observe(document.documentElement,{childList:true,subtree:true});
function set(target,text,position){const el=typeof target==='string'?document.querySelector(target):target;if(!el)return null;el.dataset.orgTooltip=text;if(position)el.dataset.orgTooltipPosition=position;initTooltip(el);return el}
function remove(target){const el=typeof target==='string'?document.querySelector(target):target;if(!el)return;delete el.dataset.orgTooltip;delete el.dataset.orgTooltipPosition;delete el.dataset.orgTooltipReady;el.removeAttribute('aria-label')}
window.Organic=window.Organic||{};window.Organic.Tooltip={init:initTooltip,set,remove,scan};
