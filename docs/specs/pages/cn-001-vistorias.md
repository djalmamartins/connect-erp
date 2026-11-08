# CN-001 — Vistorias (vistoria presencial de condomínios)

Origem: pedido do proprietário (Djalma, 08/11/2026). Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/operacional/vistorias` (grupo Operacional › Rotina). Módulo: `docs/modules/vistorias.md`. Decisão: ADR 0015.

## Requisito (CONFIRMADO, palavras do proprietário)

"Checklist de vistoria nos condomínios, com visita presencial, chegando no condomínio, abrindo por GPS, se posicionar no
condomínio, com a lista de tudo que precisa ser olhado todos os meses, todas as semanas, para gerar um relatório para mandar
para o pessoal. Evidência, com foto etc."

## Regras implementadas

| Regra | Situação |
|---|---|
| Modelos de checklist (seções, itens, item com foto obrigatória) editáveis pela Connect | INFERIDO (formato e itens iniciais) |
| Periodicidade semanal, quinzenal, mensal, trimestral, semestral e anual por condomínio (plano) | CONFIRMADO semanal/mensal; demais INFERIDO |
| Programação: próxima data = última vistoria concluída + periodicidade; "vence em breve" e "atrasada" | INFERIDO |
| Check-in pelo GPS do celular; distância ao ponto do condomínio; raio padrão 150 m (30 a 2000) | CONFIRMADO GPS; raio INFERIDO |
| Fora do raio ou sem localização: check-in permitido só com justificativa, que vai ao relatório | INFERIDO |
| Resposta por item: Conforme, Com problema (observação obrigatória), Não se aplica | INFERIDO |
| Fotos pela câmera (até 6 por item), com posição do aparelho no momento da foto | CONFIRMADO foto |
| Conclusão exige todos os itens respondidos e foto nos itens marcados; depois não muda | INFERIDO |
| Relatório HTML/impressão e PDF, envio por e-mail com PDF anexo para até 10 destinatários | CONFIRMADO relatório e envio |
| Itens do modelo copiados na vistoria (editar modelo não muda vistoria feita) | INFERIDO |

## PENDENTE (para o proprietário)

- Quem recebe o relatório por padrão (síndico, conselho, zelador)? Hoje os e-mails são digitados no envio; com o cadastro de síndico/conselho (AP-014) dá para sugerir automaticamente.
- Problemas encontrados devem abrir ocorrência/manutenção (AP-110/AP-112) ou pendência no checklist de chegada (OnboardingChecklist)?
- Raio padrão de 150 m serve para os condomínios horizontais grandes?
- Relatório deve ir também para moradores (app) ou só para a gestão?

## Estados

Lista vazia (sem planos / sem vistorias), sem condomínio no acesso, sem modelo ativo, GPS obtendo/dentro/fora/sem sinal,
erro por item, faltas para concluir, vistoria de outro responsável (só leitura), concluída, cancelada, 403 e 404 (fora do escopo).
