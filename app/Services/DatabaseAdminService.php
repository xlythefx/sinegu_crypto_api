<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safety core for the admin Database console (/api/admin/database/*).
 *
 * Every dangerous decision lives here so the controller stays a thin HTTP shell.
 * The defenses, and their honest limits:
 *
 *  - IDENTIFIERS ARE NEVER TAKEN FROM THE CALLER. A submitted table/column name
 *    is compared for identity against the server's own listing, and the SERVER's
 *    string is what reaches the query builder. No user byte lands in an
 *    identifier position, so `wrapTable()` has nothing to escape.
 *
 *  - INTROSPECTION IS SCHEMA-SCOPED. Schema::getTables()/getTableListing() with
 *    a null schema return every database the connection can see — on this box
 *    that is 569 tables across 43 databases, because the WAMP credentials are
 *    root. Every call here passes Schema::getCurrentSchemaName() explicitly and
 *    getTableListing(schemaQualified: false).
 *
 *  - RAW SQL RUNS THE NORMALIZED TEXT, NEVER THE INPUT. Comments are stripped by
 *    a scanner that copies quoted literals verbatim, and the scanner's OUTPUT is
 *    what executes — so a `;` or `--` hidden in a string literal is data, not
 *    syntax. MySQL conditional comments (`/*!` … ) are refused outright rather
 *    than stripped: the server executes their body, so removing one would run
 *    something other than what the operator typed.
 *
 *  - STATEMENTS ARE ALLOWLISTED BY LEADING KEYWORD, not denylisted, so DDL that
 *    nobody thought of (HANDLER, INSTALL, SHUTDOWN, ...) is blocked by default.
 *
 * Known limits, stated rather than hidden:
 *  - Column masking is NAME-based, so `select password as p from ...` defeats it.
 *    Accepted: this surface already permits arbitrary SELECT for admin+master.
 *  - The banned-fragment scan is textual, so a literal like `select 'load data'`
 *    is a false positive. Blocking a harmless query is the safe failure.
 *  - MySQL's max_execution_time bounds SELECT only. There is no statement
 *    timeout for DML; a slow UPDATE is bounded by innodb_lock_wait_timeout.
 *  - The LIMIT heuristic is fooled by a `limit` inside a subquery. Worst case we
 *    do not append one, and the row cap still truncates the payload.
 */
class DatabaseAdminService
{
    /** Rows returned by the SQL console before truncation kicks in. */
    public const MAX_ROWS = 500;

    /** SELECT-only, MySQL-only statement timeout for the console. */
    private const READ_TIMEOUT_MS = 5000;

    /** What a masked value renders as. Submitting this back is refused. */
    public const MASK = '••••••••';

    /** Statement kinds the console will run. Anything else is blocked. */
    private const READ_STATEMENTS = ['select', 'show', 'explain', 'describe', 'desc', 'with'];

    private const WRITE_STATEMENTS = ['insert', 'update', 'delete', 'replace'];

    /** Fully hidden, per table. */
    private const MASKED_COLUMNS = [
        'user_credentials' => ['password', 'reset_code', 'verification_code', 'remember_token'],
        'users' => ['password', 'remember_token'],
        'binance_accounts' => ['secret_key'],
        'personal_access_tokens' => ['token'],
        'password_reset_tokens' => ['token'],
        'sessions' => ['payload'],
    ];

    /** Shown as `abcd…wxyz` so the row stays identifiable. */
    private const PARTIAL_COLUMNS = [
        'binance_accounts' => ['api_key'],
    ];

    /** Masked by bare name in arbitrary SQL result sets. */
    private const MASKED_ANY = [
        'password', 'secret_key', 'remember_token', 'reset_code', 'verification_code', 'token',
    ];

    /**
     * Refused anywhere in a statement, whatever its kind. `into outfile` and
     * `load_file` are the sharp ones — they turn a plain SELECT into arbitrary
     * file write/read as the mysql server user.
     */
    private const BANNED_FRAGMENTS = [
        'into outfile', 'into dumpfile', 'load_file(', 'load data', 'load xml',
        'benchmark(', 'sleep(', 'get_lock(',
        'mysql.', 'performance_schema.', 'sys.', 'sqlite_master', 'pragma ',
    ];

    /* ============ introspection ============ */

    public function schemaName(): string
    {
        return Schema::getCurrentSchemaName();
    }

    /**
     * Bare table names in THIS connection's schema — the only allowlist there is.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_values(Schema::getTableListing($this->schemaName(), schemaQualified: false));
    }

    /**
     * The server's own spelling of $table, or null when it is not ours.
     *
     * Schema::hasTable() is NOT a substitute: it runs parseSchemaAndTable(), so
     * hasTable('napai_db.users') answers true.
     */
    public function resolveTable(string $table): ?string
    {
        foreach ($this->tableNames() as $name) {
            if ($name === $table) {
                return $name;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function columns(string $table): array
    {
        return array_values(Schema::getColumns($table));
    }

    /** @return list<string> */
    public function columnNames(string $table): array
    {
        return array_values(array_column($this->columns($table), 'name'));
    }

    /**
     * Row identity for the editor: primary key first, then a NOT NULL unique
     * index. An empty list means the table has no usable identity and the row
     * editor must refuse to touch it.
     *
     * @return list<string>
     */
    public function keyColumns(string $table): array
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['primary'] && $index['columns'] !== []) {
                return array_values($index['columns']);
            }
        }

        $nullable = array_column(
            array_filter($this->columns($table), fn ($c) => $c['nullable']),
            'name'
        );

        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] !== []
                && array_diff($index['columns'], $nullable) === $index['columns']) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    /** Columns that may never be written: key parts, auto-increment, generated. */
    public function immutableColumns(string $table): array
    {
        $immutable = $this->keyColumns($table);

        foreach ($this->columns($table) as $column) {
            if ($column['auto_increment'] || $column['generation'] !== null) {
                $immutable[] = $column['name'];
            }
        }

        return array_values(array_unique($immutable));
    }

    /** Columns holding bytes we refuse to round-trip through the editor. */
    public function binaryColumns(string $table): array
    {
        $binary = [];

        foreach ($this->columns($table) as $column) {
            if (in_array(strtolower((string) $column['type_name']), ['blob', 'binary', 'varbinary', 'longblob', 'mediumblob', 'tinyblob'], true)) {
                $binary[] = $column['name'];
            }
        }

        return $binary;
    }

    /** @return list<string> masked names that actually exist on $table */
    public function maskedColumns(string $table): array
    {
        $declared = array_merge(
            self::MASKED_COLUMNS[$table] ?? [],
            self::PARTIAL_COLUMNS[$table] ?? [],
        );

        return array_values(array_intersect($declared, $this->columnNames($table)));
    }

    /* ============ value rendering ============ */

    /**
     * Serialized PHP in cache.value / jobs.payload is not valid UTF-8, and
     * json_encode() fails on it — an unguarded browse 500s. Ordinary text is
     * never shortened here: a truncated value written back is silent data loss.
     */
    private function jsonSafe(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return '0x'.strtoupper(bin2hex(substr($value, 0, 64))).(strlen($value) > 64 ? '…' : '');
        }

        return $value;
    }

    private function partial(string $value): string
    {
        return strlen($value) <= 10
            ? self::MASK
            : substr($value, 0, 4).'…'.substr($value, -4);
    }

    /** Mask + JSON-harden one row of a known table. */
    public function presentRow(string $table, array $row): array
    {
        $full = self::MASKED_COLUMNS[$table] ?? [];
        $partial = self::PARTIAL_COLUMNS[$table] ?? [];

        $out = [];
        foreach ($row as $column => $value) {
            if ($value !== null && in_array($column, $full, true)) {
                $out[$column] = self::MASK;
            } elseif ($value !== null && in_array($column, $partial, true)) {
                $out[$column] = $this->partial((string) $value);
            } else {
                $out[$column] = $this->jsonSafe($value);
            }
        }

        return $out;
    }

    /** Mask + JSON-harden a row from an arbitrary statement, by bare column name. */
    public function presentLooseRow(array $row): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            $out[$column] = $value !== null && in_array(strtolower($column), self::MASKED_ANY, true)
                ? self::MASK
                : $this->jsonSafe($value);
        }

        return $out;
    }

    /** Nudge submitted strings toward the column's real type before binding. */
    public function coerce(string $table, string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = null;
        foreach ($this->columns($table) as $meta) {
            if ($meta['name'] === $column) {
                $type = strtolower((string) $meta['type_name']);
                break;
            }
        }

        if ($type === null || ! is_string($value)) {
            return $value;
        }

        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            if ($value === '') {
                return null;
            }
            if (strcasecmp($value, 'true') === 0) {
                return 1;
            }
            if (strcasecmp($value, 'false') === 0) {
                return 0;
            }

            return is_numeric($value) ? (int) $value : $value;
        }

        if (in_array($type, ['decimal', 'double', 'float', 'numeric', 'real'], true)) {
            return $value === '' ? null : (is_numeric($value) ? (float) $value : $value);
        }

        return $value;
    }

    /* ============ row targeting ============ */

    /**
     * A builder pinned to one row by its key columns. whereNull is used for NULL
     * key parts so a composite key with a nullable member still matches.
     *
     * @param  list<string>  $keyColumns
     */
    public function keyed(string $table, array $keyColumns, array $key): Builder
    {
        $query = DB::table($table);

        foreach ($keyColumns as $column) {
            $value = $key[$column] ?? null;
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query;
    }

    /* ============ the SQL gate ============ */

    /**
     * Strip comments and find top-level statement separators without being
     * fooled by quoted literals — `where note = 'a; drop'` holds no syntax.
     *
     * @return array{sql: string, semicolons: list<int>, conditional: bool}|null null = unterminated block comment
     */
    private function scan(string $raw): ?array
    {
        $out = '';
        $semicolons = [];
        $conditional = false;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            $next = $raw[$i + 1] ?? '';

            if (($c === '-' && $next === '-') || $c === '#') {
                while ($i < $len && $raw[$i] !== "\n") {
                    $i++;
                }
                $out .= ' ';

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($raw, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                // `/*!...*/` is a MySQL conditional comment: the server EXECUTES
                // its body. Stripping it would run something other than what was
                // typed, so the statement is refused instead.
                if (($raw[$i + 2] ?? '') === '!') {
                    $conditional = true;
                }
                $i = $end + 1;
                $out .= ' ';

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= $c;
                for ($i++; $i < $len; $i++) {
                    $ch = $raw[$i];
                    $out .= $ch;
                    if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                        $out .= $raw[++$i];

                        continue;
                    }
                    if ($ch === $quote) {
                        if (($raw[$i + 1] ?? '') === $quote) {   // '' / "" escape
                            $out .= $raw[++$i];

                            continue;
                        }
                        break;
                    }
                }

                continue;
            }

            if ($c === ';') {
                $semicolons[] = strlen($out);
                $out .= ' ';

                continue;
            }

            $out .= $c;
        }

        return ['sql' => $out, 'semicolons' => $semicolons, 'conditional' => $conditional];
    }

    /**
     * Reject `otherdb.table`. The connection is root and can see every database
     * on the box, so a bare `select * from napai_db.users` would otherwise work.
     * The check is inverted — only qualifiers that ARE a foreign schema name are
     * refused — so ordinary table aliases like `u.id` pass untouched.
     */
    private function foreignSchemaIn(string $sql): ?string
    {
        $own = strtolower($this->schemaName());
        $foreign = array_diff(
            array_map('strtolower', array_column(Schema::getSchemas(), 'name')),
            [$own]
        );

        if ($foreign === []) {
            return null;
        }

        preg_match_all(
            '/(?:`([A-Za-z0-9_$]+)`|\b([A-Za-z0-9_$]+))\s*\.\s*[`A-Za-z0-9_$*]/',
            $sql,
            $hits,
            PREG_SET_ORDER
        );

        foreach ($hits as $hit) {
            $qualifier = strtolower(($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? ''));
            if ($qualifier !== '' && in_array($qualifier, $foreign, true)) {
                return $qualifier;
            }
        }

        return null;
    }

    /**
     * Decide whether a statement may run, and hand back the normalized text that
     * should be executed in its place.
     *
     * @return array{error: string, message: string}|array{kind: 'read'|'write', sql: string}
     */
    public function gate(string $raw, bool $confirmUnfiltered = false): array
    {
        $scan = $this->scan($raw);

        if ($scan === null) {
            return ['error' => 'SQL_BLOCKED', 'message' => 'Unterminated block comment.'];
        }

        if ($scan['conditional']) {
            return [
                'error' => 'SQL_BLOCKED',
                'message' => 'Blocked: MySQL conditional comments (/*! … */) execute their contents. Write the statement plainly.',
            ];
        }

        $sql = trim($scan['sql']);
        $semicolons = $scan['semicolons'];

        if (count($semicolons) > 1) {
            return ['error' => 'SQL_MULTI_STATEMENT', 'message' => 'Run one statement at a time.'];
        }
        if (count($semicolons) === 1 && trim(substr($scan['sql'], $semicolons[0])) !== '') {
            return ['error' => 'SQL_MULTI_STATEMENT', 'message' => 'Run one statement at a time.'];
        }

        $sql = rtrim($sql, "; \t\r\n");

        if ($sql === '') {
            return ['error' => 'SQL_EMPTY', 'message' => 'Enter a statement to run.'];
        }

        $clean = ltrim($sql, "( \t\r\n");   // `(select …) union (select …)`

        if (! preg_match('/^([a-z_]+)/i', $clean, $m)) {
            return ['error' => 'SQL_EMPTY', 'message' => 'Enter a statement to run.'];
        }

        $keyword = strtolower($m[1]);

        $kind = match (true) {
            in_array($keyword, self::READ_STATEMENTS, true) => 'read',
            in_array($keyword, self::WRITE_STATEMENTS, true) => 'write',
            default => null,
        };

        if ($kind === null) {
            return [
                'error' => 'SQL_BLOCKED',
                'message' => strtoupper($keyword).' is not allowed here. This console runs reads and row writes only — schema changes are blocked.',
            ];
        }

        // MySQL 8 allows `WITH … DELETE FROM`, so a CTE is only a read if it stays one.
        if ($keyword === 'with' && preg_match('/\b(insert|update|delete|replace)\b/i', $clean)) {
            return [
                'error' => 'SQL_BLOCKED',
                'message' => 'A CTE that writes is not allowed. Write the statement directly.',
            ];
        }

        $lower = strtolower($sql);
        foreach (self::BANNED_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return [
                    'error' => 'SQL_BLOCKED',
                    'message' => "Blocked: statements containing “{$fragment}” are refused.",
                ];
            }
        }

        if (($foreign = $this->foreignSchemaIn($sql)) !== null) {
            return [
                'error' => 'SQL_BLOCKED',
                'message' => "Blocked: `{$foreign}` is a different database. This console is scoped to {$this->schemaName()}.",
            ];
        }

        if ($kind === 'write'
            && preg_match('/^(update|delete)\b/i', $clean)
            && ! preg_match('/\bwhere\b/i', $clean)
            && ! $confirmUnfiltered) {
            return [
                'error' => 'UNFILTERED_WRITE',
                'message' => 'This statement has no WHERE clause and would affect every row in the table.',
            ];
        }

        return ['kind' => $kind, 'sql' => $clean];
    }

    /**
     * Run a statement the gate already approved.
     *
     * @return array{kind: string, columns: list<string>, rows: list<array>, affected: int|null, row_count: int, truncated: bool}
     */
    public function execute(string $kind, string $sql): array
    {
        if ($kind === 'write') {
            return [
                'kind' => 'write',
                'columns' => [],
                'rows' => [],
                'affected' => DB::affectingStatement($sql),
                'row_count' => 0,
                'truncated' => false,
            ];
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('set session max_execution_time = '.self::READ_TIMEOUT_MS);
        }

        // +1 so a full page is distinguishable from an exactly-capped one.
        if (preg_match('/^\s*(select|with)\b/i', $sql) && ! preg_match('/\blimit\s+\d/i', $sql)) {
            $sql .= ' limit '.(self::MAX_ROWS + 1);
        }

        $raw = DB::select($sql);
        $truncated = count($raw) > self::MAX_ROWS;
        $raw = array_slice($raw, 0, self::MAX_ROWS);

        $rows = array_map(fn ($row) => $this->presentLooseRow((array) $row), $raw);

        return [
            'kind' => 'read',
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'affected' => null,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }
}
