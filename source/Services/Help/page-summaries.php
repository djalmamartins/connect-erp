<?php

declare(strict_types=1);

/*
 * GERADO por `php tools/help.php resumos` (ADR 0625). Não edite à mão: mude o resumo ou as seções do
 * artigo em container/web/support/v1/kb/artigos/ e rode o comando de novo. O `check` confere.
 */

return [
    'AP-001' => [
        'faz' => 'O Dashboard é a primeira tela depois de entrar. Ele mostra, de um condomínio ou da carteira inteira, a cobrança vencida, o que vence em 7 dias, as manutenções, as receitas e despesas do mês como no balancete, a inadimplência por atraso, os alertas, os cadastros, a agenda e as últimas ações da equipe.',
        'artigo' => 'dashboard',
        'topicos' => [
            [
                'id' => 'como-a-tela-esta-organizada',
                'title' => 'Como a tela está organizada',
            ],
            [
                'id' => 'trocar-o-mes',
                'title' => 'Trocar o mês',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-003' => [
        'faz' => 'Tudo de uma unidade em uma tela: quem mora e quem é o responsável financeiro, débitos em aberto, acordos, documentos emitidos e a rotina (reservas, ocorrências, entregas). Ideal para atender um morador ao telefone.',
        'artigo' => 'painel-da-unidade',
        'topicos' => [
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'ver-a-ficha-da-pessoa',
                'title' => 'Ver a ficha da pessoa',
            ],
        ],
    ],
    'AP-004' => [
        'faz' => 'Com um condomínio na barra superior, o menu Condomínios abre a visão geral dele. Em Todos os condomínios, aparece a lista para selecionar um. O cadastro de um condomínio novo segue um guia: unidades, proprietários, síndico e conselho, áreas e o checklist por último.',
        'artigo' => 'condominios',
        'topicos' => [
            [
                'id' => 'selecionar-um-condominio-para-o-sistema-todo',
                'title' => 'Selecionar um condomínio para o sistema todo',
            ],
            [
                'id' => 'visao-geral-do-condominio',
                'title' => 'Visão geral do condomínio',
            ],
            [
                'id' => 'cadastrar-um-condominio-novo',
                'title' => 'Cadastrar um condomínio novo',
            ],
            [
                'id' => 'o-guia-depois-de-salvar',
                'title' => 'O guia depois de salvar',
            ],
        ],
    ],
    'AP-005' => [
        'faz' => 'Cadastre as pessoas de cada unidade: proprietário, inquilino, morador e responsável financeiro. A mesma pessoa pode estar em várias unidades e em vários condomínios: pesquise antes de cadastrar e só acrescente o vínculo.',
        'artigo' => 'proprietarios-e-inquilinos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-006' => [
        'faz' => 'Cada apartamento, casa, sala ou loja é uma unidade. As pessoas (proprietários e inquilinos), os veículos, os animais, as cobranças e as reservas ficam ligados a ela. Cadastre uma por vez ou o prédio inteiro de uma vez pelo cadastro rápido.',
        'artigo' => 'unidades',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'trocar-o-inquilino-ou-o-proprietario',
                'title' => 'Trocar o inquilino ou o proprietário',
            ],
            [
                'id' => 'termo-de-entrada-e-de-saida',
                'title' => 'Termo de entrada e de saída',
            ],
            [
                'id' => 'configurar-unidades-em-massa',
                'title' => 'Configurar unidades em massa',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
        ],
    ],
    'AP-007' => [
        'faz' => 'Áreas vinculáveis são as que pertencem ou ficam reservadas a uma unidade: vagas de garagem, depósitos e boxes. Cadastre e vincule cada uma à unidade que usa.',
        'artigo' => 'areas-vinculaveis',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-008' => [
        'faz' => 'Salão de festas, churrasqueira, academia e outras áreas que os moradores podem reservar. Em cada área você define as regras da reserva: capacidade, antecedência, horário, taxa e se precisa de aprovação.',
        'artigo' => 'areas-comuns',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-009' => [
        'faz' => 'Funcionários próprios do condomínio, como porteiros, zeladores e faxineiros, com função, contato e admissão.',
        'artigo' => 'funcionarios',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-010' => [
        'faz' => 'Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios: empresa de limpeza, manutenção de elevador, concessionárias. O fornecedor aparece nas contas a pagar, nos contratos e na importação de notas fiscais.',
        'artigo' => 'fornecedores',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-011' => [
        'faz' => 'Veículos autorizados de cada unidade, com placa, modelo e cor, para a portaria liberar a entrada e para o controle de vagas.',
        'artigo' => 'veiculos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-012' => [
        'faz' => 'Visitantes frequentes e prestadores autorizados por unidade, como diarista, babá ou familiar. A portaria consulta a lista para liberar a entrada.',
        'artigo' => 'visitantes',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-013' => [
        'faz' => 'Animais de cada unidade, com espécie, raça e vacinação, para o regulamento interno e para a portaria.',
        'artigo' => 'animais-de-estimacao',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-014' => [
        'faz' => 'Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, com o início e o fim do mandato. O síndico aparece nos documentos e nas assinaturas da prestação de contas.',
        'artigo' => 'sindico-e-conselho',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-015' => [
        'faz' => 'Telefones úteis para moradores e portaria: plantão de elevador, eletricista, bombeiro, concessionárias. Sem condomínio, o contato vale para todos.',
        'artigo' => 'contatos-uteis',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-016' => [
        'faz' => 'Advogados e escritórios parceiros que cuidam da cobrança judicial e da assessoria dos condomínios.',
        'artigo' => 'advogados',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-017' => [
        'faz' => 'Votar os itens da pauta da assembleia aberta, um por vez, com o voto de cada unidade presente e quite e a apuração pelo quórum do item.',
        'artigo' => 'votacao',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-votar',
                'title' => 'Como votar',
            ],
        ],
    ],
    'AP-018' => [
        'faz' => 'Criar a assembleia com a pauta, convocar pelo edital, abrir, registrar a presença por unidade, encerrar com a ata ou cancelar.',
        'artigo' => 'assembleia-virtual',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'criar-a-assembleia',
                'title' => 'Criar a assembleia',
            ],
            [
                'id' => 'do-edital-a-ata',
                'title' => 'Do edital à ata',
            ],
        ],
    ],
    'AP-019' => [
        'faz' => 'Registre os bens do condomínio, como móveis, equipamentos, eletrônicos e ferramentas, com valor, data de compra e onde estão. Ajuda no inventário, no seguro e na prestação de contas.',
        'artigo' => 'patrimonio',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-020' => [
        'faz' => 'Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.',
        'artigo' => 'lancamentos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'lancar-para-varias-unidades-de-uma-vez',
                'title' => 'Lançar para várias unidades de uma vez',
            ],
            [
                'id' => 'anexar-nota-fiscal-e-comprovante',
                'title' => 'Anexar nota fiscal e comprovante',
            ],
            [
                'id' => 'atalhos-e-acoes-com-varios-lancamentos',
                'title' => 'Atalhos e ações com vários lançamentos',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-021' => [
        'faz' => 'Receitas e despesas que se repetem todo mês, como contrato de limpeza ou salário. Cadastre uma vez e gere os lançamentos de cada competência sem digitar de novo. Gerar de novo a mesma competência não duplica.',
        'artigo' => 'lancamentos-fixos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-022' => [
        'faz' => 'Despesas em aberto do condomínio, por vencimento. Use para saber o que pagar na semana e para dar baixa depois do pagamento.',
        'artigo' => 'contas-a-pagar',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'lancar-para-varias-unidades-de-uma-vez',
                'title' => 'Lançar para várias unidades de uma vez',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-023' => [
        'faz' => 'Receitas em aberto do condomínio, como taxas condominiais, multas e acordos, por vencimento. Os boletos saem daqui, e a baixa é feita pelo arquivo de retorno do banco ou à mão.',
        'artigo' => 'contas-a-receber',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'lancar-para-varias-unidades-de-uma-vez',
                'title' => 'Lançar para várias unidades de uma vez',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-024' => [
        'faz' => 'Formulário direto para lançar uma despesa do condomínio. Use quando chega uma conta para pagar, como uma nota de serviço ou a conta de água.',
        'artigo' => 'lancar-despesa',
        'topicos' => [
            [
                'id' => 'dados-da-conta-a-pagar',
                'title' => 'Dados da conta a pagar',
            ],
        ],
    ],
    'AP-025' => [
        'faz' => 'Lança uma receita do condomínio (aluguel do salão, rendimento) ou cobrada das unidades, com rateio, valor de cada unidade, fundo de reserva, cobrar de e repetição. A taxa condominial do mês sai pelas Faturas.',
        'artigo' => 'lancar-receita',
        'topicos' => [
            [
                'id' => 'como-lancar',
                'title' => 'Como lançar',
            ],
            [
                'id' => 'anexos',
                'title' => 'Anexos',
            ],
        ],
    ],
    'AP-026' => [
        'faz' => 'Dá desconto numa conta a receber em aberto: o valor da conta diminui e fica registrado o valor antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento em vez de dar 100% de desconto.',
        'artigo' => 'lancar-desconto',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
            [
                'id' => 'como-dar-o-desconto',
                'title' => 'Como dar o desconto',
            ],
        ],
    ],
    'AP-027' => [
        'faz' => 'Multa de uma unidade por descumprir o regulamento ou por uma ocorrência, com valor fixo e motivo. A multa vira uma conta a receber da unidade e entra na cobrança normal.',
        'artigo' => 'lancar-multa-avulsa',
        'topicos' => [
            [
                'id' => 'lancar-multa',
                'title' => 'Lançar multa',
            ],
        ],
    ],
    'AP-028' => [
        'faz' => 'Cobrança fora da fatura do mês para uma ou mais unidades, como chamada de capital ou uma obra aprovada em assembleia. O valor pode ser fixo por unidade, o total dividido igualmente ou rateado pela fração ideal. Gera uma conta a receber por unidade.',
        'artigo' => 'cobrancas-extras',
        'topicos' => [
            [
                'id' => 'lancar-cobranca-extra',
                'title' => 'Lançar cobrança extra',
            ],
        ],
    ],
    'AP-029' => [
        'faz' => 'Registra a transferência de dinheiro entre contas do mesmo condomínio, como da conta corrente para a poupança. Não é receita nem despesa: muda só o saldo de cada conta.',
        'artigo' => 'outros-lancamentos',
        'topicos' => [
            [
                'id' => 'registrar-transferencia',
                'title' => 'Registrar transferência',
            ],
        ],
    ],
    'AP-030' => [
        'faz' => 'O fechamento trava a competência depois de conferida: ninguém lança nem altera nada daquele mês. Confira o resumo (receitas, despesas, resultado e o que está em aberto), feche e, se precisar corrigir, reabra informando o motivo.',
        'artigo' => 'fechamento-mensal',
        'topicos' => [
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'como-fechar-e-reabrir',
                'title' => 'Como fechar e reabrir',
            ],
            [
                'id' => 'fechar-na-ordem-dos-meses',
                'title' => 'Fechar na ordem dos meses',
            ],
        ],
    ],
    'AP-031' => [
        'faz' => 'Pagamentos feitos pelas contas bancárias do condomínio, com favorecido, data e valor.',
        'artigo' => 'pagamentos-bancos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-032' => [
        'faz' => 'Cobrança de cada tipo de fatura das unidades ativas do condomínio. Prepare, confira os valores por unidade e emita para gerar as contas a receber.',
        'artigo' => 'faturas',
        'topicos' => [
            [
                'id' => 'preparar-a-fatura-do-mes',
                'title' => 'Preparar a fatura do mês',
            ],
            [
                'id' => 'formas-de-rateio',
                'title' => 'Formas de rateio',
            ],
            [
                'id' => 'conferir-e-emitir',
                'title' => 'Conferir e emitir',
            ],
            [
                'id' => 'gerar-todos-os-boletos-de-uma-vez',
                'title' => 'Gerar todos os boletos de uma vez',
            ],
            [
                'id' => 'boleto-pago-com-atraso',
                'title' => 'Boleto pago com atraso',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-033' => [
        'faz' => 'Contas a receber vencidas por unidade e os acordos feitos com elas. No acordo, as contas vencidas são trocadas por parcelas mensais.',
        'artigo' => 'acordos',
        'topicos' => [
            [
                'id' => 'fazer-um-acordo',
                'title' => 'Fazer um acordo',
            ],
            [
                'id' => 'contas-de-meses-ja-fechados',
                'title' => 'Contas de meses já fechados',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-034' => [
        'faz' => 'As parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita pelo boleto (arquivo de retorno) ou nos lançamentos.',
        'artigo' => 'parcelas-de-acordo',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-035' => [
        'faz' => 'Boletos das contas a receber das unidades: registro no banco, consulta, cancelamento e baixa pelo arquivo de retorno.',
        'artigo' => 'boletos',
        'topicos' => [
            [
                'id' => 'emitir-um-boleto',
                'title' => 'Emitir um boleto',
            ],
            [
                'id' => 'rateio-no-mesmo-boleto-da-fatura',
                'title' => 'Rateio no mesmo boleto da fatura',
            ],
            [
                'id' => 'carta-de-cobranca-para-o-correio',
                'title' => 'Carta de cobrança para o Correio',
            ],
            [
                'id' => 'boletos-pelo-banco-inter',
                'title' => 'Boletos pelo Banco Inter',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-036' => [
        'faz' => 'O arquivo de retorno é o que o banco devolve com os boletos registrados e pagos. Importando aqui, o sistema registra os boletos e dá baixa nos pagamentos sozinho. Pagamento de valor diferente ou de boleto desconhecido vai para a fila de divergências, para alguém conferir.',
        'artigo' => 'arquivo-de-retorno',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'como-importar',
                'title' => 'Como importar',
            ],
        ],
    ],
    'AP-037' => [
        'faz' => 'Boletos enviados ao banco, agrupados por conta bancária e dia, com a confirmação do registro. No Banco Inter não há arquivo de remessa: cada boleto é registrado na hora.',
        'artigo' => 'remessas',
        'topicos' => [
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
        ],
    ],
    'AP-038' => [
        'faz' => 'Lista os arquivos de retorno importados e a fila das ocorrências que não deram baixa sozinhas: pagamento de valor diferente, repetido, de título cancelado ou com nosso número desconhecido. Resolva cada divergência dando a baixa certa ou ignorando com motivo.',
        'artigo' => 'retornos',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-039' => [
        'faz' => 'Acompanhe as unidades em cobrança judicial: quais contas estão sendo cobradas, o advogado, o número do processo e o andamento.',
        'artigo' => 'cobranca-judicial',
        'topicos' => [
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
            [
                'id' => 'como-abrir-um-caso',
                'title' => 'Como abrir um caso',
            ],
        ],
    ],
    'AP-040' => [
        'faz' => 'Emite o recibo das contas já pagas de uma unidade, escolhidas uma a uma. Cada conta entra em um só recibo válido; se cancelar o recibo, a conta fica livre para outro.',
        'artigo' => 'recibos',
        'topicos' => [
            [
                'id' => 'conferir-autenticidade',
                'title' => 'Conferir autenticidade',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
        ],
    ],
    'AP-041' => [
        'faz' => 'Medidores de água, gás e energia de cada unidade. A leitura inicial é a base da primeira leitura do mês.',
        'artigo' => 'relogios',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-042' => [
        'faz' => 'Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber do consumo.',
        'artigo' => 'leituras',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-043' => [
        'faz' => 'Faixas progressivas de tarifa por tipo de medidor e vigência, usadas para calcular o valor das leituras. Sem condomínio vale o padrão da Connect; a tarifa do condomínio substitui o padrão.',
        'artigo' => 'tarifas',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'como-as-faixas-se-encadeiam',
                'title' => 'Como as faixas se encadeiam',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-044' => [
        'faz' => 'Importa de uma planilha as leituras de água, gás ou energia por relógio e competência. Leitura já lançada na mesma competência é substituída, se ainda não foi cobrada.',
        'artigo' => 'importacao-de-leituras',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-045' => [
        'faz' => 'Leituras, consumo e valor por tipo de medidor nas competências escolhidas.',
        'artigo' => 'consumo-geral',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-046' => [
        'faz' => 'Consumo de cada unidade por tipo de medidor nas competências escolhidas.',
        'artigo' => 'consumo-unidade',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-047' => [
        'faz' => 'Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.',
        'artigo' => 'relatorio-de-acordos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-048' => [
        'faz' => 'Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos são os encargos menos os descontos.',
        'artigo' => 'taxas-de-acordos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-049' => [
        'faz' => 'Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.',
        'artigo' => 'relatorio-de-centros-de-custo',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-050' => [
        'faz' => 'Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação. Use para comparar este ano com o ano passado, por exemplo.',
        'artigo' => 'comparativo',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-051' => [
        'faz' => 'A prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos. É o relatório que normalmente vai para o síndico e o conselho.',
        'artigo' => 'demonstrativo',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-052' => [
        'faz' => 'Receitas e despesas por conta do plano: o previsto, o realizado e o que segue em aberto. Bom para ver onde o condomínio gastou mais que o previsto.',
        'artigo' => 'demonstrativo-contas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-053' => [
        'faz' => 'Cada lançamento das competências escolhidas, agrupado pela conta do plano. Use quando o conselho pede o detalhe de uma conta.',
        'artigo' => 'demonstrativo-analitico',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-054' => [
        'faz' => 'Receitas e despesas das competências separadas por situação: pago, a vencer, vencido e cancelado.',
        'artigo' => 'demonstrativo-situacao',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-056' => [
        'faz' => 'Movimentos pagos e transferências de uma conta bancária do condomínio, com o saldo anterior e o saldo dia a dia. Compare com o extrato do banco para conciliar.',
        'artigo' => 'extrato-conta',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-057' => [
        'faz' => 'Total em atraso de cada condomínio na data de referência: unidades, contas, valor, encargos sugeridos pela regra de cobrança e o maior atraso. Bom para ver a carteira inteira.',
        'artigo' => 'inadimplentes-geral',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-058' => [
        'faz' => 'Cada unidade em atraso na data de referência, com o número de contas, valor, encargos, a conta mais antiga e o maior atraso. É a lista para a cobrança.',
        'artigo' => 'inadimplentes-unidade',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-059' => [
        'faz' => 'Cada conta vencida e em aberto na data de referência, com competência, vencimento, dias de atraso, valor e encargos.',
        'artigo' => 'inadimplentes-detalhado',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-060' => [
        'faz' => 'O atraso separado por tipo de cobrança (conta do plano), como taxa condominial, fundo de reserva ou consumo.',
        'artigo' => 'inadimplentes-taxa',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-061' => [
        'faz' => 'As unidades em atraso com o responsável financeiro, documento, telefone e e-mail, para fazer o contato.',
        'artigo' => 'inadimplentes-morador',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-062' => [
        'faz' => 'As despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo: a soma das partes é igual ao total.',
        'artigo' => 'rateio',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-063' => [
        'faz' => 'O rateio de duas competências lado a lado, por unidade, com a diferença e a variação.',
        'artigo' => 'rateio-comparativo',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-064' => [
        'faz' => 'A parte de cada unidade em cada conta do plano.',
        'artigo' => 'rateio-detalhado',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-065' => [
        'faz' => 'Cada despesa da competência e a parte de uma unidade nela. Use para explicar ao morador como chegou o valor dele.',
        'artigo' => 'rateio-analitico',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-066' => [
        'faz' => 'Cada despesa da competência dividida entre todas as unidades.',
        'artigo' => 'rateio-analitico-detalhado',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-067' => [
        'faz' => 'O rateio por conta do plano, com a maior e a menor parte de uma unidade.',
        'artigo' => 'rateio-por-conta',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-068' => [
        'faz' => 'Os lançamentos das competências escolhidas, com a situação de hoje. Filtre por natureza (receita ou despesa) e situação.',
        'artigo' => 'relatorio-de-lancamentos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-069' => [
        'faz' => 'Os lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem. Use para conferência e auditoria.',
        'artigo' => 'relatorio-de-lancamentos-detalhado',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-070' => [
        'faz' => 'Boletos com vencimento no período, com o valor emitido, a data do pagamento e o valor pago.',
        'artigo' => 'relatorio-de-boletos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-071' => [
        'faz' => 'Receitas pagas no período, pela data do pagamento, com atraso e conta bancária.',
        'artigo' => 'recebimentos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-072' => [
        'faz' => 'Receitas pagas no período agrupadas por conta do plano ou por conta bancária, com quantos foram pagos com atraso.',
        'artigo' => 'recebimentos-por-conta',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-073' => [
        'faz' => 'O saldo de cada conta bancária na data: saldo do dia anterior, entradas, saídas do dia e saldo final.',
        'artigo' => 'saldos-de-contas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-074' => [
        'faz' => 'Contas pagas no período, pela data do pagamento, com a conta bancária, o fornecedor e o documento.',
        'artigo' => 'registro-de-pagamentos',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
        ],
    ],
    'AP-075' => [
        'faz' => 'Mostra como foram calculados correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio. Use para responder ao morador que questiona o valor.',
        'artigo' => 'relatorio-memoria-de-calculo',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-076' => [
        'faz' => 'Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.',
        'artigo' => 'relatorio-de-transferencias',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-079' => [
        'faz' => 'Lançamentos válidos por conta do plano na competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.',
        'artigo' => 'balancete-verificacao',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-080' => [
        'faz' => 'Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês.',
        'artigo' => 'balancete-anual',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-gerar-o-relatorio',
                'title' => 'Como gerar o relatório',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-081' => [
        'faz' => 'O que o condomínio tem e o que deve numa data: saldos bancários, contas a receber e a pagar, fundos e o resultado acumulado.',
        'artigo' => 'balanco-patrimonial',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
            [
                'id' => 'quando-o-balanco-nao-fecha',
                'title' => 'Quando o balanço não fecha',
            ],
        ],
    ],
    'AP-082' => [
        'faz' => 'Receitas menos despesas do período por conta do plano, com as contas sintéticas somadas e o resultado final. Escolha a base: competência ou caixa.',
        'artigo' => 'demonstracao-do-resultado',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-083' => [
        'faz' => 'O movimento de cada conta do plano no período: saldo anterior, cada lançamento a débito ou crédito e o saldo corrido. Informe o código de uma conta para ver só ela.',
        'artigo' => 'livro-razao',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-084' => [
        'faz' => 'O previsto de cada conta no ano contra o que foi lançado (competência), com a diferença e a variação.',
        'artigo' => 'relatorio-de-previsao-orcamentaria',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-gerar-o-relatorio',
                'title' => 'Como gerar o relatório',
            ],
            [
                'id' => 'imprimir',
                'title' => 'Imprimir',
            ],
        ],
    ],
    'AP-085' => [
        'faz' => 'Reservas das áreas comuns no período, com a taxa e a situação.',
        'artigo' => 'relatorio-de-reservas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-086' => [
        'faz' => 'As unidades ativas com tipo, andar, fração ideal, área privativa e quantas pessoas, medidores e contas vencidas cada uma tem.',
        'artigo' => 'relatorio-de-unidades',
        'topicos' => [
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-087' => [
        'faz' => 'As pessoas ativas por unidade e vínculo, com contato e as preferências de cobrança e comunicado por e-mail.',
        'artigo' => 'listagem-de-pessoas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-088' => [
        'faz' => 'As unidades presentes na assembleia, com quem compareceu, procuração e direito a voto, para imprimir ou exportar em CSV.',
        'artigo' => 'listagem-de-presencas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-089' => [
        'faz' => 'Os condomínios do seu escopo com CNPJ, cidade, situação e quantas unidades, pessoas e contas bancárias têm.',
        'artigo' => 'relatorio-de-condominios',
        'topicos' => [
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-090' => [
        'faz' => 'As ocorrências registradas no período, com prioridade, situação e o chamado aberto, se houver.',
        'artigo' => 'relatorio-de-ocorrencias',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-091' => [
        'faz' => 'Os dados da administradora e o resumo dos condomínios do seu escopo, com o fechamento do mês anterior.',
        'artigo' => 'administracao',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-mudar-os-dados-da-administradora',
                'title' => 'Como mudar os dados da administradora',
            ],
        ],
    ],
    'AP-092' => [
        'faz' => 'O resultado de cada item da pauta da assembleia, com o placar e o quórum, e a ata gerada no encerramento.',
        'artigo' => 'parecer',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-093' => [
        'faz' => 'As contas a pagar em aberto até a data limite, com fornecedor e documento. As vencidas ficam marcadas.',
        'artigo' => 'pagamentos-pendentes',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-094' => [
        'faz' => 'Mostra os fechamentos mensais de cada competência, com o resultado e as reaberturas. Use para saber quais meses já foram fechados e se algum foi reaberto.',
        'artigo' => 'listagem-de-prestacoes-de-contas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-095' => [
        'faz' => 'As mudanças agendadas no período.',
        'artigo' => 'mudancas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-096' => [
        'faz' => 'Endereço completo e contato de cada condomínio do seu escopo.',
        'artigo' => 'relatorio-de-enderecos',
        'topicos' => [
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-097' => [
        'faz' => 'O registro de auditoria do ERP no período: quem fez o quê e quando. Só aparece para quem acessa todos os condomínios.',
        'artigo' => 'logs',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-098' => [
        'faz' => 'Quem tem acesso a cada condomínio por concessão, com a situação e a data em que o acesso foi dado.',
        'artigo' => 'pessoas-com-acesso',
        'topicos' => [
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-099' => [
        'faz' => 'Os avisos de boleto emitido que entraram na fila de envio por e-mail no período.',
        'artigo' => 'boletos-enviados',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-100' => [
        'faz' => 'Quem exportou qual relatório em CSV, quando e de qual condomínio, lido da auditoria.',
        'artigo' => 'relatorios-enviados',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'escolher-o-periodo',
                'title' => 'Escolher o período',
            ],
            [
                'id' => 'o-que-aparece',
                'title' => 'O que aparece',
            ],
        ],
    ],
    'AP-101' => [
        'faz' => 'O pacote do mês de um condomínio: resultado, saldos bancários, inadimplência no fim do mês e a situação do fechamento, para conferir e imprimir.',
        'artigo' => 'prestacao-de-contas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
            [
                'id' => 'ocultar-unidade',
                'title' => 'Ocultar unidade',
            ],
            [
                'id' => 'o-documento-da-prestacao-de-contas-pdf',
                'title' => 'O documento da prestação de contas (PDF)',
            ],
        ],
    ],
    'AP-102' => [
        'faz' => 'Receitas e despesas previstas por competência, o resultado de cada mês e o que venceu e segue em aberto, em gráfico e em tabela.',
        'artigo' => 'graficos-gerais',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-103' => [
        'faz' => 'Valores por conta do plano em cada competência. As oito maiores aparecem; as demais somam em "Outras".',
        'artigo' => 'grafico-de-contas',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-104' => [
        'faz' => 'Consumo medido de uma unidade por tipo de medidor e competência. Bom para mostrar ao morador a evolução do consumo.',
        'artigo' => 'grafico-de-consumo-unidade',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-105' => [
        'faz' => 'Consumo medido por tipo de medidor e competência, somando todas as unidades.',
        'artigo' => 'grafico-de-consumo-geral',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-106' => [
        'faz' => 'Recebido e pago por mês, pela data do pagamento, e o saldo de cada mês.',
        'artigo' => 'grafico-financeiro',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-107' => [
        'faz' => 'Por competência: o que foi cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.',
        'artigo' => 'grafico-de-inadimplencia',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-108' => [
        'faz' => 'Saldo de cada mês (entradas menos saídas, com as transferências) por conta bancária.',
        'artigo' => 'grafico-financeiro-por-conta',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-109' => [
        'faz' => 'Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".',
        'artigo' => 'grafico-de-areas-comuns',
        'topicos' => [
            [
                'id' => 'escolher-o-periodo-e-os-filtros',
                'title' => 'Escolher o período e os filtros',
            ],
            [
                'id' => 'o-que-o-relatorio-mostra',
                'title' => 'O que o relatório mostra',
            ],
            [
                'id' => 'imprimir-ou-exportar',
                'title' => 'Imprimir ou exportar',
            ],
        ],
    ],
    'AP-110' => [
        'faz' => 'Agenda as manutenções do condomínio, com o serviço, o fornecedor, a data prevista e a periodicidade (mensal, anual). Ao concluir, registre a data em Concluída em.',
        'artigo' => 'manutencoes',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-111' => [
        'faz' => 'Publica um comunicado para as unidades do condomínio, como aviso de obra, convocação ou regra nova. O comunicado aparece no app e no portal do morador; ele não vai mais por e-mail, para a caixa do morador receber só boleto, atraso, prestação de contas, convite e senha.',
        'artigo' => 'comunicados-gerais',
        'topicos' => [
            [
                'id' => 'novo-comunicado',
                'title' => 'Novo comunicado',
            ],
        ],
    ],
    'AP-112' => [
        'faz' => 'Ocorrências do dia a dia do condomínio, como barulho, vazamento ou dano, com a unidade e a prioridade. Toda ocorrência já nasce como chamado no SAC: o atendimento e a situação ficam no chamado, em Agenda > Atendimento > Chamados.',
        'artigo' => 'ocorrencias',
        'topicos' => [
            [
                'id' => 'a-ocorrencia-e-um-chamado',
                'title' => 'A ocorrência é um chamado',
            ],
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-113' => [
        'faz' => 'As reservas do salão de festas, churrasqueira e demais áreas, seguindo as regras de cada área: antecedência, horário, taxa e aprovação. Reserva que precisa de aprovação aparece em Agendamentos Pendentes.',
        'artigo' => 'reservas-areas-comuns',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-114' => [
        'faz' => 'Reservas que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva, e quem está na lista de espera é avisado.',
        'artigo' => 'agendamentos-pendentes',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-115' => [
        'faz' => 'Quem quer uma área comum numa data já reservada entra na lista de espera, pela ordem de inscrição. Se a reserva for cancelada ou recusada, o primeiro da lista é avisado por e-mail.',
        'artigo' => 'lista-de-espera',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-116' => [
        'faz' => 'As multas avulsas que a unidade ainda não pagou, com vencimento e motivo. A multa entra na cobrança como qualquer conta a receber.',
        'artigo' => 'multas-pendentes',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-117' => [
        'faz' => 'A fila de cobrança mostra as unidades em atraso na ordem de prioridade: primeiro as promessas de pagamento quebradas, depois as próximas ações vencidas, as unidades sem contato e as de maior atraso. Use todo dia para fazer os contatos.',
        'artigo' => 'atendimento-cobranca',
        'topicos' => [
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
            [
                'id' => 'como-atender',
                'title' => 'Como atender',
            ],
            [
                'id' => 'lembrete-da-proxima-acao',
                'title' => 'Lembrete da próxima ação',
            ],
        ],
    ],
    'AP-118' => [
        'faz' => 'As apólices do condomínio: seguradora, vigência, prêmio, cobertura e a apólice em PDF guardada no Disco Virtual. O vencimento próximo aparece nos alertas.',
        'artigo' => 'seguros',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-119' => [
        'faz' => 'Contratos do condomínio com fornecedores: objeto, vigência, valor mensal, reajuste e o contrato assinado no Disco Virtual. O vencimento próximo aparece nos alertas.',
        'artigo' => 'contratos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-120' => [
        'faz' => 'Mudanças de entrada e saída, por dia e período. Só cabe um agendamento por período e dia em cada condomínio, seguindo as regras da configuração de mudança.',
        'artigo' => 'agendamentos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-121' => [
        'faz' => 'Define as regras de mudança de cada condomínio: dias e horários permitidos, antecedência mínima do pedido e taxa.',
        'artigo' => 'configuracoes',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-122' => [
        'faz' => 'Guarde os arquivos do condomínio organizados por pasta: atas, contratos, plantas, notas fiscais, apólices. Os arquivos ficam fora do site e só baixa quem tem acesso ao condomínio.',
        'artigo' => 'disco-virtual',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'mostrar-aos-moradores',
                'title' => 'Mostrar aos moradores',
            ],
        ],
    ],
    'AP-123' => [
        'faz' => 'Emite a certidão negativa de débitos ("nada consta") da unidade na data escolhida. Com conta vencida em aberto, na data ou hoje, a certidão não é emitida: o sistema só informa o débito. Atende os pedidos de CND do aplicativo do morador.',
        'artigo' => 'certidao-de-debitos',
        'topicos' => [
            [
                'id' => 'emitir',
                'title' => 'Emitir',
            ],
            [
                'id' => 'com-debito-nao-emite',
                'title' => 'Com débito, não emite',
            ],
            [
                'id' => 'pedidos-do-aplicativo-do-morador',
                'title' => 'Pedidos do aplicativo do morador',
            ],
        ],
    ],
    'AP-124' => [
        'faz' => 'Declara as contas vencidas em aberto da unidade, com os encargos na data de referência. Só é emitida se houver conta vencida.',
        'artigo' => 'declaracao-de-inadimplencia',
        'topicos' => [
            [
                'id' => 'emitir',
                'title' => 'Emitir',
            ],
        ],
    ],
    'AP-125' => [
        'faz' => 'Comprova que todas as contas da unidade até a competência escolhida estão pagas. Se houver alguma conta em aberto, o comprovante não é emitido e a tela mostra qual falta.',
        'artigo' => 'comprovante-de-quitacao',
        'topicos' => [
            [
                'id' => 'emitir',
                'title' => 'Emitir',
            ],
        ],
    ],
    'AP-126' => [
        'faz' => 'Correspondências enviadas com aviso de recebimento: envio, entrega ou devolução, e o comprovante.',
        'artigo' => 'avisos-de-recebimento',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-127' => [
        'faz' => 'Cheques emitidos pelo condomínio, com a imagem guardada no Disco Virtual.',
        'artigo' => 'copias-de-cheques',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-128' => [
        'faz' => 'Despesas reservadas antes do pagamento, para o dinheiro não ser usado em outra coisa. Na hora de pagar, liquide o empenho com a data e, se houver, o lançamento.',
        'artigo' => 'empenhos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-129' => [
        'faz' => 'Malotes de documentos entre o condomínio e a administradora: envio, recebimento e conferência do conteúdo.',
        'artigo' => 'malotes',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-130' => [
        'faz' => 'Carta em que a administradora declara que a unidade não tem débito vencido, para a finalidade informada (por exemplo, venda ou locação).',
        'artigo' => 'carta-de-anuencia',
        'topicos' => [
            [
                'id' => 'emitir',
                'title' => 'Emitir',
            ],
        ],
    ],
    'AP-131' => [
        'faz' => 'As portarias de cada condomínio: presencial, remota ou híbrida, os turnos e o responsável.',
        'artigo' => 'gerenciamento',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-132' => [
        'faz' => 'Visitas avisadas pela unidade com antecedência, para a portaria liberar na data sem precisar interfonar.',
        'artigo' => 'previsao-de-visita',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-133' => [
        'faz' => 'Entradas e saídas registradas pela portaria: visitantes, prestadores, entregadores e moradores, com data e hora.',
        'artigo' => 'registro-de-acessos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-134' => [
        'faz' => 'Itens encontrados no condomínio, onde estão guardados e a quem foram devolvidos.',
        'artigo' => 'achados-e-perdidos',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-135' => [
        'faz' => 'Encomendas recebidas pela portaria para cada unidade. Quando o morador retirar, informe a retirada para dar baixa.',
        'artigo' => 'entregas',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-136' => [
        'faz' => 'O histórico das encomendas da portaria, com filtros e resumo, e a baixa da retirada com o nome de quem retirou.',
        'artigo' => 'registros-de-entregas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-137' => [
        'faz' => 'Mostra as unidades com conta vencida. O aviso de atraso por e-mail agora é automático: sai com 10, 15, 30, 45 e 60 dias de atraso, só para quem autorizou cobrança por e-mail, e cada boleto é avisado uma vez em cada marco.',
        'artigo' => 'cobranca',
        'topicos' => [
            [
                'id' => 'aviso-automatico-por-e-mail',
                'title' => 'Aviso automático por e-mail',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'para-falar-com-uma-unidade',
                'title' => 'Para falar com uma unidade',
            ],
        ],
    ],
    'AP-138' => [
        'faz' => 'Comunicados e avisos de cobrança que ainda vão sair. Cancele aqui o que ainda não saiu.',
        'artigo' => 'agendada',
        'topicos' => [
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-139' => [
        'faz' => 'Mostra quem recebe os comunicados e os avisos de cobrança em cada unidade. Para corrigir um e-mail ou a preferência da pessoa, abra o cadastro dela em Proprietários e inquilinos.',
        'artigo' => 'enderecos',
        'topicos' => [
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-140' => [
        'faz' => 'Importe o extrato da conta bancária em OFX (o arquivo que o internet banking exporta). Cada linha do extrato procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O que não bater fica pendente para você conferir.',
        'artigo' => 'extrato-bancario-ofx',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'buscar-o-extrato-direto-no-banco-inter',
                'title' => 'Buscar o extrato direto no banco (Inter)',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-141' => [
        'faz' => 'Importe o XML da nota fiscal de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida.',
        'artigo' => 'importacao-nf-e-xml',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-142' => [
        'faz' => 'Carga do histórico de débitos quando o condomínio chega à Connect: as receitas em aberto das unidades, com vencimento no passado.',
        'artigo' => 'inadimplentes-planilha',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-143' => [
        'faz' => 'Importa a fração ideal de cada unidade. A planilha precisa trazer todas as unidades ativas, sem repetir, e a soma tem de dar 1 (100%).',
        'artigo' => 'fracoes-ideais-planilha',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-144' => [
        'faz' => 'Importa receitas e despesas de uma planilha CSV ou XLSX. A prévia confere o arquivo inteiro; só dá para aplicar quando todas as linhas estão certas.',
        'artigo' => 'lancamentos-planilha',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-145' => [
        'faz' => 'Importa receitas de cada unidade de uma planilha CSV ou XLSX, com a unidade em todas as linhas.',
        'artigo' => 'lancamentos-por-unidade',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-146' => [
        'faz' => 'Importa despesas a pagar de uma planilha CSV ou XLSX, com o fornecedor opcional (pelo CNPJ ou CPF).',
        'artigo' => 'contas-a-pagar-planilha',
        'topicos' => [
            [
                'id' => 'enviar-arquivo',
                'title' => 'Enviar arquivo',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
        ],
    ],
    'AP-147' => [
        'faz' => 'Administrativo: e-mail e telefone de atendimento da administradora e o quadro "Sobre esta tela" no topo das telas do ERP.',
        'artigo' => 'administrativo',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-148' => [
        'faz' => 'Cadastrar imobiliárias parceiras, vincular os usuários delas e conceder ou revogar a consulta a cada condomínio.',
        'artigo' => 'usuarios-de-imobiliarias',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-dar-acesso-a-uma-imobiliaria',
                'title' => 'Como dar acesso a uma imobiliária',
            ],
        ],
    ],
    'AP-149' => [
        'faz' => 'Quem da equipe entra no ERP e em quais condomínios; dar ou tirar o acesso a cada condomínio.',
        'artigo' => 'usuarios-do-sistema',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-dar-acesso-a-uma-pessoa',
                'title' => 'Como dar acesso a uma pessoa',
            ],
        ],
    ],
    'AP-150' => [
        'faz' => 'O que cada papel pode fazer no ERP e as exceções de cada usuário, tudo fechado por padrão.',
        'artigo' => 'permissoes-de-usuarios',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'mudar-a-permissao-de-um-papel',
                'title' => 'Mudar a permissão de um papel',
            ],
            [
                'id' => 'criar-uma-excecao-para-uma-pessoa',
                'title' => 'Criar uma exceção para uma pessoa',
            ],
        ],
    ],
    'AP-151' => [
        'faz' => 'Notificações da prestação de contas: avisar o conselho quando o mês for fechado.',
        'artigo' => 'notificacoes-da-prestacao-de-contas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-152' => [
        'faz' => 'Convidar moradores por e-mail para criarem a própria conta, acompanhar os convites e revogar o que ainda está pendente.',
        'artigo' => 'criacao-de-usuarios-para-moradores',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-convidar',
                'title' => 'Como convidar',
            ],
        ],
    ],
    'AP-153' => [
        'faz' => 'Ligar ou desligar cada função do aplicativo do morador no condomínio: boletos, reservas, ocorrências, comunicados, documentos, encomendas, visitantes e mais.',
        'artigo' => 'permissoes-de-moradores',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
        ],
    ],
    'AP-154' => [
        'faz' => 'Layout da aplicação: nome exibido no aplicativo.',
        'artigo' => 'layout-da-aplicacao',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-155' => [
        'faz' => 'Notificações do aplicativo: enviar comunicados por e-mail; dias entre avisos de cobrança da mesma unidade.',
        'artigo' => 'notificacoes-aplicativo',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-156' => [
        'faz' => 'Avisos curtos no topo do aplicativo dos moradores, por período. Sem condomínio, o banner aparece para todos.',
        'artigo' => 'banners',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-157' => [
        'faz' => 'Financeiro: dia de vencimento padrão das faturas.',
        'artigo' => 'financeiro',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
            [
                'id' => 'textos-da-carta-de-cobranca',
                'title' => 'Textos da carta de cobrança',
            ],
        ],
    ],
    'AP-158' => [
        'faz' => 'Demonstrativo: mostrar contas em aberto no demonstrativo; agrupar o demonstrativo por.',
        'artigo' => 'configuracoes-demonstrativo',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-159' => [
        'faz' => 'Alertas: alertar unidade em atraso há mais de (dias); alertar contrato e seguro que vencem em (dias); alertar conta bancária com saldo abaixo de.',
        'artigo' => 'alertas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-160' => [
        'faz' => 'As contas de receita e despesa usadas em todos os lançamentos e relatórios. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos; com condomínio, é uma conta só dele.',
        'artigo' => 'plano-de-contas',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-161' => [
        'faz' => 'O saldo de partida de cada conta analítica do plano de contas numa data, com a soma nas contas sintéticas.',
        'artigo' => 'saldo-inicial-plano-de-contas',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-lancar',
                'title' => 'Como lançar',
            ],
        ],
    ],
    'AP-162' => [
        'faz' => 'Saldo de partida de cada centro de custo do condomínio numa data, para o relatório de centros de custo começar do valor certo.',
        'artigo' => 'saldo-inicial-centro-de-custo',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-lancar',
                'title' => 'Como lançar',
            ],
        ],
    ],
    'AP-163' => [
        'faz' => 'Quanto o condomínio prevê receber e gastar em cada conta do plano, mês a mês, para comparar depois com o que foi lançado.',
        'artigo' => 'previsao-orcamentaria',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-preencher',
                'title' => 'Como preencher',
            ],
        ],
    ],
    'AP-164' => [
        'faz' => 'Alíquotas de retenção (ISS, INSS, IR e outros) para conferir as notas de serviço. Sem condomínio, vale para todos. O sistema não calcula nem lança o imposto sozinho.',
        'artigo' => 'imposto',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'AP-165' => [
        'faz' => 'A regra de cobrança de cada condomínio (multa, juros e índice de correção das contas em atraso, e quando um acordo é considerado quebrado) e os índices mensais usados na correção.',
        'artigo' => 'indices',
        'topicos' => [
            [
                'id' => 'como-usar',
                'title' => 'Como usar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'lancar-o-indice-do-mes',
                'title' => 'Lançar o índice do mês',
            ],
        ],
    ],
    'AP-166' => [
        'faz' => 'Parâmetros: tolerância da soma das frações ideais; máximo de linhas por relatório.',
        'artigo' => 'parametros',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'os-parametros',
                'title' => 'Os parâmetros',
            ],
            [
                'id' => 'como-alterar',
                'title' => 'Como alterar',
            ],
        ],
    ],
    'AP-167' => [
        'faz' => 'A grade mostra todas as unidades do condomínio com a área privativa e a fração ideal, para preencher tudo de uma vez. A fração ideal define a parte de cada unidade no rateio das despesas.',
        'artigo' => 'fracao-ideal',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'unidades-de-residencial-jardim-das-acacias',
                'title' => 'Unidades de Residencial Jardim das Acácias',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'dividir-igualmente-ou-pela-area',
                'title' => 'Dividir igualmente ou pela área',
            ],
        ],
    ],
    'AP-168' => [
        'faz' => 'O que é cobrado das unidades (taxa condominial, fundo de reserva, consumo) e a conta de receita de cada cobrança, com valor padrão e recorrência. Sem condomínio, o tipo vale para todos.',
        'artigo' => 'tipos-de-faturas',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'AP-171' => [
        'faz' => 'Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. No card Banco Inter você também liga uma conta do Inter (ambiente de testes) à cobrança; o resto da configuração fica no servidor.',
        'artigo' => 'integracoes',
        'topicos' => [
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'tokens-de-acesso-connect-talk',
                'title' => 'Tokens de acesso (Connect Talk)',
            ],
        ],
    ],
    'CN-001' => [
        'faz' => 'Registra as visitas presenciais aos condomínios, com check-in pelo GPS do celular, checklist do que foi verificado, fotos e o relatório final.',
        'artigo' => 'vistorias',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'responsavel-e-chamado-automatico',
                'title' => 'Responsável e chamado automático',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
        ],
    ],
    'CN-002' => [
        'faz' => 'Centros de custo agrupam lançamentos por área ou projeto, como obra da fachada ou manutenção predial, para saber quanto cada um consumiu. Sem condomínio, o centro de custo vale para todos.',
        'artigo' => 'centros-de-custo',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'CN-003' => [
        'faz' => 'Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal. A conta de cobrança precisa dos dados do convênio para emitir boletos.',
        'artigo' => 'contas-bancarias',
        'topicos' => [
            [
                'id' => 'como-cadastrar',
                'title' => 'Como cadastrar',
            ],
            [
                'id' => 'aplicacao-financeira',
                'title' => 'Aplicação financeira',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'CN-004' => [
        'faz' => 'Quando o condomínio precisa da administradora, abra um chamado: ele cai na fila do SAC, a equipe é avisada na hora e você acompanha o andamento e as respostas por aqui.',
        'artigo' => 'chamados',
        'topicos' => [
            [
                'id' => 'outros-botoes-da-tela',
                'title' => 'Outros botões da tela',
            ],
            [
                'id' => 'abrir-chamado',
                'title' => 'Abrir chamado',
            ],
            [
                'id' => 'acompanhar-e-responder',
                'title' => 'Acompanhar e responder',
            ],
            [
                'id' => 'pesquisar-e-filtrar',
                'title' => 'Pesquisar e filtrar',
            ],
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'atender-pelo-erp',
                'title' => 'Atender pelo ERP',
            ],
        ],
    ],
    'CN-005' => [
        'faz' => 'Os avisos que o ERP manda por e-mail ao morador: boleto no dia do vencimento, atraso com 10, 15, 30, 45 e 60 dias, prestação de contas e convite. Cada aviso tenta até 5 vezes; o que falhou pode ser reenviado daqui.',
        'artigo' => 'fila-de-avisos',
        'topicos' => [
            [
                'id' => 'o-que-a-lista-mostra',
                'title' => 'O que a lista mostra',
            ],
            [
                'id' => 'acoes-de-cada-linha',
                'title' => 'Ações de cada linha',
            ],
        ],
    ],
    'CN-006' => [
        'faz' => 'Movimentos entre contas patrimoniais, como apropriar ou usar o fundo de reserva e ajustar o patrimônio líquido. Não entram na DRE e não mexem no banco.',
        'artigo' => 'lancamentos-patrimoniais',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-lancar',
                'title' => 'Como lançar',
            ],
            [
                'id' => 'importar-uma-planilha',
                'title' => 'Importar uma planilha',
            ],
            [
                'id' => 'estornar',
                'title' => 'Estornar',
            ],
        ],
    ],
    'CN-007' => [
        'faz' => 'Tudo o que entrou e saiu do caixa do condomínio no mês, com o saldo dia a dia e as notas fiscais e comprovantes anexados aos lançamentos. Sai pronto em PDF, ou num pacote com os anexos.',
        'artigo' => 'livro-caixa',
        'topicos' => [
            [
                'id' => 'para-que-serve',
                'title' => 'Para que serve',
            ],
            [
                'id' => 'como-gerar-o-livro-do-mes',
                'title' => 'Como gerar o livro do mês',
            ],
            [
                'id' => 'o-que-entra-no-livro',
                'title' => 'O que entra no livro',
            ],
            [
                'id' => 'link-para-os-moradores',
                'title' => 'Link para os moradores',
            ],
            [
                'id' => 'notas-fiscais-e-comprovantes',
                'title' => 'Notas fiscais e comprovantes',
            ],
        ],
    ],
    'CN-008' => [
        'faz' => 'Cadastre o valor mensal que cada condomínio paga à Connect pela administração, acompanhe o total do mês e peça a nota fiscal de serviço (NFS-e) de cada um.',
        'artigo' => 'honorarios',
        'topicos' => [
            [
                'id' => 'como-cadastrar-os-honorarios',
                'title' => 'Como cadastrar os honorários',
            ],
            [
                'id' => 'ligar-e-desligar-as-notas-fiscais',
                'title' => 'Ligar e desligar as notas fiscais',
            ],
            [
                'id' => 'como-pedir-a-nota-do-mes',
                'title' => 'Como pedir a nota do mês',
            ],
            [
                'id' => 'cancelar-um-pedido',
                'title' => 'Cancelar um pedido',
            ],
        ],
    ],
    'CN-009' => [
        'faz' => 'Veja tudo o que o sistema guarda de uma pessoa, entregue o relatório para ela e anonimize os dados de quem já saiu do condomínio.',
        'artigo' => 'dados-pessoais',
        'topicos' => [
            [
                'id' => 'antes-de-buscar',
                'title' => 'Antes de buscar',
            ],
            [
                'id' => 'como-ver-os-dados-de-uma-pessoa',
                'title' => 'Como ver os dados de uma pessoa',
            ],
            [
                'id' => 'como-anonimizar',
                'title' => 'Como anonimizar',
            ],
            [
                'id' => 'quando-nao-da-para-anonimizar',
                'title' => 'Quando não dá para anonimizar',
            ],
            [
                'id' => 'politica-termos-cookies-e-encarregado',
                'title' => 'Política, termos, cookies e encarregado',
            ],
        ],
    ],
    'CN-010' => [
        'faz' => 'Veja num lugar só os compromissos do dia e da semana, os chamados que estão com você, as vistorias que vencem e os documentos para vencer.',
        'artigo' => 'meu-dia',
        'topicos' => [
            [
                'id' => 'o-que-a-tela-mostra',
                'title' => 'O que a tela mostra',
            ],
            [
                'id' => 'como-marcar-um-compromisso',
                'title' => 'Como marcar um compromisso',
            ],
            [
                'id' => 'como-agendar-um-chamado',
                'title' => 'Como agendar um chamado',
            ],
            [
                'id' => 'vistoria-que-vira-chamado',
                'title' => 'Vistoria que vira chamado',
            ],
            [
                'id' => 'aparecer-no-balao-do-sac',
                'title' => 'Aparecer no balão do SAC',
            ],
        ],
    ],
];
