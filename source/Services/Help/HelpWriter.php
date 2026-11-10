<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Escreve o passo a passo dos artigos da Central de Ajuda a partir do levantamento real de cada
 * tela (tools/help/.detalhes.json, gerado por `node tools/help/details.mjs`) e dos textos escritos
 * à mão para cada página (tools/help/textos.php: para que serve, dicas e cuidados). ADR 0141.
 *
 * Cada ação do cabeçalho vira uma seção com os passos, o print do modal ou do formulário marcado
 * campo a campo e a lista "O que preencher" com obrigatórios e dicas da própria tela. Artigos com
 * "manual: sim" no cabeçalho não são tocados.
 */
final class HelpWriter
{
    /** Listas de nomes (condomínios, contas, pessoas) não viram "opções" no texto. */
    private const ENTITY_FIELDS = '/^(condom[ií]nio|unidade|conta|pessoa|morador|propriet|fornecedor|favorecido|banco|centro de custo|plano|categoria da conta|respons[aá]vel|funcion[aá]rio|advogado|[aá]rea|portaria|rel[oó]gio|tipo de fatura|cliente|usu[aá]rio)/iu';

    /** Ação da linha → o que ela faz (verbo no começo do texto do botão). */
    private const ROW_ACTIONS = [
        'editar' => '**Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.',
        'ver' => '**Ver** abre os detalhes do registro, sem alterar nada.',
        'abrir' => '**Abrir** mostra os detalhes do item.',
        'excluir' => '**Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.',
        'cancelar' => '**Cancelar** encerra o item sem apagá-lo: ele continua na lista com a situação cancelada.',
        'consultar' => '**Consultar** pergunta ao banco a situação atual do boleto.',
        'copiar' => '**Copiar linha digitável** copia o código do boleto para colar no e-mail ou no WhatsApp.',
        'atender' => '**Atender** abre o atendimento da unidade, com as contas em aberto e o histórico de contatos.',
        'confirmar' => '**Confirmar** aprova o pedido, e o morador é avisado.',
        'recusar' => '**Recusar** nega o pedido. Informe o motivo para o morador entender.',
        'desconto' => '**Desconto** lança um desconto na conta a receber da linha.',
        'fazer acordo' => '**Fazer acordo** junta as contas em atraso da unidade e abre o parcelamento.',
        'trilha' => '**Trilha** mostra cada tentativa de envio do aviso, com data e resultado.',
        'baixar' => '**Baixar** salva o arquivo no seu computador.',
        'aplicar' => '**Aplicar** grava o que a prévia mostrou.',
    ];

    /** @param array{groups:array,pages:array} $catalog */
    public function __construct(
        private KnowledgeBase $kb,
        private array $catalog,
        private array $details,
        private array $texts = [],
    ) {
    }

    /** @return list<string> arquivos reescritos */
    public function run(array $only = []): array
    {
        $written = [];
        $articles = $this->kb->articles();
        $byCode = $this->kb->byCode();
        foreach ($this->catalog['pages'] as $code => $page) {
            if (($only && !in_array($code, $only, true)) || !isset($byCode[$code], $this->details[$code]) || isset($this->details[$code]['error'])) {
                continue;
            }
            $article = $byCode[$code];
            if (strtolower($article['meta']['manual'] ?? 'nao') === 'sim' || count($article['codes']) !== 1) {
                continue;
            }
            $file = $this->kb->root() . '/artigos/' . $article['folder'] . '/' . $article['slug'] . '.md';
            file_put_contents($file, $this->article($code, $page, $article, $articles));
            $repo = dirname(__DIR__, 3) . '/';
            $written[] = str_starts_with($file, $repo) ? substr($file, strlen($repo)) : $file;
        }
        return $written;
    }

    public function article(string $code, array $page, array $article, array $articles): string
    {
        $d = $this->details[$code];
        $text = $this->texts[$code] ?? [];
        $slug = $article['slug'];
        $menu = $article['menu'];
        $name = $page['name'];
        $group = $this->catalog['groups'][$page['group']]['label'] ?? '';
        $demo = !empty($d['demo']);
        $situation = $demo ? 'exemplo' : ($article['situation'] === 'guia' ? 'guia' : 'funciona');
        $lead = trim((string)($d['lead'] ?? ''));
        $purpose = trim((string)($text['para'] ?? '')) ?: $lead;
        $summary = mb_strimwidth($this->plain($purpose !== '' ? $purpose : $article['summary']), 0, 318, '…');
        if (mb_strlen($summary) < 20) {
            $summary = $article['summary'];
        }
        $related = $this->related($code, $page, $slug, $articles);

        $out = ['---'];
        $out[] = "codigo: {$code}";
        $out[] = 'titulo: ' . ($text['titulo'] ?? $this->title($article['title']));
        $out[] = 'resumo: ' . $summary;
        $out[] = 'categoria: ' . $article['category'];
        $out[] = 'rota: ' . $article['route'];
        $out[] = "menu: {$menu}";
        $out[] = 'icone: ' . $article['icon'];
        $out[] = "situacao: {$situation}";
        if ($related) {
            $out[] = 'relacionados: ' . implode(', ', $related);
        }
        $out[] = 'palavras: ' . implode(', ', array_unique(array_merge($article['keywords'], $text['palavras'] ?? [])));
        $out[] = 'rascunho: nao';
        $out[] = '---';
        $out[] = '';

        if ($purpose !== '') {
            $out[] = $purpose;
            $out[] = '';
        }
        if ($lead !== '' && $purpose !== $lead && mb_strlen($lead) > 30 && empty($text['para'])) {
            $out[] = 'Na própria tela: *' . $lead . '*';
            $out[] = '';
        }
        if ($demo) {
            $out[] = '>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.';
            $out[] = '';
        }

        // Onde fica: menu, ações do cabeçalho, filtros e lista/cartões.
        $out[] = '## Onde fica';
        $out[] = '';
        $out[] = "No menu lateral, abra [[{$menu}]]." . ($this->usesCondominium($d) ? ' Confira o condomínio na barra superior: a tela mostra só os dados dele.' : '');
        $out[] = '';
        $out = array_merge($out, $this->mainScreen($slug, $page, $d));
        $out[] = '';

        // Ações do cabeçalho com modal ou formulário.
        $registry = false;
        foreach ($d['actions'] ?? [] as $action) {
            $form = $action['dialog'] ?? $action['form'] ?? null;
            if (!$form || !$form['fields']) {
                continue;
            }
            $isPage = isset($action['form']);
            $registry = $registry || ($isPage && str_ends_with((string)($action['formRoute'] ?? ''), '/novo'));
            $out = array_merge($out, $this->actionSection($slug, $page, $action, $form, $isPage));
        }
        // Botões do cabeçalho que levam a outra tela (explicação escrita à mão em textos.php).
        $links = [];
        foreach ($d['actions'] ?? [] as $action) {
            $explain = $text['botoes'][$action['text']] ?? null;
            if ($explain !== null && empty($action['dialog']) && empty($action['form'])) {
                $links[] = "- {{{$action['text']}}}: {$explain}";
            }
        }
        if ($links) {
            $out[] = '## Outros botões da tela';
            $out[] = '';
            $out = array_merge($out, $links);
            $out[] = '';
        }
        // Formulário da própria página (configurações, documentos, importações, chamados…).
        if (!empty($d['form']['fields']) && !$this->formIsFilter($d['form'], $d['filters'] ?? [])) {
            $out = array_merge($out, $this->pageFormSection($slug, $page, $d));
        }

        if (!empty($d['filters'])) {
            $out[] = $page['kind'] === 'report' || $page['kind'] === 'chart' ? '## Escolher o período e os filtros' : '## Pesquisar e filtrar';
            $out[] = '';
            $out[] = 'Use os filtros no alto da lista: ' . $this->joinList(array_map(fn ($f) => '**' . $f . '**', $d['filters'])) . '.';
            $button = $d['filterButtons'][0] ?? '';
            if ($button !== '' && mb_strlen($button) < 20) {
                $out[] = in_array($page['kind'], ['report', 'chart'], true)
                    ? "Depois clique em {{{$button}}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela."
                    : "Depois clique em {{{$button}}}. Para voltar à lista completa, limpe os filtros.";
            }
            $out[] = '';
        }

        $columns = array_values(array_filter($d['columns'] ?? [], fn ($c) => $c !== '' && !preg_match('/^a[cç][oõ]es$/iu', $c)));
        if ($columns) {
            $out[] = in_array($page['kind'], ['report', 'chart'], true) ? '## O que o relatório mostra' : '## O que a lista mostra';
            $out[] = '';
            $out[] = 'Colunas: ' . $this->joinList(array_map(fn ($c) => '**' . $this->label($c) . '**', $columns)) . '.';
            if (in_array($page['kind'], ['report'], true)) {
                $out[] = 'Os totais aparecem no rodapé da tabela.';
            }
            $out[] = '';
        } elseif (!empty($d['cards']) && in_array($page['kind'], ['dashboard', 'closing', 'list'], true) && empty($d['form']['fields'])) {
            $out[] = '## O que a tela mostra';
            $out[] = '';
            foreach (array_slice($d['cards'], 0, 10) as $card) {
                $out[] = '- **' . $card . '**';
            }
            $out[] = '';
        }

        $rowActions = $this->rowActions($d['rowActions'] ?? []);
        if ($rowActions) {
            $out[] = '## Ações de cada linha';
            $out[] = '';
            if (!empty($d['rowActions'][0]) && !preg_match('/^(editar|ver|abrir|excluir|cancelar|consultar|copiar|atender|confirmar|recusar|desconto|fazer|trilha)/iu', $d['rowActions'][0])) {
                $out[] = '- Clique no nome (primeira coluna) para abrir o registro.';
            }
            foreach ($rowActions as $line) {
                $out[] = '- ' . $line;
            }
            $out[] = '';
        }

        $exports = array_values(array_filter(array_column($d['actions'] ?? [], 'text'), fn ($t) => preg_match('/^(imprimir|exportar)/iu', $t)));
        if ($exports) {
            $out[] = '## Imprimir ou exportar';
            $out[] = '';
            foreach ($exports as $export) {
                if (preg_match('/^imprimir/iu', $export)) {
                    $out[] = "- {{{$export}}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.";
                } else {
                    $out[] = "- {{{$export}}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.";
                }
            }
            $out[] = '';
        }

        foreach ($text['secoes'] ?? [] as $heading => $body) {
            $out[] = '## ' . $heading;
            $out[] = '';
            $out[] = trim($body);
            $out[] = '';
        }
        foreach ($text['dicas'] ?? [] as $tip) {
            $out[] = '> ' . $tip;
            $out[] = '';
        }
        foreach ((array)($text['atencao'] ?? []) as $warning) {
            $out[] = '>! ' . $warning;
            $out[] = '';
        }
        foreach ($d['notes'] ?? [] as $note) {
            if (!str_contains($note, 'Interface proposta') && !preg_match('/^Nenhum/u', $note) && !str_contains(implode(' ', $text['dicas'] ?? []), $note) && mb_strlen($note) > 40) {
                $out[] = '> Na tela: *' . $note . '*';
                $out[] = '';
                break;
            }
        }

        $out[] = '## Quem pode usar';
        $out[] = '';
        $out[] = 'A tela aparece para quem tem acesso ao módulo **' . ($group ?: $name) . '**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).';
        $out[] = '';
        if ($related) {
            $links = array_map(fn ($r) => '[' . $articles[$r]['title'] . '](artigo:' . $r . ')', array_slice($related, 0, 3));
            $out[] = '>> Veja também: ' . $this->joinList($links) . '.';
            $out[] = '';
        }
        return implode("\n", $out);
    }

    private function mainScreen(string $slug, array $page, array $d): array
    {
        $lines = ['```tela ' . $slug, 'rota: ' . $page['route'], 'condominio: ' . HelpScaffold::DEMO_CONDOMINIUM, 'legenda: Tela ' . $page['name']];
        $n = 1;
        if ($page['group'] !== 'dashboard' || $page['route'] === '/dashboard') {
            $lines[] = ($n++) . ': menu | ' . $page['name'] . ' no menu lateral';
        }
        foreach (array_slice(array_reverse($d['actions'] ?? []), 0, 3) as $action) {
            $lines[] = ($n++) . ': main .erp-page-head__actions :is(a,button):has-text("' . $this->quote($action['text']) . '") | Botão {{' . $action['text'] . '}}';
        }
        if (!empty($d['filters'])) {
            $lines[] = ($n++) . ': main :is(form[method=get], .erp-filters) | Filtros: ' . $this->joinList(array_slice($d['filters'], 0, 4));
        }
        if (!empty($d['columns']) && ($d['rows'] ?? 0) > 0) {
            $lines[] = ($n++) . ': main table thead | Colunas da lista';
        } elseif (!empty($d['form']['fields']) && !$this->formIsFilter($d['form'], $d['filters'] ?? [])) {
            $lines[] = ($n++) . ': main form:not([method=get]) | ' . ($d['form']['title'] !== '' ? $d['form']['title'] : 'Formulário');
        }
        $lines[] = '```';
        return $lines;
    }

    private function actionSection(string $slug, array $page, array $action, array $form, bool $isPage): array
    {
        $title = $action['text'];
        $heading = preg_match('/^(nov[oa]|cadastro)/iu', $title) ? 'Como cadastrar' : $title;
        $id = $slug . '-' . $this->slugify($title);
        $required = array_values(array_filter($form['fields'], fn ($f) => $f['required']));
        $form['submit'] = $this->submits($form);
        $submit = $form['submit'][0] ?? 'Salvar';
        $out = ['## ' . $heading, ''];
        foreach ($form['intro'] as $intro) {
            $out[] = $intro;
            $out[] = '';
            break;
        }
        $out[] = "1. Clique em {{{$title}}}" . ($isPage ? ', no alto da lista.' : '. A janela abre por cima da lista.');
        $out[] = $required
            ? '2. Preencha os campos obrigatórios: ' . $this->joinList(array_map(fn ($f) => '**' . $f['label'] . '**', $required)) . '. Os outros podem ficar para depois.'
            : '2. Preencha os campos.';
        $out[] = "3. Clique em {{{$submit}}}.";
        if ($isPage) {
            $out[] = '4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.';
        }
        $out[] = '';
        $out = array_merge($out, $this->formScreen($id, $page, $form, $isPage ? ($action['formRoute'] ?: $page['route'] . '/novo') : $page['route'], $isPage ? null : $title, $isPage ? 'main form:not([method=get])' : 'dialog[open]', $heading === 'Como cadastrar' ? 'Formulário: ' . mb_strtolower($title) : $title));
        $out[] = '';
        $out = array_merge($out, $this->fieldList($form));
        return $out;
    }

    private function pageFormSection(string $slug, array $page, array $d): array
    {
        $form = $d['form'];
        $form['submit'] = $this->submits($form);
        $submit = $form['submit'][0] ?? 'Salvar';
        $heading = $form['title'] !== '' && mb_strlen($form['title']) < 50 ? $form['title'] : match ($page['kind']) {
            'import' => 'Como importar',
            'document' => 'Como emitir',
            'settings' => 'Como configurar',
            default => 'Como usar',
        };
        $required = array_values(array_filter($form['fields'], fn ($f) => $f['required']));
        $out = ['## ' . $heading, ''];
        foreach ($form['intro'] as $intro) {
            $out[] = $intro;
            $out[] = '';
            break;
        }
        $steps = [];
        $grid = count($form['fields']) > 16;
        if ($grid) {
            // Grade (permissões, frações): o passo a passo vem de textos.php.
        } elseif ($page['kind'] === 'import') {
            $steps[] = 'Escolha as opções do envio e selecione o **arquivo** no seu computador.';
        } else {
            $steps[] = $required
                ? 'Preencha ' . $this->joinList(array_map(fn ($f) => '**' . $f['label'] . '**', $required)) . '.'
                : 'Preencha os campos que quer alterar.';
        }
        $steps[] = "Clique em {{{$submit}}}.";
        if ($page['kind'] === 'import') {
            $steps[] = 'Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.';
            $steps[] = 'Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.';
        }
        if (isset($form['submit'][1])) {
            $steps[] = "Se mudou de ideia antes de gravar, {{{$form['submit'][1]}}} volta como estava.";
        }
        if (!$grid) {
            foreach ($steps as $i => $step) {
                $out[] = ($i + 1) . '. ' . $step;
            }
            $out[] = '';
        }
        $out = array_merge($out, $this->formScreen($slug . '-form', $page, $form, $page['route'], null, 'main form:not([method=get])', $heading));
        $out[] = '';
        return array_merge($out, $this->fieldList($form));
    }

    private function formScreen(string $id, array $page, array $form, string $route, ?string $open, string $frame, string $caption): array
    {
        $lines = ['```tela ' . $id, 'rota: ' . $route, 'condominio: ' . HelpScaffold::DEMO_CONDOMINIUM, 'legenda: ' . $caption];
        if ($open !== null) {
            $lines[] = 'antes: clicar main .erp-page-head__actions :is(a,button):has-text("' . $this->quote($open) . '")';
            $lines[] = 'antes: esperar 500';
        }
        $lines[] = 'recorte: ' . $frame;
        $scope = $open !== null ? 'dialog[open]' : 'main form:not([method=get])';
        $n = 1;
        // Até 5 campos (obrigatórios primeiro), numerados na ordem em que aparecem na tela.
        $marked = array_filter($form['fields'], fn ($f) => $f['name'] !== '' && $f['type'] !== 'radio' && $f['type'] !== 'checkbox');
        $chosen = [];
        foreach ([true, false] as $required) {
            foreach ($marked as $index => $field) {
                if (count($chosen) < 5 && $field['required'] === $required && !in_array($field['name'], array_column($chosen, 'name'), true)) {
                    $chosen[$index] = $field;
                }
            }
        }
        ksort($chosen);
        foreach ($chosen as $field) {
            $lines[] = ($n++) . ': ' . $scope . ' [name="' . $this->quote($field['name']) . '"] | ' . $field['label'] . ($field['required'] ? ' (obrigatório)' : '');
        }
        $submit = $form['submit'][0] ?? '';
        if ($submit !== '') {
            $lines[] = ($n++) . ': ' . $scope . ' button:has-text("' . $this->quote($submit) . '") | Botão {{' . $submit . '}}';
        }
        $lines[] = '```';
        return $lines;
    }

    private function fieldList(array $form): array
    {
        $out = ['### O que preencher', ''];
        // Grades (permissões, frações por unidade, unidades a avisar) não viram lista campo a campo.
        $fields = array_values(array_filter($form['fields'], fn ($f) => $f['type'] !== 'radio' && !preg_match('/\\b[A-Z0-9]{1,3} - \\d+|pode acessar|^marcar todas/iu', $f['label'])));
        if (count($fields) > 16) {
            return [];
        }
        foreach ($fields as $field) {
            $field['label'] = (string)preg_replace('/\\s*\\(\\d+\\)$/', '', $field['label']);
            $line = '- **' . $field['label'] . '**' . ($field['required'] ? ' (obrigatório)' : '');
            $extra = [];
            if (preg_match('/^\\d+ de ([\\d.]+)$/u', $field['hint'], $count)) {
                $extra[] = 'Até ' . $count[1] . ' caracteres.';
            } elseif ($field['hint'] !== '') {
                $extra[] = rtrim($field['hint'], '.') . '.';
            } elseif ($field['placeholder'] !== '' && mb_strlen($field['placeholder']) > 3 && preg_match('/\p{L}{3}/u', $field['placeholder']) && !preg_match('/^(Nome|E-mail|Buscar|Pesquisar)/u', $field['placeholder'])) {
                $extra[] = 'Exemplo: ' . rtrim($field['placeholder'], '.') . '.';
            }
            if ($field['type'] === 'select' && $field['options'] && count($field['options']) <= 6 && !preg_match(self::ENTITY_FIELDS, $field['label'])) {
                $extra[] = 'Opções: ' . $this->joinList($field['options']) . '.';
            }
            if ($field['type'] === 'file') {
                $extra[] = 'Escolha o arquivo no seu computador.';
            }
            $out[] = $line . ($extra ? ': ' . implode(' ', $extra) : '.');
        }
        $out[] = '';
        return count($out) > 3 ? $out : [];
    }

    /** @return list<string> */
    private function rowActions(array $actions): array
    {
        $lines = [];
        foreach ($actions as $action) {
            $lower = mb_strtolower($action);
            foreach (self::ROW_ACTIONS as $verb => $line) {
                if (str_starts_with($lower, $verb) && !in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    /** Artigos da mesma seção do menu (ou do mesmo grupo), sem o próprio. */
    private function related(string $code, array $page, string $slug, array $articles): array
    {
        $byCode = $this->kb->byCode();
        $same = [];
        $group = [];
        foreach ($this->catalog['pages'] as $other => $info) {
            if ($other === $code || !isset($byCode[$other]) || $byCode[$other]['slug'] === $slug) {
                continue;
            }
            if ($info['group'] === $page['group'] && $info['section'] === $page['section'] && $page['section'] !== '') {
                $same[] = $byCode[$other]['slug'];
            } elseif ($info['group'] === $page['group']) {
                $group[] = $byCode[$other]['slug'];
            }
        }
        $order = array_flip(array_keys($this->catalog['pages']));
        $distance = function (string $slug) use ($byCode, $order, $code): int {
            foreach ($byCode as $c => $a) {
                if ($a['slug'] === $slug && isset($order[$c])) {
                    return abs($order[$c] - $order[$code]);
                }
            }
            return 999;
        };
        usort($same, fn ($a, $b) => $distance($a) <=> $distance($b));
        usort($group, fn ($a, $b) => $distance($a) <=> $distance($b));
        $extra = array_values(array_filter($this->texts[$code]['relacionados'] ?? [], fn ($r) => isset($articles[$r])));
        return array_values(array_unique(array_slice(array_merge($extra, $same, $group), 0, 4)));
    }

    /** Botão principal primeiro; "Desfazer", "Limpar" e afins depois. */
    private function submits(array $form): array
    {
        $all = $form['submit'] ?? [];
        $secondary = array_values(array_filter($all, fn ($t) => preg_match('/^(desfazer|limpar|voltar|cancelar|fechar|descartar)/iu', $t)));
        return array_values(array_merge(array_diff($all, $secondary), $secondary));
    }

    private function formIsFilter(array $form, array $filters = []): bool
    {
        $labels = array_column($form['fields'], 'label');
        if ($filters !== [] && array_diff($labels, $filters) === []) {
            return true;
        }
        $labels = array_column($form['fields'], 'label');
        return $labels !== [] && count(array_filter($labels, fn ($l) => preg_match('/^(condom[ií]nio|de|até|situa[cç][aã]o|per[ií]odo|m[eê]s|compet[eê]ncia|buscar|pesquisar)$/iu', $l))) === count($labels);
    }

    private function usesCondominium(array $d): bool
    {
        return in_array($d['kind'] ?? '', ['list', 'report', 'chart', 'closing', 'document', 'import'], true);
    }

    /** "Cadastrar e gerenciar Plano de Contas" → "Cadastrar e gerenciar o plano de contas" fica a cargo de textos.php; aqui só baixa a caixa. */
    private function title(string $title): string
    {
        if (preg_match('/^(Cadastrar e gerenciar |Configurar |Emitir |Importar |Consultar |Gerar |Usar )(.+)$/u', $title, $m)) {
            $rest = implode(' ', array_map(fn ($w) => preg_match('/^[A-Z]{2,}|-[a-z]$|\(/u', $w) ? $w : mb_strtolower($w), explode(' ', $m[2])));
            return $m[1] . $rest;
        }
        return $title;
    }

    private function plain(string $text): string
    {
        return trim((string)preg_replace(['/\[\[|\]\]|\{\{|\}\}|\*\*/', '/\[([^\]]+)\]\([^)]+\)/', '/\s+/'], ['', '$1', ' '], $text));
    }

    private function label(string $column): string
    {
        if (mb_strtoupper($column) !== $column || mb_strlen($column) <= 3) {
            return $column;
        }
        $lower = mb_strtolower($column);
        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }

    private function joinList(array $items): string
    {
        $items = array_values(array_filter($items, fn ($i) => $i !== ''));
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        return implode(', ', array_slice($items, 0, -1)) . ' e ' . end($items);
    }

    private function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($text)) ?: $text;
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $ascii), '-') ?: 'acao';
    }

    private function quote(string $text): string
    {
        return str_replace('"', '\\"', $text);
    }
}
