<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head ?>
    <?php $studioCss=dirname(__DIR__).'/assets/studio.min.css'; $studioJs=dirname(__DIR__).'/assets/studio.min.js'; ?>
    <link rel="stylesheet" href="<?= themeStudio('/assets/studio.min.css', 'default') . '?v=' . filemtime($studioCss) ?>">
    <?php /* Camada visual do ERP sobre os componentes do Studio e do Help Desk (ADR 0026). */ $connectShellCss=dirname(__DIR__).'/assets/css/connect-shell.css'; ?>
    <link rel="stylesheet" href="<?= themeStudio('/assets/css/connect-shell.css', 'default') . '?v=' . filemtime($connectShellCss) ?>">
    <link rel="icon" href="<?= theme('/assets/images/favicon-32x32.png') ?>">
</head>
<?php
$requestPath=(string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$isHelpDeskEnvironment=(bool)preg_match('~/helpdesk(?:/|$)~',$requestPath);
$environmentName=$isHelpDeskEnvironment?'Help Desk':'Studio';
$environmentHome=$isHelpDeskEnvironment?'/helpdesk/tickets':'/studio/dash';
$userInitial=mb_strtoupper(mb_substr(trim((string)($user->first_name ?? $user->fullName() ?? 'C')),0,1));
$siteHome=function_exists('site_home_url')?site_home_url():url('/');
?>
<body class="studio-body studio-v2 studio-erp studio-app-<?= htmlspecialchars($app) ?>" data-environment="<?= $isHelpDeskEnvironment?'helpdesk':'studio' ?>" data-editor-upload="<?= url('/studio/media/editor') ?>">
<a class="studio-skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="ajax_load studio-preloader" role="status" aria-live="polite"><div class="studio-preloader-card"><span class="studio-preloader-mark"><i></i><i></i><i></i></span><strong>MOVES<small><?= $environmentName ?></small></strong><p class="ajax_load_box_title">Preparando seu painel</p><span class="studio-preloader-line"><i></i></span></div></div>
<div class="ajax_response"><?= flash() ?></div>
<div class="studio-shell">
    <aside class="studio-sidebar" id="studio-sidebar" aria-label="Navegação do <?= $environmentName ?>">
        <div class="studio-erp-sidebar-head">
            <a class="studio-erp-brand" href="<?= url($environmentHome) ?>" aria-label="Moves <?= $environmentName ?> — início">
                <img class="studio-erp-brand-logo" src="<?= themeStudio('/assets/images/moves-logo-white.svg', 'default') ?>" alt="Moves <?= $environmentName ?>" width="132" height="19">
                <img class="studio-erp-brand-mark" src="<?= themeStudio('/assets/images/moves-mark-white.svg', 'default') ?>" alt="" width="34" height="24">
            </a>
            <button class="studio-erp-collapse" type="button" data-studio-collapse aria-label="Recolher menu" aria-controls="studio-sidebar" aria-expanded="true"><ion-icon name="chevron-back-outline"></ion-icon></button>
        </div>
        <button class="studio-brand-v2 studio-system-toggle studio-erp-context" type="button" aria-expanded="false" aria-controls="studio-system-panel" title="Trocar de ambiente">
            <span><small><?= $isHelpDeskEnvironment ? 'Atendimento' : 'CMS' ?></small><strong>Moves <?= $environmentName ?></strong></span>
            <ion-icon name="chevron-down-outline"></ion-icon>
        </button>
        <div class="studio-system-panel" id="studio-system-panel" hidden>
            <small>AMBIENTES CONNECT</small>
            <?php /* Alternador único (ADR 0034): Source\Services\Environments decide quem vê o quê e liga os hosts. */ foreach(\Source\Services\Environments::menu($user,$isHelpDeskEnvironment?'helpdesk':'studio') as $system): $current=$system['current']; ?><a href="<?= htmlspecialchars($system['href']) ?>" class="<?= $current?'current':'' ?>"><ion-icon name="<?= htmlspecialchars($system['icon']) ?>"></ion-icon><span><strong><?= htmlspecialchars($system['label']) ?></strong><small><?= htmlspecialchars($system['description']) ?></small></span><?= $current?'<em>Atual</em>':'' ?></a><?php endforeach; ?>
        </div>
        <?php
        // Menu no desenho do ERP: links diretos e grupos em <details> (abertos quando contêm a página atual).
        // Cada item: [chave do app, rota, rótulo, ícone, permissão]. A rota valida a permissão de novo.
        $menu = $isHelpDeskEnvironment ? [
            ['link', ['tickets','/helpdesk/tickets','Fila de chamados','headset-outline','support.manage']],
            ['group', 'Atendimento', 'library-outline', [
                ['support','/helpdesk/support','Base de conhecimento','library-outline','support.manage'],
                ['agenda','/helpdesk/agenda','Agenda da equipe','calendar-outline','support.manage'],
            ]],
            ['group', 'Gestão', 'settings-outline', [
                ['users','/helpdesk/users','Usuários','people-outline','users.manage'],
                ['settings','/helpdesk/settings','Configurações','settings-outline','settings.manage'],
                ['versions','/helpdesk/versions','Versões','pricetags-outline','settings.manage'],
                ['system-logs','/helpdesk/system-logs','Log','bug-outline','logs.view'],
            ]],
            ['link', ['dash','/studio/dash','Voltar ao Studio','arrow-back-outline','dashboard.view']],
        ] : [
            ['link', ['dash','/studio/dash','Dashboard','grid-outline','dashboard.view']],
            ['group', 'Visão geral', 'stats-chart-outline', [
                ['reports','/studio/reports','Relatórios','stats-chart-outline','reports.view'],
                ['notifications','/studio/notifications','Notificações','notifications-outline','notifications.manage'],
                ['proposals','/studio/proposals','Propostas','document-text-outline','proposals.manage'],
            ]],
            ['group', 'Conteúdo', 'documents-outline', [
                ['pages','/studio/pages','Páginas','documents-outline','pages.manage'],
                ['blog','/studio/blog/home','Artigos','newspaper-outline','articles.manage'],
                ['media','/studio/media','Mídia','images-outline','media.manage'],
                ['slides','/studio/slides','Destaques','albums-outline','slides.manage'],
                ['testimonials','/studio/testimonials','Depoimentos','chatbox-ellipses-outline','testimonials.manage'],
                ['faqs','/studio/faqs','FAQ','help-circle-outline','faqs.manage'],
            ]],
            ['group', 'Gestão', 'settings-outline', [
                ['users','/studio/users','Usuários','people-outline','users.manage'],
                ['settings','/studio/settings','Configurações','settings-outline','settings.manage'],
                ['versions','/studio/versions','Versões','pricetags-outline','settings.manage'],
                ['system-logs','/studio/system-logs','Log','bug-outline','logs.view'],
            ]],
        ];
        $menuCurrent = static fn(string $key): string => $app === $key ? ' aria-current="page"' : '';
        ?>
        <nav class="studio-erp-nav" aria-label="Menu principal">
            <?php foreach ($menu as $entry):
                if ($entry[0] === 'link'):
                    [$key, $href, $label, $icon, $permission] = $entry[1];
                    if (!$user->can($permission)) continue; ?>
                    <a class="studio-erp-nav-link" href="<?= url($href) ?>"<?= $menuCurrent($key) ?> title="<?= htmlspecialchars($label) ?>"><ion-icon name="<?= $icon ?>" aria-hidden="true"></ion-icon><span class="studio-erp-nav-label"><?= htmlspecialchars($label) ?></span></a>
                <?php continue; endif;
                [, $groupLabel, $groupIcon, $items] = $entry;
                $items = array_values(array_filter($items, static fn(array $item): bool => $user->can($item[4])));
                if (!$items) continue;
                $groupActive = in_array($app, array_column($items, 0), true); ?>
                <details class="studio-erp-nav-group"<?= $groupActive ? ' open' : '' ?>>
                    <summary<?= $groupActive ? ' class="is-active"' : '' ?> title="<?= htmlspecialchars($groupLabel) ?>"><ion-icon name="<?= $groupIcon ?>" aria-hidden="true"></ion-icon><span class="studio-erp-nav-label"><?= htmlspecialchars($groupLabel) ?></span></summary>
                    <div class="studio-erp-nav-sub">
                        <?php foreach ($items as [$key, $href, $label]): ?>
                            <a href="<?= url($href) ?>"<?= $menuCurrent($key) ?>><?= htmlspecialchars($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>
        <div class="studio-sidebar-footer studio-erp-sidebar-foot">
            <a class="studio-erp-profile" href="<?= url(($isHelpDeskEnvironment?'/helpdesk':'/studio').'/user/'.$user->id) ?>" title="Minha conta"><span class="studio-erp-avatar" aria-hidden="true"><?= htmlspecialchars($userInitial) ?></span><span><strong><?= htmlspecialchars($user->fullName()) ?></strong><small>Connect · administradora</small></span></a>
        </div>
    </aside>
    <button class="studio-sidebar-backdrop" type="button" aria-label="Fechar menu"></button>
    <main class="studio-main" id="main-content" tabindex="-1">
        <header class="studio-topbar studio-erp-navbar">
            <button class="studio-menu" type="button" aria-label="Abrir menu" aria-controls="studio-sidebar"><ion-icon name="menu-outline"></ion-icon></button>
            <div class="studio-erp-title"><strong><?= htmlspecialchars((string)($title ?? $environmentName)) ?></strong><nav aria-label="Você está em"><a href="<?= url($environmentHome) ?>"><?= $environmentName ?></a><span aria-hidden="true">/</span><span aria-current="page"><?= htmlspecialchars((string)($title ?? '')) ?></span></nav></div>
            <form class="studio-global-search" action="<?= url('/studio/buscar') ?>" method="get" role="search"><ion-icon name="search-outline"></ion-icon><label class="studio-erp-sr-only" for="studio-global-search-input">Buscar no <?= $environmentName ?></label><input id="studio-global-search-input" name="q" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" placeholder="Buscar páginas, artigos, categorias ou usuários..." autocomplete="off"></form>
            <nav class="studio-header-actions" aria-label="Atalhos do painel">
                <a class="studio-context-help" href="<?= rtrim($siteHome,'/') . ($app==='notifications' ? '/suporte/comunicacao/como-cadastrar-uma-comunicacao' : '/suporte/buscar?q='.urlencode((string)$title)) ?>" target="_blank" title="Ajuda sobre esta tela" aria-label="Abrir ajuda sobre esta tela"><ion-icon name="help-circle-outline"></ion-icon></a>
                <div class="studio-notifications">
                    <button class="studio-notification-toggle" type="button" title="Notificações" aria-label="Notificações" data-count="<?= url('/studio/notifications/count') ?>" data-list="<?= url('/studio/notifications/list') ?>"><ion-icon name="notifications-outline"></ion-icon><small>0</small></button>
                    <div class="studio-notification-panel"><header><span><strong>Notificações</strong><small>Atualizações do sistema</small></span><a href="<?= url('/studio/notifications') ?>">Gerenciar</a></header><div role="status" aria-live="polite"><p>Não há notificações.</p></div></div>
                </div>
                <?php if($user->can('settings.manage')): ?><a href="<?= url(($isHelpDeskEnvironment?'/helpdesk':'/studio').'/settings') ?>" title="Configurações" aria-label="Configurações"><ion-icon name="settings-outline"></ion-icon></a><?php endif; ?>
                <?php $headerPhoto=$user->photo()?image($user->photo(),160,160):themeStudio('/assets/images/avatar.jpg','default'); ?>
                <div class="studio-header-profile" role="button" tabindex="0" aria-haspopup="menu" aria-expanded="false" aria-label="Menu do usuário">
                    <span class="studio-erp-avatar" aria-hidden="true"><?= htmlspecialchars($userInitial) ?></span>
                    <span><strong><?= htmlspecialchars((string)($user->first_name ?? $user->fullName())) ?></strong></span>
                    <ion-icon name="chevron-down-outline"></ion-icon>
                    <div class="studio-profile-menu" role="menu"><header><img src="<?= $headerPhoto ?>" alt=""><span><strong><?= htmlspecialchars($user->fullName()) ?></strong><small><?= htmlspecialchars((string)$user->email) ?></small></span></header><nav><a role="menuitem" href="<?= url('/studio/user/'.$user->id) ?>"><ion-icon name="person-outline"></ion-icon><span>Minha conta</span></a><a role="menuitem" href="<?= url('/studio/seguranca/2fa') ?>"><ion-icon name="shield-checkmark-outline"></ion-icon><span>Segurança e 2FA</span></a><a role="menuitem" href="<?= $user->can('settings.manage')?url('/studio/settings'):url('/studio/user/'.$user->id.'#preferencias') ?>"><ion-icon name="options-outline"></ion-icon><span>Preferências</span></a><a role="menuitem" href="<?= $siteHome ?>" target="_blank"><ion-icon name="globe-outline"></ion-icon><span>Visualizar site</span></a><a role="menuitem" class="danger" href="<?= url(($isHelpDeskEnvironment?'/helpdesk':'/studio').'/sair') ?>"><ion-icon name="log-out-outline"></ion-icon><span>Sair</span></a></nav></div>
                </div>
            </nav>
        </header>
        <div class="studio-content"><?= $this->section('content') ?></div>
        <footer class="studio-footer"><span>© <?= date('Y') ?> Connect Condomínios</span><nav><a class="studio-footer-version" href="<?= url('/studio/versions?product=studio') ?>"><?= $environmentName ?> v<?= htmlspecialchars($currentVersion ?? VERSION_STUDIO) ?></a></nav></footer>
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
// O ERP não tem modo escuro; o Studio e o Help Desk seguem a mesma moldura clara (ADR 0026).
document.documentElement.dataset.theme='light';try{localStorage.removeItem('studio-theme')}catch(e){}
const studioMenuButton=document.querySelector('.studio-menu');
const studioIsMobile=()=>window.matchMedia('(max-width:1024px)').matches;
const studioSetMenuState=()=>{studioMenuButton?.setAttribute('aria-expanded',studioIsMobile()?String(document.body.classList.contains('menu-open')):String(!document.body.classList.contains('menu-collapsed')));const collapse=document.querySelector('[data-studio-collapse]');if(collapse){const collapsed=!studioIsMobile()&&document.body.classList.contains('menu-collapsed');collapse.setAttribute('aria-expanded',String(!collapsed));collapse.setAttribute('aria-label',studioIsMobile()?'Fechar menu':(collapsed?'Expandir menu':'Recolher menu'))}};
if(!studioIsMobile()&&localStorage.getItem('studio-menu-collapsed')==='1')document.body.classList.add('menu-collapsed');
const studioCollapseButton=document.querySelector('[data-studio-collapse]');
const studioToggleMenu=()=>{if(studioIsMobile()){document.body.classList.toggle('menu-open')}else{document.body.classList.toggle('menu-collapsed');localStorage.setItem('studio-menu-collapsed',document.body.classList.contains('menu-collapsed')?'1':'0')}studioSetMenuState()};
studioMenuButton?.addEventListener('click',studioToggleMenu);
document.querySelectorAll('.studio-erp-nav-group > summary').forEach(summary=>summary.addEventListener('click',()=>{if(!studioIsMobile()&&document.body.classList.contains('menu-collapsed'))studioToggleMenu()}));
studioCollapseButton?.addEventListener('click',()=>{if(studioIsMobile()){document.body.classList.remove('menu-open');studioSetMenuState()}else studioToggleMenu()});
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
