# ADR 0678 — Como chegar no Painel da visita

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
O Painel da visita (ADR 0662) mostra o endereço do prédio, mas quem vai visitar
precisava copiar o texto e colar no app de mapas.

## Decisão
- Botão **Como chegar** no Painel, quando o `GET /my-day/visit/{id}` traz o endereço.
- A pessoa escolhe entre:
  - **Mapas** (iPhone) ou **App de mapas** (Android);
  - **Google Maps**;
  - **Waze**.
- O endereço sai de `mapsUrl` (`src/lib/visit.ts`). São só endereços `https://`, e
  `geo:` no Android, sem esquemas próprios (`comgooglemaps://`, `waze://`). Assim
  não é preciso declarar consultas de apps no iPhone. Se o app não estiver
  instalado, abre o site.
- Vai para o mapa só o nome e o endereço do prédio, nenhum dado de morador.
  Nenhuma mudança na API.

## Consequências
- Testes: `mapsUrl` no `npx jest` (os três apps, iPhone e Android); `npx tsc --noEmit`.
