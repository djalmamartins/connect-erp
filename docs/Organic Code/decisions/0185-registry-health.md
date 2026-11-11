# 0185 — Qualidade do cadastro do condomínio

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0013 (checklist de chegada), 0085 (frações ideais, AP-167, e tolerância, AP-166), 0162 (CPF
  opcional no cadastro), 0184 (extrato da unidade)

## Contexto

A cobrança, o rateio pela fração ideal e as declarações dependem de um cadastro completo. Isso quer dizer:

- toda unidade cobrada com proprietário;
- frações que somam 1;
- CPF/CNPJ válido de quem responde pela unidade;
- rótulos de unidade sem repetição.

Os validadores do cadastro barram parte disso na gravação. Mas dado importado, migrado ou antigo pode estar errado, e
não havia uma visão do que falta no condomínio inteiro. A implantação e a conferência periódica precisavam olhar tela
por tela.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/registry-health?condominium_id=`. O service é `RegistryHealth`. As
conferências são uma regra pura (`evaluate`), com os fatos levantados por SQL parametrizado, sempre no condomínio.

| Nível | Código | Quando |
|---|---|---|
| erro | `charged_without_owner` | unidade com conta a receber válida e sem proprietário ativo |
| erro | `duplicate_unit_label` | duas unidades ativas com o mesmo rótulo (sem diferença de espaço ou maiúscula) |
| erro | `fraction_sum` | todas as unidades têm fração, mas a soma não dá 1 dentro da tolerância do AP-166 |
| erro | `document_invalid` | proprietário, inquilino ou responsável financeiro com CPF/CNPJ preenchido e inválido |
| aviso | `fraction_missing` | parte das unidades com fração e parte sem |
| aviso | `units_without_owner` | unidade ativa sem proprietário ativo |
| aviso | `document_missing` | proprietário ou responsável financeiro sem CPF/CNPJ (inquilino e morador podem ficar sem) |
| informação | `fractions_not_set` | nenhuma unidade com fração (o rateio é igual) |

### O que fica de fora

- Unidade inativa ou excluída e vínculo inativo ou excluído não contam.
- Conta cancelada, excluída ou de despesa não conta como cobrança.

### Dados pessoais

Os itens trazem o rótulo da unidade, o id da unidade, o id do vínculo e o tipo de vínculo, nunca o nome nem o
documento. O teste confere que nenhum dos dois aparece na resposta. Cada conferência traz até 50 itens; a contagem é
sempre a total.

### Permissões e escopo

- Exige `erp.units.view` e `erp.residents.view`.
- Fora do escopo dá 404.
- Sem condomínio dá 422.

## Consequências

- **Testes.** `RegistryHealthTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - um condomínio com todos os problemas, com cada código e os seus itens;
  - que inativo, excluído, despesa e conta cancelada ficam de fora;
  - que nem nome nem documento aparecem na resposta;
  - um condomínio certo, sem nada;
  - a soma das frações errada;
  - nenhuma fração (informação);
  - a tolerância;
  - o escopo, a entrada inválida e o controller.
- **Rota e documentação no patch:** a rota entra no `index.php` e na OpenAPI, dentro do patch.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. O checklist de chegada (ADR 0013) e o painel do condomínio
  podem mostrar o resultado quando a frente de layout quiser.
