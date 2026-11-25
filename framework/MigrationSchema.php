<?php
declare(strict_types=1);

namespace Osoni;

use PDO;
use RuntimeException;

final class MigrationSchema
{
    /**
     * @return array{up: list<string>, down: list<string>}
     */
    public static function capture(PDO $db, string $driver): array
    {
        return match ($driver) {
            'sqlite' => self::sqlite($db),
            'mysql' => self::mysql($db),
            'pgsql', 'sqlsrv' => self::serverDatabase($db, $driver),
            default => throw new RuntimeException('Cannot import schema for this database driver.'),
        };
    }

    /**
     * @param array{up: list<string>, down: list<string>} $schema
     */
    public static function migrationSource(array $schema): string
    {
        $up = var_export($schema['up'], true);
        $down = var_export($schema['down'], true);
        return <<<PHP
<?php
declare(strict_types=1);

use Osoni\\Migration;

return new class extends Migration {
    public function up(PDO \$db): void
    {
        foreach ($up as \$sql) {
            \$db->exec(\$sql);
        }
    }

    public function down(PDO \$db): void
    {
        foreach ($down as \$sql) {
            \$db->exec(\$sql);
        }
    }
};

PHP;
    }

    /** @return array{up: list<string>, down: list<string>} */
    private static function sqlite(PDO $db): array
    {
        $objects = $db->query(
            "SELECT type, name, tbl_name, sql FROM sqlite_master
             WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%'
                AND tbl_name <> 'migrations' AND sql IS NOT NULL
             ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $tables = [];
        $indexes = [];
        foreach ($objects as $object) {
            if ($object['type'] === 'table') {
                if (preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE\b/i', $object['sql'])) {
                    throw new RuntimeException('Virtual SQLite tables cannot be imported into a migration.');
                }
                $tables[$object['name']] = $object['sql'];
            } else {
                $indexes[$object['name']] = $object['sql'];
            }
        }
        if ($tables === []) {
            throw new RuntimeException('The configured database has no importable tables.');
        }
        $edges = [];
        foreach (array_keys($tables) as $table) {
            foreach ($db->query('PRAGMA foreign_key_list(' . self::quote($table, 'sqlite') . ')')->fetchAll(PDO::FETCH_ASSOC) as $foreignKey) {
                $parent = (string) $foreignKey['table'];
                if (isset($tables[$parent])) {
                    $edges[$table][] = $parent;
                }
            }
        }
        $ordered = self::dependencyOrder(array_keys($tables), $edges);
        $up = [];
        foreach ($ordered as $table) {
            $up[] = $tables[$table];
        }
        foreach ($indexes as $sql) {
            $up[] = $sql;
        }
        $down = [];
        foreach (array_keys($indexes) as $index) {
            $down[] = 'DROP INDEX ' . self::quote($index, 'sqlite');
        }
        foreach (array_reverse($ordered) as $table) {
            $down[] = 'DROP TABLE ' . self::quote($table, 'sqlite');
        }
        return ['up' => $up, 'down' => $down];
    }

    /** @return array{up: list<string>, down: list<string>} */
    private static function mysql(PDO $db): array
    {
        $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        $tables = $db->prepare(
            "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'migrations'
             ORDER BY TABLE_NAME"
        );
        $tables->execute([$database]);
        $names = $tables->fetchAll(PDO::FETCH_COLUMN);
        if ($names === []) {
            throw new RuntimeException('The configured database has no importable tables.');
        }
        $edges = [];
        $dependencies = $db->prepare(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $dependencies->execute([$database]);
        foreach ($dependencies->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['TABLE_NAME'] !== 'migrations' && $row['REFERENCED_TABLE_NAME'] !== 'migrations') {
                $edges[$row['TABLE_NAME']][] = $row['REFERENCED_TABLE_NAME'];
            }
        }
        $ordered = self::dependencyOrder($names, $edges);
        $up = [];
        foreach ($ordered as $table) {
            $statement = $db->query('SHOW CREATE TABLE ' . self::quote($table, 'mysql'))->fetch(PDO::FETCH_NUM);
            if (!is_array($statement) || !is_string($statement[1] ?? null)) {
                throw new RuntimeException("Cannot read the table definition for $table.");
            }
            $up[] = $statement[1];
        }
        $down = [];
        foreach (array_reverse($ordered) as $table) {
            $down[] = 'DROP TABLE ' . self::quote($table, 'mysql');
        }
        return ['up' => $up, 'down' => $down];
    }

    /**
     * @return array{up: list<string>, down: list<string>}
     */
    private static function serverDatabase(PDO $db, string $driver): array
    {
        $schemaFilter = $driver === 'pgsql'
            ? "table_schema NOT IN ('information_schema', 'pg_catalog')"
            : "table_schema NOT IN ('INFORMATION_SCHEMA', 'sys')";
        $tables = $db->query(
            "SELECT table_schema, table_name FROM information_schema.tables
             WHERE table_type = 'BASE TABLE' AND $schemaFilter AND table_name <> 'migrations'
             ORDER BY table_schema, table_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($tables === []) {
            throw new RuntimeException('The configured database has no importable tables.');
        }
        $tableNames = [];
        foreach ($tables as $table) {
            $tableNames[] = self::tableKey($table['table_schema'], $table['table_name']);
        }
        $columns = match ($driver) {
            'pgsql' => $db->query(
                "SELECT c.table_schema, c.table_name, c.column_name, c.data_type, c.udt_schema, c.udt_name,
                        c.character_maximum_length, c.numeric_precision, c.numeric_scale,
                        c.datetime_precision, c.is_nullable, c.column_default, c.is_identity,
                        c.identity_generation, c.is_generated, c.generation_expression,
                        c.ordinal_position, pg_catalog.format_type(a.atttypid, a.atttypmod) AS native_type
                 FROM information_schema.columns c
                 JOIN pg_catalog.pg_namespace n ON n.nspname = c.table_schema
                 JOIN pg_catalog.pg_class t ON t.relnamespace = n.oid AND t.relname = c.table_name
                 JOIN pg_catalog.pg_attribute a
                   ON a.attrelid = t.oid AND a.attname = c.column_name
                  AND a.attnum > 0 AND NOT a.attisdropped
                 WHERE c.table_schema NOT IN ('information_schema', 'pg_catalog')
                   AND c.table_name <> 'migrations'
                 ORDER BY c.table_schema, c.table_name, c.ordinal_position"
            )->fetchAll(PDO::FETCH_ASSOC),
            'sqlsrv' => $db->query(
                "SELECT s.name AS table_schema, t.name AS table_name, c.name AS column_name,
                        ty.name AS data_type, ts.name AS udt_schema, ty.name AS udt_name,
                        CASE WHEN c.max_length = -1 THEN NULL
                             WHEN ty.name IN ('nvarchar', 'nchar') THEN c.max_length / 2
                             ELSE c.max_length END AS character_maximum_length,
                        c.precision AS numeric_precision, c.scale AS numeric_scale,
                        c.scale AS datetime_precision,
                        CASE c.is_nullable WHEN 1 THEN 'YES' ELSE 'NO' END AS is_nullable,
                        dc.definition AS column_default, 'NO' AS is_identity,
                        NULL AS identity_generation, c.column_id AS ordinal_position,
                        ty.is_user_defined
                 FROM sys.columns c
                 JOIN sys.tables t ON t.object_id = c.object_id
                 JOIN sys.schemas s ON s.schema_id = t.schema_id
                 JOIN sys.types ty ON ty.user_type_id = c.user_type_id
                 JOIN sys.schemas ts ON ts.schema_id = ty.schema_id
                 LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
                 WHERE t.name <> 'migrations'
                 ORDER BY s.name, t.name, c.column_id"
            )->fetchAll(PDO::FETCH_ASSOC),
            default => $db->query(
                "SELECT table_schema, table_name, column_name, data_type, udt_schema, udt_name,
                        character_maximum_length, numeric_precision, numeric_scale,
                        datetime_precision, is_nullable, column_default, 'NO' AS is_identity,
                        NULL AS identity_generation,
                        ordinal_position
                 FROM information_schema.columns
                 WHERE $schemaFilter AND table_name <> 'migrations'
                 ORDER BY table_schema, table_name, ordinal_position"
            )->fetchAll(PDO::FETCH_ASSOC),
        };
        $byTable = [];
        foreach ($tables as $table) {
            $byTable[self::tableKey($table['table_schema'], $table['table_name'])] = [
                'schema' => $table['table_schema'], 'name' => $table['table_name'], 'columns' => [],
            ];
        }
        foreach ($columns as $column) {
            $key = self::tableKey($column['table_schema'], $column['table_name']);
            if (!isset($byTable[$key])) {
                continue;
            }
            $byTable[$key]['columns'][] = $column;
        }
        if ($driver === 'sqlsrv') {
            $identities = $db->query(
                "SELECT s.name AS table_schema, t.name AS table_name, c.name AS column_name,
                        ic.seed_value, ic.increment_value
                 FROM sys.identity_columns ic
                 JOIN sys.tables t ON t.object_id = ic.object_id
                 JOIN sys.schemas s ON s.schema_id = t.schema_id
                 JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                 WHERE t.name <> 'migrations'"
            )->fetchAll(PDO::FETCH_ASSOC);
            $identityMap = [];
            foreach ($identities as $identity) {
                $identityMap[self::tableKey($identity['table_schema'], $identity['table_name'])][$identity['column_name']] = $identity;
            }
            foreach ($byTable as $key => &$table) {
                foreach ($table['columns'] as &$column) {
                    $identity = $identityMap[$key][$column['column_name']] ?? null;
                    if ($identity !== null) {
                        $column['identity_seed'] = $identity['seed_value'];
                        $column['identity_increment'] = $identity['increment_value'];
                    }
                }
                unset($column);
            }
            unset($table);
            $computedColumns = $db->query(
                "SELECT s.name AS table_schema, t.name AS table_name, c.name AS column_name,
                        cc.definition, cc.is_persisted
                 FROM sys.computed_columns cc
                 JOIN sys.tables t ON t.object_id = cc.object_id
                 JOIN sys.schemas s ON s.schema_id = t.schema_id
                 JOIN sys.columns c ON c.object_id = cc.object_id AND c.column_id = cc.column_id
                 WHERE t.name <> 'migrations'"
            )->fetchAll(PDO::FETCH_ASSOC);
            $computedMap = [];
            foreach ($computedColumns as $computed) {
                $computedMap[self::tableKey($computed['table_schema'], $computed['table_name'])][$computed['column_name']] = $computed;
            }
            foreach ($byTable as $key => &$table) {
                foreach ($table['columns'] as &$column) {
                    $computed = $computedMap[$key][$column['column_name']] ?? null;
                    if ($computed !== null) {
                        $column['computed_expression'] = $computed['definition'];
                        $column['computed_persisted'] = $computed['is_persisted'];
                    }
                }
                unset($column);
            }
            unset($table);
        }

        $constraints = self::constraints($db, $driver);
        foreach ($constraints as $key => $constraintList) {
            if (isset($byTable[$key])) {
                $byTable[$key]['constraints'] = $constraintList;
            }
        }
        $edges = [];
        foreach ($constraints as $key => $constraintList) {
            foreach ($constraintList as $constraint) {
                if ($constraint['type'] === 'FOREIGN KEY') {
                    $parent = self::tableKey($constraint['ref_schema'], $constraint['ref_table']);
                    if (isset($byTable[$parent])) {
                        $edges[$key][] = $parent;
                    }
                }
            }
        }
        $ordered = self::dependencyOrder($tableNames, $edges);
        $up = [];
        $sequences = $driver === 'pgsql' ? self::sequences($db) : [];
        foreach ($sequences as $sequence) {
            $up[] = $sequence['create'];
        }
        foreach ($ordered as $key) {
            $table = $byTable[$key];
            $definitions = [];
            foreach ($table['columns'] as $column) {
                $definitions[] = self::columnDefinition($column, $driver);
            }
            foreach ($table['constraints'] ?? [] as $constraint) {
                $definitions[] = self::constraintDefinition($constraint, $driver);
            }
            $up[] = 'CREATE TABLE ' . self::qualified($table['schema'], $table['name'], $driver)
                . " (\n    " . implode(",\n    ", $definitions) . "\n)";
        }
        foreach (self::indexes($db, $driver) as $index) {
            $up[] = $index;
        }
        $down = [];
        foreach (array_reverse(self::indexes($db, $driver, true)) as $indexDrop) {
            $down[] = $indexDrop;
        }
        foreach (array_reverse($ordered) as $key) {
            $table = $byTable[$key];
            $down[] = 'DROP TABLE ' . self::qualified($table['schema'], $table['name'], $driver);
        }
        foreach (array_reverse($sequences) as $sequence) {
            $down[] = $sequence['drop'];
        }
        return ['up' => $up, 'down' => $down];
    }

    /**
     * @return list<array{create: string, drop: string}>
     */
    private static function sequences(PDO $db): array
    {
        $rows = $db->query(
            "SELECT s.sequence_schema, s.sequence_name, s.start_value, s.minimum_value,
                    s.maximum_value, s.increment, s.cycle_option
             FROM information_schema.sequences s
             WHERE s.sequence_schema NOT IN ('information_schema', 'pg_catalog')
               AND NOT EXISTS (
                   SELECT 1 FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                   JOIN pg_depend d ON d.objid = c.oid AND d.deptype = 'i'
                   WHERE c.relkind = 'S' AND n.nspname = s.sequence_schema AND c.relname = s.sequence_name
               )
             ORDER BY s.sequence_schema, s.sequence_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $name = self::qualified($row['sequence_schema'], $row['sequence_name'], 'pgsql');
            $cycle = $row['cycle_option'] === 'YES' ? ' CYCLE' : ' NO CYCLE';
            $result[] = [
                'create' => "CREATE SEQUENCE $name INCREMENT BY {$row['increment']} MINVALUE {$row['minimum_value']}"
                    . " MAXVALUE {$row['maximum_value']} START WITH {$row['start_value']} CACHE 1$cycle",
                'drop' => "DROP SEQUENCE $name",
            ];
        }
        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function constraints(PDO $db, string $driver): array
    {
        $schemaFilter = $driver === 'pgsql'
            ? "tc.table_schema NOT IN ('information_schema', 'pg_catalog')"
            : "tc.table_schema NOT IN ('INFORMATION_SCHEMA', 'sys')";
        $referenceColumns = $driver === 'pgsql'
            ? 'kcu.referenced_table_schema AS ref_schema, kcu.referenced_table_name AS ref_table,
               kcu.referenced_column_name AS ref_column'
            : 'NULL AS ref_schema, NULL AS ref_table, NULL AS ref_column';
        $constraintTypes = $driver === 'pgsql'
            ? "'PRIMARY KEY', 'FOREIGN KEY', 'UNIQUE'"
            : "'PRIMARY KEY', 'UNIQUE'";
        $rows = $db->query(
            "SELECT tc.table_schema, tc.table_name, tc.constraint_name, tc.constraint_type,
                    kcu.column_name, kcu.ordinal_position,
                    $referenceColumns,
                    rc.update_rule, rc.delete_rule
             FROM information_schema.table_constraints tc
             LEFT JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_catalog = tc.constraint_catalog
              AND kcu.constraint_schema = tc.constraint_schema
              AND kcu.constraint_name = tc.constraint_name
              AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name
             LEFT JOIN information_schema.referential_constraints rc
               ON rc.constraint_catalog = tc.constraint_catalog
              AND rc.constraint_schema = tc.constraint_schema
              AND rc.constraint_name = tc.constraint_name
             WHERE $schemaFilter AND tc.table_name <> 'migrations'
               AND tc.constraint_type IN ($constraintTypes)
             ORDER BY tc.table_schema, tc.table_name, tc.constraint_name, kcu.ordinal_position"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $key = self::tableKey($row['table_schema'], $row['table_name']);
            $name = $row['constraint_name'];
            $result[$key][$name] ??= [
                'name' => $name, 'type' => $row['constraint_type'], 'columns' => [],
                'ref_schema' => $row['ref_schema'], 'ref_table' => $row['ref_table'],
                'ref_columns' => [], 'update_rule' => $row['update_rule'], 'delete_rule' => $row['delete_rule'],
            ];
            if (is_string($row['column_name'])) {
                $result[$key][$name]['columns'][] = $row['column_name'];
            }
            if (is_string($row['ref_column'])) {
                $result[$key][$name]['ref_columns'][] = $row['ref_column'];
            }
        }
        if ($driver === 'sqlsrv') {
            $foreignKeys = $db->query(
                "SELECT ps.name AS table_schema, pt.name AS table_name, fk.name AS constraint_name,
                        pc.name AS column_name, fkc.constraint_column_id AS ordinal_position,
                        rs.name AS ref_schema, rt.name AS ref_table, rc.name AS ref_column,
                        CASE fk.update_referential_action
                            WHEN 1 THEN 'CASCADE' WHEN 2 THEN 'SET NULL' WHEN 3 THEN 'SET DEFAULT' ELSE 'NO ACTION'
                        END AS update_rule,
                        CASE fk.delete_referential_action
                            WHEN 1 THEN 'CASCADE' WHEN 2 THEN 'SET NULL' WHEN 3 THEN 'SET DEFAULT' ELSE 'NO ACTION'
                        END AS delete_rule
                 FROM sys.foreign_keys fk
                 JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
                 JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
                 JOIN sys.schemas ps ON ps.schema_id = pt.schema_id
                 JOIN sys.columns pc ON pc.object_id = pt.object_id AND pc.column_id = fkc.parent_column_id
                 JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
                 JOIN sys.schemas rs ON rs.schema_id = rt.schema_id
                 JOIN sys.columns rc ON rc.object_id = rt.object_id AND rc.column_id = fkc.referenced_column_id
                 WHERE pt.name <> 'migrations'
                 ORDER BY ps.name, pt.name, fk.name, fkc.constraint_column_id"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($foreignKeys as $row) {
                $key = self::tableKey($row['table_schema'], $row['table_name']);
                $name = $row['constraint_name'];
                $result[$key][$name] ??= [
                    'name' => $name, 'type' => 'FOREIGN KEY', 'columns' => [],
                    'ref_schema' => $row['ref_schema'], 'ref_table' => $row['ref_table'],
                    'ref_columns' => [], 'update_rule' => $row['update_rule'], 'delete_rule' => $row['delete_rule'],
                ];
                $result[$key][$name]['columns'][] = $row['column_name'];
                $result[$key][$name]['ref_columns'][] = $row['ref_column'];
            }
        }
        $checks = $db->query(
            "SELECT tc.table_schema, tc.table_name, tc.constraint_name, cc.check_clause
             FROM information_schema.table_constraints tc
             JOIN information_schema.check_constraints cc
               ON cc.constraint_catalog = tc.constraint_catalog
              AND cc.constraint_schema = tc.constraint_schema
              AND cc.constraint_name = tc.constraint_name
             WHERE $schemaFilter AND tc.table_name <> 'migrations'
               AND tc.constraint_type = 'CHECK'
             ORDER BY tc.table_schema, tc.table_name, tc.constraint_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($checks as $check) {
            $key = self::tableKey($check['table_schema'], $check['table_name']);
            $result[$key][] = [
                'name' => $check['constraint_name'],
                'type' => 'CHECK',
                'clause' => $check['check_clause'],
                'columns' => [],
                'ref_schema' => null,
                'ref_table' => null,
                'ref_columns' => [],
                'update_rule' => null,
                'delete_rule' => null,
            ];
        }
        foreach ($result as &$constraintList) {
            $constraintList = array_values($constraintList);
        }
        unset($constraintList);
        return $result;
    }

    /** @param array<string, mixed> $column */
    private static function columnDefinition(array $column, string $driver): string
    {
        $columnName = self::quote((string) $column['column_name'], $driver);
        if ($driver === 'sqlsrv' && is_string($column['computed_expression'] ?? null)) {
            $definition = $columnName . ' AS ' . $column['computed_expression'];
            if ($column['computed_persisted']) {
                $definition .= ' PERSISTED';
            }
            return $definition;
        }
        $type = strtolower((string) $column['data_type']);
        if ($driver === 'pgsql' && is_string($column['native_type'] ?? null)) {
            $type = $column['native_type'];
        } elseif ($driver === 'sqlsrv' && ($column['is_user_defined'] ?? false)) {
            $type = self::qualified((string) $column['udt_schema'], (string) $column['udt_name'], $driver);
        } elseif ($driver === 'pgsql' && in_array($type, ['character varying', 'character', 'bit varying', 'bit'], true)
            && is_numeric($column['character_maximum_length'])) {
            $type .= '(' . (int) $column['character_maximum_length'] . ')';
        } elseif (in_array($type, ['decimal', 'numeric'], true) && is_numeric($column['numeric_precision'])) {
            $type .= '(' . (int) $column['numeric_precision']
                . (is_numeric($column['numeric_scale']) ? ',' . (int) $column['numeric_scale'] : '') . ')';
        } elseif (in_array($type, ['timestamp without time zone', 'timestamp with time zone', 'time without time zone', 'time with time zone'], true)
            && is_numeric($column['datetime_precision'])) {
            $type .= '(' . (int) $column['datetime_precision'] . ')';
        } elseif ($driver === 'sqlsrv' && in_array($type, ['varchar', 'nvarchar', 'char', 'nchar', 'varbinary', 'binary'], true)) {
            $length = $column['character_maximum_length'];
            $type .= '(' . ($length === null ? 'max' : (string) $length) . ')';
        } elseif ($driver === 'sqlsrv' && in_array($type, ['decimal', 'numeric'], true)) {
            $type .= '(' . (int) $column['numeric_precision'] . ',' . (int) $column['numeric_scale'] . ')';
        } elseif ($driver === 'sqlsrv' && in_array($type, ['datetime2', 'datetimeoffset', 'time'], true)
            && is_numeric($column['datetime_precision'])) {
            $type .= '(' . (int) $column['datetime_precision'] . ')';
        } elseif ($driver === 'pgsql' && $type === 'user-defined') {
            $type = self::qualified((string) $column['udt_schema'], (string) $column['udt_name'], $driver);
        }
        $definition = $columnName . ' ' . $type;
        if ($driver === 'pgsql' && ($column['is_generated'] ?? 'NEVER') === 'ALWAYS') {
            $definition .= ' GENERATED ALWAYS AS (' . $column['generation_expression'] . ') STORED';
            return $definition;
        }
        if ($driver === 'pgsql' && ($column['is_identity'] ?? 'NO') === 'YES') {
            $mode = ($column['identity_generation'] ?? 'BY DEFAULT') === 'ALWAYS' ? 'ALWAYS' : 'BY DEFAULT';
            $definition .= " GENERATED $mode AS IDENTITY";
        }
        if ($driver === 'sqlsrv' && array_key_exists('identity_seed', $column)) {
            $definition .= ' IDENTITY(' . (string) $column['identity_seed'] . ',' . (string) $column['identity_increment'] . ')';
        }
        if ($column['column_default'] !== null && ($column['is_identity'] ?? 'NO') !== 'YES') {
            $definition .= ' DEFAULT ' . $column['column_default'];
        }
        if ($column['is_nullable'] === 'NO') {
            $definition .= ' NOT NULL';
        }
        return $definition;
    }

    /** @param array<string, mixed> $constraint */
    private static function constraintDefinition(array $constraint, string $driver): string
    {
        $prefix = 'CONSTRAINT ' . self::quote((string) $constraint['name'], $driver) . ' ';
        $columns = implode(', ', array_map(static fn (string $column): string => self::quote($column, $driver), $constraint['columns']));
        if ($constraint['type'] === 'PRIMARY KEY') {
            return $prefix . 'PRIMARY KEY (' . $columns . ')';
        }
        if ($constraint['type'] === 'UNIQUE') {
            return $prefix . 'UNIQUE (' . $columns . ')';
        }
        if ($constraint['type'] === 'CHECK') {
            return $prefix . 'CHECK (' . $constraint['clause'] . ')';
        }
        $referenced = implode(', ', array_map(static fn (string $column): string => self::quote($column, $driver), $constraint['ref_columns']));
        $sql = $prefix . 'FOREIGN KEY (' . $columns . ') REFERENCES '
            . self::qualified((string) $constraint['ref_schema'], (string) $constraint['ref_table'], $driver)
            . ' (' . $referenced . ')';
        foreach (['update_rule' => 'UPDATE', 'delete_rule' => 'DELETE'] as $key => $action) {
            if (is_string($constraint[$key]) && strtoupper($constraint[$key]) !== 'NO ACTION') {
                $sql .= " ON $action " . strtoupper($constraint[$key]);
            }
        }
        return $sql;
    }

    /** @return list<string> */
    private static function indexes(PDO $db, string $driver, bool $drops = false): array
    {
        if ($driver === 'pgsql') {
            $rows = $db->query(
                "SELECT i.schemaname, i.indexname, i.indexdef
                 FROM pg_indexes i
                 WHERE i.schemaname NOT IN ('pg_catalog', 'information_schema')
                   AND i.tablename <> 'migrations'
                   AND NOT EXISTS (
                       SELECT 1 FROM pg_constraint c
                       JOIN pg_class x ON x.oid = c.conindid
                       JOIN pg_namespace n ON n.oid = x.relnamespace
                       WHERE n.nspname = i.schemaname AND x.relname = i.indexname
                   )
                 ORDER BY i.schemaname, i.indexname"
            )->fetchAll(PDO::FETCH_ASSOC);
            return array_map(
                static fn (array $row): string => $drops
                    ? 'DROP INDEX ' . self::qualified($row['schemaname'], $row['indexname'], $driver)
                    : $row['indexdef'],
                $rows,
            );
        }
        $rows = $db->query(
            "SELECT s.name AS schema_name, t.name AS table_name, i.name AS index_name,
                    i.is_unique, i.type_desc, i.filter_definition, ic.key_ordinal,
                    ic.is_included_column, c.name AS column_name
             FROM sys.indexes i
             JOIN sys.tables t ON t.object_id = i.object_id
             JOIN sys.schemas s ON s.schema_id = t.schema_id
             JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
             JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
             WHERE i.is_primary_key = 0 AND i.is_unique_constraint = 0
               AND i.name IS NOT NULL AND i.is_disabled = 0 AND i.is_hypothetical = 0 AND t.name <> 'migrations'
             ORDER BY s.name, t.name, i.name, ic.is_included_column, ic.key_ordinal"
        )->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $key = self::tableKey($row['schema_name'], $row['index_name']);
            $grouped[$key] ??= ['schema' => $row['schema_name'], 'table' => $row['table_name'],
                'name' => $row['index_name'], 'unique' => (bool) $row['is_unique'],
                'type' => $row['type_desc'], 'filter' => $row['filter_definition'], 'keys' => [], 'includes' => []];
            $grouped[$key][$row['is_included_column'] ? 'includes' : 'keys'][] = $row['column_name'];
        }
        $result = [];
        foreach ($grouped as $index) {
            if ($drops) {
                $result[] = 'DROP INDEX ' . self::quote($index['name'], $driver) . ' ON '
                    . self::qualified($index['schema'], $index['table'], $driver);
                continue;
            }
            $unique = $index['unique'] ? 'UNIQUE ' : '';
            $type = $index['type'] === 'CLUSTERED' ? 'CLUSTERED ' : 'NONCLUSTERED ';
            $sql = "CREATE $unique$type" . 'INDEX ' . self::quote($index['name'], $driver) . ' ON '
                . self::qualified($index['schema'], $index['table'], $driver)
                . ' (' . implode(', ', array_map(static fn (string $column): string => self::quote($column, $driver), $index['keys'])) . ')';
            if ($index['includes'] !== []) {
                $sql .= ' INCLUDE (' . implode(', ', array_map(static fn (string $column): string => self::quote($column, $driver), $index['includes'])) . ')';
            }
            if (is_string($index['filter'])) {
                $sql .= ' WHERE ' . $index['filter'];
            }
            $result[] = $sql;
        }
        return $result;
    }

    /**
     * @param list<string> $tables
     * @param array<string, list<string>> $dependencies Child table => referenced parent tables.
     * @return list<string>
     */
    private static function dependencyOrder(array $tables, array $dependencies): array
    {
        $remaining = array_fill_keys($tables, true);
        $ordered = [];
        while ($remaining !== []) {
            $ready = [];
            foreach (array_keys($remaining) as $table) {
                $parents = array_filter($dependencies[$table] ?? [], static fn (string $parent): bool => isset($remaining[$parent]));
                if ($parents === []) {
                    $ready[] = $table;
                }
            }
            if ($ready === []) {
                throw new RuntimeException('Cannot safely order tables with circular foreign-key dependencies.');
            }
            sort($ready, SORT_STRING);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }
        return $ordered;
    }

    private static function quote(string $identifier, string $driver): string
    {
        return match ($driver) {
            'sqlsrv' => '[' . str_replace(']', ']]', $identifier) . ']',
            'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    private static function qualified(string $schema, string $table, string $driver): string
    {
        return self::quote($schema, $driver) . '.' . self::quote($table, $driver);
    }

    private static function tableKey(string $schema, string $table): string
    {
        return $schema . '.' . $table;
    }
}
