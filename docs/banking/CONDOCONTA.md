# CondoConta — integração de cobrança (PENDENTE, ADR 0633)

Situação em 21/11/2026: **sem código**. A CondoConta tem a "CondoConta Open API" (api-open.condoconta.com.br), mas a
especificação só abre num navegador e o acesso é pelo programa de parceiros (condoconta.com.br/parcerias, gratuito).
O site cita integração por API, CNAB e VAN, e o produto "Receita Automática", em que a própria CondoConta emite a
cobrança e devolve os pagamentos ao ERP da administradora.

## O que pedir à CondoConta

1. A especificação da API (arquivo OpenAPI/Swagger) da cobrança: emissão de boleto e Pix, consulta, cancelamento,
   aviso de pagamento (webhook), extrato e saldo.
2. Um acesso de teste (sandbox ou homologação) com credenciais de teste.
3. O cadastro da Connect como parceira, explicando que o sistema é próprio (Connect ERP), com vários condomínios,
   cada um com CNPJ e conta própria na CondoConta.
4. Se a emissão é feita pelo ERP (como Inter e Sicoob) ou pela CondoConta (Receita Automática), e como o ERP recebe
   os pagamentos.

Contato público: WhatsApp (48) 99969-0186, queroajuda@condoconta.com.br, ou o gerente da conta.

## Quando a documentação chegar

Mesmo desenho do Inter e do Sicoob: `CondoConta\CondoContaBankAdapter` + credencial pela tela Integrações, consulta
de hora em hora, extrato, só ambiente de teste até a aprovação do Djalma. Abrir um ADR novo com o contrato confirmado.
