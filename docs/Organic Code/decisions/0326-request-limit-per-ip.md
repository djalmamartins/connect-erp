# 0326 — request_limit() também conta por IP

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` etapa 6 (teste de carga dos limites, pendente do ADR 0159); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0023 (LoginThrottle por conta e IP), 0159 (revisão de segurança da API), 0325 (hospedagem compartilhada)

## Contexto

O teste de carga mostrou que o `request_limit()` só conta por sessão. Quem não manda o cookie ganha uma sessão nova
a cada pedido e nunca chega ao limite: 24 pedidos seguidos sem cookie numa chave com limite 2 passaram todos.
Os logins do ERP, do app e da API já têm o `LoginThrottle` em banco (por conta e IP), mas as demais chaves (ajuda
pública, abertura de chamado, envio de arquivos, documentos, pagamento) dependiam só da sessão.

## Decisão

1. `request_limit()` continua igual para a sessão e ganha uma segunda trava por IP (`Source\Support\IpRateLimit`),
   com o IP de `ClientIp::resolve()` (respeita `CONNECT_TRUSTED_PROXIES`).
2. Limite do IP = 5 vezes o da sessão, no mínimo 20, na mesma janela, para não barrar várias pessoas atrás do mesmo
   endereço (portaria, escritório, rede do condomínio).
3. Contador em arquivo em `storage/temp/rate-limit` com `flock`: funciona na hospedagem compartilhada sem Redis. O nome
   do arquivo é o sha256 da chave com o IP; o IP não fica gravado. Contadores parados há mais de um dia são apagados de
   vez em quando (limite de inodes do plano).
4. Falha ao gravar o contador não bloqueia ninguém (o limite de sessão continua valendo). Fora de requisição HTTP (CLI,
   testes) não há IP e a trava não conta.
5. O `reset` do `request_limit()` (depois de um login certo) limpa só a sessão, não o IP.

## Consequências

- Com a mudança, os mesmos 24 pedidos sem cookie: 20 aceitos e o resto recusado.
- Robôs com muitos IPs ainda passam; para isso ficam o `LoginThrottle`, o CAPTCHA onde houver e o firewall da hospedagem.
- PENDENTE: revisão do HTML dos templates de e-mail (a outra metade do pendente do ADR 0159).
