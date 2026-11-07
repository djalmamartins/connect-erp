/** Organic UI v2 - Tabs */
const instances=new WeakMap();
class OrganicTabs{
 constructor(root){this.root=root;this.tabs=[...root.querySelectorAll('[data-org-tab]')];this.panels=[...root.querySelectorAll('[data-org-tab-panel]')];instances.set(root,this);this.setup();this.bind()}
 setup(){this.root.setAttribute('role',this.root.getAttribute('role')||'tablist');this.tabs.forEach((t,i)=>{t.setAttribute('role','tab');t.tabIndex=t.classList.contains('is-active')||t.getAttribute('aria-selected')==='true'?0:-1;const id=t.dataset.orgTab;const p=this.panels.find(x=>x.dataset.orgTabPanel===id);if(p){p.setAttribute('role','tabpanel');if(!p.id)p.id=`org-tab-panel-${id}`;t.setAttribute('aria-controls',p.id)}});if(!this.tabs.some(t=>t.getAttribute('aria-selected')==='true'))this.activate(this.tabs.find(t=>t.classList.contains('is-active'))||this.tabs[0],false)}
 activate(target,focus=true){const tab=typeof target==='number'?this.tabs[target]:typeof target==='string'?this.tabs.find(x=>x.dataset.orgTab===target):target;if(!tab||tab.disabled)return;const key=tab.dataset.orgTab;this.tabs.forEach(t=>{const active=t===tab;t.classList.toggle('is-active',active);t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1});this.panels.forEach(p=>p.hidden=p.dataset.orgTabPanel!==key);if(focus)tab.focus();this.root.dispatchEvent(new CustomEvent('organic:tabs:change',{bubbles:true,detail:{tab:key}}))}
 bind(){this.tabs.forEach((t,i)=>{t.addEventListener('click',()=>this.activate(t,false));t.addEventListener('keydown',e=>{let n=null;if(e.key==='ArrowRight')n=(i+1)%this.tabs.length;if(e.key==='ArrowLeft')n=(i-1+this.tabs.length)%this.tabs.length;if(e.key==='Home')n=0;if(e.key==='End')n=this.tabs.length-1;if(n!==null){e.preventDefault();this.activate(n)}})})}
}
function init(root=document){root.querySelectorAll('[data-org-tabs]').forEach(el=>{if(!instances.has(el))new OrganicTabs(el)})}
function get(t){const el=typeof t==='string'?document.querySelector(t):t;return el?(instances.get(el)||new OrganicTabs(el)):null}
init();window.Organic=window.Organic||{};window.Organic.Tabs={init,get,activate:(r,t)=>get(r)?.activate(t)};
