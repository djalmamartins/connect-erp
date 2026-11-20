# Plano de resposta a incidente com dados pessoais (LGPD art. 48)

> ADR 0603. **Rascunho técnico: revisar com advogado.** Base: LGPD art. 48 e Regulamento de Comunicação de Incidente
> de Segurança (Resolução CD/ANPD nº 15/2024). Conferir a norma vigente antes de usar.

## 1. O que é incidente

Qualquer evento que comprometa a confidencialidade, a integridade ou a disponibilidade de dados pessoais. Exemplos:
- acesso de alguém sem permissão (conta invadida, morador vendo dado de outra unidade, IDOR);
- dado enviado para a pessoa errada (e-mail, WhatsApp, boleto, PDF);
- perda ou vazamento de backup, notebook, celular ou pendrive com dados;
- ataque que apague, cifre (ransomware) ou derrube o sistema;
- credencial de banco, OneDrive, e-mail ou hospedagem exposta (Git, print, conversa).

## 2. Quem faz o quê

| Papel | Quem | O que faz |
| --- | --- | --- |
| Responsável pela decisão | Djalma (diretor da Connect) | decide comunicar, aprova os textos |
| Encarregado de dados | **PENDENTE** (Configurações > Identificação e encarregado) | coordena, fala com a ANPD e com os titulares |
| Técnico | quem mantém o sistema | contém, investiga, guarda as provas, corrige |
| Advogado | **PENDENTE** | confere o risco e os textos |

## 3. Passo a passo

1. **Conter (na hora):** trocar a senha ou chave exposta, desativar a conta, tirar o arquivo do ar, bloquear o IP, pôr o
   sistema em manutenção se preciso. Não apagar logs.
2. **Guardar provas:** copiar `system_audit_logs`, `app_log`, `storage/logs/`, logs da hospedagem e prints, com data e hora.
3. **Registrar** na planilha de incidentes (seção 5) no mesmo dia, mesmo que pareça pequeno.
4. **Avaliar o risco** (seção 4) em até 1 dia útil.
5. **Avisar o condomínio:** nos dados dos condomínios a Connect é operadora; avise o síndico do condomínio afetado logo que
   souber, por escrito, para que ele (controlador) também possa cumprir a lei. O contrato com o condomínio deve dizer isso.
6. **Comunicar a ANPD e os titulares** quando houver risco ou dano relevante: **em até 3 dias úteis** a partir de quando a
   Connect soube que o incidente afetou dados pessoais. Pelo formulário do site da ANPD (gov.br/anpd). Se faltar
   informação, comunicar o que se sabe e complementar depois (a norma admite complemento).
7. **Corrigir a causa** e registrar o que mudou (ADR, PR).

## 4. Quando há risco ou dano relevante

Comunicar quando o incidente puder afetar significativamente interesses ou direitos dos titulares **e** envolver pelo
menos um destes:
- dados sensíveis (saúde, biometria, religião etc.) ou de crianças, adolescentes ou idosos;
- dados financeiros (boleto, conta, Pix, renda, inadimplência);
- dados de autenticação (senha, token, código 2FA);
- dados protegidos por sigilo;
- dados em grande escala.

Na dúvida, trate como relevante e fale com o advogado.

## 5. Registro de incidentes

Todo incidente fica registrado (mesmo os não comunicados) e o registro é guardado por **no mínimo 5 anos**:
data em que aconteceu e em que a Connect soube; o que aconteceu; dados e titulares afetados (quantos, quais
condomínios); risco avaliado e por quê; se comunicou ANPD, condomínio e titulares (data e protocolo); medidas tomadas.

## 6. Modelo de aviso aos titulares (rascunho)

> Assunto: Aviso sobre seus dados pessoais
>
> Olá, [nome]. Em [data], identificamos [o que aconteceu, em linguagem simples]. Os dados envolvidos foram: [lista].
> Já fizemos: [medidas]. Recomendamos que você: [trocar a senha / desconfiar de boletos por WhatsApp / conferir o código
> do boleto no app]. A ANPD foi comunicada em [data]. Dúvidas: [encarregado e e-mail]. — [razão social e CNPJ]

## 7. Conteúdo da comunicação à ANPD

Descrição do incidente e dos dados; titulares afetados (número e categorias); medidas técnicas de segurança já usadas
(ver `LGPD_REGISTRO_OPERACOES.md` §3); riscos; motivo de eventual demora; medidas para reverter ou mitigar; data em que
soube; contato do encarregado.
