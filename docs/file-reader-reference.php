<?php
declare(strict_types=1);

return [
    'Osoni\\FileReader' => [
        'description' => 'Static entry point for bounded local XLSX, CSV, JSON, XML, and YAML/YML reading. Explicitly loaded by bootstrap; no database, Application instance, Composer, or autoloader is required. See Structured File Reading for exact output shapes and trust boundaries.',
        'setup' => 'use Osoni\\FileReader;',
        'methods' => [
            'read' => ['Detect a case-insensitive extension or accept options["format"]. Return arrays only. Unknown options/formats and invalid arguments throw InvalidArgumentException; missing/unreadable/oversized files, missing extensions, and invalid content throw RuntimeException. Default max_bytes is 16777216; max_depth is 64 (1..256).', '$rows = \\Osoni\\FileReader::read($path, ["format" => "csv", "headers" => true]);'],
            'iterate' => ['Stream CSV rows lazily with the same options and row shape as read(). The file opens on iteration; errors can occur after earlier rows were yielded. XLSX/JSON/XML/YAML reject streaming explicitly. Release an abandoned generator to close its file.', 'foreach (\\Osoni\\FileReader::iterate($path, ["format" => "csv"]) as $row) { processRow($row); }'],
            'register' => ['Register a trusted ReaderInterface implementation by format/extension. Names are case-insensitive ASCII alphanumeric identifiers starting with a letter, up to 32 characters. Overrides persist for this PHP process; no class names are loaded from file content.', '\\Osoni\\FileReader::register("data", new \\Osoni\\Readers\\JsonReader());'],
        ],
    ],
    'Osoni\\Readers\\Reader' => [
        'description' => 'Abstract ReaderInterface implementation shared by native readers. Protected helpers validate options, bounded regular local files, and UTF-8. Custom readers may extend it and implement read(); nonstreaming readers inherit the explicit streaming error.',
        'setup' => '',
        'methods' => [
            'iterate' => ['Throw RuntimeException because streaming is unsupported unless the concrete reader overrides this method.', '$reader = new \\Osoni\\Readers\\JsonReader(); // Use read(), not iterate().'],
        ],
    ],
    'Osoni\\Readers\\CsvReader' => [
        'description' => 'UTF-8 CSV parsing with optional BOM, strict quoting/header validation, and lazy rows. Cell values remain strings; blank cells are empty strings. Implements ReaderInterface.',
        'setup' => '$reader = new \\Osoni\\Readers\\CsvReader();',
        'methods' => [
            'read' => ['Collect all streamed rows in an array. headers defaults true (first nonblank record); false yields lists; a nonempty unique name list supplies headers without consuming a record. delimiter defaults comma, enclosure double quote, escape empty, skip_empty_lines true. Nonempty CSV controls are distinct single bytes excluding CR, LF and NUL. Header rows require matching record widths.', '$rows = $reader->read($path, ["headers" => true, "delimiter" => ";", "escape" => ""]);'],
            'iterate' => ['Yield one validated row at a time without retaining previous records. Supports multiline quoted cells and doubled enclosures. Invalid UTF-8, unterminated quotes, characters after closing quotes, duplicate/empty headers and inconsistent header widths throw. Memory is proportional to the largest record, not total rows; max_bytes still limits the whole file.', 'foreach ($reader->iterate($path, ["headers" => ["id", "name"]]) as $row) { processRow($row); }'],
        ],
    ],
    'Osoni\\Readers\\ExcelReader' => [
        'description' => 'Eager XLSX worksheet reader using native zip and DOM. No formula execution, XLS support, spreadsheet formatting, or streaming.',
        'setup' => '$reader = new \\Osoni\\Readers\\ExcelReader();',
        'methods' => [
            'read' => ['Read a worksheet by zero-based sheet index or exact name (default 0); headers=true consumes the first nonempty row, false returns positional rows, or supply a unique name list. Missing cells are empty strings, numeric values remain exact strings, booleans are booleans, formulas use cached results, and errors or missing/empty formula caches throw. Shared and inline text are supported. Archive expansion, XML depth/elements, and returned cells are bounded; max_uncompressed_bytes defaults to 64 MiB and accepts 1..256 MiB.', '$rows = $reader->read($path, ["sheet" => "Customers", "headers" => true]);'],
        ],
    ],
    'Osoni\\Readers\\JsonReader' => [
        'description' => 'Native UTF-8 JSON reader; optional UTF-8 BOM is removed. Implements ReaderInterface and inherits the unsupported streaming error.',
        'setup' => '$reader = new \\Osoni\\Readers\\JsonReader();',
        'methods' => [
            'read' => ['Decode root objects/arrays and nested objects into PHP arrays. Preserve scalar types and large integers as strings; reject scalar roots, nonfinite number overflow, excessive depth and invalid JSON. Parse errors explain the native reason and retain JsonException as previous, without echoing document contents. Empty objects and arrays both become [].', '$data = $reader->read($path, ["max_depth" => 32]);'],
        ],
    ],
    'Osoni\\Readers\\XmlReader' => [
        'description' => 'Native DOM reader for UTF-8 XML with optional BOM. Rejects DTD/entity declarations, disables external resolution/substitution, uses LIBXML_NONET, and never invokes XInclude or schema loading. Implements ReaderInterface.',
        'setup' => '$reader = new \\Osoni\\Readers\\XmlReader();',
        'methods' => [
            'read' => ['Return [rootName => node]. Every node has @attributes (name => string), @text (direct text/CDATA), and @children (name => list of nodes). Namespaced names use {URI}local; namespace declarations are not attributes. Preserve whitespace and repeated children; mixed-content ordering across child groups is not preserved. Enforce max_depth and 100000 elements. Malformed input reports line/column without source snippets.', '$root = $reader->read($path)["catalog"]; $items = $root["@children"]["item"] ?? [];'],
        ],
    ],
    'Osoni\\Readers\\YamlReader' => [
        'description' => 'Native ext-yaml/LibYAML reader for .yaml and .yml; no fallback or PHP package. Requires yaml.decode_php=0 and rejects PHP object tags. Implements ReaderInterface.',
        'setup' => '$reader = new \\Osoni\\Readers\\YamlReader();',
        'methods' => [
            'read' => ['Parse exactly one root mapping/sequence; return nested arrays/scalars only. Native YAML scalar/tag/merge-key semantics apply. Normalize aliases into bounded value arrays; reject cycles/excessive expansion, objects/resources, nonfinite numbers and scalar/multiple roots. Enforce max_depth and a 100000-value/container traversal budget. Missing extension and invalid content throw RuntimeException; parser messages omit source data.', '$settings = $reader->read($path, ["max_bytes" => 1048576]);'],
        ],
    ],
];
