<?php

declare(strict_types=1);

/**
 * Gera docs/api/registries.openapi.yaml a partir de Source\Services\Erp\Registry\Definitions,
 * para que o contrato da API dos cadastros nunca divirja das definições usadas pelo servidor.
 *
 * Uso: MOVESOS_ENV=testing php tools/registry-openapi.php (o boot do autoload lê o banco de teste)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;

$q = static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
$schema = static function (array $field) use ($q): string {
    return match ($field['type']) {
        'condominium' => '{ type: [integer, "null"], minimum: 1 }',
        'unit' => '{ type: [integer, "null"], minimum: 1, description: ' . $q('ID de /units do mesmo condomínio; preenche unit_label com o texto da unidade.') . ' }',
        'ref' => '{ type: [integer, "null"], minimum: 1, description: ' . $q('ID de /' . (\Source\Services\Erp\Registry\Definitions::get((string)$field['ref'])['api'] ?? '') . ' ativo, do plano padrão ou do mesmo condomínio.') . ' }',
        'decimal' => '{ type: [number, "null"], minimum: ' . (float)($field['min'] ?? 0) . ', maximum: ' . (float)($field['max_value'] ?? 1e12) . ' }',
        'bool' => '{ type: boolean }',
        'money' => '{ type: [integer, "null"], minimum: 0, description: ' . $q('Centavos. Na entrada também aceita "1.250,00".') . ' }',
        'percent' => '{ type: [number, "null"], minimum: 0, maximum: 100 }',
        'date' => '{ type: [string, "null"], format: date }',
        'time' => '{ type: [string, "null"], pattern: "^\\\\d{2}:\\\\d{2}$", examples: ["08:00"] }',
        'int' => '{ type: [integer, "null"], minimum: ' . (int)($field['min'] ?? 0) . ', maximum: ' . (int)($field['max_value'] ?? 999999999) . ' }',
        'email' => '{ type: [string, "null"], format: email, maxLength: 254 }',
        'select' => '{ type: [string, "null"], enum: [' . implode(', ', array_map($q, array_keys($field['options']))) . '] }',
        'cpf' => '{ type: [string, "null"], pattern: "^\\\\d{11}$", description: ' . $q('CPF com DV válido; resposta só com dígitos.') . ' }',
        'cnpj' => '{ type: [string, "null"], pattern: "^\\\\d{14}$" }',
        'document' => '{ type: [string, "null"], description: ' . $q('CPF ou CNPJ com DV válido; resposta só com dígitos.') . ' }',
        'id_document' => '{ type: [string, "null"], description: ' . $q('CPF válido ou RG/outro (5 a 20 caracteres alfanuméricos).') . ' }',
        'phone' => '{ type: [string, "null"], description: ' . $q('DDD e número; resposta só com dígitos.') . ' }',
        'plate' => '{ type: [string, "null"], pattern: "^[A-Z]{3}\\\\d[A-Z0-9]\\\\d{2}$" }',
        'oab' => '{ type: [string, "null"], examples: ["SP 123456"] }',
        default => '{ type: [string, "null"], maxLength: ' . (int)($field['max'] ?? ($field['type'] === 'longtext' ? 2000 : 160)) . ' }',
    };
};

$out = [];
$out[] = '# Gerado por tools/registry-openapi.php a partir de Definitions.php. Não editar à mão.';
$out[] = 'openapi: 3.1.0';
$out[] = 'info:';
$out[] = '  title: Connect ERP API — Cadastros';
$out[] = '  version: 0.3.0';
$out[] = '  description: |';
$out[] = '    Cadastros do ERP (ADR 0014). Mesma autenticação de openapi.yaml: sessão do Organic Code,';
$out[] = '    X-CSRF-Token em POST/PUT/PATCH/DELETE. Permissões erp.<cadastro>.view (leitura) e';
$out[] = '    erp.<cadastro>.manage (escrita). Registros fora do escopo de condomínios respondem 404.';
$out[] = '    Listagens paginadas no servidor (' . RegistryService::PER_PAGE . ' por página). Exclusão lógica.';
$out[] = 'servers:';
$out[] = '  - url: /api/v1';
$out[] = 'security:';
$out[] = '  - session: []';
$out[] = 'components:';
$out[] = '  securitySchemes:';
$out[] = '    session: { type: apiKey, in: cookie, name: PHPSESSID }';
$out[] = '    csrf: { type: apiKey, in: header, name: X-CSRF-Token }';
$out[] = '  schemas:';
$out[] = '    Error:';
$out[] = '      type: object';
$out[] = '      properties:';
$out[] = '        error:';
$out[] = '          type: object';
$out[] = '          required: [code, message]';
$out[] = '          properties:';
$out[] = '            code: { type: string, examples: [unauthenticated, forbidden, not_found, csrf_invalid, invalid_json, validation_failed] }';
$out[] = '            message: { type: string }';
$out[] = '            fields: { type: object, additionalProperties: { type: string }, description: Mensagem por campo (422) }';
$out[] = '    Meta:';
$out[] = '      type: object';
$out[] = '      properties: { total: { type: integer }, page: { type: integer }, per_page: { type: integer }, pages: { type: integer } }';
$paths = [];
foreach (Definitions::all() as $slug => $d) {
    $name = str_replace(' ', '', ucwords(str_replace('-', ' ', $d['api'])));
    $required = array_keys(array_filter($d['fields'], static fn (array $f): bool => $f['required']));
    $out[] = "    {$name}Input:";
    $out[] = '      type: object';
    $out[] = '      required: [' . implode(', ', $required) . ']';
    $out[] = '      properties:';
    foreach ($d['fields'] as $field => $f) {
        $out[] = "        {$field}: " . $schema($f);
    }
    $out[] = '        status: { type: string, enum: [' . implode(', ', array_keys($d['status'])) . '], default: active }';
    $out[] = "    {$name}:";
    $out[] = '      allOf:';
    $out[] = "        - \$ref: '#/components/schemas/{$name}Input'";
    $out[] = '        - type: object';
    $out[] = '          properties:';
    $out[] = '            id: { type: integer }';
    if ($d['scope'] !== 'none') {
        $out[] = '            condominium_name: { type: [string, "null"] }';
    }
    foreach ($d['computed'] ?? [] as $column) {
        $out[] = "            {$column}: { type: [string, \"null\"], readOnly: true }";
    }
    $out[] = '            created_at: { type: string }';
    $out[] = '            updated_at: { type: string }';
    $paths[] = [$d, $name];
}
$out[] = 'paths:';
foreach ($paths as [$d, $name]) {
    $tag = $q($d['label']);
    $filter = null;
    foreach ($d['fields'] as $field => $f) {
        if ($f['filter'] && $f['type'] === 'select') { $filter = $field; break; }
    }
    $out[] = "  /{$d['api']}:";
    $out[] = '    get:';
    $out[] = "      tags: [{$tag}]";
    $out[] = '      summary: ' . $q('Lista ' . mb_strtolower($d['label']) . ' (permissão ' . $d['permission'] . '.view)');
    $out[] = '      parameters:';
    if ($d['scope'] !== 'none') {
        $out[] = '        - { name: condominium_id, in: query, schema: { type: integer } }';
    }
    $out[] = '        - { name: q, in: query, schema: { type: string, maxLength: 100 } }';
    $out[] = '        - { name: status, in: query, schema: { type: string, enum: [' . implode(', ', array_keys($d['status'])) . '] } }';
    if ($filter !== null) {
        $out[] = "        - { name: type, in: query, description: 'Filtra por {$filter}', schema: { type: string, enum: [" . implode(', ', array_map($q, array_keys($d['fields'][$filter]['options']))) . '] } }';
    }
    $out[] = '        - { name: page, in: query, schema: { type: integer, minimum: 1 } }';
    $out[] = '      responses:';
    $out[] = "        '200': { description: OK, content: { application/json: { schema: { type: object, properties: { data: { type: array, items: { \$ref: '#/components/schemas/{$name}' } }, meta: { \$ref: '#/components/schemas/Meta' } } } } } }";
    $out[] = "        '401': { description: Sem sessão, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = "        '403': { description: Sem permissão, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = '    post:';
    $out[] = "      tags: [{$tag}]";
    $out[] = '      summary: ' . $q('Cadastra (permissão ' . $d['permission'] . '.manage)');
    $out[] = '      security: [{ session: [], csrf: [] }]';
    $out[] = "      requestBody: { required: true, content: { application/json: { schema: { \$ref: '#/components/schemas/{$name}Input' } } } }";
    $out[] = '      responses:';
    $out[] = "        '201': { description: Criado, content: { application/json: { schema: { type: object, properties: { data: { \$ref: '#/components/schemas/{$name}' } } } } } }";
    $out[] = "        '400': { description: JSON inválido, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = "        '403': { description: Sem permissão, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = "        '419': { description: CSRF inválido, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = "        '422': { description: Dados inválidos, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    $out[] = "  /{$d['api']}/{id}:";
    $out[] = '    parameters:';
    $out[] = '      - { name: id, in: path, required: true, schema: { type: integer, minimum: 1 } }';
    $out[] = '    get:';
    $out[] = "      tags: [{$tag}]";
    $out[] = "      responses:";
    $out[] = "        '200': { description: OK, content: { application/json: { schema: { type: object, properties: { data: { \$ref: '#/components/schemas/{$name}' } } } } } }";
    $out[] = "        '404': { description: Inexistente ou fora do escopo, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    foreach (['put' => 'Substitui todos os campos', 'patch' => 'Mesmo comportamento do PUT (todos os campos)'] as $method => $summary) {
        $out[] = "    {$method}:";
        $out[] = "      tags: [{$tag}]";
        $out[] = '      summary: ' . $q($summary);
        $out[] = '      security: [{ session: [], csrf: [] }]';
        $out[] = "      requestBody: { required: true, content: { application/json: { schema: { \$ref: '#/components/schemas/{$name}Input' } } } }";
        $out[] = '      responses:';
        $out[] = "        '200': { description: Alterado, content: { application/json: { schema: { type: object, properties: { data: { \$ref: '#/components/schemas/{$name}' } } } } } }";
        $out[] = "        '404': { description: Inexistente ou fora do escopo, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
        $out[] = "        '422': { description: Dados inválidos, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    }
    $out[] = '    delete:';
    $out[] = "      tags: [{$tag}]";
    $out[] = '      summary: Exclusão lógica (o histórico de auditoria é mantido)';
    $out[] = '      security: [{ session: [], csrf: [] }]';
    $out[] = '      responses:';
    $out[] = "        '204': { description: Excluído }";
    $pointedByRef = false;
    foreach (Definitions::all() as $other) {
        foreach ($other['fields'] as $f) {
            $pointedByRef = $pointedByRef || ($f['type'] === 'ref' && ($f['ref'] ?? null) === $d['slug']);
        }
    }
    if (!empty($d['referenced_by']) || isset($d['hierarchy']) || $pointedByRef) {
        $out[] = "        '409': { description: Em uso por cadastros ativos vinculados, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
    }
    $out[] = "        '404': { description: Inexistente ou fora do escopo, content: { application/json: { schema: { \$ref: '#/components/schemas/Error' } } } }";
}
file_put_contents(dirname(__DIR__) . '/docs/api/registries.openapi.yaml', implode("\n", $out) . "\n");
echo "docs/api/registries.openapi.yaml\n";
