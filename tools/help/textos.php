<?php

/*
 * Textos escritos à mão de cada página do menu, para a Central de Ajuda (ADR 0141).
 * O `php tools/help.php escrever` junta estes textos com o levantamento real da tela
 * (tools/help/.detalhes.json): campos, botões, filtros, colunas e prints.
 *
 * Chaves: titulo, para (para que serve e quando usar), dicas, atencao, botoes (texto do botão do
 * cabeçalho => o que ele faz), secoes (título => markdown), relacionados (slugs), palavras.
 * Linguagem simples, para o administrador do dia a dia; nada que a tela não faça.
 */

return [
    // ── Dashboard e painéis ───────────────────────────────────────────────────────────────────
    'AP-001' => [
        'titulo' => 'Dashboard: o resumo do dia',
        'para' => 'O Dashboard é a primeira tela depois de entrar. Ele mostra, de um condomínio ou da carteira inteira, o que está para vencer, o que venceu, as ocorrências abertas, os alertas do dia e a agenda. É daqui que você decide o que fazer primeiro.',
        'dicas' => ['Escolha **Todos os condomínios** na barra superior para ver a carteira inteira; escolha um condomínio para ver só os números dele.', 'Os cartões levam para a tela de origem: clique em **A receber vencido** para abrir as contas, por exemplo.'],
        'botoes' => ['Comunicado rápido' => 'abre o envio de um comunicado por e-mail para as unidades do condomínio escolhido.'],
        'palavras' => ['início', 'painel', 'resumo', 'alertas', 'agenda'],
    ],
    'AP-002' => [
        'titulo' => 'Painel do condomínio',
        'para' => 'O painel reúne num só lugar a situação de um condomínio: unidades, saldo nos bancos, inadimplência, resultado do mês e os alertas. Use antes de uma reunião com o síndico ou para conferir rapidamente como o condomínio está.',
        'botoes' => ['Ficha do condomínio' => 'abre o cadastro completo do condomínio, com endereço, CNPJ, contas e documentos.'],
        'dicas' => ['Os números vêm dos cadastros e dos lançamentos. Se algo parecer errado, confira o lançamento na origem.'],
    ],
    'AP-003' => [
        'titulo' => 'Painel da unidade',
        'para' => 'Tudo de uma unidade em uma tela: quem mora e quem é o responsável financeiro, débitos em aberto, acordos, documentos emitidos e a rotina (reservas, ocorrências, entregas). Ideal para atender um morador ao telefone.',
        'dicas' => ['Escolha a unidade no campo do alto. A lista mostra só as unidades do condomínio escolhido na barra superior.'],
        'relacionados' => ['unidades', 'proprietarios-e-inquilinos'],
    ],

    // ── Condomínios ───────────────────────────────────────────────────────────────────────────
    'AP-004' => [
        'titulo' => 'Cadastrar um condomínio',
        'para' => 'Aqui ficam os condomínios administrados pela Connect que a sua conta pode ver. O cadastro tem endereço, contato, síndico e dados fiscais. Depois de cadastrar, siga o checklist de chegada para deixar o condomínio pronto: unidades, pessoas, contas bancárias e cobrança.',
        'dicas' => ['O CNPJ é opcional: dá para cadastrar o condomínio antes de ele ter CNPJ e completar depois.', 'Ao digitar o CEP, o endereço se preenche sozinho. Confira o número e o complemento.'],
        'palavras' => ['prédio', 'edifício', 'checklist', 'cnpj', 'cep'],
    ],
    'AP-006' => [
        'titulo' => 'Cadastrar unidades',
        'para' => 'Cada apartamento, casa, sala ou loja é uma unidade. As pessoas (proprietários e inquilinos), os veículos, os animais, as cobranças e as reservas ficam ligados a ela. Cadastre uma por vez ou o prédio inteiro de uma vez pelo cadastro rápido.',
        'botoes' => [
            'Cadastro rápido' => 'gera todas as unidades de uma vez pela sequência de andares (ex.: 101 a 1208, 8 por andar), com pré-visualização antes de criar.',
            'Fração ideal e área' => 'abre a grade para preencher a fração ideal e a área privativa de todas as unidades juntas.',
        ],
        'dicas' => ['A fração ideal pode ficar para depois: ela é usada no rateio de despesas e pode ser dividida igualmente ou pela área na grade de frações.'],
        'atencao' => ['Unidade inativa sai das cobranças novas, mas o histórico continua. Prefira inativar a excluir.'],
        'palavras' => ['apartamento', 'casa', 'sala', 'loja', 'cadastro rápido', 'fração ideal'],
    ],
    'AP-008' => [
        'titulo' => 'Cadastrar áreas comuns',
        'para' => 'Salão de festas, churrasqueira, academia e outras áreas que os moradores podem reservar. Em cada área você define as regras da reserva: capacidade, antecedência, horário, taxa e se precisa de aprovação.',
        'dicas' => ['Com aprovação obrigatória, o pedido do morador cai em Agendamentos Pendentes até alguém confirmar.'],
        'relacionados' => ['reservas', 'agendamentos-pendentes', 'lista-de-espera'],
        'palavras' => ['salão de festas', 'churrasqueira', 'academia', 'reserva'],
    ],
    'AP-007' => [
        'titulo' => 'Cadastrar vagas, depósitos e boxes',
        'para' => 'Áreas vinculáveis são as que pertencem ou ficam reservadas a uma unidade: vagas de garagem, depósitos e boxes. Cadastre e vincule cada uma à unidade que usa.',
        'palavras' => ['vaga', 'garagem', 'depósito', 'box'],
    ],
    'AP-019' => [
        'titulo' => 'Cadastrar o patrimônio do condomínio',
        'para' => 'Registre os bens do condomínio, como móveis, equipamentos, eletrônicos e ferramentas, com valor, data de compra e onde estão. Ajuda no inventário, no seguro e na prestação de contas.',
        'palavras' => ['bens', 'inventário', 'equipamentos'],
    ],
    'AP-167' => [
        'titulo' => 'Preencher a fração ideal e a área das unidades',
        'para' => 'A grade mostra todas as unidades do condomínio com a área privativa e a fração ideal, para preencher tudo de uma vez. A fração ideal define a parte de cada unidade no rateio das despesas.',
        'botoes' => ['Unidades' => 'volta para a lista de unidades.', 'Cadastro rápido' => 'gera as unidades em sequência, se ainda faltarem.'],
        'secoes' => ['Dividir igualmente ou pela área' => "1. Preencha a **área privativa** de cada unidade, se for dividir pela área.\n2. Use **Dividir igualmente** ou **Dividir pela área**: a grade se preenche, sem gravar ainda.\n3. Ajuste o que for diferente. A fração pode ser decimal (0,0125) ou porcentagem (1,25%).\n4. Clique em {{Salvar}}. A soma das frações precisa dar 1 (100%)."],
        'atencao' => ['O sistema só grava se a soma das frações der exatamente 1. Se faltar ou sobrar, a tela mostra a diferença.'],
        'palavras' => ['fração ideal', 'rateio', 'área privativa'],
    ],

    // ── Cadastros ─────────────────────────────────────────────────────────────────────────────
    'AP-005' => [
        'titulo' => 'Cadastrar proprietários e inquilinos',
        'para' => 'Cadastre as pessoas de cada unidade: proprietário, inquilino, morador e responsável financeiro. A mesma pessoa pode estar em várias unidades e em vários condomínios: pesquise antes de cadastrar e só acrescente o vínculo.',
        'dicas' => ['O responsável financeiro é quem recebe a cobrança. Marque também se a pessoa aceita cobrança e comunicados por e-mail.', 'Para ligar uma pessoa já cadastrada a outra unidade, abra o cadastro dela e acrescente o vínculo.'],
        'atencao' => ['CPF, e-mail e telefone são dados pessoais (LGPD): cadastre só o necessário e não envie listas para fora do sistema.'],
        'palavras' => ['morador', 'condômino', 'proprietário', 'inquilino', 'responsável financeiro', 'cpf'],
    ],
    'AP-014' => [
        'titulo' => 'Cadastrar síndico e conselho',
        'para' => 'Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, com o início e o fim do mandato. O síndico aparece nos documentos e nas assinaturas da prestação de contas.',
        'dicas' => ['Ao trocar de síndico, encerre o mandato anterior com a data de fim em vez de excluir, para manter o histórico.'],
        'palavras' => ['síndico', 'subsíndico', 'conselho fiscal', 'mandato'],
    ],
    'AP-009' => [
        'titulo' => 'Cadastrar funcionários do condomínio',
        'para' => 'Funcionários próprios do condomínio, como porteiros, zeladores e faxineiros, com função, contato e admissão.',
        'palavras' => ['porteiro', 'zelador', 'colaborador'],
    ],
    'AP-010' => [
        'titulo' => 'Cadastrar fornecedores',
        'para' => 'Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios: empresa de limpeza, manutenção de elevador, concessionárias. O fornecedor aparece nas contas a pagar, nos contratos e na importação de notas fiscais.',
        'dicas' => ['Cadastre o CNPJ certinho: a importação de NF-e encontra o fornecedor por ele.'],
        'palavras' => ['prestador', 'empresa', 'cnpj', 'concessionária'],
    ],
    'AP-016' => [
        'titulo' => 'Cadastrar advogados',
        'para' => 'Advogados e escritórios parceiros que cuidam da cobrança judicial e da assessoria dos condomínios.',
        'relacionados' => ['cobranca-judicial'],
    ],
    'AP-011' => [
        'titulo' => 'Cadastrar veículos das unidades',
        'para' => 'Veículos autorizados de cada unidade, com placa, modelo e cor, para a portaria liberar a entrada e para o controle de vagas.',
        'palavras' => ['carro', 'moto', 'placa'],
    ],
    'AP-012' => [
        'titulo' => 'Cadastrar visitantes autorizados',
        'para' => 'Visitantes frequentes e prestadores autorizados por unidade, como diarista, babá ou familiar. A portaria consulta a lista para liberar a entrada.',
        'relacionados' => ['previsao-de-visita', 'registro-de-acessos'],
    ],
    'AP-013' => [
        'titulo' => 'Cadastrar animais de estimação',
        'para' => 'Animais de cada unidade, com espécie, raça e vacinação, para o regulamento interno e para a portaria.',
        'palavras' => ['pet', 'cachorro', 'gato'],
    ],
    'AP-015' => [
        'titulo' => 'Cadastrar contatos úteis',
        'para' => 'Telefones úteis para moradores e portaria: plantão de elevador, eletricista, bombeiro, concessionárias. Sem condomínio, o contato vale para todos.',
    ],

    // ── Financeiro ────────────────────────────────────────────────────────────────────────────
    'AP-020' => [
        'titulo' => 'Lançamentos: receitas e despesas',
        'para' => 'Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.',
        'dicas' => ['Para dar baixa, edite o lançamento e informe **Pago em** e a **Conta bancária**.', 'Use a competência (mês de referência) certa: é ela que decide em que mês o valor aparece nos relatórios.'],
        'atencao' => ['Mês fechado no Fechamento Mensal não aceita lançamento novo nem alteração até ser reaberto.'],
        'palavras' => ['receita', 'despesa', 'baixa', 'pagamento', 'competência'],
    ],
    'AP-021' => [
        'titulo' => 'Lançamentos fixos (recorrentes)',
        'para' => 'Receitas e despesas que se repetem todo mês, como contrato de limpeza ou salário. Cadastre uma vez e gere os lançamentos de cada competência sem digitar de novo. Gerar de novo a mesma competência não duplica.',
        'palavras' => ['recorrente', 'mensal', 'repetir'],
    ],
    'AP-022' => [
        'titulo' => 'Contas a pagar',
        'para' => 'Despesas em aberto do condomínio, por vencimento. Use para saber o que pagar na semana e para dar baixa depois do pagamento.',
        'dicas' => ['Para dar baixa, abra a conta e informe **Pago em** e a **Conta bancária** de onde saiu o dinheiro.'],
        'relacionados' => ['lancar-despesa', 'pagamentos-pendentes'],
        'palavras' => ['despesa', 'pagar', 'vencimento', 'baixa'],
    ],
    'AP-023' => [
        'titulo' => 'Contas a receber',
        'para' => 'Receitas em aberto do condomínio, como taxas condominiais, multas e acordos, por vencimento. Os boletos saem daqui, e a baixa é feita pelo arquivo de retorno do banco ou à mão.',
        'relacionados' => ['boletos', 'lancar-receita', 'lancar-desconto'],
        'palavras' => ['receita', 'receber', 'taxa condominial', 'baixa'],
    ],
    'AP-024' => [
        'titulo' => 'Lançar uma despesa',
        'para' => 'Formulário direto para lançar uma despesa do condomínio. Use quando chega uma conta para pagar, como uma nota de serviço ou a conta de água.',
        'dicas' => ['Já pagou? Preencha **Pago em** e a **Conta bancária** no mesmo formulário, e a despesa entra paga.'],
        'relacionados' => ['contas-a-pagar', 'importacao-nf-e-xml'],
    ],
    'AP-025' => [
        'titulo' => 'Lançar uma receita',
        'para' => 'Formulário direto para lançar uma receita avulsa, como um aluguel de salão ou um rendimento. A taxa condominial do mês sai pelas Faturas, não daqui.',
        'relacionados' => ['contas-a-receber', 'faturas'],
    ],
    'AP-026' => [
        'titulo' => 'Lançar um desconto numa conta a receber',
        'para' => 'Dá desconto numa conta a receber em aberto: o valor da conta diminui e fica registrado o valor antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento em vez de dar 100% de desconto.',
        'secoes' => ['Como dar o desconto' => "1. Pesquise a conta pelo campo **Buscar** (unidade, descrição ou competência).\n2. Na linha da conta, clique em {{Desconto}}.\n3. Informe o valor do desconto e o motivo, e confirme."],
        'botoes' => ['Contas a receber' => 'abre a lista completa de contas a receber.'],
        'atencao' => ['O motivo é obrigatório e aparece na auditoria. Escreva de forma que outra pessoa entenda depois.'],
    ],
    'AP-027' => [
        'titulo' => 'Lançar uma multa avulsa',
        'para' => 'Multa de uma unidade por descumprir o regulamento ou por uma ocorrência, com valor fixo e motivo. A multa vira uma conta a receber da unidade e entra na cobrança normal.',
        'relacionados' => ['multas-pendentes', 'ocorrencias'],
    ],
    'AP-028' => [
        'titulo' => 'Cobranças extras (rateio ou valor fixo)',
        'para' => 'Cobrança fora da fatura do mês para uma ou mais unidades, como chamada de capital ou uma obra aprovada em assembleia. O valor pode ser fixo por unidade, o total dividido igualmente ou rateado pela fração ideal. Gera uma conta a receber por unidade.',
        'dicas' => ['Rateio pela fração ideal só funciona com as frações preenchidas e somando 100%.'],
    ],
    'AP-029' => [
        'titulo' => 'Transferir entre contas bancárias',
        'para' => 'Registra a transferência de dinheiro entre contas do mesmo condomínio, como da conta corrente para a poupança. Não é receita nem despesa: muda só o saldo de cada conta.',
        'relacionados' => ['relatorio-de-transferencias', 'extrato-conta'],
    ],
    'AP-030' => [
        'titulo' => 'Fechar o mês (fechamento mensal)',
        'para' => 'O fechamento trava a competência depois de conferida: ninguém lança nem altera nada daquele mês. Confira o resumo (receitas, despesas, resultado e o que está em aberto), feche e, se precisar corrigir, reabra informando o motivo.',
        'secoes' => ['Como fechar e reabrir' => "1. Escolha a **Competência** e confira o resumo de cada condomínio.\n2. Clique em {{Fechar competência}} na linha do condomínio.\n3. Para corrigir depois, clique em {{Reabrir competência}} e informe o motivo. A reabertura aparece na listagem de prestações de contas."],
        'atencao' => ['Feche só depois de conferir as baixas e os lançamentos do mês: o fechamento é a base da prestação de contas.'],
        'relacionados' => ['listagem-de-prestacoes-de-contas', 'demonstrativo'],
    ],
    'AP-031' => [
        'titulo' => 'Pagamentos pelo banco',
        'para' => 'Pagamentos feitos pelas contas bancárias do condomínio, com favorecido, data e valor.',
    ],
    'AP-040' => [
        'titulo' => 'Emitir recibos de pagamento',
        'para' => 'Emite o recibo das contas já pagas de uma unidade, escolhidas uma a uma. Cada conta entra em um só recibo válido; se cancelar o recibo, a conta fica livre para outro.',
        'dicas' => ['Todo recibo tem um código de verificação: quem recebeu pode conferir a autenticidade no bloco **Conferir autenticidade**.'],
    ],
    'CN-002' => [
        'titulo' => 'Cadastrar centros de custo',
        'para' => 'Centros de custo agrupam lançamentos por área ou projeto, como obra da fachada ou manutenção predial, para saber quanto cada um consumiu. Sem condomínio, o centro de custo vale para todos.',
        'relacionados' => ['relatorio-de-centros-de-custo', 'saldo-inicial-centro-de-custo'],
    ],
    'CN-006' => [
        'titulo' => 'Lançamentos patrimoniais',
        'para' => 'Movimentos entre contas patrimoniais, como apropriar ou usar o fundo de reserva e ajustar o patrimônio líquido. Não entram na DRE e não mexem no banco.',
        'relacionados' => ['balanco-patrimonial', 'saldo-inicial-plano-de-contas', 'lancamentos'],
        'palavras' => ['fundo de reserva', 'partida dobrada', 'estorno', 'patrimônio líquido'],
    ],
    'CN-003' => [
        'titulo' => 'Cadastrar contas bancárias',
        'para' => 'Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal. A conta de cobrança precisa dos dados do convênio para emitir boletos.',
        'atencao' => ['Os dados do convênio bancário só são usados em homologação. Boleto real depende da liberação do banco.'],
        'relacionados' => ['saldos-de-contas', 'extrato-conta', 'boletos'],
        'palavras' => ['banco', 'agência', 'conta corrente', 'convênio'],
    ],

    // ── Cobrança ──────────────────────────────────────────────────────────────────────────────
    'AP-117' => [
        'titulo' => 'Atender as unidades em atraso',
        'para' => 'A fila de cobrança mostra as unidades em atraso na ordem de prioridade: primeiro as promessas de pagamento quebradas, depois as próximas ações vencidas, as unidades sem contato e as de maior atraso. Use todo dia para fazer os contatos.',
        'secoes' => ['Como atender' => "1. Clique em {{Atender}} na unidade do topo da fila.\n2. Veja as contas em aberto e o histórico de contatos.\n3. Registre o contato: o que foi conversado, a promessa de pagamento (se houver) e a próxima ação.\n4. A unidade volta para a fila na data da próxima ação."],
        'relacionados' => ['acordos', 'inadimplentes-unidade'],
    ],
    'AP-034' => [
        'titulo' => 'Parcelas de acordo',
        'para' => 'As parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita pelo boleto (arquivo de retorno) ou nos lançamentos.',
        'botoes' => ['Acordos' => 'volta para a tela de acordos.'],
        'relacionados' => ['acordos', 'relatorio-de-acordos'],
    ],
    'AP-037' => [
        'titulo' => 'Remessas de cobrança',
        'para' => 'Arquivos de remessa enviados ao banco com os boletos para registro.',
    ],
    'AP-036' => [
        'titulo' => 'Importar o arquivo de retorno do banco',
        'para' => 'O arquivo de retorno é o que o banco devolve com os boletos registrados e pagos. Importando aqui, o sistema registra os boletos e dá baixa nos pagamentos sozinho. Pagamento de valor diferente ou de boleto desconhecido vai para a fila de divergências, para alguém conferir.',
        'botoes' => ['Divergências' => 'abre a fila das ocorrências que não deram baixa sozinhas.'],
        'dicas' => ['Importar o mesmo arquivo duas vezes não duplica nada: o sistema reconhece o arquivo.'],
        'relacionados' => ['retornos', 'boletos'],
        'palavras' => ['cnab', 'retorno', 'baixa automática', 'divergência'],
    ],
    'AP-038' => [
        'titulo' => 'Retornos importados e divergências',
        'para' => 'Lista os arquivos de retorno importados e a fila das ocorrências que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou com nosso número desconhecido. Resolva cada divergência dando a baixa certa ou ignorando com motivo.',
        'botoes' => ['Importar retorno' => 'abre a tela para importar um novo arquivo de retorno.'],
        'relacionados' => ['arquivo-de-retorno', 'boletos'],
    ],
    'AP-039' => [
        'titulo' => 'Cobrança judicial',
        'para' => 'Acompanhe as unidades em cobrança judicial: quais contas estão sendo cobradas, o advogado, o número do processo e o andamento.',
        'secoes' => ['Como abrir um caso' => "1. No bloco **Abrir caso**, escolha a unidade e as contas que vão para a justiça.\n2. Informe o advogado e, quando tiver, o número do processo.\n3. Registre o andamento sempre que houver novidade."],
        'relacionados' => ['advogados', 'inadimplentes-unidade'],
    ],
    'AP-116' => [
        'titulo' => 'Multas pendentes',
        'para' => 'As multas avulsas que a unidade ainda não pagou, com vencimento e motivo. A multa entra na cobrança como qualquer conta a receber.',
        'botoes' => ['Lançar multa' => 'abre o formulário de multa avulsa.'],
        'relacionados' => ['lancar-multa-avulsa'],
    ],

    // ── Prestação de contas ───────────────────────────────────────────────────────────────────
    'AP-101' => ['titulo' => 'Prestação de contas', 'para' => 'Monta a prestação de contas do condomínio no período: saldo anterior, entradas, saídas e saldo final de cada conta.'],
    'AP-094' => [
        'titulo' => 'Listagem de prestações de contas',
        'para' => 'Mostra os fechamentos mensais de cada competência, com o resultado e as reaberturas. Use para saber quais meses já foram fechados e se algum foi reaberto.',
        'relacionados' => ['fechamento-mensal'],
    ],
    'AP-051' => [
        'titulo' => 'Demonstrativo (regime de caixa)',
        'para' => 'A prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos. É o relatório que normalmente vai para o síndico e o conselho.',
        'dicas' => ['Feche o mês antes de imprimir o demonstrativo, para os números não mudarem depois.'],
    ],
    'AP-052' => ['titulo' => 'Demonstrativo por conta', 'para' => 'Receitas e despesas por conta do plano: o previsto, o realizado e o que segue em aberto. Bom para ver onde o condomínio gastou mais que o previsto.'],
    'AP-053' => ['titulo' => 'Demonstrativo analítico', 'para' => 'Cada lançamento das competências escolhidas, agrupado pela conta do plano. Use quando o conselho pede o detalhe de uma conta.'],
    'AP-054' => ['titulo' => 'Demonstrativo por situação', 'para' => 'Receitas e despesas das competências separadas por situação: pago, a vencer, vencido e cancelado.'],
    'AP-056' => [
        'titulo' => 'Extrato da conta bancária',
        'para' => 'Movimentos pagos e transferências de uma conta bancária do condomínio, com o saldo anterior e o saldo dia a dia. Compare com o extrato do banco para conciliar.',
        'relacionados' => ['extrato-bancario-ofx', 'saldos-de-contas'],
    ],
    'AP-079' => ['titulo' => 'Balancete de verificação', 'para' => 'Lançamentos válidos por conta do plano na competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.'],
    'AP-080' => ['titulo' => 'Balancete anual', 'para' => 'Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês. Escolha a base (competência ou caixa).'],
    'AP-081' => ['titulo' => 'Balanço patrimonial', 'para' => 'Posição do patrimônio do condomínio no período: saldos das contas, direitos e obrigações.'],
    'AP-082' => ['titulo' => 'Demonstração do resultado (DRE)', 'para' => 'Receitas menos despesas do período por conta do plano, com as contas sintéticas somadas e o resultado final. Escolha a base: competência ou caixa.'],
    'AP-083' => [
        'titulo' => 'Livro razão',
        'para' => 'O movimento de cada conta do plano no período: saldo anterior, cada lançamento a débito ou crédito e o saldo corrido. Informe o código de uma conta para ver só ela.',
    ],

    // ── Operacional ───────────────────────────────────────────────────────────────────────────
    'CN-001' => [
        'titulo' => 'Vistorias nos condomínios',
        'para' => 'Registra as visitas presenciais aos condomínios, com check-in pelo GPS do celular, checklist do que foi verificado, fotos e o relatório final.',
        'botoes' => ['Nova vistoria' => 'começa uma vistoria: escolha o condomínio, faça o check-in no local e siga o checklist.', 'Configurar' => 'define a programação das vistorias e os itens do checklist.'],
        'dicas' => ['Faça a vistoria pelo celular, no próprio condomínio: o check-in usa a localização para comprovar a visita.'],
    ],
    'CN-004' => [
        'titulo' => 'Abrir e acompanhar chamados',
        'para' => 'Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do Help Desk, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.',
        'botoes' => ['Fila do Help Desk' => 'abre a fila de atendimento no Help Desk (para quem atende os chamados).', 'Abrir chamado' => 'leva ao formulário de abertura, no alto da página.'],
        'dicas' => ['Se o pedido é de um morador, marque **Um morador ou outra pessoa** e informe nome, e-mail e telefone dele: a resposta vai para ele.', 'Guarde o protocolo: ele identifica o chamado em qualquer conversa.'],
        'relacionados' => ['pedir-ajuda', 'ocorrencias'],
        'palavras' => ['help desk', 'protocolo', 'suporte', 'atendimento'],
    ],
    'AP-110' => ['titulo' => 'Manutenções preventivas e corretivas', 'para' => 'Agenda as manutenções do condomínio, com o serviço, o fornecedor, a data prevista e a periodicidade (mensal, anual). Ao concluir, registre a data em **Concluída em**.', 'dicas' => ['As manutenções atrasadas aparecem no Dashboard e nos alertas.']],
    'AP-112' => ['titulo' => 'Registrar ocorrências', 'para' => 'Ocorrências do dia a dia do condomínio, como barulho, vazamento ou dano, com a unidade, a prioridade e o que foi feito. Quando precisar da administradora, abra também um chamado no Help Desk.', 'relacionados' => ['chamados', 'lancar-multa-avulsa', 'relatorio-de-ocorrencias']],
    'AP-113' => ['titulo' => 'Reservas de áreas comuns', 'para' => 'As reservas do salão de festas, churrasqueira e demais áreas, seguindo as regras de cada área: antecedência, horário, taxa e aprovação. Reserva que precisa de aprovação aparece em Agendamentos Pendentes.', 'relacionados' => ['areas-comuns', 'agendamentos-pendentes', 'lista-de-espera']],
    'AP-114' => ['titulo' => 'Aprovar reservas pendentes', 'para' => 'Reservas que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva, e quem está na lista de espera é avisado.', 'botoes' => ['Todas as reservas' => 'abre a lista completa de reservas.']],
    'AP-115' => ['titulo' => 'Lista de espera das áreas comuns', 'para' => 'Quem quer uma área comum numa data já reservada entra na lista de espera, pela ordem de inscrição. Se a reserva for cancelada ou recusada, o primeiro da lista é avisado por e-mail.'],
    'AP-118' => ['titulo' => 'Seguros do condomínio', 'para' => 'As apólices do condomínio: seguradora, vigência, prêmio, cobertura e a apólice em PDF guardada no Disco Virtual. O vencimento próximo aparece nos alertas.'],
    'AP-119' => ['titulo' => 'Contratos com fornecedores', 'para' => 'Contratos do condomínio com fornecedores: objeto, vigência, valor mensal, reajuste e o contrato assinado no Disco Virtual. O vencimento próximo aparece nos alertas.'],
    'AP-120' => ['titulo' => 'Agendar mudanças', 'para' => 'Mudanças de entrada e saída, por dia e período. Só cabe um agendamento por período e dia em cada condomínio, seguindo as regras da configuração de mudança.', 'relacionados' => ['configuracoes-de-mudanca', 'mudancas']],
    'AP-121' => ['titulo' => 'Regras de mudança', 'para' => 'Define as regras de mudança de cada condomínio: dias e horários permitidos, antecedência mínima do pedido e taxa.'],
    'AP-041' => ['titulo' => 'Cadastrar relógios (medidores)', 'para' => 'Medidores de água, gás e energia de cada unidade. A leitura inicial é a base da primeira leitura do mês.', 'relacionados' => ['leituras', 'tarifas']],
    'AP-042' => [
        'titulo' => 'Lançar as leituras do mês',
        'para' => 'Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber do consumo.',
        'botoes' => ['Relógios' => 'abre o cadastro dos medidores.', 'Tarifas' => 'abre as faixas de tarifa usadas no cálculo do valor.'],
        'dicas' => ['Leitura menor que a anterior é sinal de erro de digitação ou troca de relógio: confira antes de cobrar.'],
        'relacionados' => ['importacao-de-leituras'],
    ],
    'AP-043' => ['titulo' => 'Tarifas de consumo', 'para' => 'Faixas progressivas de tarifa por tipo de medidor e vigência, usadas para calcular o valor das leituras. Sem condomínio vale o padrão da Connect; a tarifa do condomínio substitui o padrão.'],
    'AP-131' => ['titulo' => 'Portarias', 'para' => 'As portarias de cada condomínio: presencial, remota ou híbrida, os turnos e o responsável.'],
    'AP-132' => ['titulo' => 'Visitas previstas', 'para' => 'Visitas avisadas pela unidade com antecedência, para a portaria liberar na data sem precisar interfonar.'],
    'AP-133' => ['titulo' => 'Registro de acessos da portaria', 'para' => 'Entradas e saídas registradas pela portaria: visitantes, prestadores, entregadores e moradores, com data e hora.'],
    'AP-134' => ['titulo' => 'Achados e perdidos', 'para' => 'Itens encontrados no condomínio, onde estão guardados e a quem foram devolvidos.'],
    'AP-135' => ['titulo' => 'Entregas na portaria', 'para' => 'Encomendas recebidas pela portaria para cada unidade. Quando o morador retirar, informe a retirada para dar baixa.'],
    'AP-136' => ['titulo' => 'Registros de entregas', 'para' => 'Histórico das entregas registradas pela portaria.'],

    // ── Comunicação ───────────────────────────────────────────────────────────────────────────
    'AP-111' => [
        'titulo' => 'Enviar um comunicado por e-mail',
        'para' => 'Envia um comunicado por e-mail para as unidades do condomínio, como aviso de obra, convocação ou regra nova. Recebem as pessoas ativas com e-mail válido que não recusaram comunicado no cadastro.',
        'dicas' => ['Escolha **Agendar** para o comunicado sair numa data e hora; até lá ele aparece em Comunicação > Agendada e pode ser cancelado.', 'Confira em **Endereços** quem vai receber antes de enviar.'],
        'relacionados' => ['agendada', 'enderecos'],
    ],
    'AP-018' => ['titulo' => 'Assembleia virtual', 'para' => 'Assembleias do condomínio, com data, tipo, pauta, presenças e situação.'],
    'AP-017' => ['titulo' => 'Votações', 'para' => 'Votações abertas para os condôminos, com a pergunta, a data de encerramento, os votos e a situação.'],
    'AP-137' => [
        'titulo' => 'Enviar aviso de cobrança',
        'para' => 'Envia por e-mail o aviso de débitos em aberto para as unidades com conta vencida. Só recebe quem autorizou cobrança por e-mail, e a unidade avisada nos últimos 7 dias fica de fora para não repetir.',
        'secoes' => ['Como enviar' => "1. Ajuste a **Data de referência** e o **Atraso mínimo (dias)**.\n2. Marque as unidades que vão receber, ou **Marcar todas as unidades**.\n3. Escolha **Agora** ou **Agendar**.\n4. Clique em {{Enviar aviso de cobrança}}."],
        'relacionados' => ['agendada', 'atendimento-cobranca'],
    ],
    'AP-138' => ['titulo' => 'Comunicados agendados', 'para' => 'Comunicados e avisos de cobrança que ainda vão sair. Cancele aqui o que ainda não saiu.'],
    'AP-139' => ['titulo' => 'Quem recebe os comunicados (endereços)', 'para' => 'Mostra quem recebe os comunicados e os avisos de cobrança em cada unidade. Para corrigir um e-mail ou a preferência da pessoa, abra o cadastro dela em Proprietários e inquilinos.', 'relacionados' => ['proprietarios-e-inquilinos']],

    // ── Documentos ────────────────────────────────────────────────────────────────────────────
    'AP-122' => [
        'titulo' => 'Disco Virtual: arquivos do condomínio',
        'para' => 'Guarde os arquivos do condomínio organizados por pasta: atas, contratos, plantas, notas fiscais, apólices. Os arquivos ficam fora do site e só baixa quem tem acesso ao condomínio.',
        'dicas' => ['Cada arquivo enviado passa pelo antivírus quando ele está ligado. Arquivo bloqueado não pode ser baixado.'],
    ],
    'AP-123' => [
        'titulo' => 'Emitir a certidão de débitos',
        'para' => 'Emite a certidão de débitos da unidade na data escolhida. Sem conta vencida, ela sai como "nada consta"; com conta vencida, lista as contas e os encargos. Muito pedida em venda e locação.',
        'dicas' => ['Cada documento tem um código de verificação. Quem recebeu confere a autenticidade no endereço público /verificar-certidao-debitos.'],
        'atencao' => ['Depois de emitido, o documento não muda. Para corrigir, cancele e emita outro.'],
        'relacionados' => ['carta-de-anuencia', 'comprovante-de-quitacao'],
        'palavras' => ['nada consta', 'certidão negativa', 'venda', 'locação'],
    ],
    'AP-124' => [
        'titulo' => 'Emitir a declaração de inadimplência',
        'para' => 'Declara as contas vencidas em aberto da unidade, com os encargos na data de referência. Só é emitida se houver conta vencida.',
        'atencao' => ['Depois de emitido, o documento não muda. Para corrigir, cancele e emita outro.'],
    ],
    'AP-125' => [
        'titulo' => 'Emitir o comprovante de quitação',
        'para' => 'Comprova que todas as contas da unidade até a competência escolhida estão pagas. Se houver alguma conta em aberto, o comprovante não é emitido e a tela mostra qual falta.',
    ],
    'AP-130' => [
        'titulo' => 'Emitir a carta de anuência',
        'para' => 'Carta em que a administradora declara que a unidade não tem débito vencido, para a finalidade informada (por exemplo, venda ou locação).',
    ],
    'AP-126' => ['titulo' => 'Avisos de recebimento (AR)', 'para' => 'Correspondências enviadas com aviso de recebimento: envio, entrega ou devolução, e o comprovante.'],
    'AP-127' => ['titulo' => 'Cópias de cheques', 'para' => 'Cheques emitidos pelo condomínio, com a imagem guardada no Disco Virtual.'],
    'AP-128' => ['titulo' => 'Empenhos', 'para' => 'Despesas reservadas antes do pagamento, para o dinheiro não ser usado em outra coisa. Na hora de pagar, liquide o empenho com a data e, se houver, o lançamento.'],
    'AP-129' => ['titulo' => 'Malotes', 'para' => 'Malotes de documentos entre o condomínio e a administradora: envio, recebimento e conferência do conteúdo.'],

    // ── Relatórios: gráficos ──────────────────────────────────────────────────────────────────
    'AP-102' => ['titulo' => 'Gráficos gerais', 'para' => 'Receitas e despesas previstas por competência, o resultado de cada mês e o que venceu e segue em aberto, em gráfico e em tabela.'],
    'AP-103' => ['titulo' => 'Gráfico de contas', 'para' => 'Valores por conta do plano em cada competência. As oito maiores aparecem; as demais somam em "Outras".'],
    'AP-104' => ['titulo' => 'Gráfico de consumo da unidade', 'para' => 'Consumo medido de uma unidade por tipo de medidor e competência. Bom para mostrar ao morador a evolução do consumo.'],
    'AP-105' => ['titulo' => 'Gráfico de consumo geral', 'para' => 'Consumo medido por tipo de medidor e competência, somando todas as unidades.'],
    'AP-106' => ['titulo' => 'Gráfico financeiro (caixa)', 'para' => 'Recebido e pago por mês, pela data do pagamento, e o saldo de cada mês.'],
    'AP-107' => ['titulo' => 'Gráfico de inadimplência', 'para' => 'Por competência: o que foi cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.'],
    'AP-108' => ['titulo' => 'Gráfico financeiro por conta bancária', 'para' => 'Saldo de cada mês (entradas menos saídas, com as transferências) por conta bancária.'],
    'AP-109' => ['titulo' => 'Gráfico de uso das áreas comuns', 'para' => 'Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".'],

    // ── Relatórios ────────────────────────────────────────────────────────────────────────────
    'AP-045' => ['titulo' => 'Relatório de consumo geral', 'para' => 'Leituras, consumo e valor por tipo de medidor nas competências escolhidas.'],
    'AP-046' => ['titulo' => 'Relatório de consumo por unidade', 'para' => 'Consumo de cada unidade por tipo de medidor nas competências escolhidas.'],
    'AP-047' => ['titulo' => 'Relatório de acordos', 'para' => 'Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.'],
    'AP-048' => ['titulo' => 'Taxas de acordos', 'para' => 'Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos são os encargos menos os descontos.'],
    'AP-049' => ['titulo' => 'Relatório de centros de custo', 'para' => 'Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.'],
    'AP-050' => ['titulo' => 'Comparativo entre períodos', 'para' => 'Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação. Use para comparar este ano com o ano passado, por exemplo.'],
    'AP-057' => ['titulo' => 'Inadimplentes por condomínio', 'para' => 'Total em atraso de cada condomínio na data de referência: unidades, contas, valor, encargos sugeridos pela regra de cobrança e o maior atraso. Bom para ver a carteira inteira.'],
    'AP-058' => ['titulo' => 'Inadimplentes por unidade', 'para' => 'Cada unidade em atraso na data de referência, com o número de contas, valor, encargos, a conta mais antiga e o maior atraso. É a lista para a cobrança.'],
    'AP-059' => ['titulo' => 'Inadimplentes detalhado (conta a conta)', 'para' => 'Cada conta vencida e em aberto na data de referência, com competência, vencimento, dias de atraso, valor e encargos.'],
    'AP-060' => ['titulo' => 'Inadimplentes por taxa', 'para' => 'O atraso separado por tipo de cobrança (conta do plano), como taxa condominial, fundo de reserva ou consumo.'],
    'AP-061' => ['titulo' => 'Inadimplentes por responsável', 'para' => 'As unidades em atraso com o responsável financeiro, documento, telefone e e-mail, para fazer o contato.', 'atencao' => ['Este relatório tem dados pessoais (LGPD). Não envie para fora do sistema e descarte as cópias impressas.']],
    'AP-062' => ['titulo' => 'Rateio das despesas', 'para' => 'As despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo: a soma das partes é igual ao total.'],
    'AP-063' => ['titulo' => 'Rateio comparativo', 'para' => 'O rateio de duas competências lado a lado, por unidade, com a diferença e a variação.'],
    'AP-064' => ['titulo' => 'Rateio detalhado', 'para' => 'A parte de cada unidade em cada conta do plano.'],
    'AP-065' => ['titulo' => 'Rateio analítico de uma unidade', 'para' => 'Cada despesa da competência e a parte de uma unidade nela. Use para explicar ao morador como chegou o valor dele.'],
    'AP-066' => ['titulo' => 'Rateio analítico detalhado', 'para' => 'Cada despesa da competência dividida entre todas as unidades.'],
    'AP-067' => ['titulo' => 'Rateio por conta', 'para' => 'O rateio por conta do plano, com a maior e a menor parte de uma unidade.'],
    'AP-068' => ['titulo' => 'Relatório de lançamentos', 'para' => 'Os lançamentos das competências escolhidas, com a situação de hoje. Filtre por natureza (receita ou despesa) e situação.'],
    'AP-069' => ['titulo' => 'Relatório de lançamentos detalhado', 'para' => 'Os lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem. Use para conferência e auditoria.'],
    'AP-070' => ['titulo' => 'Relatório de boletos', 'para' => 'Boletos com vencimento no período, com o valor emitido, a data do pagamento e o valor pago.'],
    'AP-071' => ['titulo' => 'Relatório de recebimentos', 'para' => 'Receitas pagas no período, pela data do pagamento, com atraso e conta bancária.'],
    'AP-072' => ['titulo' => 'Recebimentos por conta', 'para' => 'Receitas pagas no período agrupadas por conta do plano ou por conta bancária, com quantos foram pagos com atraso.'],
    'AP-073' => ['titulo' => 'Saldos das contas bancárias', 'para' => 'O saldo de cada conta bancária na data: saldo do dia anterior, entradas, saídas do dia e saldo final.'],
    'AP-074' => ['titulo' => 'Registro de pagamentos', 'para' => 'Contas pagas no período, pela data do pagamento, com a conta bancária, o fornecedor e o documento.'],
    'AP-075' => ['titulo' => 'Memória de cálculo dos encargos', 'para' => 'Mostra como foram calculados correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio. Use para responder ao morador que questiona o valor.', 'relacionados' => ['indices']],
    'AP-076' => ['titulo' => 'Relatório de transferências', 'para' => 'Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.'],
    'AP-084' => ['titulo' => 'Relatório de previsão orçamentária', 'para' => 'Compara o previsto no orçamento com o realizado de cada conta, com a diferença e a execução em porcentagem.'],
    'AP-085' => ['titulo' => 'Relatório de reservas', 'para' => 'Reservas das áreas comuns no período, com a taxa e a situação.'],
    'AP-086' => ['titulo' => 'Relatório de unidades', 'para' => 'As unidades ativas com tipo, andar, fração ideal, área privativa e quantas pessoas, medidores e contas vencidas cada uma tem.'],
    'AP-087' => ['titulo' => 'Listagem de pessoas', 'para' => 'As pessoas ativas por unidade e vínculo, com contato e as preferências de cobrança e comunicado por e-mail.', 'atencao' => ['Este relatório tem dados pessoais (LGPD). Use só para o atendimento do condomínio.']],
    'AP-088' => ['titulo' => 'Listagem de presenças', 'para' => 'As presenças registradas no período.'],
    'AP-089' => ['titulo' => 'Relatório de condomínios', 'para' => 'Os condomínios do seu escopo com CNPJ, cidade, situação e quantas unidades, pessoas e contas bancárias têm.'],
    'AP-090' => ['titulo' => 'Relatório de ocorrências', 'para' => 'As ocorrências registradas no período, com prioridade, situação e o chamado aberto, se houver.'],
    'AP-091' => ['titulo' => 'Relatório de administração', 'para' => 'Registros administrativos do condomínio no período.'],
    'AP-092' => ['titulo' => 'Parecer do conselho', 'para' => 'Pareceres registrados no período.'],
    'AP-093' => ['titulo' => 'Pagamentos pendentes', 'para' => 'As contas a pagar em aberto até a data limite, com fornecedor e documento. As vencidas ficam marcadas.', 'relacionados' => ['contas-a-pagar']],
    'AP-095' => ['titulo' => 'Relatório de mudanças', 'para' => 'As mudanças agendadas no período.'],
    'AP-096' => ['titulo' => 'Relatório de endereços dos condomínios', 'para' => 'Endereço completo e contato de cada condomínio do seu escopo.'],
    'AP-097' => ['titulo' => 'Logs de auditoria', 'para' => 'O registro de auditoria do ERP no período: quem fez o quê e quando. Só aparece para quem acessa todos os condomínios.'],
    'AP-098' => ['titulo' => 'Pessoas com acesso aos condomínios', 'para' => 'Quem tem acesso a cada condomínio por concessão, com a situação e a data em que o acesso foi dado.'],
    'AP-099' => ['titulo' => 'Boletos enviados', 'para' => 'Os avisos de boleto emitido que entraram na fila de envio por e-mail no período.'],
    'AP-100' => ['titulo' => 'Relatórios enviados', 'para' => 'Os relatórios enviados aos condôminos no período.'],

    // ── Importações ───────────────────────────────────────────────────────────────────────────
    'AP-044' => ['titulo' => 'Importar leituras de consumo', 'para' => 'Importa de uma planilha as leituras de água, gás ou energia por relógio e competência. Leitura já lançada na mesma competência é substituída, se ainda não foi cobrada.'],
    'AP-140' => [
        'titulo' => 'Conciliar o extrato bancário (OFX)',
        'para' => 'Importe o extrato da conta bancária em OFX (o arquivo que o internet banking exporta). Cada linha do extrato procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O que não bater fica pendente para você conferir.',
        'dicas' => ['O extrato não cria lançamentos: ele só confere. Lance o que faltar e importe de novo.'],
        'palavras' => ['conciliação', 'ofx', 'extrato', 'internet banking'],
    ],
    'AP-141' => [
        'titulo' => 'Importar nota fiscal eletrônica (NF-e XML)',
        'para' => 'Importe o XML da nota fiscal de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida.',
        'relacionados' => ['fornecedores', 'contas-a-pagar'],
    ],
    'AP-142' => ['titulo' => 'Importar inadimplentes de uma planilha', 'para' => 'Carga do histórico de débitos quando o condomínio chega à Connect: as receitas em aberto das unidades, com vencimento no passado.'],
    'AP-143' => ['titulo' => 'Importar frações ideais de uma planilha', 'para' => 'Importa a fração ideal de cada unidade. A planilha precisa trazer todas as unidades ativas, sem repetir, e a soma tem de dar 1 (100%).', 'relacionados' => ['fracao-ideal']],
    'AP-144' => ['titulo' => 'Importar lançamentos de uma planilha', 'para' => 'Importa receitas e despesas de uma planilha CSV ou XLSX. A prévia confere o arquivo inteiro; só dá para aplicar quando todas as linhas estão certas.'],
    'AP-145' => ['titulo' => 'Importar lançamentos por unidade', 'para' => 'Importa receitas de cada unidade de uma planilha CSV ou XLSX, com a unidade em todas as linhas.'],
    'AP-146' => ['titulo' => 'Importar contas a pagar de uma planilha', 'para' => 'Importa despesas a pagar de uma planilha CSV ou XLSX, com o fornecedor opcional (pelo CNPJ ou CPF).'],

    // ── Configurações ─────────────────────────────────────────────────────────────────────────
    'AP-147' => ['titulo' => 'Dados da administradora', 'para' => 'Os dados da Connect que aparecem nos documentos e no atendimento: razão social, CNPJ, e-mail, telefone, horário de atendimento e prazo de resposta.'],
    'AP-149' => ['titulo' => 'Usuários do sistema', 'para' => 'As pessoas da equipe que entram no ERP, com perfil, situação, 2FA e último acesso.'],
    'AP-150' => [
        'titulo' => 'Permissões dos perfis de usuário',
        'para' => 'A grade define o que cada perfil (Administrador, Financeiro, Atendimento, Consulta) pode fazer em cada módulo.',
        'secoes' => ['Como mudar uma permissão' => "1. Encontre o módulo na linha e o perfil na coluna.\n2. Marque ou desmarque a caixa.\n3. Clique em {{Salvar configurações}}. {{Desfazer alterações}} volta como estava antes de salvar."],
    ],
    'AP-153' => [
        'titulo' => 'Permissões dos moradores no aplicativo',
        'para' => 'A grade define o que cada tipo de pessoa (proprietário, inquilino, morador, responsável financeiro) vê no aplicativo: boletos, prestação de contas, reservas e outros módulos.',
        'secoes' => ['Como mudar uma permissão' => "1. Encontre o módulo na linha e o tipo de pessoa na coluna.\n2. Marque ou desmarque a caixa.\n3. Clique em {{Salvar configurações}}."],
    ],
    'AP-148' => ['titulo' => 'Usuários de imobiliárias', 'para' => 'Acesso de imobiliárias parceiras aos condomínios administrados pela Connect. A imobiliária só consulta, e os contatos dos moradores aparecem mascarados.'],
    'AP-152' => ['titulo' => 'Criar o acesso de um morador', 'para' => 'Cria o usuário do morador no aplicativo, ligado à unidade, e envia o convite por e-mail. Revise o resumo antes de confirmar.'],
    'AP-151' => ['titulo' => 'Envio da prestação de contas aos condôminos', 'para' => 'Define se a prestação de contas é enviada aos condôminos, em que dia, por qual canal e o que vai junto (balancete, demonstrativo, comprovantes).'],
    'AP-154' => ['titulo' => 'Aparência do aplicativo', 'para' => 'Nome exibido, cor principal e logotipo do aplicativo dos moradores.'],
    'AP-155' => ['titulo' => 'Notificações do aplicativo', 'para' => 'Escolha quais avisos o morador recebe no aplicativo: boleto novo, boleto vencendo, comunicado, entrega na portaria, reserva aprovada e assembleia.'],
    'AP-156' => ['titulo' => 'Banners do aplicativo', 'para' => 'Avisos curtos no topo do aplicativo dos moradores, por período. Sem condomínio, o banner aparece para todos.'],
    'AP-159' => ['titulo' => 'Alertas do sistema', 'para' => 'Define quando o ERP alerta a equipe: conta a pagar vencendo, inadimplência acima de um limite, contrato e seguro a vencer.'],
    'AP-157' => ['titulo' => 'Configurações financeiras', 'para' => 'Padrões da cobrança: multa por atraso, juros ao mês, dias de carência, correção, desconto por pontualidade, conta bancária padrão e carteira.', 'relacionados' => ['indices']],
    'AP-158' => ['titulo' => 'Modelo do demonstrativo', 'para' => 'Define como o demonstrativo sai: modelo, agrupamento e se mostra o saldo anterior e a previsão orçamentária.'],
    'AP-160' => [
        'titulo' => 'Plano de contas',
        'para' => 'As contas de receita e despesa usadas em todos os lançamentos e relatórios. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos; com condomínio, é uma conta só dele.',
        'dicas' => ['Contas sintéticas agrupam as analíticas e só somam nos relatórios; os lançamentos vão nas analíticas.'],
        'atencao' => ['Conta com lançamentos não deve ser excluída: inative para ela sair das listas sem perder o histórico.'],
    ],
    'AP-161' => ['titulo' => 'Saldo inicial do plano de contas', 'para' => 'O saldo de cada conta do plano na data em que o condomínio começou a ser administrado pela Connect. É o ponto de partida do razão e do balanço.'],
    'AP-162' => ['titulo' => 'Saldo inicial dos centros de custo', 'para' => 'O saldo de cada centro de custo na data de início, ponto de partida dos relatórios de centro de custo.'],
    'AP-163' => ['titulo' => 'Previsão orçamentária', 'para' => 'O orçamento do exercício: reajuste previsto e fundo de reserva. Ele é comparado com o realizado no relatório de previsão orçamentária.'],
    'AP-164' => ['titulo' => 'Impostos retidos', 'para' => 'Alíquotas de retenção (ISS, INSS, IR e outros) para conferir as notas de serviço. Sem condomínio, vale para todos. O sistema não calcula nem lança o imposto sozinho.'],
    'AP-165' => [
        'titulo' => 'Índices e regra de cobrança',
        'para' => 'A regra de cobrança de cada condomínio (multa, juros e índice de correção das contas em atraso, e quando um acordo é considerado quebrado) e os índices mensais usados na correção.',
        'secoes' => ['Lançar o índice do mês' => "1. Informe a **Competência** e a **Variação (%)** do índice.\n2. Ou importe um arquivo com uma linha por mês, no formato `AAAA-MM;variação`.\n3. Os encargos passam a usar o índice na hora."],
        'relacionados' => ['relatorio-memoria-de-calculo'],
    ],
    'AP-166' => ['titulo' => 'Parâmetros gerais', 'para' => 'Fuso horário, moeda, arredondamento e o tempo para a sessão expirar.'],
    'AP-168' => ['titulo' => 'Tipos de faturas', 'para' => 'O que é cobrado das unidades (taxa condominial, fundo de reserva, consumo) e a conta de receita de cada cobrança, com valor padrão e recorrência. Sem condomínio, o tipo vale para todos.', 'relacionados' => ['faturas']],
    'AP-171' => ['titulo' => 'Situação das integrações', 'para' => 'Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. Esta tela só consulta; a configuração fica no servidor.'],
    'CN-005' => [
        'titulo' => 'Fila de avisos por e-mail',
        'para' => 'Os avisos que o ERP manda por e-mail, como a chamada da lista de espera. Cada aviso tenta até 5 vezes; o que falhou pode ser reenviado daqui.',
    ],
];
