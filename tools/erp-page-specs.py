#!/usr/bin/env python3
"""Gera container/erp/connect/v1/components/layout/page-specs.php: a definição visual de
cada página do catálogo (colunas, filtros, campos, abas, indicadores).

As fichas docs/specs/pages/ap-*.md ainda não confirmam campos (PENDENTE). Estas
definições são PROPOSTAS de interface derivadas do nome e do domínio de cada página,
marcadas como tal na tela; não são regras de negócio e devem ser revistas com as fichas.

Notação de coluna/campo: "Rótulo:tipo" com tipos condo, unit, person, doc, phone,
email, date, month, money, int, pct, text, longtext, bank, select(a|b|c), status(a|b|c),
file, bool. Campos aceitam sufixos "!" (obrigatório) e "@N" (largura em 12 colunas).
"""
import json, pathlib, subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
CATALOG = json.loads(subprocess.check_output(["php", "-r", f'echo json_encode(require "{ROOT}/container/erp/connect/v1/components/layout/catalog.php");']))

def L(entity, cols, fields, filters=("condo", "search"), tabs=("Dados", "Vínculos", "Histórico"), kpis=None, gender="o"):
    return {"entity": entity, "gender": gender, "columns": cols, "fields": fields, "filters": list(filters), "tabs": list(tabs), "kpis": kpis or []}

ATIVO = "status(Ativo|Inativo)"
SPECS = {
    # Condomínios
    "AP-004": L("condomínio", ["Condomínio:condo", "CNPJ:doc", "Cidade:text", "Unidades:int", "Síndico:person", "Situação:status(Ativo|Em implantação|Inativo)"],
                ["Nome do condomínio:text!@8", "CNPJ:doc!@4", "Tipo:select(Residencial|Comercial|Misto)@4", "Início da administração:date!@4", "Dia de vencimento:int!@4", "CEP:text@3", "Endereço:text!@6", "Número:text@3", "Cidade:text!@6", "UF:select(SP|MG|RJ|PR)@3", "E-mail do condomínio:email@6", "Telefone:phone@6", "Observações:longtext@12"],
                filters=("search", "status", "city"), tabs=("Dados", "Unidades", "Contas bancárias", "Configurações", "Vínculos", "Histórico"),
                kpis=[["Condomínios ativos", "24"], ["Unidades administradas", "1.186"], ["Em implantação", "2"]]),
    "AP-006": L("unidade", ["Unidade:unit", "Condomínio:condo", "Bloco:text", "Fração ideal:pct", "Proprietário:person", "Situação:status(Ocupada|Vaga|Em obra)"],
                ["Condomínio:condo!@6", "Bloco/torre:text@3", "Unidade:text!@3", "Tipo:select(Apartamento|Casa|Sala|Loja|Vaga)@4", "Área privativa (m²):int@4", "Fração ideal (%):pct@4", "Proprietário:person@6", "Morador responsável:person@6"],
                tabs=("Dados", "Pessoas", "Vínculos", "Financeiro", "Histórico"), gender="a"),
    "AP-008": L("área comum", ["Área:text", "Condomínio:condo", "Capacidade:int", "Taxa de reserva:money", "Reserva:status(Exige aprovação|Livre|Bloqueada)"],
                ["Condomínio:condo!@6", "Nome da área:text!@6", "Capacidade (pessoas):int@4", "Taxa de reserva:money@4", "Antecedência mínima (dias):int@4", "Regras de uso:longtext@12"], gender="a"),
    "AP-007": L("área vinculável", ["Área:text", "Condomínio:condo", "Unidade vinculada:unit", "Tipo:select(Vaga|Depósito|Box)", "Situação:status(Vinculada|Livre)"],
                ["Condomínio:condo!@6", "Identificação:text!@6", "Tipo:select(Vaga de garagem|Depósito|Box)@6", "Unidade vinculada:unit@6"], gender="a"),
    "AP-014": L("conselheiro", ["Nome:person", "Condomínio:condo", "Cargo:select(Síndico|Subsíndico|Conselheiro fiscal|Conselheiro consultivo)", "Mandato até:date", "Situação:" + ATIVO],
                ["Condomínio:condo!@6", "Pessoa:person!@6", "Cargo:select(Síndico|Subsíndico|Conselheiro fiscal|Conselheiro consultivo)!@6", "Início do mandato:date!@3", "Fim do mandato:date!@3"]),
    "AP-019": L("bem", ["Bem:text", "Condomínio:condo", "Patrimônio nº:int", "Aquisição:date", "Valor:money", "Estado:status(Bom|Manutenção|Baixado)"],
                ["Condomínio:condo!@6", "Descrição:text!@6", "Número de patrimônio:int@4", "Data de aquisição:date@4", "Valor de aquisição:money@4", "Localização:text@6", "Nota fiscal:file@6"]),
    # Cadastros
    "AP-005": L("pessoa", ["Nome:person", "CPF:doc", "Unidade:unit", "Vínculo:select(Proprietário|Inquilino|Morador|Responsável financeiro)", "Telefone:phone", "Situação:" + ATIVO],
                ["Nome completo:person!@8", "CPF/CNPJ:doc!@4", "E-mail:email@6", "Telefone:phone@6", "Condomínio:condo!@6", "Unidade:unit!@3", "Vínculo:select(Proprietário|Inquilino|Morador|Responsável financeiro)!@3", "Início do vínculo:date@4", "Fim do vínculo:date@4", "Recebe boleto por e-mail:bool@4"],
                tabs=("Dados", "Unidades", "Vínculos", "Financeiro", "Histórico"), gender="a"),
    "AP-009": L("funcionário", ["Nome:person", "Condomínio:condo", "Função:select(Zelador|Porteiro|Faxineiro|Jardineiro)", "Admissão:date", "Turno:select(Manhã|Tarde|Noite|12x36)", "Situação:" + ATIVO],
                ["Nome completo:person!@8", "CPF:doc!@4", "Condomínio:condo!@6", "Função:select(Zelador|Porteiro|Faxineiro|Jardineiro)!@6", "Admissão:date!@4", "Turno:select(Manhã|Tarde|Noite|12x36)@4", "Salário:money@4", "Telefone:phone@6", "E-mail:email@6"]),
    "AP-010": L("fornecedor", ["Fornecedor:text", "CNPJ:doc", "Categoria:select(Manutenção|Limpeza|Segurança|Elevadores|Jardinagem)", "Contato:phone", "Contratos:int", "Situação:" + ATIVO],
                ["Razão social:text!@8", "CNPJ/CPF:doc!@4", "Nome fantasia:text@6", "Categoria:select(Manutenção|Limpeza|Segurança|Elevadores|Jardinagem)!@6", "E-mail:email@6", "Telefone:phone@6", "Banco:bank@4", "Agência:text@4", "Conta:text@4", "Chave Pix:text@6"],
                filters=("search", "category", "status"), tabs=("Dados", "Contratos", "Contas a pagar", "Histórico")),
    "AP-016": L("advogado", ["Nome:person", "OAB:text", "Escritório:text", "Processos:int", "Telefone:phone"],
                ["Nome:person!@8", "OAB:text!@4", "Escritório:text@6", "E-mail:email@6", "Telefone:phone@6", "Honorários (%):pct@6"], filters=("search",)),
    "AP-011": L("veículo", ["Placa:text", "Modelo:text", "Cor:select(Prata|Preto|Branco|Vermelho)", "Unidade:unit", "Condomínio:condo"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Placa:text!@4", "Modelo:text@4", "Cor:select(Prata|Preto|Branco|Vermelho)@4", "Vaga:text@6"]),
    "AP-012": L("visitante", ["Nome:person", "Documento:doc", "Unidade visitada:unit", "Condomínio:condo", "Última visita:date", "Acesso:status(Liberado|Bloqueado)"],
                ["Nome:person!@8", "Documento:doc!@4", "Condomínio:condo!@6", "Unidade:unit!@6", "Telefone:phone@6", "Acesso permanente:bool@6"]),
    "AP-013": L("animal", ["Nome:text", "Espécie:select(Cão|Gato|Ave)", "Raça:text", "Unidade:unit", "Condomínio:condo"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Nome:text!@6", "Espécie:select(Cão|Gato|Ave)@3", "Porte:select(Pequeno|Médio|Grande)@3", "Vacinação em dia:bool@6"]),
    "AP-015": L("contato útil", ["Nome:text", "Serviço:select(Encanador|Eletricista|Chaveiro|Dedetização)", "Telefone:phone", "Condomínio:condo"],
                ["Condomínio:condo@6", "Nome:text!@6", "Serviço:select(Encanador|Eletricista|Chaveiro|Dedetização)!@6", "Telefone:phone!@6", "Observações:longtext@12"]),
    # Financeiro
    "AP-020": L("lançamento", ["Data:date", "Condomínio:condo", "Descrição:text", "Conta:select(Taxa condominial|Água|Energia|Manutenção|Salários)", "Tipo:select(Receita|Despesa)", "Valor:money"],
                ["Condomínio:condo!@6", "Tipo:select(Receita|Despesa)!@3", "Data:date!@3", "Descrição:text!@8", "Valor:money!@4", "Plano de contas:select(Taxa condominial|Água|Energia|Manutenção|Salários)!@6", "Centro de custo:select(Administração|Manutenção|Pessoal)@6"],
                filters=("condo", "period", "search"), kpis=[["Receitas", "R$ 412.380"], ["Despesas", "R$ 371.905"], ["Saldo do período", "R$ 40.475"]]),
    "AP-021": L("lançamento fixo", ["Descrição:text", "Condomínio:condo", "Conta:select(Salários|Elevadores|Portaria|Seguro)", "Dia:int", "Valor:money", "Situação:" + ATIVO],
                ["Condomínio:condo!@6", "Descrição:text!@6", "Plano de contas:select(Salários|Elevadores|Portaria|Seguro)!@6", "Dia do lançamento:int!@3", "Valor:money!@3", "Início:month!@3", "Fim:month@3"]),
    "AP-022": L("conta a pagar", ["Vencimento:date", "Fornecedor:text", "Condomínio:condo", "Descrição:text", "Valor:money", "Situação:status(A vencer|Vencida|Paga|Agendada)"],
                ["Condomínio:condo!@6", "Fornecedor:text!@6", "Descrição:text!@8", "Documento/NF:text@4", "Vencimento:date!@4", "Valor:money!@4", "Plano de contas:select(Manutenção|Elevadores|Limpeza|Energia)!@4", "Anexo:file@12"],
                filters=("condo", "period", "status", "search"), kpis=[["A pagar em 7 dias", "R$ 342.115"], ["Vencidas", "R$ 18.740"], ["Pagas no mês", "R$ 896.210"]], gender="a"),
    "AP-023": L("conta a receber", ["Vencimento:date", "Unidade:unit", "Condomínio:condo", "Pagador:person", "Valor:money", "Situação:status(A vencer|Vencida|Recebida|Em acordo)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Pagador:person!@6", "Descrição:text!@6", "Vencimento:date!@4", "Valor:money!@4", "Competência:month!@4"],
                filters=("condo", "period", "status", "search"), kpis=[["A receber no mês", "R$ 1.469.020"], ["Recebido", "R$ 1.284.530"], ["Em atraso", "R$ 187.940"]], gender="a"),
    "AP-028": L("cobrança extra", ["Descrição:text", "Condomínio:condo", "Parcelas:int", "Valor total:money", "Início:month", "Situação:status(Aprovada|Em cobrança|Concluída)"],
                ["Condomínio:condo!@6", "Descrição:text!@6", "Valor total:money!@4", "Parcelas:int!@4", "Primeira competência:month!@4", "Rateio:select(Por fração ideal|Igual por unidade)!@6", "Ata da assembleia:file@6"], gender="a"),
    "AP-031": L("pagamento", ["Data:date", "Conta bancária:bank", "Favorecido:text", "Condomínio:condo", "Valor:money", "Situação:status(Pendente de aprovação|Aprovado|Enviado ao banco|Rejeitado)"],
                ["Condomínio:condo!@6", "Conta bancária:bank!@6", "Favorecido:text!@6", "Data:date!@3", "Valor:money!@3"], filters=("condo", "period", "status")),
    "AP-032": L("fatura", ["Competência:month", "Condomínio:condo", "Unidades:int", "Valor total:money", "Emissão:date", "Situação:status(Em preparação|Emitida|Enviada)"],
                ["Condomínio:condo!@6", "Competência:month!@3", "Vencimento:date!@3", "Tipo de fatura:select(Taxa condominial|Extra|Fundo de reserva)!@6"], filters=("condo", "period", "status"), gender="a"),
    "AP-040": L("recibo", ["Número:int", "Data:date", "Pagador:person", "Unidade:unit", "Valor:money"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Pagador:person!@6", "Valor:money!@3", "Data:date!@3", "Referente a:text!@12"], filters=("condo", "period", "search")),
    # Cobrança
    "AP-117": L("atendimento", ["Data:date", "Unidade:unit", "Devedor:person", "Canal:select(Telefone|E-mail|WhatsApp|Presencial)", "Débito:money", "Resultado:status(Promessa de pagamento|Sem contato|Acordo proposto)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Canal:select(Telefone|E-mail|WhatsApp|Presencial)!@6", "Data do retorno:date@6", "Registro do atendimento:longtext!@12"]),
    "AP-033": L("acordo", ["Nº:int", "Unidade:unit", "Devedor:person", "Parcelas:int", "Valor:money", "Situação:status(Em dia|Parcela em atraso|Quitado|Rompido)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Débitos incluídos:text!@12", "Entrada:money@4", "Parcelas:int!@4", "Primeiro vencimento:date!@4", "Juros (% ao mês):pct@4", "Multa (%):pct@4", "Desconto:money@4"],
                filters=("condo", "status", "search"), kpis=[["Acordos ativos", "38"], ["Valor em acordos", "R$ 214.860"], ["Parcelas em atraso", "6"]]),
    "AP-034": L("parcela", ["Acordo:int", "Parcela:text", "Unidade:unit", "Vencimento:date", "Valor:money", "Situação:status(A vencer|Paga|Vencida)"], [], filters=("condo", "period", "status"), gender="a"),
    "AP-035": L("boleto", ["Nosso número:int", "Unidade:unit", "Pagador:person", "Vencimento:date", "Valor:money", "Situação:status(Registrado|Pago|Vencido|Cancelado)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Vencimento:date!@4", "Valor:money!@4", "Instruções:text@4"], filters=("condo", "period", "status", "search")),
    "AP-037": L("remessa", ["Arquivo:text", "Banco:bank", "Condomínio:condo", "Boletos:int", "Gerada em:date", "Situação:status(Gerada|Enviada|Confirmada)"], ["Condomínio:condo!@6", "Conta bancária:bank!@6"], filters=("condo", "period", "status"), gender="a"),
    "AP-038": L("retorno", ["Arquivo:text", "Banco:bank", "Processado em:date", "Liquidações:int", "Valor:money", "Situação:status(Processado|Com divergências|Pendente)"], [], filters=("period", "status")),
    "AP-039": L("processo", ["Processo:text", "Unidade:unit", "Devedor:person", "Advogado:person", "Valor da causa:money", "Fase:status(Notificação|Ajuizado|Sentença|Acordo judicial)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Advogado:person!@6", "Número do processo:text@6", "Valor da causa:money@6", "Distribuição:date@6"]),
    "AP-116": L("multa", ["Data:date", "Unidade:unit", "Área:text", "Motivo:text", "Valor:money", "Situação:status(Pendente|Lançada|Cancelada)"], [], filters=("condo", "period", "status"), gender="a"),
    # Operacional
    "AP-110": L("manutenção", ["Data prevista:date", "Condomínio:condo", "Serviço:text", "Fornecedor:text", "Periodicidade:select(Mensal|Trimestral|Anual)", "Situação:status(Agendada|Em execução|Concluída|Atrasada)"],
                ["Condomínio:condo!@6", "Serviço:text!@6", "Fornecedor:text@6", "Data prevista:date!@3", "Periodicidade:select(Única|Mensal|Trimestral|Anual)@3", "Descrição:longtext@12"], gender="a"),
    "AP-112": L("ocorrência", ["Nº:int", "Abertura:date", "Condomínio:condo", "Assunto:text", "Prioridade:select(Baixa|Média|Alta)", "Situação:status(Aberta|Em andamento|Resolvida)"],
                ["Condomínio:condo!@6", "Unidade:unit@6", "Assunto:text!@8", "Prioridade:select(Baixa|Média|Alta)!@4", "Descrição:longtext!@12", "Fotos:file@12"], filters=("condo", "status", "search"), gender="a"),
    "AP-113": L("reserva", ["Data:date", "Área:text", "Unidade:unit", "Morador:person", "Taxa:money", "Situação:status(Confirmada|Aguardando aprovação|Cancelada)"],
                ["Condomínio:condo!@6", "Área comum:text!@6", "Unidade:unit!@6", "Data:date!@3", "Horário:text@3"], filters=("condo", "period", "status"), gender="a"),
    "AP-114": L("agendamento", ["Solicitado em:date", "Área:text", "Unidade:unit", "Data desejada:date", "Situação:status(Aguardando aprovação)"], [], filters=("condo", "period")),
    "AP-115": L("pedido", ["Posição:int", "Área:text", "Unidade:unit", "Data desejada:date", "Inscrito em:date"], [], filters=("condo",)),
    "AP-118": L("seguro", ["Apólice:text", "Condomínio:condo", "Seguradora:text", "Vigência até:date", "Prêmio:money", "Situação:status(Vigente|A renovar|Vencido)"],
                ["Condomínio:condo!@6", "Seguradora:text!@6", "Apólice:text!@4", "Início da vigência:date!@4", "Fim da vigência:date!@4", "Prêmio:money@6", "Cobertura:money@6", "Apólice (PDF):file@12"]),
    "AP-119": L("contrato", ["Contrato:text", "Condomínio:condo", "Fornecedor:text", "Vigência até:date", "Valor mensal:money", "Situação:status(Vigente|A renovar|Encerrado)"],
                ["Condomínio:condo!@6", "Fornecedor:text!@6", "Objeto:text!@12", "Início:date!@4", "Fim:date@4", "Valor mensal:money!@4", "Reajuste:select(IPCA|IGP-M|Sem reajuste)@6", "Contrato assinado:file@6"]),
    "AP-120": L("mudança", ["Data:date", "Unidade:unit", "Morador:person", "Tipo:select(Entrada|Saída)", "Situação:status(Agendada|Realizada|Cancelada)"],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Tipo:select(Entrada|Saída)!@4", "Data:date!@4", "Período:select(Manhã|Tarde)@4"], gender="a"),
    "AP-121": L("regra de mudança", ["Condomínio:condo", "Dias permitidos:text", "Horário:text", "Antecedência:int", "Taxa:money"],
                ["Condomínio:condo!@6", "Dias permitidos:text!@6", "Horário:text!@4", "Antecedência mínima (dias):int@4", "Taxa:money@4"], gender="a"),
    "AP-041": L("relógio", ["Medidor:text", "Unidade:unit", "Tipo:select(Água|Gás|Energia)", "Instalado em:date", "Situação:" + ATIVO],
                ["Condomínio:condo!@6", "Unidade:unit!@6", "Tipo:select(Água|Gás|Energia)!@4", "Número do medidor:text!@4", "Instalação:date@4"]),
    "AP-042": L("leitura", ["Competência:month", "Unidade:unit", "Medidor:text", "Leitura anterior:int", "Leitura atual:int", "Consumo (m³):int"],
                ["Condomínio:condo!@6", "Competência:month!@6", "Unidade:unit!@6", "Leitura atual:int!@6"], filters=("condo", "period"), gender="a"),
    "AP-043": L("tarifa", ["Faixa:text", "Tipo:select(Água|Gás)", "De (m³):int", "Até (m³):int", "Valor por m³:money"],
                ["Tipo:select(Água|Gás)!@4", "De (m³):int!@4", "Até (m³):int!@4", "Valor por m³:money!@6", "Vigência:date@6"], filters=("condo",), gender="a"),
    "AP-131": L("portaria", ["Condomínio:condo", "Tipo:select(Presencial|Remota|Híbrida)", "Turnos:int", "Responsável:person", "Situação:" + ATIVO], ["Condomínio:condo!@6", "Tipo:select(Presencial|Remota|Híbrida)!@6", "Responsável:person@6"], gender="a"),
    "AP-132": L("visita prevista", ["Data:date", "Visitante:person", "Unidade:unit", "Autorizado por:person", "Situação:status(Prevista|Realizada|Expirada)"], ["Condomínio:condo!@6", "Unidade:unit!@6", "Visitante:person!@6", "Data:date!@6"], gender="a"),
    "AP-133": L("acesso", ["Entrada:date", "Pessoa:person", "Unidade:unit", "Tipo:select(Visitante|Prestador|Entregador)", "Portaria:text"], [], filters=("condo", "period", "search")),
    "AP-134": L("item", ["Encontrado em:date", "Item:text", "Local:text", "Condomínio:condo", "Situação:status(Guardado|Devolvido)"], ["Condomínio:condo!@6", "Item:text!@6", "Local:text@6", "Data:date!@6"]),
    "AP-135": L("entrega", ["Recebida em:date", "Unidade:unit", "Destinatário:person", "Transportadora:select(Correios|Mercado Livre|Amazon|Outra)", "Situação:status(Aguardando retirada|Retirada)"], ["Condomínio:condo!@6", "Unidade:unit!@6", "Destinatário:person!@6", "Transportadora:select(Correios|Mercado Livre|Amazon|Outra)@6"], gender="a"),
    "AP-136": L("registro de entrega", ["Retirada em:date", "Unidade:unit", "Retirado por:person", "Portaria:text"], [], filters=("condo", "period")),
    # Comunicação
    "AP-111": L("comunicado", ["Publicação:date", "Título:text", "Condomínio:condo", "Canal:select(Aplicativo|E-mail|Mural)", "Leituras:int", "Situação:status(Publicado|Agendado|Rascunho)"],
                ["Condomínio:condo!@6", "Canal:select(Aplicativo|E-mail|Mural)!@6", "Título:text!@12", "Mensagem:longtext!@12", "Publicar em:date@6", "Anexo:file@6"]),
    "AP-018": L("assembleia", ["Data:date", "Condomínio:condo", "Tipo:select(AGO|AGE)", "Pauta:text", "Presenças:int", "Situação:status(Convocada|Em andamento|Encerrada)"],
                ["Condomínio:condo!@6", "Tipo:select(AGO|AGE)!@3", "Data:date!@3", "Pauta:longtext!@12", "Edital de convocação:file@6"], tabs=("Dados", "Pauta", "Presenças", "Votações", "Ata"), gender="a"),
    "AP-017": L("votação", ["Encerra em:date", "Condomínio:condo", "Pergunta:text", "Votos:int", "Situação:status(Aberta|Encerrada)"],
                ["Condomínio:condo!@6", "Encerra em:date!@6", "Pergunta:text!@12", "Opções (uma por linha):longtext!@12"], gender="a"),
    "AP-137": L("mala direta de cobrança", ["Enviada em:date", "Condomínio:condo", "Destinatários:int", "Modelo:select(Lembrete|1º aviso|2º aviso)", "Situação:status(Enviada|Agendada)"], ["Condomínio:condo!@6", "Modelo:select(Lembrete|1º aviso|2º aviso)!@6", "Débitos a partir de (dias):int@6"], gender="a"),
    "AP-138": L("mensagem agendada", ["Envio em:date", "Assunto:text", "Condomínio:condo", "Destinatários:int", "Situação:status(Agendada|Enviada|Cancelada)"], ["Condomínio:condo!@6", "Envio em:date!@6", "Assunto:text!@12", "Mensagem:longtext!@12"], gender="a"),
    "AP-139": L("endereço", ["Pessoa:person", "Unidade:unit", "Endereço de correspondência:text", "Cidade:text", "Atualizado em:date"], ["Pessoa:person!@6", "Endereço:text!@6", "Cidade:text!@6", "CEP:text@6"]),
    # Documentos
    "AP-122": L("arquivo", ["Arquivo:text", "Condomínio:condo", "Pasta:select(Atas|Contratos|Plantas|Notas fiscais)", "Tamanho:text", "Enviado em:date"], ["Condomínio:condo!@6", "Pasta:select(Atas|Contratos|Plantas|Notas fiscais)!@6", "Arquivo:file!@12"]),
    "AP-126": L("aviso de recebimento", ["Enviado em:date", "Destinatário:person", "Unidade:unit", "Código:text", "Situação:status(Entregue|Em trânsito|Devolvido)"], ["Condomínio:condo!@6", "Destinatário:person!@6", "Código de rastreio:text!@6"]),
    "AP-127": L("cópia de cheque", ["Data:date", "Número:int", "Banco:bank", "Favorecido:text", "Valor:money"], ["Condomínio:condo!@6", "Número do cheque:int!@6", "Imagem:file!@12"], gender="a"),
    "AP-128": L("empenho", ["Data:date", "Condomínio:condo", "Fornecedor:text", "Descrição:text", "Valor:money", "Situação:status(Empenhado|Liquidado|Cancelado)"], ["Condomínio:condo!@6", "Fornecedor:text!@6", "Descrição:text!@8", "Valor:money!@4"]),
    "AP-129": L("malote", ["Data:date", "Condomínio:condo", "Documentos:int", "Portador:person", "Situação:status(Enviado|Recebido|Conferido)"], ["Condomínio:condo!@6", "Portador:person!@6", "Documentos:longtext!@12"]),
    # Configurações (listas)
    "AP-149": L("usuário", ["Nome:person", "E-mail:email", "Perfil:select(Administrador|Financeiro|Atendimento|Consulta)", "2FA:status(Ativo|Pendente)", "Último acesso:date", "Situação:" + ATIVO],
                ["Nome:person!@6", "E-mail:email!@6", "Perfil:select(Administrador|Financeiro|Atendimento|Consulta)!@6", "Condomínios com acesso:select(Todos|Selecionados)!@6"], filters=("search", "status")),
    "AP-148": L("usuário de imobiliária", ["Nome:person", "Imobiliária:text", "E-mail:email", "Condomínios:int", "Situação:" + ATIVO], ["Nome:person!@6", "Imobiliária:text!@6", "E-mail:email!@6", "Condomínios:condo!@6"], filters=("search", "status")),
    "AP-156": L("banner", ["Título:text", "Condomínio:condo", "Exibição até:date", "Cliques:int", "Situação:status(No ar|Agendado|Expirado)"], ["Condomínio:condo@6", "Título:text!@6", "Link:text@6", "Exibir até:date@6", "Imagem:file!@12"]),
    "AP-160": L("conta", ["Código:text", "Conta:text", "Tipo:select(Receita|Despesa)", "Nível:int", "Situação:" + ATIVO], ["Código:text!@4", "Nome da conta:text!@8", "Tipo:select(Receita|Despesa)!@6", "Conta pai:text@6"], filters=("search",), gender="a"),
    "AP-164": L("imposto", ["Imposto:text", "Alíquota:pct", "Base:select(Serviços|Folha|Faturamento)", "Retenção:select(Sim|Não)"], ["Nome:text!@6", "Alíquota (%):pct!@6", "Base de cálculo:select(Serviços|Folha|Faturamento)@6"], filters=("search",)),
    "AP-165": L("índice", ["Índice:select(IPCA|IGP-M|INPC|Selic)", "Competência:month", "Variação:pct", "Acumulado 12 meses:pct"], ["Índice:select(IPCA|IGP-M|INPC|Selic)!@6", "Competência:month!@3", "Variação (%):pct!@3"], filters=("period",)),
    "AP-168": L("tipo de fatura", ["Tipo:text", "Conta do plano:text", "Gera boleto:select(Sim|Não)", "Situação:" + ATIVO], ["Nome:text!@6", "Conta do plano:text!@6", "Gera boleto:bool@6"], filters=("search",)),
    "AP-171": L("integração", ["Serviço:text", "Tipo:select(Banco|E-mail|SMS)", "Condomínios:int", "Última sincronização:date", "Situação:status(Conectada|Pendente|Erro)"], ["Serviço:select(Banco|E-mail|SMS)!@6", "Identificação:text!@6"], filters=("status",), gender="a"),
}

FORMS = {
    "AP-024": ("Despesa", ["Condomínio:condo!@6", "Fornecedor:text!@6", "Descrição:text!@8", "Documento/NF:text@4", "Data de competência:date!@4", "Vencimento:date!@4", "Valor:money!@4", "Plano de contas:select(Manutenção|Elevadores|Limpeza|Energia)!@6", "Centro de custo:select(Administração|Manutenção|Pessoal)@6", "Repetir:select(Não repetir|Mensal|Parcelado)@6", "Anexo:file@6"]),
    "AP-025": ("Receita", ["Condomínio:condo!@6", "Unidade:unit@6", "Descrição:text!@8", "Competência:month!@4", "Data:date!@4", "Valor:money!@4", "Plano de contas:select(Taxa condominial|Fundo de reserva|Multas|Rendimentos)!@4", "Conta bancária:bank!@6", "Observações:longtext@12"]),
    "AP-026": ("Desconto", ["Condomínio:condo!@6", "Unidade:unit!@6", "Débito de referência:text!@8", "Tipo:select(Valor|Percentual)!@4", "Valor/percentual:money!@4", "Motivo:longtext!@12"]),
    "AP-027": ("Multa avulsa", ["Condomínio:condo!@6", "Unidade:unit!@6", "Motivo:select(Infração ao regimento|Uso de área comum|Barulho)!@6", "Valor:money!@3", "Vencimento:date!@3", "Fundamentação:longtext!@12", "Notificação enviada:bool@6"]),
    "AP-029": ("Lançamento", ["Condomínio:condo!@6", "Tipo:select(Transferência entre contas|Aplicação|Resgate|Ajuste)!@6", "Conta de origem:bank!@6", "Conta de destino:bank@6", "Data:date!@4", "Valor:money!@4", "Descrição:text!@4"]),
    "AP-152": ("Usuário de morador", ["Condomínio:condo!@6", "Unidade:unit!@6", "Morador:person!@6", "E-mail:email!@6", "Perfil no aplicativo:select(Morador|Proprietário|Responsável)!@6", "Enviar convite por e-mail:bool@6"]),
}

SETTINGS = {
    "AP-147": [("Administradora", ["Razão social:text!@8", "CNPJ:doc!@4", "E-mail de contato:email@6", "Telefone:phone@6"]), ("Atendimento", ["Horário de atendimento:text@6", "Prazo de resposta (horas):int@6"])],
    "AP-150": "perm", "AP-153": "perm",
    "AP-151": [("Envio", ["Enviar prestação aos condôminos:bool@6", "Dia do envio:int@6", "Canal:select(E-mail|Aplicativo|Ambos)@6"]), ("Conteúdo", ["Incluir balancete:bool@4", "Incluir demonstrativo:bool@4", "Incluir comprovantes:bool@4"])],
    "AP-154": [("Aplicativo", ["Nome exibido:text!@6", "Cor principal:select(Dourado Connect|Azul-marinho)@6", "Logotipo:file@12"])],
    "AP-155": [("Notificações", ["Novo boleto:bool@4", "Boleto vencendo:bool@4", "Comunicado novo:bool@4", "Entrega na portaria:bool@4", "Reserva aprovada:bool@4", "Assembleia convocada:bool@4"])],
    "AP-159": [("Alertas da equipe", ["Conta a pagar vencendo (dias):int@6", "Inadimplência acima de (%):pct@6", "Contrato a vencer (dias):int@6", "Seguro a vencer (dias):int@6"])],
    "AP-157": [("Cobrança", ["Multa por atraso (%):pct!@4", "Juros ao mês (%):pct!@4", "Dias de carência:int@4", "Correção:select(IPCA|IGP-M|Sem correção)@6", "Desconto por pontualidade (%):pct@6"]), ("Contas", ["Conta bancária padrão:bank@6", "Carteira de cobrança:text@6"])],
    "AP-158": [("Demonstrativo", ["Modelo:select(Sintético|Analítico)@6", "Agrupar por:select(Plano de contas|Centro de custo)@6", "Mostrar saldo anterior:bool@6", "Mostrar previsão orçamentária:bool@6"])],
    "AP-161": [("Saldo inicial por conta", ["Condomínio:condo!@6", "Data de referência:date!@6", "Conta do plano:text!@6", "Saldo inicial:money!@6"])],
    "AP-162": [("Saldo inicial por centro de custo", ["Condomínio:condo!@6", "Data de referência:date!@6", "Centro de custo:select(Administração|Manutenção|Pessoal)!@6", "Saldo inicial:money!@6"])],
    "AP-163": [("Previsão orçamentária", ["Condomínio:condo!@6", "Exercício:select(2026|2027)!@6", "Reajuste previsto (%):pct@6", "Fundo de reserva (%):pct@6"])],
    "AP-166": [("Parâmetros gerais", ["Fuso horário:select(America/Sao_Paulo)@6", "Moeda:select(Real (R$))@6", "Arredondamento:select(2 casas|Sem centavos)@6", "Dias para expirar sessão:int@6"])],
    "AP-167": [("Fração ideal", ["Condomínio:condo!@6", "Método:select(Área privativa|Informada manualmente)!@6", "Total das frações (%):pct@6"])],
    "AP-121": None,
}

REPORT_COLS = {
    "inad": ["Unidade:unit", "Devedor:person", "Débitos:int", "Mais antigo:date", "Valor:money"],
    "rateio": ["Unidade:unit", "Fração ideal:pct", "Conta:text", "Valor rateado:money"],
    "lanc": ["Data:date", "Descrição:text", "Conta:text", "Tipo:select(Receita|Despesa)", "Valor:money"],
    "receb": ["Data:date", "Unidade:unit", "Pagador:person", "Conta bancária:bank", "Valor:money"],
    "contas": ["Conta:text", "Saldo anterior:money", "Entradas:money", "Saídas:money", "Saldo final:money"],
    "consumo": ["Unidade:unit", "Leitura anterior:int", "Leitura atual:int", "Consumo (m³):int", "Valor:money"],
    "pessoas": ["Nome:person", "Unidade:unit", "Vínculo:select(Proprietário|Inquilino|Morador)", "Telefone:phone", "E-mail:email"],
    "condo": ["Condomínio:condo", "Unidades:int", "Síndico:person", "Cidade:text", "Situação:status(Ativo|Inativo)"],
    "boletos": ["Nosso número:int", "Unidade:unit", "Vencimento:date", "Valor:money", "Situação:status(Pago|Aberto|Vencido)"],
    "acordos": ["Acordo:int", "Unidade:unit", "Parcelas:int", "Pago:money", "Saldo:money"],
    "orcamento": ["Conta:text", "Previsto:money", "Realizado:money", "Diferença:money", "Execução:pct"],
    "generic": ["Data:date", "Condomínio:condo", "Descrição:text", "Responsável:person", "Situação:status(Concluído|Pendente)"],
}
REPORT_OF = {}
for ap, key in [(57, "inad"), (58, "inad"), (59, "inad"), (60, "inad"), (61, "inad"), (62, "rateio"), (63, "rateio"), (64, "rateio"), (65, "rateio"), (66, "rateio"), (67, "rateio"), (68, "lanc"), (69, "lanc"), (75, "rateio"), (76, "lanc"),
                (71, "receb"), (72, "receb"), (74, "receb"), (93, "lanc"), (73, "contas"), (56, "lanc"), (79, "contas"), (80, "orcamento"), (81, "contas"), (82, "orcamento"), (83, "lanc"), (51, "orcamento"), (52, "contas"), (53, "lanc"), (54, "contas"),
                (45, "consumo"), (46, "consumo"), (87, "pessoas"), (98, "pessoas"), (96, "pessoas"), (86, "pessoas"), (89, "condo"), (70, "boletos"), (99, "boletos"), (47, "acordos"), (48, "acordos"), (84, "orcamento"), (50, "orcamento"), (49, "orcamento"), (101, "contas"), (94, "condo")]:
    REPORT_OF[f"AP-{ap:03d}"] = key

IMPORTS = {
    "AP-036": ("Arquivo de retorno bancário (CNAB 240/400)", ".ret,.txt", ["Nosso número", "Unidade", "Pago em", "Valor pago", "Situação"]),
    "AP-044": ("Planilha de leituras", ".csv,.xlsx", ["Unidade", "Medidor", "Leitura atual", "Consumo", "Situação"]),
    "AP-140": ("Extrato bancário OFX", ".ofx", ["Data", "Histórico", "Documento", "Valor", "Situação"]),
    "AP-141": ("NF-e em XML", ".xml", ["Emitente", "Número", "Emissão", "Valor", "Situação"]),
    "AP-142": ("Planilha de inadimplentes", ".csv,.xlsx", ["Unidade", "Competência", "Valor", "Vencimento", "Situação"]),
    "AP-143": ("Planilha de frações ideais", ".csv,.xlsx", ["Unidade", "Bloco", "Fração ideal", "Área", "Situação"]),
    "AP-144": ("Planilha de lançamentos", ".csv,.xlsx", ["Data", "Descrição", "Conta", "Valor", "Situação"]),
    "AP-145": ("Planilha de lançamentos por unidade", ".csv,.xlsx", ["Unidade", "Descrição", "Competência", "Valor", "Situação"]),
    "AP-146": ("Planilha de contas a pagar", ".csv,.xlsx", ["Fornecedor", "Descrição", "Vencimento", "Valor", "Situação"]),
}
DOCUMENTS = {
    "AP-123": ("Certidão negativa de débitos", "Certificamos que a unidade abaixo não possui débitos condominiais vencidos até a data de emissão."),
    "AP-124": ("Declaração de inadimplência", "Declaramos que a unidade abaixo possui os débitos condominiais relacionados, vencidos e não pagos até a data de emissão."),
    "AP-125": ("Comprovante de quitação anual", "Declaramos a quitação das contribuições condominiais da unidade abaixo referentes ao exercício indicado."),
    "AP-130": ("Carta de anuência", "Declaramos, para os devidos fins, anuência do condomínio quanto ao pedido indicado abaixo."),
}

out = {}
for pid, page in CATALOG["pages"].items():
    kind = page["kind"]
    if pid == "AP-001":
        continue
    spec = {"kind": kind}
    if kind == "list":
        spec.update(SPECS.get(pid) or L(page["name"].lower(), ["Nome:text", "Condomínio:condo", "Atualizado em:date", "Situação:" + ATIVO], ["Condomínio:condo!@6", "Nome:text!@6"]))
    elif kind == "form":
        title, fields = FORMS[pid]
        spec.update({"entity": title, "fields": fields})
    elif kind == "settings":
        conf = SETTINGS.get(pid)
        if conf == "perm":
            spec.update({"permissions": True, "roles": ["Administrador", "Financeiro", "Atendimento", "Consulta"] if pid == "AP-150" else ["Proprietário", "Inquilino", "Morador", "Responsável financeiro"],
                         "modules": ["Condomínios", "Cadastros", "Financeiro", "Cobrança", "Prestação de contas", "Operacional", "Comunicação", "Documentos", "Relatórios"] if pid == "AP-150" else ["Boletos", "Prestação de contas", "Reservas", "Comunicados", "Ocorrências", "Assembleias", "Documentos"]})
        else:
            spec.update({"sections": [{"title": t, "fields": f} for t, f in conf]})
    elif kind == "report":
        spec.update({"columns": REPORT_COLS[REPORT_OF.get(pid, "generic")]})
    elif kind == "import":
        label, accept, cols = IMPORTS[pid]
        spec.update({"fileLabel": label, "accept": accept, "columns": cols})
    elif kind == "document":
        title, text = DOCUMENTS[pid]
        spec.update({"docTitle": title, "docText": text})
    elif kind == "chart":
        spec.update({"series": ["Receitas", "Despesas"] if "financeiro" in page["name"].lower() or "contas" in page["name"].lower() or "gerais" in page["name"].lower() else (["Consumo (m³)"] if "consumo" in page["name"].lower() else (["Inadimplência (%)"] if "inadimpl" in page["name"].lower() else ["Reservas"]))})
    out[pid] = spec

def php(v, ind=0):
    pad = "    " * ind
    if isinstance(v, dict):
        return "[\n" + "".join(f"{pad}    {php(k)} => {php(x, ind + 1)},\n" for k, x in v.items()) + pad + "]"
    if isinstance(v, (list, tuple)):
        if all(isinstance(x, str) for x in v):
            return "[" + ", ".join(php(x) for x in v) + "]"
        return "[\n" + "".join(f"{pad}    {php(x, ind + 1)},\n" for x in v) + pad + "]"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, str):
        return "'" + v.replace("\\", "\\\\").replace("'", "\\'") + "'"
    return str(v)

target = ROOT / "container/erp/connect/v1/components/layout/page-specs.php"
target.write_text("<?php\n\ndeclare(strict_types=1);\n\n/**\n * Definição visual das páginas do ERP (colunas, filtros, campos, abas).\n * GERADO por tools/erp-page-specs.py: não editar à mão. São PROPOSTAS de interface;\n * os campos ainda precisam ser confirmados nas fichas docs/specs/pages.\n */\n\nreturn " + php(out) + ";\n", encoding="utf-8")
print(len(out), "definições")
