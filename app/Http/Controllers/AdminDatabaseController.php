<?php

namespace App\Http\Controllers;

use App\Services\DatabaseAdminService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * phpMyAdmin-style database console for admin/master accounts.
 *
 * HTTP shell only — every dangerous decision (identifier allowlisting, primary
 * key discovery, column masking, the raw-SQL gate) lives in
 * DatabaseAdminService, which documents the defenses and their limits.
 *
 * Two rules this controller enforces on top of the service:
 *  - A table name reaches the query builder only after resolveTable() has
 *    matched it against the server's own listing, so the string used is the
 *    SERVER's, never the caller's. The route also constrains {table} to a bare
 *    identifier, making a dotted cross-schema reference unroutable.
 *  - Every row write pre-counts inside a transaction and refuses to proceed
 *    unless exactly one row matches, so a malformed key can never fan out.
 */
class AdminDatabaseController extends Controller
{
    /** Browse page size ceiling — a LONGTEXT table can make each row large. */
    private const MAX_PER_PAGE = 200;

    public function __construct(private DatabaseAdminService $db) {}

    /* ============ introspection ============ */

    public function tables(): JsonResponse
    {
        $counts = [];
        foreach ($this->db->tableNames() as $name) {
            $counts[$name] = DB::table($name)->count();
        }

        $tables = [];
        foreach (Schema::getTables($this->db->schemaName()) as $table) {
            $name = $table['name'];
            $tables[] = [
                'name' => $name,
                'rows' => $counts[$name] ?? 0,
                'size_bytes' => $table['size'] ?? null,
                'engine' => $table['engine'] ?? null,
                'comment' => ($table['comment'] ?? '') !== '' ? $table['comment'] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'database' => $this->db->schemaName(),
            'driver' => DB::getDriverName(),
            'tables' => $tables,
        ]);
    }

    public function structure(string $table): JsonResponse
    {
        if (($name = $this->db->resolveTable($table)) === null) {
            return $this->unknownTable($table);
        }

        return response()->json(array_merge(
            ['success' => true, 'table' => $name],
            $this->meta($name),
            [
                'indexes' => array_values(Schema::getIndexes($name)),
                'foreign_keys' => array_values(Schema::getForeignKeys($name)),
            ],
        ));
    }

    /* ============ browse ============ */

    public function rows(Request $request, string $table): JsonResponse
    {
        if (($name = $this->db->resolveTable($table)) === null) {
            return $this->unknownTable($table);
        }

        $columns = $this->db->columnNames($name);
        $keyColumns = $this->db->keyColumns($name);

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        // Unknown sort falls back silently — a bad query string should not 422.
        $sort = (string) $request->query('sort', '');
        if (! in_array($sort, $columns, true)) {
            $sort = $keyColumns[0] ?? $columns[0] ?? '';
        }
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = DB::table($name);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $textual = $this->textualColumns($name);
            $query->where(function ($q) use ($textual, $search) {
                foreach ($textual as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $total = (clone $query)->count();

        if ($sort !== '') {
            $query->orderBy($sort, $direction);
        }

        $raw = $query->forPage($page, $perPage)->get();
        $rows = $raw->map(fn ($row) => $this->db->presentRow($name, (array) $row))->all();

        return response()->json(array_merge(
            ['success' => true, 'table' => $name],
            $this->meta($name),
            [
                'rows' => $rows,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'sort' => $sort !== '' ? $sort : null,
                'direction' => $direction,
            ],
        ));
    }

    /* ============ row writes ============ */

    public function storeRow(Request $request, string $table): JsonResponse
    {
        if (($name = $this->db->resolveTable($table)) === null) {
            return $this->unknownTable($table);
        }

        $request->validate(['values' => ['required', 'array']]);

        $keyColumns = $this->db->keyColumns($name);
        if ($keyColumns === []) {
            return $this->notEditable();
        }

        $values = (array) $request->input('values');
        if (($bad = $this->rejectValues($name, $values, forInsert: true)) !== null) {
            return $bad;
        }

        $clean = [];
        foreach ($values as $column => $value) {
            $clean[$column] = $this->db->coerce($name, $column, $value);
        }

        try {
            $key = DB::transaction(function () use ($name, $keyColumns, $clean) {
                $auto = $this->autoIncrementColumn($name);

                if ($auto !== null && $keyColumns === [$auto]) {
                    return [$auto => DB::table($name)->insertGetId($clean)];
                }

                DB::table($name)->insert($clean);

                $key = [];
                foreach ($keyColumns as $column) {
                    $key[$column] = $clean[$column] ?? null;
                }

                return $key;
            });
        } catch (QueryException $e) {
            return $this->queryFailed($e);
        }

        $row = $this->db->keyed($name, $keyColumns, $key)->first();

        return response()->json([
            'success' => true,
            'row' => $row === null ? null : $this->db->presentRow($name, (array) $row),
            'key' => $key,
        ], 201);
    }

    public function updateRow(Request $request, string $table): JsonResponse
    {
        if (($name = $this->db->resolveTable($table)) === null) {
            return $this->unknownTable($table);
        }

        $request->validate([
            'key' => ['required', 'array'],
            'values' => ['required', 'array'],
        ]);

        $keyColumns = $this->db->keyColumns($name);
        if ($keyColumns === []) {
            return $this->notEditable();
        }

        $key = (array) $request->input('key');
        if (($bad = $this->rejectKey($name, $keyColumns, $key)) !== null) {
            return $bad;
        }

        $values = (array) $request->input('values');
        if ($values === []) {
            return $this->fail('NO_CHANGES', 'Nothing to update.');
        }
        if (($bad = $this->rejectValues($name, $values, forInsert: false)) !== null) {
            return $bad;
        }

        $clean = [];
        foreach ($values as $column => $value) {
            $clean[$column] = $this->db->coerce($name, $column, $value);
        }

        try {
            $affected = DB::transaction(function () use ($name, $keyColumns, $key, $clean) {
                $matches = $this->db->keyed($name, $keyColumns, $key)->count();
                if ($matches === 0) {
                    return 0;
                }
                if ($matches > 1) {
                    return -1;
                }

                return $this->db->keyed($name, $keyColumns, $key)->update($clean);
            });
        } catch (QueryException $e) {
            return $this->queryFailed($e);
        }

        if ($affected === 0) {
            return $this->rowNotFound();
        }
        if ($affected === -1) {
            return $this->ambiguous();
        }

        $row = $this->db->keyed($name, $keyColumns, $key)->first();

        return response()->json([
            'success' => true,
            'row' => $row === null ? null : $this->db->presentRow($name, (array) $row),
            'affected' => $affected,
        ]);
    }

    public function destroyRow(Request $request, string $table): JsonResponse
    {
        if (($name = $this->db->resolveTable($table)) === null) {
            return $this->unknownTable($table);
        }

        $request->validate(['key' => ['required', 'array']]);

        $keyColumns = $this->db->keyColumns($name);
        if ($keyColumns === []) {
            return $this->notEditable();
        }

        $key = (array) $request->input('key');
        if (($bad = $this->rejectKey($name, $keyColumns, $key)) !== null) {
            return $bad;
        }

        // Deleting your own login leaves nobody able to undo it.
        if ($name === 'user_credentials'
            && ($key['uni_id'] ?? null) === $request->user()->uni_id) {
            return $this->fail('SELF_DELETE_BLOCKED', 'You cannot delete your own account from here.');
        }

        try {
            $deleted = DB::transaction(function () use ($name, $keyColumns, $key) {
                $matches = $this->db->keyed($name, $keyColumns, $key)->count();
                if ($matches === 0) {
                    return 0;
                }
                if ($matches > 1) {
                    return -1;
                }

                return $this->db->keyed($name, $keyColumns, $key)->delete();
            });
        } catch (QueryException $e) {
            return $this->queryFailed($e);
        }

        if ($deleted === 0) {
            return $this->rowNotFound();
        }
        if ($deleted === -1) {
            return $this->ambiguous();
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /* ============ raw SQL ============ */

    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
            'confirm_unfiltered' => ['nullable', 'boolean'],
        ]);

        $verdict = $this->db->gate(
            (string) $request->input('sql'),
            $request->boolean('confirm_unfiltered'),
        );

        if (isset($verdict['error'])) {
            return $this->fail($verdict['error'], $verdict['message']);
        }

        $startedAt = microtime(true);

        try {
            $result = $this->db->execute($verdict['kind'], $verdict['sql']);
        } catch (QueryException $e) {
            return $this->queryFailed($e);
        }

        return response()->json(array_merge(
            ['success' => true, 'statement' => $verdict['sql']],
            $result,
            ['duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)],
        ));
    }

    /* ============ helpers ============ */

    /** Shape shared by structure() and rows() so the UI has one contract. */
    private function meta(string $table): array
    {
        return [
            'columns' => $this->db->columns($table),
            'key_columns' => $this->db->keyColumns($table),
            'editable' => $this->db->keyColumns($table) !== [],
            'masked_columns' => $this->db->maskedColumns($table),
            'binary_columns' => $this->db->binaryColumns($table),
            'immutable_columns' => $this->db->immutableColumns($table),
        ];
    }

    /** Columns a LIKE search can meaningfully scan. */
    private function textualColumns(string $table): array
    {
        $textual = [];
        foreach ($this->db->columns($table) as $column) {
            if (str_contains(strtolower((string) $column['type_name']), 'char')
                || str_contains(strtolower((string) $column['type_name']), 'text')
                || strtolower((string) $column['type_name']) === 'enum') {
                $textual[] = $column['name'];
            }
        }

        return $textual;
    }

    private function autoIncrementColumn(string $table): ?string
    {
        foreach ($this->db->columns($table) as $column) {
            if ($column['auto_increment']) {
                return $column['name'];
            }
        }

        return null;
    }

    /** Every key column must be present, or the WHERE would under-constrain. */
    private function rejectKey(string $table, array $keyColumns, array $key): ?JsonResponse
    {
        foreach ($keyColumns as $column) {
            if (! array_key_exists($column, $key)) {
                return $this->fail('UNKNOWN_COLUMN', "Missing key column “{$column}”.");
            }
        }

        return null;
    }

    /**
     * Guard a write payload: real columns only, nothing immutable, and never a
     * masked placeholder — a round-tripped grid would otherwise overwrite a
     * password hash with bullets.
     */
    private function rejectValues(string $table, array $values, bool $forInsert): ?JsonResponse
    {
        $columns = $this->db->columnNames($table);
        $immutable = $this->db->immutableColumns($table);
        $binary = $this->db->binaryColumns($table);

        foreach ($values as $column => $value) {
            if (! in_array($column, $columns, true)) {
                return $this->fail('UNKNOWN_COLUMN', "“{$column}” is not a column on {$table}.");
            }

            if ($value === DatabaseAdminService::MASK) {
                return $this->fail('MASKED_VALUE', "“{$column}” is hidden — clear the field to keep it, or type a real value.");
            }

            if (in_array($column, $binary, true)) {
                return $this->fail('IMMUTABLE_COLUMN', "“{$column}” holds binary data and cannot be edited here.");
            }

            if (! $forInsert && in_array($column, $immutable, true)) {
                return $this->fail('IMMUTABLE_COLUMN', "“{$column}” is a key or generated column. Delete the row and re-insert instead.");
            }

            if ($forInsert && in_array($column, $immutable, true) && ! in_array($column, $this->db->keyColumns($table), true)) {
                return $this->fail('IMMUTABLE_COLUMN', "“{$column}” is generated and cannot be set.");
            }

            if (is_array($value)) {
                return $this->fail('UNKNOWN_COLUMN', "“{$column}” must be a scalar value.");
            }
        }

        return null;
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }

    private function unknownTable(string $table): JsonResponse
    {
        return $this->fail('UNKNOWN_TABLE', "“{$table}” is not a table in this database.", 404);
    }

    private function notEditable(): JsonResponse
    {
        return $this->fail(
            'TABLE_NOT_EDITABLE',
            'This table has no primary key, so a single row cannot be targeted safely. Use the SQL tab.',
        );
    }

    private function rowNotFound(): JsonResponse
    {
        return $this->fail('ROW_NOT_FOUND', 'That row no longer exists — it may have been changed elsewhere.', 404);
    }

    private function ambiguous(): JsonResponse
    {
        return $this->fail('AMBIGUOUS_ROW', 'That key matches more than one row. Refusing to write.', 409);
    }

    private function queryFailed(QueryException $e): JsonResponse
    {
        return $this->fail('SQL_ERROR', $e->getMessage());
    }
}
