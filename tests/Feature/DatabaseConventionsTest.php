<?php

use Illuminate\Support\Facades\DB;

test('the connection stores times in UTC', function () {
    expect(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+00:00');
});

test('tables use InnoDB and the language-neutral default collation', function () {
    $tables = DB::select(
        'SELECT table_name AS name, engine AS engine, table_collation AS collation
         FROM information_schema.tables WHERE table_schema = DATABASE()'
    );

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect($table->engine)->toBe('InnoDB', "{$table->name} engine")
            ->and($table->collation)->toBe('utf8mb4_0900_ai_ci', "{$table->name} collation");
    }
});

test('no column uses the TIMESTAMP type', function () {
    $columns = DB::select(
        "SELECT CONCAT(table_name, '.', column_name) AS name
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND data_type = 'timestamp'"
    );

    expect(array_column($columns, 'name'))->toBe([]);
});
