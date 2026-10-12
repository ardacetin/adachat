<?php

use App\Support\FrontendBuild;

test('the upgrade stops before touching anything when the front end is stale', function () {
    $stamp = public_path(FrontendBuild::STAMP);
    $saved = is_file($stamp) ? file_get_contents($stamp) : null;
    @mkdir(dirname($stamp), 0755, true);
    file_put_contents($stamp, json_encode(['hash' => 'old']));

    try {
        $this->artisan('ada:upgrade')
            ->expectsOutputToContain('npm ci && npm run build')
            ->assertFailed();

        expect(app()->isDownForMaintenance())->toBeFalse();
    } finally {
        $saved === null ? @unlink($stamp) : file_put_contents($stamp, $saved);
    }
});

test('the doctor reports pending migrations and an outdated build', function () {
    $stamp = public_path(FrontendBuild::STAMP);
    $saved = is_file($stamp) ? file_get_contents($stamp) : null;
    @mkdir(dirname($stamp), 0755, true);
    file_put_contents($stamp, json_encode(['hash' => 'old']));
    $migration = database_path('migrations/2099_01_01_000000_pending_doctor_test.php');
    file_put_contents($migration, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n");

    try {
        $this->artisan('ada:doctor')
            ->expectsOutputToContain('The front end is built from this version')
            ->expectsOutputToContain('public/build is older than the code')
            ->expectsOutputToContain('1 migration(s) have not run');
    } finally {
        unlink($migration);
        $saved === null ? @unlink($stamp) : file_put_contents($stamp, $saved);
    }
});
