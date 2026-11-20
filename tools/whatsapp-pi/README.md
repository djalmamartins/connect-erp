# WhatsApp do Connect Talk num Raspberry Pi

> **Histórico (ADR 0620, 20/11/2026):** o Talk dentro do ERP foi removido; o Talk em uso é o projeto separado
> `djalmamartins/talk`, inteiro no Raspberry Pi (ADR 0359). O `tools/talk-worker.php` citado abaixo não existe mais.
> O `instalar.sh` da Evolution continua aqui só como referência.

O Talk roda dentro do ERP, no Cloud Startup (ADR 0324). O motor do WhatsApp por QR Code (Evolution API)
precisa ficar ligado o tempo todo, e o Cloud Startup não deixa. Por isso ele mora num Raspberry Pi em casa
(ADR 0349). Se um dia o Pi não der conta, o mesmo `instalar.sh` funciona numa VPS. Depois é só trocar o
`EVOLUTION_URL` no ERP.

```
celular ⇄ WhatsApp ⇄ Evolution (Pi, em casa) ⇄ túnel Cloudflare ⇄ Talk dentro do ERP (Cloud Startup)
```

## O que precisa
- Raspberry Pi 4 ou 5 com 2 GB de RAM ou mais (4 GB é folgado) e fonte original.
- Um cartão SD para começar. Melhor ainda: um SSD no USB 3, porque o cartão estraga em meses com banco gravando.
- Cabo de rede no roteador (Wi-Fi funciona, mas cai mais) e, de preferência, um nobreak pequeno.
- Uma conta gratuita na Cloudflare com o domínio `connectcondominios.com.br` (passo 3).

## 1. Gravar o sistema (no Mac)
1. Instale o **Raspberry Pi Imager** (raspberrypi.com/software).
2. Escolha **Raspberry Pi 4**, depois **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**, e por fim o cartão (ou o SSD).
   Confira o tamanho do disco para não apagar outro.
3. Em **Editar configurações**, preencha:
   - nome `connect-whatsapp`;
   - um usuário e uma senha, que ficam só com você;
   - o Wi-Fi, se não usar cabo;
   - fuso `America/Sao_Paulo`;
   - na aba Serviços, ative o **SSH**.
4. Grave, coloque no Pi e ligue. Em uns 2 minutos, no Terminal do Mac:
   `ssh SEUUSUARIO@connect-whatsapp.local`

## 2. Atualizar o Pi (dentro do ssh)
```bash
sudo apt update && sudo apt full-upgrade -y && sudo reboot
```

## 3. Túnel da Cloudflare (no navegador, uma vez)
O túnel publica o motor em `https://whatsapp.connectcondominios.com.br` sem abrir porta no roteador. Funciona
mesmo sem IP fixo e mesmo com a operadora usando CGNAT.
1. Em dash.cloudflare.com, adicione o site `connectcondominios.com.br` (plano Free).
   - A Cloudflare copia os registros de DNS que já existem.
   - Confira se o site e o e-mail (registros MX) vieram.
   - Troque os servidores DNS do domínio para os dois que ela mostrar. A troca é feita no Registro.br, ou
     onde o domínio foi registrado.
2. Vá em **Zero Trust → Networks → Tunnels → Create a tunnel → Cloudflared**, com o nome `connect-whatsapp`.
   Na tela de instalação, copie **só o token**, o texto longo que começa com `eyJ`. Não precisa rodar o
   comando que ela mostra.
3. Em **Public Hostname**, preencha:
   - subdomínio `whatsapp`;
   - domínio `connectcondominios.com.br`;
   - Service: tipo `HTTP`, URL `whatsapp:8080`.

## 4. Instalar o motor (dentro do ssh)
```bash
git clone --depth 1 https://github.com/djalmamartins/connect-erp ~/connect-erp
bash ~/connect-erp/tools/whatsapp-pi/instalar.sh
```
O script:
- confere o Pi;
- instala o Docker;
- gera as chaves em `/opt/connect-whatsapp/.env`;
- pede o token do túnel;
- liga a Evolution, o banco e o túnel;
- testa tudo.

No fim, ele mostra duas linhas, `EVOLUTION_URL` e `EVOLUTION_API_KEY`.

Rodar de novo não apaga nada nem troca as chaves.

## 5. Ligar no ERP (servidor do Cloud Startup)
1. No `.env` do ERP, cole as duas linhas do passo 4 e confira estas:
   ```
   TALK_URL=https://talk.connectcondominios.com.br
   TALK_KEY=   (gere uma vez com: openssl rand -base64 32, e guarde uma cópia fora do servidor)
   ```
   Deixe `EVOLUTION_WEBHOOK_URL` vazio: a Evolution avisa o Talk pelo endereço público (`TALK_URL`).
2. No hPanel, em **Avançado → Tarefas Cron**, crie uma tarefa **a cada minuto** com o comando:
   ```
   php /home/USUARIO/CAMINHO_DO_ERP/tools/talk-worker.php
   ```
   É ela que envia as respostas, baixa fotos e áudios, faz a triagem da Jack e os agendamentos. Cada
   chamada roda 55 segundos e sai. Para conferir se está viva: `php tools/talk-worker.php --check`.
3. No Talk, vá em **Conexões → Novo número → QR Code** e leia o QR Code com o celular do número.

## Dia a dia
- **Faltou luz ou internet:** o Pi e o motor voltam sozinhos. O WhatsApp entrega o que chegou enquanto
  estava fora.
- **Ver se está tudo ligado:** `cd /opt/connect-whatsapp && sudo docker compose ps`
- **Ver os logs:** `sudo docker compose logs --tail 50 whatsapp`
- **Atualizar:** `cd ~/connect-erp && git pull && bash tools/whatsapp-pi/instalar.sh`
- **Se perder o Pi ou o cartão:** instale de novo e leia o QR Code de novo. As conversas não se perdem,
  porque ficam no banco do ERP. No Pi ficam só as sessões dos números.
- **Mudar para uma VPS:** rode o `instalar.sh` na VPS e aponte o Public Hostname do túnel para ela (ou
  use o mesmo token). O ERP não muda.

## Segurança
- A Evolution só aceita chamadas com a `EVOLUTION_API_KEY` (48 caracteres). A chave fica só no `.env` do Pi
  e no do ERP, nunca no Git nem no chat.
- O Pi não abre porta no roteador. Só o túnel sai para a Cloudflare.
- A Evolution chama o Talk com o segredo de cada número (`X-Talk-Secret`), que o Talk confere.
