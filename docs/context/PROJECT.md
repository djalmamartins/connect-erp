# Contexto do projeto
Produto: Connect ERP; base técnica: repositório Organic Code (`github.com/djalmamartins/organiccode`).
Objetivo: ERP para administradora de condomínios com base independente em requisitos de domínio.
Referência: `docs/reference/AUDITORIA_COMPLETA_APCONTROLE.pdf` (52 páginas, 07/11/2026).
Métricas reportadas pela fonte: 172 rotas descobertas; 169 abertas; 3 pendentes; 1.830 controles visíveis; 57 páginas de relatório/contabilidade.
**Não equivale a 98,26% de cobertura funcional profunda**: ações mutáveis, modais e cálculos não foram executados.
O repositório Organic Code NÃO foi clonado nem auditado neste pacote; Claude deve verificar sua estrutura atual.

Decisão atualizada: núcleo de logs, usuários e permissões já funcional no Organic Code; reutilizar. Banco-alvo MariaDB; integração API REST sujeita a compatibilidade técnica; integração bancária completa é requisito obrigatório novo/ampliado.
