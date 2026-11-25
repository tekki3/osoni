<?php
declare(strict_types=1);

namespace Osoni\Readers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class ExcelReader extends Reader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const STRICT_MAIN = 'http://purl.oclc.org/ooxml/spreadsheetml/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const STRICT_REL = 'http://purl.oclc.org/ooxml/officeDocument/relationships';
    private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function read(string $path, array $options = []): array
    {
        $options = $this->options($options, ['sheet' => 0, 'headers' => true, 'max_uncompressed_bytes' => 67108864]);
        if ((!is_int($options['sheet']) || $options['sheet'] < 0) && (!is_string($options['sheet']) || $options['sheet'] === '')) {
            throw new InvalidArgumentException('Excel sheet must be a nonnegative index or a nonempty name.');
        }
        if (!is_bool($options['headers']) && !is_array($options['headers'])) {
            throw new InvalidArgumentException('Excel headers must be a boolean or a list of names.');
        }
        if (!is_int($options['max_uncompressed_bytes']) || $options['max_uncompressed_bytes'] < 1
            || $options['max_uncompressed_bytes'] > 268435456) {
            throw new InvalidArgumentException('Excel max_uncompressed_bytes must be 1..268435456.');
        }
        $headers = is_array($options['headers']) ? $this->headers($options['headers']) : null;
        if (!class_exists(ZipArchive::class, false) || !class_exists(DOMDocument::class, false)) {
            throw new RuntimeException('Excel reading requires the native zip and DOM extensions.');
        }
        $stream = $this->open($path, $options['max_bytes']);
        fclose($stream);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Invalid Excel workbook archive.');
        }
        try {
            $entries = $this->entries($zip, $options['max_uncompressed_bytes']);
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
                if (!isset($entries[$required])) {
                    throw new RuntimeException('Excel workbook is missing a required package part.');
                }
            }
            $workbook = $this->xml($zip, $entries, 'xl/workbook.xml', $options['max_depth']);
            $root = $workbook->documentElement;
            if ($root === null || $root->localName !== 'workbook' || !in_array($root->namespaceURI, [self::MAIN, self::STRICT_MAIN], true)) {
                throw new RuntimeException('Invalid Excel workbook XML.');
            }
            $namespace = $root->namespaceURI;
            $query = new DOMXPath($workbook);
            $query->registerNamespace('s', $namespace);
            $sheets = $query->query('/s:workbook/s:sheets/s:sheet');
            if ($sheets === false) {
                throw new RuntimeException('Cannot read Excel worksheets.');
            }
            $selected = null;
            foreach ($sheets as $index => $sheet) {
                if ($sheet instanceof DOMElement && (is_int($options['sheet']) ? $index === $options['sheet'] : $sheet->getAttribute('name') === $options['sheet'])) {
                    $selected = $sheet;
                    break;
                }
            }
            if (!$selected instanceof DOMElement) {
                throw new RuntimeException('The requested Excel worksheet does not exist.');
            }
            $id = $selected->getAttributeNS(self::REL, 'id') ?: $selected->getAttributeNS(self::STRICT_REL, 'id');
            $relations = $this->relationships($zip, $entries, $options['max_depth']);
            if ($id === '' || !isset($relations[$id]) || $relations[$id]['type'] !== 'worksheet') {
                throw new RuntimeException('Excel worksheet relationship is missing or invalid.');
            }
            $shared = [];
            $hasShared = false;
            foreach ($relations as $relation) {
                if ($relation['type'] === 'sharedStrings') {
                    if ($hasShared) {
                        throw new RuntimeException('Excel workbook has duplicate shared string parts.');
                    }
                    $hasShared = true;
                    $shared = $this->sharedStrings($this->xml($zip, $entries, $relation['path'], $options['max_depth']), $namespace);
                }
            }
            $sheet = $this->xml($zip, $entries, $relations[$id]['path'], $options['max_depth']);
            return $this->rows($sheet, $namespace, $options['headers'], $headers, $shared);
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Cannot close the Excel workbook archive.');
            }
        }
    }

    /** @return array<string, array{index: int, size: int}> */
    private function entries(ZipArchive $zip, int $limit): array
    {
        if ($zip->numFiles > 4096) {
            throw new RuntimeException('Excel workbook has too many archive entries.');
        }
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['name'], $stat['size'], $stat['comp_size'])
                || $stat['size'] < 0 || $stat['comp_size'] < 0 || ($stat['encryption_method'] ?? 0) !== 0
                || preg_match('~(?:^|/)\.\.?(/|$)|[\\\\:\x00-\x1f]|^/|//~', $stat['name'])
                || isset($entries[$stat['name']])) {
                throw new RuntimeException('Excel workbook contains an unsafe archive entry.');
            }
            if ($stat['size'] > $limit - $total) {
                throw new RuntimeException('Excel workbook exceeds max_uncompressed_bytes.');
            }
            $total += $stat['size'];
            $entries[$stat['name']] = ['index' => $index, 'size' => $stat['size']];
        }
        return $entries;
    }

    private function xml(ZipArchive $zip, array $entries, string $part, int $maxDepth): DOMDocument
    {
        if (!isset($entries[$part])) {
            throw new RuntimeException('Excel workbook is missing a referenced package part.');
        }
        $entry = $entries[$part];
        $stream = $zip->getStreamIndex($entry['index']);
        if ($stream === false) {
            throw new RuntimeException('Cannot read an Excel workbook part.');
        }
        try {
            $contents = '';
            while (!feof($stream)) {
                $remaining = $entry['size'] - strlen($contents);
                $chunk = fread($stream, min(8192, $remaining + 1));
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new RuntimeException('Cannot read an Excel workbook part.');
                }
                $contents .= $chunk;
                if (strlen($contents) > $entry['size']) {
                    throw new RuntimeException('Excel workbook part exceeds its declared size.');
                }
            }
            if (strlen($contents) !== $entry['size']) {
                throw new RuntimeException('Excel workbook part has an invalid size.');
            }
        } finally {
            fclose($stream);
        }
        $contents = $this->utf8($contents);
        if (str_contains($contents, "\0") || stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false
            || (preg_match('/^<\?xml\s[^?]*\bencoding\s*=\s*(["\'])(.*?)\1/i', $contents, $encoding)
                && !in_array(strtoupper($encoding[2]), ['UTF-8', 'UTF8', 'US-ASCII'], true))) {
            throw new RuntimeException('Excel XML must be UTF-8 without DTDs or entity declarations.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null || $document->documentElement === null) {
                throw new RuntimeException('Invalid Excel workbook XML.');
            }
            $pending = [[$document->documentElement, 1]];
            $count = 0;
            while ($pending !== []) {
                [$node, $depth] = array_pop($pending);
                if (++$count > 100000 || $depth > $maxDepth) {
                    throw new RuntimeException('Excel XML exceeds max_depth or the 100000-element limit.');
                }
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $pending[] = [$child, $depth + 1];
                    } elseif ($child->nodeType === XML_ENTITY_REF_NODE) {
                        throw new RuntimeException('Excel XML entity references are not allowed.');
                    }
                }
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, array{type: string, path: string}> */
    private function relationships(ZipArchive $zip, array $entries, int $maxDepth): array
    {
        $document = $this->xml($zip, $entries, 'xl/_rels/workbook.xml.rels', $maxDepth);
        $query = new DOMXPath($document);
        $query->registerNamespace('p', self::PACKAGE_REL);
        $nodes = $query->query('/p:Relationships/p:Relationship');
        if ($nodes === false || $document->documentElement?->namespaceURI !== self::PACKAGE_REL
            || $document->documentElement?->localName !== 'Relationships') {
            throw new RuntimeException('Invalid Excel workbook relationships.');
        }
        $result = [];
        foreach ($nodes as $node) {
            $id = $node->getAttribute('Id');
            $type = $node->getAttribute('Type');
            $kind = match ($type) {
                self::REL . '/worksheet', self::STRICT_REL . '/worksheet' => 'worksheet',
                self::REL . '/sharedStrings', self::STRICT_REL . '/sharedStrings' => 'sharedStrings',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $target = $node->getAttribute('Target');
            if ($id === '' || isset($result[$id]) || !in_array($node->getAttribute('TargetMode'), ['', 'Internal'], true)
                || $target === '' || preg_match('~[\\\\:%?#\x00-\x20]|(?:^|/)\.\.?(/|$)|//~', $target)) {
                throw new RuntimeException('Invalid Excel workbook relationship target.');
            }
            $path = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
            if (!str_starts_with($path, 'xl/')) {
                throw new RuntimeException('Excel workbook relationship must target an internal part.');
            }
            $result[$id] = ['type' => $kind, 'path' => $path];
        }
        return $result;
    }

    private function sharedStrings(DOMDocument $document, string $namespace): array
    {
        if ($document->documentElement?->localName !== 'sst' || $document->documentElement?->namespaceURI !== $namespace) {
            throw new RuntimeException('Invalid Excel shared strings.');
        }
        $query = new DOMXPath($document);
        $query->registerNamespace('s', $namespace);
        $strings = [];
        foreach ($query->query('/s:sst/s:si') as $item) {
            $strings[] = $this->text($query, $item);
        }
        return $strings;
    }

    private function text(DOMXPath $query, DOMElement $node): string
    {
        $text = '';
        foreach ($query->query('./s:t | ./s:r/s:t', $node) as $part) {
            $text .= $part->textContent;
        }
        return $this->decodeText($text);
    }

    private function decodeText(string $text): string
    {
        return preg_replace_callback('/_x(d[89ab][0-9a-f]{2})__x(d[cdef][0-9a-f]{2})_|_x([0-9a-f]{4})_/i', static function (array $match): string {
            $first = hexdec($match[3] ?? $match[1]);
            $second = isset($match[3]) ? null : hexdec($match[2]);
            if ($first >= 0xD800 && $first <= 0xDBFF) {
                if ($second === null || $second < 0xDC00 || $second > 0xDFFF) {
                    throw new RuntimeException('Invalid Excel text escape.');
                }
                $code = 0x10000 + (($first - 0xD800) << 10) + $second - 0xDC00;
                return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 63))
                    . chr(0x80 | (($code >> 6) & 63)) . chr(0x80 | ($code & 63));
            }
            if ($first >= 0xDC00 && $first <= 0xDFFF) {
                throw new RuntimeException('Invalid Excel text escape.');
            }
            $encoded = $first < 0x80 ? chr($first) : ($first < 0x800
                ? chr(0xC0 | ($first >> 6)) . chr(0x80 | ($first & 63))
                : chr(0xE0 | ($first >> 12)) . chr(0x80 | (($first >> 6) & 63)) . chr(0x80 | ($first & 63)));
            return $encoded;
        }, $text);
    }

    private function rows(DOMDocument $document, string $namespace, bool|array $headerOption, ?array $headers, array $shared): array
    {
        if ($document->documentElement?->localName !== 'worksheet' || $document->documentElement?->namespaceURI !== $namespace) {
            throw new RuntimeException('Invalid Excel worksheet XML.');
        }
        $query = new DOMXPath($document);
        $query->registerNamespace('s', $namespace);
        if ($query->query('/s:worksheet/s:sheetData')->length !== 1) {
            throw new RuntimeException('Excel worksheet requires one sheetData element.');
        }
        $rows = [];
        $lastRow = 0;
        $total = 0;
        foreach ($query->query('/s:worksheet/s:sheetData/s:row') as $row) {
            $rowNumber = $row->getAttribute('r');
            if ($rowNumber === '') {
                $rowNumber = $lastRow + 1;
            } elseif (!preg_match('/^[1-9][0-9]{0,6}$/D', $rowNumber)) {
                throw new RuntimeException('Invalid Excel row reference.');
            }
            $rowNumber = (int) $rowNumber;
            if ($rowNumber <= $lastRow || $rowNumber > 1048576) {
                throw new RuntimeException('Excel row references must be ordered and within worksheet limits.');
            }
            $lastRow = $rowNumber;
            $cells = [];
            $lastColumn = 0;
            foreach ($query->query('./s:c', $row) as $cell) {
                $reference = $cell->getAttribute('r');
                if ($reference === '') {
                    $column = $lastColumn + 1;
                } elseif (preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $reference, $match) && (int) $match[2] === $rowNumber) {
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                } else {
                    throw new RuntimeException('Invalid Excel cell reference.');
                }
                if ($column <= $lastColumn || $column > 16384) {
                    throw new RuntimeException('Excel cell references must be ordered and within worksheet limits.');
                }
                $lastColumn = $column;
                $cells[$column - 1] = $this->cell($query, $cell, $shared);
            }
            if ($cells === [] || count(array_filter($cells, static fn ($value): bool => $value !== '')) === 0) {
                continue;
            }
            $width = $headers === null ? $lastColumn : count($headers);
            if ($lastColumn > $width || ($total += $width) > 100000) {
                throw new RuntimeException('Excel row exceeds its header width or the 100000-value limit.');
            }
            $values = array_replace(array_fill(0, $width, ''), $cells);
            if ($headers === null && $headerOption === true) {
                try {
                    $headers = $this->headers($values);
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException('Invalid Excel headers.', 0, $error);
                }
            } else {
                $rows[] = $headers === null ? $values : array_combine($headers, $values);
            }
        }
        return $rows;
    }

    private function cell(DOMXPath $query, DOMElement $cell, array $shared): string|bool
    {
        $type = $cell->getAttribute('t');
        $values = $query->query('./s:v', $cell);
        $formula = $query->query('./s:f', $cell)->length > 0;
        if ($values->length > 1 || ($formula && ($values->length === 0 || $values->item(0)->textContent === ''))) {
            throw new RuntimeException('Excel formula cells require a cached value.');
        }
        $value = $values->length === 0 ? '' : $values->item(0)->textContent;
        return match ($type) {
            '', 'n' => $value === '' || preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value)
                ? $value : throw new RuntimeException('Invalid Excel numeric value.'),
            'b' => match ($value) {
                '1' => true, '0' => false,
                default => throw new RuntimeException('Invalid Excel boolean value.'),
            },
            's' => preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $value) && array_key_exists((int) $value, $shared)
                ? $shared[(int) $value] : throw new RuntimeException('Invalid Excel shared string index.'),
            'inlineStr' => $this->inlineText($query, $cell),
            'str', 'd' => $this->decodeText($value),
            'e' => throw new RuntimeException('Excel error cells cannot be imported.'),
            default => throw new RuntimeException('Unsupported Excel cell type.'),
        };
    }

    private function inlineText(DOMXPath $query, DOMElement $cell): string
    {
        $nodes = $query->query('./s:is', $cell);
        if ($nodes->length !== 1) {
            throw new RuntimeException('Invalid Excel inline string.');
        }
        return $this->text($query, $nodes->item(0));
    }

    private function headers(array $headers): array
    {
        if ($headers === [] || !array_is_list($headers)) {
            throw new InvalidArgumentException('Excel headers must be a nonempty list of unique names.');
        }
        foreach ($headers as $name) {
            if (!is_string($name) || $name === '' || str_contains($name, "\0") || preg_match('//u', $name) !== 1) {
                throw new InvalidArgumentException('Excel headers must be nonempty UTF-8 strings without NUL bytes.');
            }
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw new InvalidArgumentException('Excel headers must be unique.');
        }
        return $headers;
    }
}
