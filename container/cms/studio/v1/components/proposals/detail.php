<?php
/**
 * Proposta no Studio só para consulta (ADR 0650): criar, anexar o PDF, enviar e mudar a situação ficam no ERP
 * (Comunicação › Comercial › Propostas). Aqui ficam os dados do pedido, o acompanhamento e as versões em PDF.
 * @var object $proposal @var array<int,string> $team @var array $responses @var string $erpUrl
 */
$this->layout('layouts/studio');
$labels=['new'=>'Nova','contacted'=>'Contatada','qualified'=>'Qualificada','proposal_sent'=>'Proposta enviada','won'=>'Fechada','lost'=>'Perdida','archived'=>'Arquivada'];
$origin=\Source\Services\Proposals\ProposalComposer::originLabel((string)($proposal->origin ?? 'site'));
?>
<section class="studio-page-header"><div><small><?= htmlspecialchars($proposal->protocol) ?></small><h2><?= htmlspecialchars($proposal->condominium) ?></h2><p>Recebida em <?= date('d/m/Y \à\s H:i',strtotime($proposal->created_at)) ?>.</p></div><div><a class="studio-btn" href="<?= url('/studio/proposals') ?>"><ion-icon name="arrow-back-outline"></ion-icon>Voltar</a> <a class="studio-btn primary" href="<?= htmlspecialchars($erpUrl) ?>"><ion-icon name="open-outline"></ion-icon>Abrir no ERP</a></div></section>
<div class="studio-panel studio-proposal-moved" role="status"><header><div><small>SÓ CONSULTA</small><h2>As propostas agora ficam no ERP</h2><p>Anexar o PDF, enviar por e-mail e mudar a situação são feitos no ERP, em Comunicação › Comercial › Propostas. Nada foi perdido: os dados e as versões continuam aqui para consulta.</p></div><ion-icon name="information-circle-outline"></ion-icon></header></div>
<div class="studio-proposal-detail">
  <section class="studio-panel"><header><div><small>SOLICITANTE</small><h2><?= htmlspecialchars($proposal->name) ?></h2></div><span class="studio-proposal-status <?= htmlspecialchars($proposal->status) ?>"><?= $labels[$proposal->status]??$proposal->status ?></span></header>
    <dl><div><dt>E-mail</dt><dd><a href="mailto:<?= htmlspecialchars($proposal->email) ?>"><?= htmlspecialchars($proposal->email) ?></a></dd></div><div><dt>WhatsApp</dt><dd><a href="https://wa.me/55<?= preg_replace('/\D+/','',$proposal->whatsapp) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($proposal->whatsapp) ?></a></dd></div><div><dt>Condomínio</dt><dd><?= htmlspecialchars($proposal->condominium) ?></dd></div><div><dt>Unidades</dt><dd><?= (int)$proposal->units ?></dd></div><div><dt>Perfil</dt><dd><?= htmlspecialchars(ucfirst($proposal->profile)) ?></dd></div><div><dt>Origem</dt><dd><?= htmlspecialchars($origin) ?></dd></div></dl>
    <?php if($proposal->message):?><div class="studio-proposal-message"><strong>Necessidade informada</strong><p><?= nl2br(htmlspecialchars($proposal->message)) ?></p></div><?php endif;?>
  </section>
  <section class="studio-panel"><header><div><small>ACOMPANHAMENTO</small><h2>Situação</h2></div><ion-icon name="git-branch-outline"></ion-icon></header>
    <dl><div><dt>Situação</dt><dd><?= htmlspecialchars($labels[$proposal->status]??$proposal->status) ?></dd></div><div><dt>Responsável</dt><dd><?= htmlspecialchars($team[(int)$proposal->assigned_to] ?? 'Sem responsável') ?></dd></div></dl>
    <div class="studio-form-actions"><a class="studio-btn primary" href="<?= htmlspecialchars($erpUrl) ?>"><ion-icon name="open-outline"></ion-icon>Mudar no ERP</a></div>
  </section>
</div>
<section class="studio-panel studio-proposal-history"><header><div><small>VERSÕES</small><h2>Propostas em PDF</h2></div><span><?= count($responses) ?></span></header><div><?php if(!$responses):?><div class="studio-empty">Nenhuma proposta em PDF foi gerada.</div><?php endif;?><?php foreach($responses as $response):?><article><div><strong><?= htmlspecialchars($response->subject) ?></strong><small><?= $response->template_type==='administrator'?'Administradora':'Síndico e condomínio' ?> · <?= date('d/m/Y H:i',strtotime($response->created_at)) ?></small></div><span class="studio-proposal-status <?= htmlspecialchars($response->status) ?>"><?= ['generated'=>'PDF gerado','queued'=>'Na fila','sent'=>'Enviada','failed'=>'Falhou','draft'=>'Rascunho'][$response->status]??$response->status ?></span><?php if($response->pdf_path):?><a class="studio-icon-action" href="<?= url('/studio/proposal-response/'.$response->id.'/pdf') ?>" target="_blank" rel="noopener" title="Abrir PDF"><ion-icon name="document-outline"></ion-icon></a><?php endif;?></article><?php endforeach;?></div></section>
