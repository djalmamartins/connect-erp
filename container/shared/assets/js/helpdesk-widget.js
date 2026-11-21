(function(){
    'use strict';
    // Botão do SAC (ADR 0355, visual ADR 0631): cinza-claro com dourado; mostra quem está online no horário.
    if(window.__movesHelpDeskWidget)return;
    window.__movesHelpDeskWidget=true;
    var script=document.currentScript;
    if(!script)return;
    var source=new URL(script.src,window.location.href);
    var base=(script.dataset.base||source.href.replace(/\/container\/shared\/assets\/js\/helpdesk-widget\.js(?:\?.*)?$/,'')).replace(/\/$/,'');
    var side=script.dataset.position==='left'?'left':'right';
    var root=document.createElement('div');
    root.className='moves-helpdesk-embed moves-helpdesk-embed--'+side;
    root.innerHTML='<button class="moves-helpdesk-launcher" type="button" aria-label="Abrir o SAC" aria-expanded="false"><span class="moves-helpdesk-pill" hidden><span class="moves-helpdesk-faces" aria-hidden="true"></span><span class="moves-helpdesk-pill__text"><strong>Precisa de ajuda?</strong><small></small></span></span><img alt="" src="'+base+'/container/web/support/v1/assets/images/helpdesk-mark.svg"></button><section class="moves-helpdesk-panel" hidden><iframe title="SAC Connect" loading="lazy" src="'+base+'/suporte/widget?embed=1"></iframe></section>';
    var style=document.createElement('style');
    style.textContent='.moves-helpdesk-embed{position:fixed;z-index:2147483000;bottom:22px;'+side+':22px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.moves-helpdesk-launcher{display:flex;align-items:center;gap:10px;flex-direction:'+(side==='left'?'row-reverse':'row')+';padding:0;border:0;background:transparent;cursor:pointer;color:#1f2328}.moves-helpdesk-launcher>img{width:58px;height:58px;border-radius:18px;box-shadow:0 12px 32px rgba(15,23,42,.22);transition:transform .15s}.moves-helpdesk-launcher:hover>img{transform:translateY(-2px)}.moves-helpdesk-launcher:focus-visible{outline:2px solid #7a5c0c;outline-offset:4px;border-radius:18px}.moves-helpdesk-pill{display:flex;align-items:center;gap:8px;padding:7px 14px 7px 8px;border:1px solid #c5a131;border-radius:999px;background:#f3f4f6;box-shadow:0 10px 26px rgba(15,23,42,.16);text-align:left;opacity:0;transform:translateX('+(side==='left'?'-':'')+'8px);transition:opacity .2s,transform .2s;pointer-events:none}.moves-helpdesk-pill[hidden]{display:none}.moves-helpdesk-launcher:hover .moves-helpdesk-pill,.moves-helpdesk-launcher:focus-visible .moves-helpdesk-pill,.moves-helpdesk-embed.is-greeting .moves-helpdesk-pill{opacity:1;transform:none}.moves-helpdesk-faces{display:flex}.moves-helpdesk-faces span{width:30px;height:30px;margin-left:-8px;border:2px solid #fff;border-radius:50%;background:#faf5e4;color:#7a5c0c;display:grid;place-items:center;font:750 12px/1 inherit;overflow:hidden}.moves-helpdesk-faces span:first-child{margin-left:0}.moves-helpdesk-faces img{width:100%;height:100%;object-fit:cover}.moves-helpdesk-pill__text strong{display:block;font-size:13px}.moves-helpdesk-pill__text small{display:flex;align-items:center;gap:5px;color:#5d636b;font-size:11.5px}.moves-helpdesk-pill__text small:before{content:"";width:7px;height:7px;border-radius:50%;background:#22a35a}.moves-helpdesk-panel{position:absolute;bottom:72px;'+side+':0;width:min(390px,calc(100vw - 24px));height:min(660px,calc(100vh - 105px));overflow:hidden;border-radius:18px;background:#fff;border:1px solid #e3e5e8;box-shadow:0 22px 70px rgba(15,23,42,.28)}.moves-helpdesk-panel iframe{display:block;width:100%;height:100%;border:0}.moves-helpdesk-panel[hidden]{display:none}@media(max-width:520px){.moves-helpdesk-embed{bottom:12px;'+side+':12px}.moves-helpdesk-pill{display:none!important}.moves-helpdesk-panel{position:fixed;inset:12px 12px 82px;width:auto;height:auto;border-radius:16px}}@media(prefers-reduced-motion:reduce){.moves-helpdesk-pill,.moves-helpdesk-launcher>img{transition:none}}';
    document.head.appendChild(style);document.body.appendChild(root);
    var button=root.querySelector('.moves-helpdesk-launcher'),panel=root.querySelector('.moves-helpdesk-panel');
    var toggle=function(open){panel.hidden=!open;button.setAttribute('aria-expanded',String(open));if(open)root.classList.remove('is-greeting');};
    button.addEventListener('click',function(){toggle(panel.hidden)});
    window.addEventListener('message',function(event){if(event.origin===source.origin&&event.data&&event.data.type==='moves-helpdesk-close')toggle(false)});
    document.addEventListener('keydown',function(event){if(event.key==='Escape')toggle(false)});
    // Equipe online: só no horário e só quem autorizou (primeiro nome e foto). Fora do horário, nada aparece.
    if(!window.fetch)return;
    fetch(base+'/suporte/widget/equipe',{credentials:'omit'}).then(function(r){return r.ok?r.json():null}).then(function(data){
        if(!data||!data.open||!data.agents||!data.agents.length)return;
        var faces=root.querySelector('.moves-helpdesk-faces'),names=[];
        data.agents.forEach(function(agent){var face=document.createElement('span');if(agent.photo){var img=document.createElement('img');img.alt='';img.src=agent.photo;face.appendChild(img)}else{face.textContent=agent.initials||''}faces.appendChild(face);names.push(agent.name)});
        root.querySelector('.moves-helpdesk-pill__text small').textContent=(names.length>1?names.slice(0,-1).join(', ')+' e '+names[names.length-1]:names[0])+' online';
        root.querySelector('.moves-helpdesk-pill').hidden=false;
        button.setAttribute('aria-label','Abrir o SAC: '+names.join(', ')+' online');
        root.classList.add('is-greeting');setTimeout(function(){root.classList.remove('is-greeting')},6000);
    }).catch(function(){});
})();
