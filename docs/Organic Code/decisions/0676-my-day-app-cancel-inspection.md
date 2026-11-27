# ADR 0676 — Cancelar a vistoria pelo app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
Quem chegava ao condomínio e não conseguia fazer a vistoria (portaria fechada,
síndico ausente) ficava com uma vistoria em andamento no app. Para cancelar, era
preciso abrir a tela do ERP (`POST /operacional/vistorias/{id}/cancelar`).

## Decisão
- A API ganha a rota `POST /api/v1/inspections/{id}/cancel`, com o corpo
  `{reason}`, atrás do `ApiAuthenticated` (sessão com CSRF ou token da equipe).
- A rota usa o mesmo `InspectionService::cancel` da tela, com as mesmas regras:
  - permissão de fazer vistoria;
  - condomínio no escopo;
  - só vistoria em andamento (se não estiver, responde 409);
  - motivo de 5 a 1000 caracteres (se não tiver, responde 422).
- A vistoria cancelada guarda o motivo no resumo e entra na auditoria. A resposta
  traz a vistoria atualizada.
- No app, o botão **Não dá para fazer a vistoria** abre o campo do motivo e o botão
  **Cancelar vistoria**. Antes de cancelar, o app pede confirmação. As respostas e
  as fotos continuam guardadas.

## Consequências
- Não há migration e a tela do ERP não muda.
- Testes:
  - `InspectionServiceTest` ganhou o caso em que quem só vê tenta cancelar (403).
    O motivo vazio e a vistoria já cancelada já eram cobertos.
  - `InspectionApiPhotoHeadersTest` confere que a rota está protegida.
  - No app, `cancelReasonError` tem teste.
