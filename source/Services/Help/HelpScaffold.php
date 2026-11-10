<?php

declare(strict_types=1);

namespace Source\Services\Help;

use Source\Services\Erp\Registry\Definitions;

/**
 * Rascunho do artigo de cada página do menu do ERP que ainda não tem artigo (ADR 0141).
 *
 * Usa o catálogo do menu, as definições dos cadastros (rótulos, obrigatórios, dicas e opções dos
 * campos) e, se existir, o inventário das telas (tools/help/.inventario.json, gerado por
 * `node tools/help/shots.mjs --inventario`) com os botões, filtros e colunas reais. O resultado é
 * um ponto de partida com "rascunho: sim": alguém revisa, completa e publica.
 */
final class HelpScaffold
{
    public const GROUP_CATEGORY = [
        'dashboard' => 'primeiros-passos', 'condominios' => 'condominios', 'cadastros' => 'cadastros',
        'financeiro' => 'financeiro', 'cobranca' => 'cobranca', 'prestacao-contas' => 'prestacao-de-contas',
        'operacional' => 'operacional', 'comunicacao' => 'comunicacao', 'documentos' => 'documentos',
        'relatorios' => 'relatorios', 'importacoes' => 'importacoes', 'configuracoes' => 'configuracoes',
    ];

    /** Condomínio fictício da base de demonstração (tools/help/demo-seed.php) usado nos prints. */
    public const DEMO_CONDOMINIUM = 'residencial-jardim-das-acacias';

    private const KIND_ICON = [
        'dashboard' => 'speedometer-outline', 'list' => 'list-outline', 'form' => 'create-outline',
        'closing' => 'lock-closed-outline', 'import' => 'cloud-upload-outline', 'report' => 'document-text-outline',
        'document' => 'ribbon-outline', 'chart' => 'bar-chart-outline', 'settings' => 'options-outline',
    ];

    /** @param array{groups:array,pages:array} $catalog */
    public function __construct(
        private KnowledgeBase $kb,
        private array $catalog,
        private array $inventory = [],
        private array $situations = [],
    ) {
    }

    /** Situação de cada página lida da tabela do guia do sistema (✅/🟡/⚪). */
    public static function situationsFromGuide(string $guideFile): array
    {
        $map = [];
        if (!is_file($guideFile)) {
            return $map;
        }
        foreach (file($guideFile) ?: [] as $line) {
            if (preg_match('/^\|\s*((?:AP|CN)-\d{3})\s*\|[^|]*\|[^|]*\|\s*(✅|🟡|⚪)/u', $line, $m)) {
                $map[$m[1]] = ['✅' => 'funciona', '🟡' => 'exemplo', '⚪' => 'sem-backend'][$m[2]];
            }
        }
        return $map;
    }

    /** @return list<string> arquivos criados */
    public function run(bool $draft = true): array
    {
        $covered = $this->kb->byCode();
        $existing = $this->kb->articles();
        $created = [];
        $registries = [];
        foreach (Definitions::all() as $slug => $definition) {
            $registries[$definition['page']] ??= $definition + ['slug' => $slug];
        }
        foreach ($this->catalog['pages'] as $code => $page) {
            if (isset($covered[$code])) {
                continue;
            }
            $category = self::GROUP_CATEGORY[$page['group']] ?? 'primeiros-passos';
            $slug = $this->slug($page, $existing);
            $existing[$slug] = true;
            $file = $this->kb->root() . "/artigos/{$category}/{$slug}.md";
            if (is_file($file)) {
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $this->article($code, $page, $category, $slug, $registries[$code] ?? null, $draft));
            $created[] = substr($file, strlen(dirname(__DIR__, 3)) + 1);
        }
        return $created;
    }

    public function article(string $code, array $page, string $category, string $slug, ?array $registry, bool $draft): string
    {
        $inv = $this->inventory[$code] ?? [];
        $group = $this->catalog['groups'][$page['group']]['label'] ?? '';
        $name = $page['name'];
        $menu = $page['group'] === 'dashboard' ? 'Dashboard' : trim($group . ($page['section'] !== '' ? ' > ' . $page['section'] : '') . ' > ' . $name);
        $lead = trim((string)($inv['lead'] ?? '')) ?: ($registry['lead'] ?? '') ?: trim((string)($page['note'] ?? ''));
        $situation = $this->situations[$code] ?? 'funciona';
        $title = $this->title($page, $registry);
        $summary = $lead !== '' ? $lead : "Como usar a tela {$name} do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.";
        if (mb_strlen($summary) < 20) {
            $summary .= ' Veja onde fica e como usar a tela.';
        }
        $summary = mb_strimwidth($summary, 0, 318, '…');
        $route = $page['route'];
        $id = preg_replace('/[^a-z0-9-]/', '', $slug);

        $out = [];
        $out[] = '---';
        $out[] = "codigo: {$code}";
        $out[] = "titulo: {$title}";
        $out[] = 'resumo: ' . str_replace("\n", ' ', $summary);
        $out[] = "categoria: {$category}";
        $out[] = "rota: {$route}";
        $out[] = "menu: {$menu}";
        $out[] = 'icone: ' . (self::KIND_ICON[$page['kind']] ?? 'document-text-outline');
        $out[] = "situacao: {$situation}";
        $out[] = 'palavras: ' . implode(', ', array_unique(array_filter([mb_strtolower($name), mb_strtolower($group), $code])));
        $out[] = 'rascunho: ' . ($draft ? 'sim' : 'nao');
        $out[] = '---';
        $out[] = '';

        if ($situation === 'exemplo') {
            $out[] = '>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.';
            $out[] = '';
        } elseif ($situation === 'sem-backend') {
            $out[] = '>! Esta tela está **em implantação**: ela mostra como vai funcionar, com dados de exemplo, e ainda não grava nada.';
            $out[] = '';
        }

        $out[] = '## Onde fica';
        $out[] = '';
        $out[] = "No menu lateral, abra [[{$menu}]]." . ($lead !== '' && $lead !== $summary ? ' ' . $lead : '');
        $out[] = '';
        $out = array_merge($out, $this->listScreen($id, $route, $name, $inv));
        $out[] = '';
        if ($this->usesCondominium($inv, $registry)) {
            $out[] = '> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.';
            $out[] = '';
        }

        switch ($page['kind']) {
            case 'list':
                $out = array_merge($out, $registry ? $this->registrySections($registry, $id, $route, $name) : $this->listSections($inv, $name));
                break;
            case 'report':
            case 'chart':
                $out = array_merge($out, $this->reportSections($inv, $name, $page['kind'] === 'chart'));
                break;
            case 'import':
                $out = array_merge($out, $this->importSections($inv, $name));
                break;
            case 'document':
                $out = array_merge($out, $this->documentSections($inv, $name));
                break;
            case 'settings':
            case 'form':
                $out = array_merge($out, $this->formSections($inv, $name));
                break;
            default:
                $out = array_merge($out, $this->panelSections($inv, $name));
        }

        $out[] = '## Quem pode usar';
        $out[] = '';
        $out[] = 'A tela aparece para quem tem acesso ao módulo **' . ($group ?: $name) . '**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).';
        $out[] = '';

        return implode("\n", $out) . "\n";
    }

    /** @return list<string> */
    private function listScreen(string $id, string $route, string $name, array $inv): array
    {
        $lines = ['```tela ' . $id, "rota: {$route}", 'condominio: ' . self::DEMO_CONDOMINIUM, "legenda: Tela {$name}"];
        $n = 1;
        $lines[] = ($n++) . ': menu | ' . $name . ' no menu lateral';
        // O botão principal costuma ser o último do cabeçalho; ele vem primeiro nas marcações.
        foreach (array_slice(array_reverse($inv['actions'] ?? []), 0, 2) as $action) {
            $lines[] = ($n++) . ': main :is(a,button):has-text("' . str_replace('"', '', $action) . '") | Botão {{' . $action . '}}';
        }
        if (!empty($inv['filters'])) {
            $lines[] = ($n++) . ': main :is(form[method=get], .erp-filters) | Filtros: ' . implode(', ', $inv['filters']);
        }
        if (!empty($inv['columns'])) {
            $lines[] = ($n++) . ': main table thead | Lista com ' . self::joinList(array_map([self::class, 'label'], array_diff($inv['columns'], ['AÇÕES', 'Ações'])));
        } elseif (!empty($inv['cards'])) {
            $lines[] = ($n++) . ': main .erp-card | ' . $inv['cards'][0];
        }
        $lines[] = '```';
        return $lines;
    }

    private function registrySections(array $registry, string $id, string $route, string $name): array
    {
        $new = ($registry['gender'] ?? 'o') === 'a' ? 'Nova' : 'Novo';
        $entity = $registry['entity'] ?? 'registro';
        $out = ['## Como cadastrar', ''];
        $out[] = "1. Clique em {{{$new} {$entity}}} no alto da tela.";
        $out[] = '2. Preencha os campos. Os obrigatórios têm asterisco (*).';
        $out[] = '3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.';
        $out[] = '';
        $out[] = '```tela ' . $id . '-novo';
        $out[] = "rota: {$route}/novo";
        $out[] = 'condominio: ' . self::DEMO_CONDOMINIUM;
        $out[] = "legenda: Formulário de {$entity}";
        $out[] = 'recorte: main form.erp-form';
        $out[] = '1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *';
        $out[] = '2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro';
        $out[] = '```';
        $out[] = '';
        $out[] = '### O que preencher';
        $out[] = '';
        foreach ($registry['fields'] as $field) {
            $label = $field['label'];
            $text = '- **' . $label . '**' . (!empty($field['required']) ? ' (obrigatório)' : '');
            $detail = [];
            if (!empty($field['hint'])) {
                $detail[] = rtrim($field['hint'], '.') . '.';
            }
            if (!empty($field['options'])) {
                $detail[] = 'Opções: ' . self::joinList(array_values($field['options'])) . '.';
            }
            $detail[] = match ($field['type']) {
                'condominium' => 'Só aparecem os condomínios que a sua conta pode ver.',
                'unit' => 'Escolha entre as unidades do condomínio selecionado.',
                'money' => 'Em reais, por exemplo 1.250,00.',
                'cpf' => 'O sistema confere os dígitos do CPF.',
                'cnpj' => 'O sistema confere os dígitos do CNPJ.',
                'document' => 'CPF ou CNPJ; o sistema confere os dígitos.',
                'date' => 'No formato dia/mês/ano.',
                'bool' => 'Marque para ativar.',
                default => '',
            };
            $detail = array_filter($detail);
            $out[] = $text . ($detail ? ': ' . lcfirst(implode(' ', $detail)) : '.');
        }
        $out[] = '';
        $out[] = '## Pesquisar e filtrar';
        $out[] = '';
        $out[] = 'Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.';
        $out[] = '';
        $out[] = '## Editar ou excluir';
        $out[] = '';
        $out[] = 'Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.';
        if (!empty($registry['referenced_by'])) {
            $out[] = '';
            $out[] = "> **Atenção:** um cadastro usado por outros (por exemplo, {$entity} com lançamentos ou pessoas vinculadas) não pode ser excluído. Marque-o como inativo para tirá-lo das listas sem perder o histórico.";
        }
        $out[] = '';
        return $out;
    }

    private function listSections(array $inv, string $name): array
    {
        $out = ['## Como usar', ''];
        $actions = $inv['actions'] ?? [];
        if ($actions) {
            $out[] = 'Os botões no alto da tela são ' . self::joinList(array_map(static fn ($a) => '{{' . $a . '}}', $actions)) . '.';
            $out[] = '';
        }
        if (!empty($inv['filters'])) {
            $out[] = '1. Ajuste os filtros (' . self::joinList($inv['filters']) . ').';
            $out[] = '2. Clique em {{Filtrar}}.';
            $out[] = '3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.';
            $out[] = '';
        }
        if (!empty($inv['columns'])) {
            $out[] = '### O que cada coluna mostra';
            $out[] = '';
            foreach (array_diff($inv['columns'], ['AÇÕES', 'Ações']) as $column) {
                $out[] = '- **' . self::label($column) . '**';
            }
            $out[] = '';
        }
        return $out;
    }

    private function reportSections(array $inv, string $name, bool $chart): array
    {
        $out = [$chart ? '## Como ler o gráfico' : '## Como gerar o relatório', ''];
        if (!empty($inv['filters'])) {
            $out[] = '1. Escolha ' . self::joinList(array_map('mb_strtolower', $inv['filters'])) . '.';
            $out[] = '2. Clique em {{' . (in_array('Gerar', $inv['buttons'] ?? [], true) ? 'Gerar' : 'Filtrar') . '}}.';
            $out[] = '3. Confira o resultado na tela.';
        } else {
            $out[] = 'A tela abre com o período atual. Escolha o condomínio na barra superior para ver só os números dele.';
        }
        $out[] = '';
        if (!empty($inv['columns'])) {
            $out[] = '### O que cada coluna mostra';
            $out[] = '';
            foreach (array_diff($inv['columns'], ['AÇÕES', 'Ações']) as $column) {
                $out[] = '- **' . self::label($column) . '**';
            }
            $out[] = '';
        }
        if (array_intersect(['Imprimir', 'Exportar', 'CSV', 'Exportar CSV'], $inv['buttons'] ?? [])) {
            $out[] = '## Imprimir ou exportar';
            $out[] = '';
            $out[] = 'Use ' . self::joinList(array_map(static fn ($b) => '{{' . $b . '}}', array_values(array_intersect(['Imprimir', 'Exportar', 'Exportar CSV', 'CSV'], $inv['buttons'])))) . ' para levar o resultado para a reunião, o conselho ou a planilha.';
            $out[] = '';
        }
        return $out;
    }

    private function importSections(array $inv, string $name): array
    {
        return [
            '## Como importar', '',
            '1. Separe o arquivo no formato pedido na tela.',
            '2. Escolha o condomínio e o arquivo e envie.',
            '3. Confira a **pré-visualização**: o sistema mostra as linhas reconhecidas e aponta as que têm erro, antes de gravar qualquer coisa.',
            '4. Confirme a importação. Ela é gravada de uma vez: se algo falhar, nada entra pela metade.',
            '',
            '> **Dica:** o histórico em "Importações enviadas" mostra quem enviou cada arquivo, quando e o resultado.',
            '',
        ];
    }

    private function documentSections(array $inv, string $name): array
    {
        $fields = $inv['fields'] ?? [];
        return [
            '## Como emitir', '',
            '1. Escolha ' . ($fields ? self::joinList(array_map('mb_strtolower', array_slice($fields, 0, 2))) : 'a unidade e a data de referência') . '.',
            '2. Clique em {{Emitir documento}}.',
            '3. O documento sai com um **código de verificação** impresso no rodapé.',
            '',
            '## Conferir a autenticidade', '',
            'Qualquer pessoa pode conferir um documento emitido pelo sistema na página pública de verificação, digitando o código impresso. O sistema responde se é autêntico, o tipo, a data e o condomínio, sem mostrar valores nem dados pessoais.',
            '',
        ];
    }

    private function formSections(array $inv, string $name): array
    {
        $out = ['## Como preencher', ''];
        $fields = $inv['fields'] ?? [];
        if ($fields) {
            foreach ($fields as $field) {
                $out[] = '- **' . rtrim($field, ' *') . '**' . (str_ends_with($field, '*') ? ' (obrigatório)' : '');
            }
            $out[] = '';
        }
        $out[] = 'Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.';
        $out[] = '';
        return $out;
    }

    private function panelSections(array $inv, string $name): array
    {
        $out = ['## O que a tela mostra', ''];
        foreach ($inv['cards'] ?? [] as $card) {
            $out[] = '- **' . $card . '**';
        }
        $out[] = '';
        return $out;
    }

    private function title(array $page, ?array $registry): string
    {
        $name = $page['name'];
        $lower = mb_strtolower(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        return match ($page['kind']) {
            'list' => $registry ? "Cadastrar e gerenciar {$lower}" : $name,
            'report' => "Relatório {$name}",
            'chart' => "Gráfico {$name}",
            'import' => str_starts_with($name, 'Importação') ? $name : "Importar {$lower}",
            'document' => "Emitir {$lower}",
            'settings' => "Configurar {$lower}",
            default => $name,
        };
    }

    private function slug(array $page, array $existing): string
    {
        $parts = array_values(array_filter(explode('/', $page['route'])));
        $slug = end($parts) ?: 'pagina';
        if (isset($existing[$slug])) {
            $slug = ($parts[0] ?? $page['group']) . '-' . $slug;
        }
        return $slug;
    }

    private function usesCondominium(array $inv, ?array $registry): bool
    {
        if ($registry) {
            return ($registry['scope'] ?? '') !== 'none';
        }
        return in_array('Condomínio', $inv['filters'] ?? [], true) || in_array('CONDOMÍNIO', $inv['columns'] ?? [], true);
    }

    private static function label(string $column): string
    {
        $lower = mb_strtolower($column);
        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }

    private static function joinList(array $items): string
    {
        $items = array_values(array_filter($items, static fn ($i) => $i !== ''));
        if (count($items) < 2) {
            return (string)($items[0] ?? '');
        }
        return implode(', ', array_slice($items, 0, -1)) . ' e ' . end($items);
    }
}
