<?php

declare(strict_types=1);

/*
 * Explicações das telas (ADR 0309), etapa 1: Dashboard, Condomínios e Cadastros.
 * 'faz' aparece no quadro "Sobre esta tela" do ERP; 'por_tras' no quadro e no artigo; 'mais' só no artigo.
 * Conferido no código: RegistryService (gravação, exclusão lógica e auditoria de todos os cadastros),
 * Registry/Definitions (regras de cada cadastro), CondominiumRegistry, UnitBatch, FinancialDashboard,
 * PanelSummaries e ManagementFees.
 */

$registry = [
    'Antes de gravar, o sistema confere os campos obrigatórios e se o condomínio escolhido está no seu acesso. Se algo estiver errado, nada é gravado e o formulário mostra o que corrigir.',
    'Toda inclusão, alteração e exclusão entra no histórico (quem fez, quando e o que mudou), que você vê no próprio registro.',
    'Excluir não apaga de verdade: o registro sai das listas, mas continua guardado com o histórico. Por isso, para quem saiu ou deixou de valer, prefira mudar a **Situação** para inativo.',
];

return [
    'AP-001' => [
        'faz' => 'É a primeira tela depois de entrar: um resumo do condomínio escolhido na barra superior (ou de todos) com o que venceu, o que vence nos próximos dias, receitas e despesas do mês, inadimplência, alertas, agenda e as últimas ações da equipe.',
        'por_tras' => [
            'O Dashboard só lê: nada é gravado quando você abre ou troca o mês.',
            'Os números são calculados na hora, a partir dos lançamentos, cadastros e agendas do sistema. Não existe um "total guardado": se um lançamento muda, o Dashboard já mostra o novo valor na próxima vez que abrir.',
            'Receitas e despesas seguem a **competência** (o mês de referência), como no balancete; a inadimplência e os alertas olham sempre para hoje.',
            'Cada bloco aparece só para quem tem permissão no módulo dele, e só com os condomínios do seu acesso.',
        ],
        'mais' => "Um exemplo: quando alguém registra o pagamento de uma conta em [[Financeiro > Contas e pagamentos > Contas a Pagar]], ela sai do cartão **A pagar em 7 dias** e o valor passa para o **pago** do mês em Receitas e despesas. Nada precisa ser atualizado à mão.",
    ],

    'AP-004' => [
        'faz' => 'Lista os condomínios que a Connect administra e que você pode ver. Daqui você abre a visão geral de um condomínio, edita os dados dele ou cadastra um novo, seguindo um guia de passos.',
        'por_tras' => [
            'Ao cadastrar um condomínio, o sistema confere o CNPJ (não pode repetir) e grava tudo de uma vez: os dados, o checklist de chegada com os itens do catálogo e, se você não acessa todos os condomínios, o seu acesso a ele.',
            'Depois de salvar, o condomínio novo vira o condomínio da barra superior e abre o guia: unidades, proprietários, síndico e conselho, áreas e, por último, o checklist.',
            'Condomínio salvo como inativo não abre o guia: ative-o para cadastrar unidades e pessoas.',
            'Toda inclusão e alteração fica no histórico, com quem fez e quando.',
        ],
        'mais' => "O condomínio é a peça central do sistema: unidades, pessoas, contas bancárias, lançamentos, cobranças e relatórios pertencem sempre a um condomínio. Cada pessoa da equipe só enxerga os condomínios liberados na conta dela.",
    ],

    'AP-003' => [
        'faz' => 'Mostra tudo de uma unidade em uma tela: quem mora e quem responde pelas contas, débitos em aberto, acordos, documentos emitidos e a rotina (reservas, ocorrências, entregas). É a tela para atender um morador ao telefone.',
        'por_tras' => [
            'O painel só lê: nada é gravado aqui. Para mudar um dado, use o botão que leva ao cadastro.',
            'Os débitos são as receitas da unidade ainda sem pagamento, calculadas na hora a partir dos lançamentos: as vencidas, com os encargos de atraso até hoje, e as que ainda vão vencer. Os acordos e documentos vêm das telas de cobrança e de documentos.',
            'Só aparecem as unidades dos condomínios do seu acesso. Sem condomínio escolhido na barra superior, o campo de unidade não aparece; com um escolhido, ele lista só as unidades daquele condomínio.',
            'A marca **Unidade vazia** ou **Unidade ocupada** vem do cadastro da unidade, onde a equipe a altera.',
        ],
    ],

    'AP-006' => [
        'faz' => 'Cadastra cada apartamento, casa, sala ou loja do condomínio. Proprietários, inquilinos, veículos, animais, cobranças e reservas ficam ligados à unidade. Dá para cadastrar uma por vez ou o prédio inteiro pelo cadastro rápido.',
        'por_tras' => [
            'O sistema não deixa repetir a mesma unidade (bloco e número) no mesmo condomínio.',
            'No **Cadastro rápido**, você vê a lista antes de criar; ao confirmar, entram só as unidades que ainda não existem, todas juntas ou nenhuma (se uma falhar, nada é criado).',
            'A **fração ideal** é usada quando uma fatura é rateada pela fração: cada unidade paga a parte dela do total. Na grade de frações, a soma não pode passar de 100% e, com todas preenchidas, precisa dar 100%.',
            'Uma unidade com pessoas, veículos, lançamentos ou outros registros ativos ligados a ela não pode ser excluída. Inative-a: ela sai das cobranças novas e o histórico continua.',
            'A marca **Unidade vazia** é da equipe: o sistema não a muda sozinho. Ela aparece na coluna **Ocupação**, no filtro de mesmo nome e no painel da unidade.',
            'A lista mostra 50 unidades por página; em **Itens por página** dá para ver 100, 200 ou 500 de uma vez.',
            'Na **configuração em massa**, só muda o que você escolher; o que ficar em **Manter como está** continua como está em cada unidade. Ou todas as unidades marcadas mudam, ou nenhuma, e cada unidade alterada fica com a mudança no histórico.',
            'Unidade **isenta de rateio** fica fora das faturas geradas para o condomínio todo; o valor é dividido entre as outras.',
            '**Percentual definido no rateio (%)**: a parte da unidade quando a fatura usa o cálculo **Percentual definido** (por exemplo, loja 8% e sala 4,25%). Os percentuais das unidades que participam precisam somar 100%. As formas de rateio estão no artigo Faturas.',
            'Na troca de **inquilino ou proprietário**, o antigo termina na data de saída e o novo começa na data de entrada, tudo junto ou nada. As faturas abertas passam para a pessoa nova só quando ela é quem paga a unidade, conforme a opção escolhida; conta com boleto já gerado não muda. O antigo continua como pagador das contas que ficaram com ele.',
            'O **termo de entrada/saída** usa o texto do condomínio; sem ele, o texto geral da Connect; sem nenhum, o texto padrão. As palavras-chave são trocadas pelos dados da unidade e da pessoa só na hora de gerar o termo, e o texto nunca vira código na página.',
            'O **código CIB** tem 8 caracteres (7 e o dígito) e não pode repetir em outra unidade. O sistema confere o formato; o dígito não é conferido porque a regra dele não é pública.',
            ...$registry,
        ],
    ],

    'AP-008' => [
        'faz' => 'Cadastra o salão de festas, a churrasqueira, a academia e as outras áreas que os moradores podem usar, com as regras da reserva: se é livre, exige aprovação ou está bloqueada, capacidade, taxa e regras de uso.',
        'por_tras' => [
            'O tipo de reserva decide o caminho do pedido: em área **Livre**, a reserva feita pela equipe já nasce confirmada; em área que **Exige aprovação**, a reserva espera em [[Agenda > Áreas comuns > Agendamentos Pendentes]] até alguém confirmar. O pedido feito pelo morador no aplicativo sempre espera a aprovação, mesmo em área livre.',
            'Área **Bloqueada** ou **Não reservável** recusa qualquer reserva nova, e o sistema confere o horário de funcionamento da área.',
            'A **taxa de reserva** vale para as reservas da área quando a reserva não informa outro valor; a cobrança da taxa é feita depois, como cobrança extra da unidade.',
            'Não pode haver duas áreas com o mesmo nome no mesmo condomínio.',
            ...$registry,
        ],
    ],

    'AP-007' => [
        'faz' => 'Cadastra as vagas de garagem, depósitos e boxes do condomínio e diz a qual unidade cada um pertence ou está emprestado. Área sem unidade fica como livre.',
        'por_tras' => [
            'A identificação (ex.: G1-023) não pode se repetir para o mesmo tipo no mesmo condomínio.',
            'A unidade escolhida precisa ser uma unidade ativa do mesmo condomínio.',
            ...$registry,
        ],
    ],

    'AP-019' => [
        'faz' => 'Registra os bens do condomínio, como móveis, equipamentos, eletrônicos e ferramentas, com número de patrimônio, categoria e estado de conservação. Bem que saiu de uso fica como Baixado.',
        'por_tras' => [
            'O número de patrimônio não pode se repetir no mesmo condomínio.',
            'Este cadastro é um controle dos bens: ele não gera lançamento financeiro. A compra de um bem é lançada no Financeiro como despesa.',
            ...$registry,
        ],
    ],

    'CN-008' => [
        'faz' => 'Guarda o contrato de administração de cada condomínio com a Connect: o valor mensal, o início, o dia de vencimento e a situação. Mostra o total mensal dos contratos ativos e, por mês, se a nota fiscal de serviço já foi pedida.',
        'por_tras' => [
            'Cada condomínio tem no máximo um contrato de administração; salvar de novo atualiza o mesmo contrato.',
            'Pedir a nota só registra o pedido, com o valor e a descrição do contrato. O envio à prefeitura é uma etapa que ainda vai ser ligada, e enquanto as notas fiscais estiverem desligadas nas configurações, nenhum pedido é aceito.',
            'Só pode haver um pedido aberto por contrato e mês. O cancelamento fica registrado.',
            'Os honorários não geram lançamento no financeiro do condomínio por esta tela.',
            'Toda gravação entra no histórico.',
        ],
    ],

    'CN-010' => [
        'faz' => 'Mostra o seu dia de trabalho num lugar só: os compromissos de hoje e da semana, os chamados que estão com você esperando uma data, os que aguardam o cliente, as vistorias que vencem e os documentos dos condomínios que estão para vencer.',
        'por_tras' => [
            'A tela só junta o que já existe no sistema: a agenda, os chamados atribuídos a você, os planos de vistoria em que você é o responsável e os itens do checklist de chegada sob sua responsabilidade. Nada muda quando você só abre a tela.',
            '"Agendar" um chamado cria um compromisso ligado a ele; o chamado sai da lista "Para agendar" enquanto o compromisso estiver marcado.',
            'Quando uma vistoria vence em breve, a rotina das 7h abre um chamado de vistoria para o responsável do plano. Ao concluir a vistoria, esse chamado é resolvido sozinho e os itens com problema viram um chamado de pendências.',
            'No balão do SAC, conta como online quem atende chamados, autorizou aparecer e abriu uma tela do ERP ou do SAC nos últimos 5 minutos. A autorização e a retirada entram no histórico.',
            'Atrasado é o que tinha data antes de hoje e ainda está aberto. Nada é mudado de data: o item continua no dia original (em vermelho) e só é mostrado de novo em hoje. Um chamado agendado num compromisso atrasado aparece uma vez só.',
            'A rotina das 7h manda um aviso por dia no sininho para quem tem atraso e marca como lido o aviso do dia anterior, para o sininho não acumular. Nenhum e-mail vai para morador.',
            'Os chamados sem responsável aparecem só para quem tem a permissão de atender chamados e só dos condomínios do seu acesso.',
            'Cada pessoa vê o próprio dia. Ver o dia de outra pessoa ou marcar compromisso para ela exige a permissão "Ver o Meu Dia da equipe". Toda marcação, conclusão e cancelamento entra no histórico.',
        ],
        'mais' => 'Um exemplo: a gerente abre o chamado "Portão quebrado" e atribui a você. Ele aparece em **Para agendar** com a etiqueta Urgente. Clique em {{Agendar}}, escolha o horário, e ele entra na sua agenda do dia.',
    ],

    'CN-009' => [
        'faz' => 'Atende os pedidos dos titulares pela LGPD: mostra tudo o que o sistema guarda de uma pessoa nos condomínios que você acessa, baixa esse relatório em JSON para entregar a ela e anonimiza quem já saiu do condomínio.',
        'por_tras' => [
            'A busca vai pelo CPF/CNPJ e/ou pelo e-mail e não fica na barra de endereço. O histórico do sistema guarda só um código da busca, nunca o CPF ou o e-mail.',
            'Anonimizar troca nome, documento, e-mail e telefone por "Titular anonimizado" nos cadastros, visitantes, veículos, animais, chamados, portaria, mudanças, reservas, lista de espera e contatos de cobrança. As contas a receber, a prestação de contas, o conselho e as atas continuam guardados. No Connect Talk, o contato perde nome e telefone e as mensagens ficam, como registro do atendimento.',
            'Quem acessa todos os condomínios vê também o Connect Talk, procurado pelos telefones dos cadastros encontrados: o contato, as conversas, as mensagens e os envios de campanha.',
            'Não dá para anonimizar quem ainda mora (cadastro ativo sem data de saída no passado) nem quem tem conta em aberto como pagador.',
            'Não tem volta: os dados só voltam restaurando o backup.',
            'Consultar exige a permissão de dados pessoais; anonimizar exige também a de gerenciar usuários.',
        ],
    ],

    'CN-011' => [
        'faz' => 'Reúne as propostas pedidas no site e as cadastradas pela equipe, guarda os PDFs prontos (administradora e síndico profissional), envia por e-mail e acompanha cada uma até virar contrato.',
        'por_tras' => [
            'Os pedidos do site e as propostas cadastradas aqui ficam na mesma lista; a coluna do condomínio mostra a origem.',
            'Cada PDF anexado (só PDF, até 20 MB) fica guardado e aparece na lista; os PDFs feitos antes no Studio também continuam lá.',
            'Enviar põe um e-mail com os PDFs anexados na fila de avisos e muda a situação para Proposta enviada (a não ser que já esteja Fechada, Perdida ou Arquivada).',
            'A conta da proposta (preço por unidade e mínimo que compensa) fica guardada só para a equipe e não vai no e-mail.',
            'Cadastrar, fazer a conta, anexar, enviar e mudar a situação entram no histórico do sistema.',
            'As propostas não são de um condomínio cadastrado, então a escolha do condomínio na barra superior não muda esta lista.',
        ],
    ],

    'AP-005' => [
        'faz' => 'Cadastra as pessoas de cada unidade e o vínculo delas: proprietário, inquilino, morador ou responsável financeiro. É daqui que saem os nomes, e-mails e telefones usados nas cobranças, nos comunicados e no painel da unidade.',
        'por_tras' => [
            'A mesma pessoa pode ter vínculo com várias unidades e condomínios, mas não pode ter o mesmo vínculo duas vezes com a mesma unidade.',
            'Marcar **Recebe boleto por e-mail** exige um e-mail e faz a pessoa receber o aviso de cobrança. Marcar **Não quer receber comunicados por e-mail** tira a pessoa dos comunicados gerais (a cobrança continua seguindo a opção do boleto).',
            'O vínculo vale do **Início** ao **Fim do vínculo**. Quando o inquilino sai, preencha o fim ou inative o vínculo, em vez de excluir: o histórico de quem morou ali continua.',
            'Vínculo com **Fim do vínculo** já passado é gravado como inativo: a pessoa sem unidade sai das listas de ativos.',
            'O CPF/CNPJ é opcional, mas cada documento é de uma pessoa só: o mesmo CPF pode estar em vários vínculos, sempre com o mesmo nome. Com outro nome, o sistema avisa e não grava.',
            ...$registry,
        ],
    ],

    'AP-014' => [
        'faz' => 'Registra o síndico, o subsíndico e os conselheiros fiscais e consultivos de cada condomínio, com o início e o fim do mandato, o e-mail e o telefone, que são o canal de contato com o conselho.',
        'por_tras' => [
            'Cada condomínio só pode ter um síndico ativo. Para cadastrar o novo, inative o atual antes.',
            'E-mail e telefone são obrigatórios, e o fim do mandato precisa ser depois do início.',
            'O fim do mandato não inativa o membro sozinho: depois da eleição, inative quem saiu.',
            ...$registry,
        ],
    ],

    'AP-009' => [
        'faz' => 'Cadastra os funcionários próprios de cada condomínio (porteiro, zelador, faxineiro e outros), com função, turno, admissão, salário e desligamento.',
        'por_tras' => [
            'O CPF não pode se repetir no mesmo condomínio.',
            'O desligamento é a data: preencha a data de saída em vez de excluir o funcionário, para o quadro e o histórico continuarem certos.',
            'O salário cadastrado aqui é uma informação do quadro de funcionários: o pagamento da folha é lançado no Financeiro como despesa.',
            ...$registry,
        ],
    ],

    'AP-010' => [
        'faz' => 'Cadastra os fornecedores e prestadores de serviço da Connect (manutenção, limpeza, elevadores e outros). O mesmo cadastro serve para todos os condomínios e aparece na hora de lançar uma despesa.',
        'por_tras' => [
            'O CPF ou CNPJ não pode se repetir: um fornecedor é cadastrado uma vez só e usado por todos os condomínios.',
            'Com o CNPJ completo, o sistema consulta os dados públicos da Receita (BrasilAPI) e preenche só os campos vazios; nada que você já digitou é trocado. Só o CNPJ sai do navegador para a consulta, e o servidor confere tudo de novo ao salvar.',
            'Um fornecedor ligado a lançamentos ou a outros cadastros ativos (contratos, manutenções, empenhos) não pode ser excluído. Inative-o: ele deixa de aparecer nas escolhas, e o histórico das despesas continua.',
            'Toda inclusão, alteração e exclusão entra no histórico (quem fez, quando e o que mudou).',
        ],
    ],

    'AP-016' => [
        'faz' => 'Cadastra os advogados e escritórios parceiros, usados na cobrança judicial e na assessoria aos condomínios. O cadastro vale para todos os condomínios.',
        'por_tras' => [
            'A OAB (UF e número) e o CPF/CNPJ não podem se repetir. O CPF é conferido pelos dígitos; não há consulta pública de CPF.',
            'Com o CNPJ completo, o sistema consulta os dados públicos da Receita (BrasilAPI) e preenche só os campos vazios; nada que você já digitou é trocado. Só o CNPJ sai do navegador para a consulta, e o servidor confere tudo de novo ao salvar.',
            'Advogado que deixou de atender a Connect deve ser inativado, para os processos antigos continuarem mostrando quem cuidou deles.',
            'Toda inclusão, alteração e exclusão entra no histórico (quem fez, quando e o que mudou).',
        ],
    ],

    'AP-011' => [
        'faz' => 'Cadastra os veículos autorizados de cada unidade (placa, tipo, marca e modelo, cor e vaga), para a portaria saber quem pode entrar e para o controle das vagas.',
        'por_tras' => [
            'A placa não pode se repetir no mesmo condomínio.',
            'O veículo precisa estar ligado a uma unidade do condomínio.',
            'Quando o morador se muda, inative o veículo: a portaria deixa de vê-lo como autorizado.',
            ...$registry,
        ],
    ],

    'AP-012' => [
        'faz' => 'Cadastra os visitantes frequentes e prestadores autorizados por unidade (diarista, babá, familiar), com a validade da autorização. A portaria consulta a lista para liberar a entrada.',
        'por_tras' => [
            'Cada visitante precisa de uma data **Autorizado até** ou da marca de acesso permanente.',
            'A situação **Liberado** ou **Bloqueado** é o que a portaria vê. Passada a data, bloqueie a autorização (o sistema não avisa o morador nem o visitante).',
            'O visitante fica ligado à unidade visitada.',
            ...$registry,
        ],
    ],

    'AP-013' => [
        'faz' => 'Cadastra os animais de estimação de cada unidade, para a portaria, as regras de convivência e as campanhas de vacinação.',
        'por_tras' => [
            'O animal precisa estar ligado a uma unidade do condomínio.',
            'Quando a família se muda, inative o animal para ele sair das contagens.',
            ...$registry,
        ],
    ],

    'AP-015' => [
        'faz' => 'Guarda os telefones úteis para moradores e portaria: emergência, eletricista, encanador, chaveiro, gás, concessionária e outros. Contato sem condomínio vale para todos os condomínios.',
        'por_tras' => [
            'Só quem acessa todos os condomínios pode cadastrar um contato para todos; os demais escolhem o condomínio.',
            'Um contato de todos os condomínios aparece junto com os contatos próprios de cada um.',
            ...$registry,
        ],
    ],
];
