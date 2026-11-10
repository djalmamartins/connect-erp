# Base de conhecimento da Central de Ajuda

Artigos e categorias da página pública `/suporte`. O processo, o formato e os comandos estão em
[docs/process/HELP_CENTER.md](../../../../../docs/process/HELP_CENTER.md) (ADR 0141).

    php tools/help.php check      # formato, links, prints e cobertura das páginas do menu
    php tools/help.php scaffold   # rascunho para página do menu sem artigo
    bash tools/help/prints.sh     # regera os prints marcados (Mac, banco de demonstração)
    php tools/help.php sync       # publica no banco
