# 0013 — Vistoria presencial: check-in por GPS, fotos privadas e relatório

Data: 2026-11-08 · Status: proposto (aguarda revisão do proprietário)

## Contexto

O proprietário pediu checklist de vistoria presencial nos condomínios, com posição por GPS, lista semanal/mensal,
evidência com foto e relatório para enviar. Não há página equivalente no inventário APControle.

## Decisão

- Página própria `CN-001` no catálogo (grupo Operacional), registrada por `tools/erp-catalog.py` (`CONNECT_PAGES`).
- Web responsivo no ERP, sem app nativo: `capture="environment"` abre a câmera e a Geolocation API dá a posição.
- O servidor calcula a distância (haversine) ao ponto do condomínio (`condominium_geolocations`, tabela separada para
  não alterar `condominiums`). Fora do raio ou sem posição exige justificativa; a precisão do GPS amplia o raio até o dobro.
- Modelos globais da Connect (administradora única) e planos por condomínio com periodicidade; itens copiados na vistoria.
- Fotos sempre regravadas pelo GD em `storage/private` e servidas por rota autorizada.
- Relatório num único template (HTML e PDF via dompdf sem acesso remoto), e-mail pela fila `mail_queue` com PDF anexo.
- Mesmas regras para tela e API (`InspectionService`), seguindo a fatia vertical MariaDB + REST.

## Consequências

- Exige HTTPS em produção para o GPS funcionar.
- GPS do aparelho pode ser falsificado; o relatório registra a posição e a justificativa como evidência.
- Sem modo offline nesta versão.
