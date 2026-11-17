# ADR 0377 — Foto do morador apagada junto com a conta

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0302 (foto do morador), ADR 0350 (registro LGPD, operação 10), ADR 0372 (foto pelo armazenamento)

## Contexto

O registro LGPD diz, na operação 10 (app do morador), "foto apagada ao remover". Mas excluir a conta em Usuários do
Sistema (`User::destroy`) apagava só a linha de `users`: a linha de `erp_resident_app_profiles` (sem chave estrangeira) e
o arquivo da foto ficavam para sempre, no servidor ou no OneDrive. Era dado pessoal sem dono e sem prazo.

## Decisão

- `ResidentProfile::forgetAccount(userId)`: apaga a foto no armazenamento (disco local ou OneDrive) e a linha do perfil.
  Sem perfil, não faz nada; repetir não dá erro.
- `User::destroy` chama `forgetAccount` depois que a conta sai. Se a limpeza falhar, a exclusão da conta continua e fica um
  aviso no log (`resident_photo_orphan`) para apagar à mão.

## Testes

`ResidentProfileIntegrationTest::testDeletingTheAccountRemovesThePhotoAndTheProfile`: a foto e o perfil somem, a foto de
outra conta fica, repetir não dá erro, e a exclusão pelo modelo `User` (a mesma de Usuários do Sistema) apaga o perfil.
Conferido que o teste falha sem a chamada no `User::destroy`.
