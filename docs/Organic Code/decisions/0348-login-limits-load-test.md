# 0348 — Teste de carga dos limites de tentativas e trava da conta

- Status: Aceito
- Data: 17/11/2026
- Contexto: plano v1.0, §3.7; pendência do ADR 0159 ("teste de carga dos limites")

## Contexto

O login tem três limites: `request_limit()` por sessão (8 em 5 min), o contador por IP em arquivo
(`IpRateLimit`, ADR 0326, 40 em 5 min) e o `LoginThrottle` no banco (ADR 0023: 6 falhas por conta e 30 por IP
em 15 min). Nenhum tinha sido testado com pedidos ao mesmo tempo.

## Medição (lab, `php -S` com 8 processos, MariaDB local)

Ferramenta nova: `tools/hosting/load-test-limits.php`. Cada tentativa abre o login (sessão nova), pega o token e
manda uma senha errada, como faria quem tenta senhas trocando de sessão.

| Cenário | Antes | Depois |
| --- | --- | --- |
| mesma conta, 1 por vez | 6 passaram | 6 |
| mesma conta, 20 ao mesmo tempo | **9** | 6 |
| mesma conta, 50 ao mesmo tempo | **10** | 6 |
| e-mail diferente a cada tentativa, 50 ao mesmo tempo | 33 | 30 |
| `IpRateLimit`, 10 processos × 30 pedidos, limite 100 | 100 exatos, contador 300 (sem perda) | igual |

**CONFIRMADO:** o `LoginThrottle` conferia (`blocked`) e só gravava a falha (`fail`) depois de conferir a senha;
pedidos simultâneos passavam todos pela conferência antes da primeira falha ser gravada.

## Decisão

- `LoginThrottle::hold()` trava a conta com `GET_LOCK` do MariaDB enquanto a tentativa é conferida, e devolve a
  função que solta. Usado no login, nos dois passos do 2FA (`Auth`) e na recuperação de senha do app
  (`ResidentAppSessions::forgot`).
- Só a conta é travada, nunca o IP: uma portaria ou um escritório com várias pessoas no mesmo IP não fica em fila.
- Banco indisponível, outro banco (SQLite dos testes) ou espera de 10 s esgotada: segue sem a trava, como antes.
- `HOSPEDAGEM.md` §9: como repetir o teste na hospedagem antes de abrir para os moradores.

## Testes

- `LoginThrottleLockIntegrationTest`: a mesma conta espera a trava, outra conta no mesmo IP não espera, soltar
  duas vezes não quebra, sem conta ou fora do MariaDB não faz nada.
- `IpRateLimitTest::testConcurrentProcessesNeverExceedTheLimit`: 10 processos ao mesmo tempo, limite exato.
- Teste de carga antes e depois (tabela acima).

## Riscos e lacunas

- O limite por IP no banco ainda pode passar um pouco com muitos pedidos ao mesmo tempo (33 contra 30 antes; 30 na
  medição depois, sem garantia). O contador em arquivo (40, exato) segura o resto.
- A medição foi no lab; na hospedagem o tempo por pedido muda. O roteiro do §9 repete a medição lá.
