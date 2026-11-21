<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SAC Connect</title>
    <style>
        :root{color-scheme:light;--gold:#c5a131;--gold-ink:#7a5c0c;--gold-soft:#faf5e4;--ink:#1f2328;--muted:#5d636b;--line:#e3e5e8;--surface:#fff;--bg:#f3f4f6;--green:#1f8a4c;--online:#22a35a;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-size:14px}.shell{min-height:100vh;background:var(--surface);display:flex;flex-direction:column}.top{display:flex;align-items:center;gap:12px;padding:18px 20px 16px;background:var(--bg);border-bottom:2px solid var(--gold)}.top img{width:40px;height:40px}.top div{min-width:0}.top strong{display:block;font-size:16px}.top small{display:block;color:var(--muted);margin-top:3px}.close{margin-left:auto;border:0;background:transparent;color:var(--ink);font-size:25px;line-height:1;cursor:pointer;padding:6px;border-radius:8px}.close:focus-visible,.action:focus-visible,.wa:focus-visible,.submit:focus-visible,.back:focus-visible{outline:2px solid var(--gold-ink);outline-offset:2px}.content{padding:16px 20px 20px}.view[hidden]{display:none}.team{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:14px;margin-bottom:12px}.faces{display:flex}.faces span{position:relative;width:38px;height:38px;border-radius:50%;border:2px solid #fff;background:var(--gold-soft);color:var(--gold-ink);display:grid;place-items:center;font-weight:750;margin-left:-8px;overflow:hidden}.faces span:first-child{margin-left:0}.faces img{width:100%;height:100%;object-fit:cover}.team strong{display:block}.team small{display:flex;align-items:center;gap:6px;color:var(--muted);margin-top:3px}.dot{width:8px;height:8px;border-radius:50%;background:var(--online)}.closed{padding:12px 14px;border-radius:14px;background:var(--gold-soft);border:1px solid #ecdcae;margin-bottom:12px;line-height:1.45}.closed strong{display:block;margin-bottom:2px}.wa{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;border-radius:12px;background:var(--green);color:#fff;text-decoration:none;font-weight:700;padding:13px;margin-bottom:12px}.wa svg{width:20px;height:20px}.search{display:flex;align-items:center;background:var(--bg);border-radius:12px;padding:0 12px;margin-bottom:10px}.search span{font-size:19px;color:var(--muted)}input,textarea{width:100%;border:0;outline:0;background:transparent;color:var(--ink);font:inherit}.search input{height:46px;padding:0 10px}.action{width:100%;display:flex;align-items:center;gap:12px;border:0;background:transparent;border-radius:12px;padding:12px 10px;text-align:left;color:inherit;cursor:pointer;text-decoration:none}.action:hover{background:var(--bg)}.action i{width:36px;height:36px;flex:none;display:grid;place-items:center;border-radius:10px;background:var(--bg);border:1px solid var(--gold);color:var(--gold-ink);font-style:normal;font-size:19px}.action strong,.action small{display:block}.action small{color:var(--muted);margin-top:3px}.back{border:0;background:transparent;color:var(--gold-ink);font-weight:700;padding:0 0 14px;cursor:pointer}.field{display:block;margin:0 0 12px}.field span{display:block;font-size:12px;font-weight:700;margin-bottom:6px}.field input,.field textarea{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px}.field textarea{min-height:105px;resize:vertical}.field input[type=file]{font-size:12px}.submit{width:100%;border:0;border-radius:10px;background:var(--ink);color:#fff;padding:13px;font-weight:700;cursor:pointer;box-shadow:inset 0 -2px 0 var(--gold)}.submit:disabled{opacity:.6}.hint{color:var(--muted);font-size:12px;line-height:1.45}.message{padding:11px 12px;border-radius:10px;margin:0 0 12px;line-height:1.4}.message.error{background:#fff1f2;color:#be123c}.message.success{background:#ecfdf5;color:#047857}.ticket{background:var(--bg);border-radius:12px;padding:14px;margin-top:13px}.ticket header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.ticket h3{font-size:14px;margin:0}.badge{background:var(--gold-soft);color:var(--gold-ink);border-radius:999px;padding:5px 8px;font-size:11px;font-weight:700}.timeline{border-left:2px solid var(--line);margin:15px 0 0 5px;padding-left:14px}.timeline article{margin-bottom:14px;white-space:pre-wrap}.timeline small{display:block;color:var(--muted);margin-bottom:4px}.honeypot{position:absolute!important;left:-9999px!important}.foot{margin-top:auto;padding:12px 20px;color:var(--muted);font-size:11px;text-align:center;border-top:1px solid var(--line)}.foot a{color:inherit}
    </style>
    <?= connect_fields_assets('css') /* upload e datas padrão (ADR 0628) */ ?>
</head>
<body>
<?php
/** @var array{open:bool, agents:list<array{name:string,initials:string,photo:?string}>, hours:string, whatsapp:?string} $sac SacDesk::status() (ADR 0631) */
$sac ??= ['open' => false, 'agents' => [], 'hours' => '', 'whatsapp' => null];
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$agents = $sac['agents'];
$names = array_column($agents, 'name');
$who = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' e ' . end($names) : ($names[0] ?? '');
?>
<div class="shell">
    <header class="top"><img src="<?= theme('/assets/images/helpdesk-mark.svg', 'support') ?>" alt=""><div><strong>SAC — Atendimento Connect</strong><small><?= $sac['open'] ? 'Como podemos ajudar?' : 'Atendimento ' . $e($sac['hours']) ?></small></div><button class="close" type="button" aria-label="Fechar">×</button></header>
    <main class="content">
        <section class="view" data-view="home">
            <?php if ($sac['open'] && $agents !== []): ?>
                <div class="team" data-sac-team>
                    <div class="faces" aria-hidden="true"><?php foreach ($agents as $agent): ?><span><?php if ($agent['photo']): ?><img src="<?= $e($agent['photo']) ?>" alt=""><?php else: ?><?= $e($agent['initials']) ?><?php endif; ?></span><?php endforeach; ?></div>
                    <div><strong><?= $e($who) ?> <?= count($agents) > 1 ? 'estão' : 'está' ?> no atendimento</strong><small><i class="dot"></i>Online agora</small></div>
                </div>
            <?php elseif ($sac['open']): ?>
                <div class="team"><div><strong>Atendimento aberto</strong><small>Deixe seu chamado: a equipe responde ainda hoje.</small></div></div>
            <?php else: ?>
                <div class="closed" role="status"><strong>Estamos fora do horário</strong>Deixe seu chamado que respondemos no próximo dia útil. Atendimento <?= $e($sac['hours']) ?>.</div>
            <?php endif; ?>
            <?php if ($sac['whatsapp']): ?>
                <a class="wa" href="<?= $e($sac['whatsapp']) ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3Z"/></svg>Falar no WhatsApp</a>
            <?php endif; ?>
            <form class="search" action="<?= url('/suporte/buscar') ?>" method="get" target="_blank"><span aria-hidden="true">⌕</span><input name="q" type="search" placeholder="Pesquisar ajuda" minlength="2" aria-label="Pesquisar ajuda"></form>
            <button class="action" type="button" data-open="create"><i aria-hidden="true">＋</i><span><strong>Abrir chamado</strong><small>Envie assunto, mensagem e anexos</small></span></button>
            <button class="action" type="button" data-open="track"><i aria-hidden="true">▤</i><span><strong>Acompanhar protocolo</strong><small>Consulte respostas e andamento</small></span></button>
            <a class="action" href="<?= url('/suporte') ?>" target="_blank" rel="noopener"><i aria-hidden="true">?</i><span><strong>Central de Ajuda</strong><small>Tutoriais passo a passo, com as telas</small></span></a>
        </section>
        <section class="view" data-view="create" hidden>
            <button class="back" type="button">← Voltar</button><div data-feedback></div>
            <form id="ticket-create" action="<?= url('/suporte/widget/abrir') ?>" enctype="multipart/form-data">
                <input type="hidden" name="widget_token" value="<?= htmlspecialchars($widgetToken) ?>"><label class="honeypot">Site<input name="website" tabindex="-1" autocomplete="off"></label>
                <label class="field"><span>Nome</span><input name="name" autocomplete="name" maxlength="160" required></label>
                <label class="field"><span>E-mail</span><input name="email" type="email" autocomplete="email" maxlength="190" required></label>
                <label class="field"><span>Assunto</span><input name="subject" maxlength="180" minlength="4" required></label>
                <label class="field"><span>Mensagem</span><textarea name="message" minlength="10" required></textarea></label>
                <label class="field"><span>Anexos</span><input name="attachments[]" type="file" data-cx-upload-size="compact" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx,.txt"><small class="hint">PDF, imagem, documento, planilha ou TXT. Até 3 arquivos de 5 MB.</small></label>
                <button class="submit" type="submit">Enviar chamado</button>
            </form>
        </section>
        <section class="view" data-view="track" hidden>
            <button class="back" type="button">← Voltar</button><div data-feedback></div>
            <form id="ticket-track" action="<?= url('/suporte/widget/acompanhar') ?>">
                <input type="hidden" name="widget_token" value="<?= htmlspecialchars($widgetToken) ?>"><input name="token" type="hidden">
                <label class="field"><span>Protocolo</span><input name="protocol" autocomplete="off" placeholder="CH0000000000" required></label>
                <label class="field"><span>E-mail</span><input name="email" type="email" autocomplete="email" required></label>
                <button class="submit" type="submit">Consultar chamado</button>
            </form><div data-ticket></div>
        </section>
    </main>
    <footer class="foot">Seus dados estão protegidos · <a href="<?= url('/politica-de-privacidade') ?>" target="_blank" rel="noopener">Privacidade</a></footer>
</div>
<script>
(()=>{const views=[...document.querySelectorAll('[data-view]')],savedKey='moves-helpdesk-ticket';const show=name=>views.forEach(view=>view.hidden=view.dataset.view!==name);const feedback=(form,type,text)=>{const box=form.parentElement.querySelector('[data-feedback]');box.className='message '+type;box.textContent=text};const status={open:'Aberto',in_progress:'Em andamento',waiting_customer:'Aguardando cliente',resolved:'Resolvido',closed:'Fechado'};document.querySelectorAll('[data-open]').forEach(button=>button.addEventListener('click',()=>show(button.dataset.open)));document.querySelectorAll('.back').forEach(button=>button.addEventListener('click',()=>show('home')));document.querySelector('.close').addEventListener('click',()=>parent.postMessage({type:'moves-helpdesk-close'},'*'));let saved={};try{saved=JSON.parse(localStorage.getItem(savedKey)||'{}')}catch(e){}const track=document.getElementById('ticket-track');if(saved.protocol){track.protocol.value=saved.protocol;track.email.value=saved.email||'';track.token.value=saved.token||''}document.getElementById('ticket-create').addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type=submit]');button.disabled=true;try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'}}),data=await response.json();if(!data.success)throw new Error(data.message||'Não foi possível enviar.');saved={protocol:data.protocol,email:form.email.value,token:data.token};localStorage.setItem(savedKey,JSON.stringify(saved));track.protocol.value=saved.protocol;track.email.value=saved.email;track.token.value=saved.token;form.reset();feedback(form,'success','Chamado enviado. Seu protocolo é '+data.protocol+'. Guarde este número para acompanhar o atendimento.')}catch(error){feedback(form,'error',error.message)}finally{button.disabled=false}});track.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type=submit]'),result=form.parentElement.querySelector('[data-ticket]');button.disabled=true;result.innerHTML='';try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest'}}),data=await response.json();if(!data.success)throw new Error(data.message||'Chamado não encontrado.');const card=document.createElement('section');card.className='ticket';const header=document.createElement('header'),title=document.createElement('h3'),badge=document.createElement('span');title.textContent=data.ticket.protocol+' · '+data.ticket.subject;badge.className='badge';badge.textContent=status[data.ticket.status]||data.ticket.status;header.append(title,badge);card.append(header);if(data.messages.length){const timeline=document.createElement('div');timeline.className='timeline';data.messages.forEach(item=>{const article=document.createElement('article'),meta=document.createElement('small'),body=document.createElement('div');meta.textContent=(item.author||'Equipe de atendimento')+' · '+item.created_at;body.textContent=item.message;article.append(meta,body);timeline.append(article)});card.append(timeline)}else{const empty=document.createElement('p');empty.className='hint';empty.textContent='Ainda não há respostas públicas neste chamado.';card.append(empty)}result.append(card)}catch(error){feedback(form,'error',error.message)}finally{button.disabled=false}})})();
</script>
<?= connect_fields_assets('js') ?>
</body>
</html>
