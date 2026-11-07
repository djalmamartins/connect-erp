<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head ?>
    <?php $studioCss=dirname(__DIR__).'/assets/studio.min.css'; $studioJs=dirname(__DIR__).'/assets/studio.min.js'; ?>
    <link rel="stylesheet" href="<?= themeStudio('/assets/studio.min.css', 'default') . '?v=' . filemtime($studioCss) ?>">
    <link rel="icon" href="<?= themeStudio('/assets/images/favicon.png', 'default') ?>">
</head>
<?php $requestPath=(string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''); $isHelpDeskEnvironment=(bool)preg_match('~/helpdesk(?:/|$)~',$requestPath); ?>
<body class="studio-body studio-v2 studio-app-<?= htmlspecialchars($app) ?>" data-environment="<?= $isHelpDeskEnvironment?'helpdesk':'studio' ?>" data-editor-upload="<?= url('/studio/media/editor') ?>">
<a class="studio-skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="ajax_load studio-preloader" role="status" aria-live="polite"><div class="studio-preloader-card"><span class="studio-preloader-mark"><i></i><i></i><i></i></span><strong>MOVES<small>OS</small></strong><p class="ajax_load_box_title">Preparando seu painel</p><span class="studio-preloader-line"><i></i></span></div></div>
<div class="ajax_response"><?= flash() ?></div>
<div class="studio-shell">
    <aside class="studio-sidebar">
        <button class="studio-brand-v2 studio-system-toggle" type="button" aria-expanded="false" aria-controls="studio-system-panel">
            <img src="<?= themeStudio('/assets/images/studio-logo.svg', 'default') ?>" alt="<?= $isHelpDeskEnvironment?'Help Desk':'Studio' ?>">
            <?php if($isHelpDeskEnvironment): ?>
                <span>MOVES<small>Help Desk</small></span>
            <?php else: ?>
                <span>MOVES<small>Studio</small></span>
            <?php endif; ?>
            <ion-icon name="chevron-down-outline"></ion-icon>
        </button>
        <div class="studio-system-panel" id="studio-system-panel" hidden>
            <small>AMBIENTES MOVES</small>
            <?php $systems=[['Studio','Gestão de conteúdo e operação','/studio/dash','grid-outline','studio.access',!$isHelpDeskEnvironment],['ERP','Gestão administrativa','/erp','business-outline','erp.access',false],['Help Desk','Atendimento e chamados','/helpdesk','headset-outline','support.manage',$isHelpDeskEnvironment],['Moradores','Portal de moradores','/app','home-outline','app.access',false],['Site','Site institucional','/','globe-outline','site.access',false]]; foreach($systems as [$systemName,$systemDescription,$systemUrl,$systemIcon,$systemPermission,$current]): if(!$user->can($systemPermission))continue; ?><a href="<?= url($systemUrl) ?>" class="<?= $current?'current':'' ?>"><ion-icon name="<?= $systemIcon ?>"></ion-icon><span><strong><?= $systemName ?></strong><small><?= $systemDescription ?></small></span><?= $current?'<em>Atual</em>':'' ?></a><?php endforeach; ?>
        </div>
        <?php $nav = function(string $key,string $href,string $label,string $icon,string $permission) use($app,$user){if(!$user->can($permission))return '';$active=$app===$key?'active':''; return '<a class="'.$active.'" href="'.url($href).'" title="'.htmlspecialchars($label).'"><ion-icon name="'.$icon.'" class="studio-nav-icon"></ion-icon><span>'.$label.'</span></a>';}; ?>
        <nav class="studio-nav-v2">
            <?php if($isHelpDeskEnvironment): ?>
            <small>HELP DESK</small>
            <?= $nav('tickets','/helpdesk/tickets','Fila de chamados','headset-outline','support.manage') ?>
            <?= $nav('support','/helpdesk/support','Base de conhecimento','library-outline','support.manage') ?>
            <?= $nav('agenda','/helpdesk/agenda','Agenda da equipe','calendar-outline','support.manage') ?>
            <small>GESTÃO</small>
            <?= $nav('users','/helpdesk/users','Usuários','people-outline','users.manage') ?>
            <?= $nav('settings','/helpdesk/settings','Configurações','settings-outline','settings.manage') ?>
            <?= $nav('versions','/helpdesk/versions','Versões','pricetags-outline','settings.manage') ?>
            <?= $nav('system-logs','/helpdesk/system-logs','Log','bug-outline','logs.view') ?>
            <small>AMBIENTE</small>
            <?= $nav('dash','/studio/dash','Voltar ao Studio','arrow-back-outline','dashboard.view') ?>
            <?php else: ?>
            <small>VISÃO GERAL</small>
            <?= $nav('dash','/studio/dash','Dashboard','grid-outline','dashboard.view') ?>
            <?= $nav('reports','/studio/reports','Relatórios','stats-chart-outline','reports.view') ?>
            <?= $nav('notifications','/studio/notifications','Notificações','notifications-outline','notifications.manage') ?>
            <?= $nav('proposals','/studio/proposals','Propostas','document-text-outline','proposals.manage') ?>
            <small>CONTEÚDO</small>
            <?= $nav('pages','/studio/pages','Páginas','documents-outline','pages.manage') ?>
            <?= $nav('blog','/studio/blog/home','Artigos','newspaper-outline','articles.manage') ?>
            <?= $nav('media','/studio/media','Mídia','images-outline','media.manage') ?>
            <?= $nav('slides','/studio/slides','Destaques','albums-outline','slides.manage') ?>
            <?= $nav('testimonials','/studio/testimonials','Depoimentos','chatbox-ellipses-outline','testimonials.manage') ?>
            <?= $nav('faqs','/studio/faqs','FAQ','help-circle-outline','faqs.manage') ?>
            <small>GESTÃO</small>
            <?= $nav('users','/studio/users','Usuários','people-outline','users.manage') ?>
            <?= $nav('settings','/studio/settings','Configurações','settings-outline','settings.manage') ?>
            <?= $nav('versions','/studio/versions','Versões','pricetags-outline','settings.manage') ?>
            <?= $nav('system-logs','/studio/system-logs','Log','bug-outline','logs.view') ?>
            <?php endif; ?>
        </nav>
        <div class="studio-sidebar-footer"><a href="<?= url('/') ?>" target="_blank"><ion-icon name="open-outline"></ion-icon><span>Visualizar site</span></a><a href="<?= url('/studio/sair') ?>"><ion-icon name="log-out-outline"></ion-icon><span>Sair</span></a></div>
    </aside>
    <button class="studio-sidebar-backdrop" type="button" aria-label="Fechar menu"></button>
    <main class="studio-main" id="main-content" tabindex="-1">
        <header class="studio-topbar">
            <button class="studio-menu" type="button" aria-label="Abrir menu"><ion-icon name="apps-outline"></ion-icon></button>
            <form class="studio-global-search" action="<?= url('/studio/buscar') ?>" method="get"><ion-icon name="search-outline"></ion-icon><input name="q" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" placeholder="Buscar páginas, artigos, categorias ou usuários..." autocomplete="off"></form>
            <nav class="studio-header-actions" aria-label="Atalhos do painel">
                <button class="studio-theme-toggle" type="button" title="Alternar tema" aria-label="Ativar modo escuro" aria-pressed="false"><ion-icon name="moon-outline"></ion-icon></button>
                <a class="studio-context-help" href="<?= $app==='notifications' ? url('/suporte/comunicacao/como-cadastrar-uma-comunicacao') : url('/suporte/buscar?q='.urlencode($title)) ?>" target="_blank" title="Ajuda sobre esta tela" aria-label="Abrir ajuda sobre esta tela"><ion-icon name="help-circle-outline"></ion-icon></a>
                <a href="<?= url('/') ?>" target="_blank" title="Visualizar site"><ion-icon name="globe-outline"></ion-icon></a>
                <div class="studio-notifications">
                    <button class="studio-notification-toggle" type="button" title="Notificações" data-count="<?= url('/studio/notifications/count') ?>" data-list="<?= url('/studio/notifications/list') ?>"><ion-icon name="notifications-outline"></ion-icon><small>0</small></button>
                    <div class="studio-notification-panel"><header><span><strong>Notificações</strong><small>Atualizações do sistema</small></span><a href="<?= url('/studio/notifications') ?>">Gerenciar</a></header><div role="status" aria-live="polite"><p>Não há notificações.</p></div></div>
                </div>
                <?php $headerPhoto=$user->photo()?image($user->photo(),160,160):themeStudio('/assets/images/avatar.jpg','default'); $headerRole=$user->accessRole(); ?>
                <div class="studio-header-profile" role="button" tabindex="0" aria-haspopup="menu" aria-expanded="false">
                    <img src="<?= $headerPhoto ?>" alt="<?= htmlspecialchars($user->fullName()) ?>">
                    <span><strong><?= htmlspecialchars($user->fullName()) ?></strong><small>Administrador</small></span>
                    <ion-icon name="chevron-down-outline"></ion-icon>
                    <div class="studio-profile-menu" role="menu"><header><img src="<?= $headerPhoto ?>" alt=""><span><strong><?= htmlspecialchars($user->fullName()) ?></strong><small><?= htmlspecialchars((string)$user->email) ?></small></span></header><nav><a role="menuitem" href="<?= url('/studio/user/'.$user->id) ?>"><ion-icon name="person-outline"></ion-icon><span>Minha conta</span></a><a role="menuitem" href="<?= $user->can('settings.manage')?url('/studio/settings'):url('/studio/user/'.$user->id.'#preferencias') ?>"><ion-icon name="options-outline"></ion-icon><span>Preferências</span></a><a role="menuitem" class="danger" href="<?= url('/studio/sair') ?>"><ion-icon name="log-out-outline"></ion-icon><span>Sair</span></a></nav></div>
                </div>
            </nav>
        </header>
        <div class="studio-content"><?= $this->section('content') ?></div>
        <footer class="studio-footer"><span>Copyright © <?= date('Y') ?> MovesOS. Todos os direitos reservados.</span><nav><a class="studio-footer-version" href="<?= url('/studio/versions?product=studio') ?>">Studio v<?= htmlspecialchars($currentVersion ?? VERSION_STUDIO) ?></a></nav></footer>
    </main>
</div>
<div class="studio-library-picker" id="studio-library-picker" data-library-url="<?= url('/studio/media/library') ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="studio-library-title"><div class="studio-library-dialog"><header><div><small>BIBLIOTECA DE MÍDIA</small><h2 id="studio-library-title">Escolha uma imagem</h2></div><button type="button" data-library-close aria-label="Fechar"><ion-icon name="close-outline"></ion-icon></button></header><label class="studio-library-search"><ion-icon name="search-outline"></ion-icon><input type="search" placeholder="Buscar pelo nome do arquivo" data-library-search></label><div class="studio-library-grid" data-library-grid><p>Carregando imagens...</p></div><footer><span data-library-count></span><a class="studio-btn" href="<?= url('/studio/media') ?>" target="_blank"><ion-icon name="images-outline"></ion-icon>Abrir biblioteca completa</a></footer></div></div>
<div class="studio-notification-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="studio-notification-modal-title">
    <div class="studio-notification-modal-card">
        <?= csrf_input() ?>
        <button class="studio-notification-modal-x" type="button" aria-label="Ler mais tarde"><ion-icon name="close-outline"></ion-icon></button>
        <i><ion-icon name="notifications-outline"></ion-icon></i>
        <small data-notification-date></small>
        <h2 id="studio-notification-modal-title" data-notification-title>Notificação</h2>
        <p data-notification-body></p>
        <div class="studio-notification-modal-actions">
            <button type="button" data-notification-later title="Ler mais tarde" aria-label="Ler mais tarde"><ion-icon name="time-outline"></ion-icon></button>
            <button type="button" class="danger" data-notification-delete title="Excluir notificação" aria-label="Excluir notificação"><ion-icon name="trash-outline"></ion-icon></button>
            <button type="button" class="primary" data-notification-open title="Ver conteúdo" aria-label="Ver conteúdo"><ion-icon name="arrow-forward-outline"></ion-icon></button>
        </div>
    </div>
</div>
<audio id="studio-notification-sound" preload="auto" src="<?= themeStudio('/assets/audio/notification.mp3', 'default') ?>"></audio>
<script src="<?= url('/container/shared/assets/vendor/scripts/tinymce/tinymce.min.js') ?>"></script>
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= themeStudio('/assets/studio.min.js', 'default') . '?v=' . filemtime($studioJs) ?>"></script>
<script src="<?= url('/container/shared/assets/js/helpdesk-widget.js') ?>" data-moves-helpdesk-widget defer></script>
<?php $editorJs=dirname(__DIR__).'/assets/js/editor.js'; ?>
<script type="module" src="<?= themeStudio('/assets/js/editor.js', 'default') . '?v=' . filemtime($editorJs) ?>"></script>
<script>
const studioThemeButton=document.querySelector('.studio-theme-toggle');
const studioApplyTheme=theme=>{document.documentElement.dataset.theme=theme;localStorage.setItem('studio-theme',theme);const dark=theme==='dark';studioThemeButton?.setAttribute('aria-pressed',String(dark));studioThemeButton?.setAttribute('aria-label',dark?'Ativar modo claro':'Ativar modo escuro');studioThemeButton?.querySelector('ion-icon')?.setAttribute('name',dark?'sunny-outline':'moon-outline')};
studioApplyTheme(localStorage.getItem('studio-theme')||'light');
studioThemeButton?.addEventListener('click',()=>studioApplyTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
const studioMenuButton=document.querySelector('.studio-menu');
const studioIsMobile=()=>window.matchMedia('(max-width:1024px)').matches;
const studioSetMenuState=()=>studioMenuButton?.setAttribute('aria-expanded',studioIsMobile()?String(document.body.classList.contains('menu-open')):String(!document.body.classList.contains('menu-collapsed')));
if(!studioIsMobile()&&localStorage.getItem('studio-menu-collapsed')==='1')document.body.classList.add('menu-collapsed');
studioMenuButton?.addEventListener('click',()=>{if(studioIsMobile()){document.body.classList.toggle('menu-open')}else{document.body.classList.toggle('menu-collapsed');localStorage.setItem('studio-menu-collapsed',document.body.classList.contains('menu-collapsed')?'1':'0')}studioSetMenuState()});
document.querySelector('.studio-sidebar-backdrop')?.addEventListener('click',()=>{document.body.classList.remove('menu-open');studioSetMenuState()});
window.addEventListener('resize',()=>{if(!studioIsMobile())document.body.classList.remove('menu-open');studioSetMenuState()});
studioSetMenuState();
const studioSystemToggle=document.querySelector('.studio-system-toggle'),studioSystemPanel=document.getElementById('studio-system-panel');if(studioSystemPanel)document.body.append(studioSystemPanel);const studioPositionSystemPanel=()=>{if(!studioSystemToggle||!studioSystemPanel)return;const rect=studioSystemToggle.getBoundingClientRect();studioSystemPanel.style.left=Math.max(10,rect.left)+'px';studioSystemPanel.style.top=(rect.bottom+6)+'px'};studioSystemToggle?.addEventListener('click',event=>{event.stopPropagation();const open=studioSystemPanel.hidden;if(open)studioPositionSystemPanel();studioSystemPanel.hidden=!open;studioSystemToggle.setAttribute('aria-expanded',String(open))});window.addEventListener('resize',()=>{if(studioSystemPanel&&!studioSystemPanel.hidden)studioPositionSystemPanel()});
const studioProfile=document.querySelector('.studio-header-profile');
const studioToggleProfile=()=>{const open=!studioProfile?.classList.contains('open');studioProfile?.classList.toggle('open',open);studioProfile?.setAttribute('aria-expanded',String(open))};
studioProfile?.addEventListener('click',e=>{if(!e.target.closest('a'))studioToggleProfile()});
studioProfile?.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();studioToggleProfile()}if(e.key==='Escape'){studioProfile.classList.remove('open');studioProfile.setAttribute('aria-expanded','false')}});
const studioNotifyButton=document.querySelector('.studio-notification-toggle');
const studioNotifyPanel=document.querySelector('.studio-notification-panel');
const studioNotifyModal=document.querySelector('.studio-notification-modal');
const studioNotifySound=document.getElementById('studio-notification-sound');
let studioLastNotificationCount=null;
let studioActiveNotification=null;
const studioHideNotifyModal=()=>{studioNotifyModal?.classList.remove('open');studioNotifyModal?.setAttribute('aria-hidden','true');studioActiveNotification=null};
const studioShowNotifyModal=item=>{studioActiveNotification=item;studioNotifyModal.querySelector('[data-notification-title]').textContent=item.title;studioNotifyModal.querySelector('[data-notification-body]').textContent=item.body||'Sem detalhes adicionais.';studioNotifyModal.querySelector('[data-notification-date]').textContent=item.created_at;studioNotifyModal.querySelector('i').className=item.severity||'info';studioNotifyModal.querySelector('[data-notification-open]').hidden=!item.content_url;studioNotifyModal.classList.add('open');studioNotifyModal.setAttribute('aria-hidden','false');studioNotifyPanel?.classList.remove('open');studioNotifyModal.querySelector('[data-notification-later]')?.focus()};
const studioNotifyAction=action=>{if(!studioActiveNotification)return;const form=new FormData();form.append('csrf',studioNotifyModal.querySelector('[name="csrf"]').value);form.append('action',action);fetch(studioActiveNotification.action_url,{method:'POST',body:form,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(data=>{if(data.redirect){window.location.href=data.redirect;return}studioHideNotifyModal();studioLoadNotifyCount()}).catch(()=>{})};
const studioLoadNotifyCount=()=>studioNotifyButton&&fetch(studioNotifyButton.dataset.count,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(data=>{const nextCount=Number(data.count||0);studioNotifyButton.querySelector('small').textContent=nextCount;if(studioLastNotificationCount!==null&&nextCount>studioLastNotificationCount){studioNotifySound?.play().catch(()=>{})}studioLastNotificationCount=nextCount}).catch(()=>{});
studioNotifyButton?.addEventListener('click',e=>{e.stopPropagation();studioNotifyPanel?.classList.toggle('open');if(!studioNotifyPanel?.classList.contains('open'))return;fetch(studioNotifyButton.dataset.list,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(data=>{const box=studioNotifyPanel.querySelector('div');box.innerHTML='';if(!data.notifications?.length){box.innerHTML='<p>Não há notificações.</p>';return}data.notifications.forEach(item=>{const button=document.createElement('button');button.type='button';button.className=`${item.view?'':'unread'} ${item.severity}`;button.innerHTML=`<ion-icon name="${item.severity==='warning'?'warning-outline':item.severity==='error'?'alert-circle-outline':'notifications-outline'}"></ion-icon><span><strong></strong><em></em><small></small></span>`;button.querySelector('strong').textContent=item.title;button.querySelector('em').textContent=item.body||'';button.querySelector('small').textContent=item.created_at;button.addEventListener('click',()=>studioShowNotifyModal(item));box.appendChild(button)})}).catch(()=>{})});
document.addEventListener('click',e=>{if(!e.target.closest('.studio-notifications'))studioNotifyPanel?.classList.remove('open');if(!e.target.closest('.studio-header-profile')){studioProfile?.classList.remove('open');studioProfile?.setAttribute('aria-expanded','false')}if(!e.target.closest('.studio-system-panel')&&!e.target.closest('.studio-system-toggle')){if(studioSystemPanel)studioSystemPanel.hidden=true;studioSystemToggle?.setAttribute('aria-expanded','false')}});
studioNotifyModal?.querySelector('[data-notification-later]')?.addEventListener('click',studioHideNotifyModal);
studioNotifyModal?.querySelector('.studio-notification-modal-x')?.addEventListener('click',studioHideNotifyModal);
studioNotifyModal?.querySelector('[data-notification-delete]')?.addEventListener('click',()=>studioNotifyAction('delete'));
studioNotifyModal?.querySelector('[data-notification-open]')?.addEventListener('click',()=>studioNotifyAction('open'));
studioNotifyModal?.addEventListener('click',e=>{if(e.target===studioNotifyModal)studioHideNotifyModal()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&studioNotifyModal?.classList.contains('open'))studioHideNotifyModal()});
studioLoadNotifyCount();setInterval(studioLoadNotifyCount,50000);
document.querySelectorAll('[data-confirm-submit]').forEach(b=>b.addEventListener('click',e=>{if(!confirm(b.dataset.confirmSubmit))e.preventDefault()}));
const studioCepInputs=[...document.querySelectorAll('input[name="site_code"],input[name="code"]')];
studioCepInputs.forEach(input=>{let lastCep='';input.addEventListener('input',()=>{const digits=input.value.replace(/\D/g,'').slice(0,8);input.value=digits.length>5?digits.slice(0,5)+'-'+digits.slice(5):digits;if(digits.length!==8||digits===lastCep)return;lastCep=digits;input.setAttribute('aria-busy','true');fetch('https://viacep.com.br/ws/'+digits+'/json/').then(response=>response.ok?response.json():Promise.reject()).then(address=>{if(address.erro)throw new Error('CEP');const form=input.closest('form');const prefix=input.name==='site_code'?'site_':'';const values={street:address.logradouro,district:address.bairro,city:address.localidade,state:address.uf};Object.entries(values).forEach(([name,value])=>{const field=form?.querySelector('[name="'+prefix+name+'"]');if(field&&value)field.value=value});form?.querySelector('[name="'+prefix+'number"]')?.focus()}).catch(()=>{lastCep='';input.setCustomValidity('CEP não encontrado.');input.reportValidity();setTimeout(()=>input.setCustomValidity(''),2500)}).finally(()=>input.removeAttribute('aria-busy'))})});
const studioUserPhotoInput=document.querySelector('[data-user-photo-input]'),studioUserPhotoDrop=document.querySelector('[data-user-photo-drop]'),studioUserPhotoPreview=document.querySelector('[data-user-photo-preview]'),studioUserPhotoName=document.querySelector('[data-user-photo-name]'),studioUserPhotoRemove=document.querySelector('[data-user-photo-remove]');
const studioPreviewUserPhoto=file=>{if(!file)return;if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>3145728){studioUserPhotoInput.value='';studioUserPhotoName.textContent=file.size>3145728?'Arquivo maior que 3 MB':'Formato não permitido';studioUserPhotoDrop.classList.add('invalid');return}studioUserPhotoDrop.classList.remove('invalid');studioUserPhotoName.textContent=file.name+' · '+(file.size/1048576).toFixed(2)+' MB';studioUserPhotoPreview.src=URL.createObjectURL(file);if(studioUserPhotoRemove)studioUserPhotoRemove.checked=false};
studioUserPhotoInput?.addEventListener('change',()=>studioPreviewUserPhoto(studioUserPhotoInput.files[0]));['dragenter','dragover'].forEach(eventName=>studioUserPhotoDrop?.addEventListener(eventName,event=>{event.preventDefault();studioUserPhotoDrop.classList.add('dragging')}));['dragleave','drop'].forEach(eventName=>studioUserPhotoDrop?.addEventListener(eventName,event=>{event.preventDefault();studioUserPhotoDrop.classList.remove('dragging')}));studioUserPhotoDrop?.addEventListener('drop',event=>{const file=event.dataTransfer.files[0];if(!file)return;const transfer=new DataTransfer();transfer.items.add(file);studioUserPhotoInput.files=transfer.files;studioPreviewUserPhoto(file)});
</script>
<?= $this->section('scripts') ?>
</body>
</html>
