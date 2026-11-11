<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;

/**
 * Leitor mínimo de ZIP em memória (ADR 0158), só para o pacote de certificado do banco: nada é
 * gravado em disco (ZipArchive precisa de arquivo). Lê o diretório central, aceita entradas
 * guardadas (método 0) ou comprimidas com deflate (método 8) e para em limites baixos contra
 * "zip bomb": poucas entradas, cada uma pequena, e o total descompactado também pequeno.
 * Pastas, arquivos cifrados e outros métodos são ignorados.
 */
final class ZipEntries
{
    public const MAX_ENTRIES = 20;
    public const MAX_ENTRY_BYTES = 65536;
    public const MAX_TOTAL_BYTES = 262144;

    /** @return list<array{name:string, content:string}> */
    public static function read(string $zip): array
    {
        $end = strrpos($zip, "PK\x05\x06");
        if ($end === false || strlen($zip) < $end + 22) {
            throw new InvalidArgumentException('O arquivo ZIP está corrompido ou incompleto.');
        }
        $eocd = unpack('vdisk/vcdDisk/vdiskEntries/ventries/Vsize/Voffset', substr($zip, $end + 4, 16));
        if ($eocd === false || (int)$eocd['entries'] > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('O ZIP tem arquivos demais. Envie só o pacote de certificado do banco.');
        }
        $pos = (int)$eocd['offset'];
        $out = [];
        $total = 0;
        for ($i = 0; $i < (int)$eocd['entries']; $i++) {
            if (substr($zip, $pos, 4) !== "PK\x01\x02") {
                throw new InvalidArgumentException('O arquivo ZIP está corrompido.');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Vlocal', substr($zip, $pos + 4, 42));
            if ($h === false) {
                throw new InvalidArgumentException('O arquivo ZIP está corrompido.');
            }
            $name = substr($zip, $pos + 46, (int)$h['nameLen']);
            $pos += 46 + (int)$h['nameLen'] + (int)$h['extraLen'] + (int)$h['commentLen'];
            $encrypted = ((int)$h['flags'] & 1) === 1;
            if (str_ends_with($name, '/') || $encrypted || !in_array((int)$h['method'], [0, 8], true)) {
                continue;
            }
            if ((int)$h['usize'] > self::MAX_ENTRY_BYTES || (int)$h['csize'] > self::MAX_ENTRY_BYTES) {
                throw new InvalidArgumentException("O arquivo {$name} dentro do ZIP é grande demais para ser um certificado.");
            }
            $local = (int)$h['local'];
            if (substr($zip, $local, 4) !== "PK\x03\x04") {
                throw new InvalidArgumentException('O arquivo ZIP está corrompido.');
            }
            $lh = unpack('vnameLen/vextraLen', substr($zip, $local + 26, 4));
            $data = substr($zip, $local + 30 + (int)$lh['nameLen'] + (int)$lh['extraLen'], (int)$h['csize']);
            if ((int)$h['method'] === 8) {
                $data = @gzinflate($data, self::MAX_ENTRY_BYTES);
                if ($data === false) {
                    throw new InvalidArgumentException("Não consegui descompactar {$name}.");
                }
            }
            if (strlen($data) !== (int)$h['usize'] || (crc32($data) & 0xFFFFFFFF) !== ((int)$h['crc'] & 0xFFFFFFFF)) {
                throw new InvalidArgumentException("O arquivo {$name} dentro do ZIP está corrompido.");
            }
            $total += strlen($data);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('O conteúdo do ZIP é grande demais para um pacote de certificado.');
            }
            $out[] = ['name' => basename(str_replace('\\', '/', $name)), 'content' => $data];
        }
        return $out;
    }
}
