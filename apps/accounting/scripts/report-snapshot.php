<?php

declare(strict_types=1);

/**
 * Read-only evidence for the v1 -> v2 migration rehearsal (docs/ecopa-multi-org-migration.md §4a):
 * computes Trial Balance, Balance Sheet and Income Statement for every entity and both books with
 * the application's own report services, so the numbers can be compared before and after the
 * migration. Runs on the v1 build as well as the v2 build (it uses the TenantContext only when it
 * exists) and never writes to the database.
 *
 *   php scripts/report-snapshot.php storage/app/migration-reports/reports-before.json
 *   php scripts/report-snapshot.php storage/app/migration-reports/reports-after.json \
 *       --compare=storage/app/migration-reports/reports-before.json
 *
 * Options: --as-of=YYYY-MM-DD (default: end of the year of --year), --year=YYYY (default: current year),
 * --root=<application folder> (default: this build; point it at the v1 checkout for the "before" snapshot).
 * Exit code 1 when --compare finds any difference.
 */

use Akunta\Rbac\Support\TenantContext;
use App\Models\Journal;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\IncomeStatementService;
use App\Services\Reporting\TrialBalanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$args = array_slice($argv, 1);
$out = null;
$options = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$key] = $value;
    } elseif ($out === null) {
        $out = $arg;
    }
}
if ($out === null) {
    fwrite(STDERR, "Usage: php scripts/report-snapshot.php <output.json> [--compare=before.json] [--year=YYYY] [--as-of=YYYY-MM-DD]\n");
    exit(2);
}

// A failure must fail the rehearsal: the framework's own handler would otherwise print it and exit 0.
$fail = static function (Throwable $e): never {
    fwrite(STDERR, 'report-snapshot failed: '.$e->getMessage().PHP_EOL);
    exit(1);
};
set_exception_handler($fail);

// --root=<application folder>: bootstrap another build (the v1 checkout for the "before" snapshot).
try {
    $root = rtrim((string) ($options['root'] ?? dirname(__DIR__)), '/\\');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $e) {
    $fail($e);
}
set_exception_handler($fail);

$year = $options['year'] ?? date('Y');
$asOf = $options['as-of'] ?? "{$year}-12-31";
$from = "{$year}-01-01";

$trial = $app->make(TrialBalanceService::class);
$sheet = $app->make(BalanceSheetService::class);
$income = $app->make(IncomeStatementService::class);
$hasTenant = class_exists(TenantContext::class) && Schema::hasColumn('entities', 'tenant_id');

$snapshot = [];
$entities = DB::table('entities')->orderBy('id')->get($hasTenant ? ['id', 'name', 'tenant_id'] : ['id', 'name']);
foreach ($entities as $entity) {
    $compute = static function () use ($trial, $sheet, $income, $entity, $asOf, $from): array {
        $books = [];
        foreach ([Journal::MODE_INTERNAL, Journal::MODE_FISCAL] as $book) {
            $books[$book] = [
                'trial_balance' => $trial->compute($entity->id, $asOf, $book),
                'balance_sheet' => $sheet->compute($entity->id, $asOf, $from, $book),
                'income_statement' => $income->compute($entity->id, $from, $asOf, $book),
            ];
        }

        return $books;
    };

    $snapshot[$entity->id] = ['name' => $entity->name, 'books' => $hasTenant && $entity->tenant_id !== null
        ? TenantContext::run((string) $entity->tenant_id, $compute)
        : $compute()];
}

$encode = static fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (! is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
$encoded = $encode(['as_of' => $asOf, 'from' => $from, 'entities' => $snapshot]);
file_put_contents($out, $encoded);
// Compare what was written (plain arrays), not the in-memory result objects.
$snapshot = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['entities'];
echo 'Report snapshot: '.count($snapshot)." entities, as of {$asOf} -> {$out}\n";

if (isset($options['compare'])) {
    $before = json_decode((string) file_get_contents($options['compare']), true, 512, JSON_THROW_ON_ERROR);
    $differences = [];
    $walk = static function (mixed $a, mixed $b, string $path) use (&$walk, &$differences): void {
        if (is_array($a) && is_array($b)) {
            foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
                $walk($a[$key] ?? null, $b[$key] ?? null, $path.'/'.$key);
            }

            return;
        }
        if ($a !== $b && ! (is_numeric($a) && is_numeric($b) && (string) $a === (string) $b)) {
            $differences[] = $path.': '.json_encode($a).' != '.json_encode($b);
        }
    };
    $walk($before['entities'], $snapshot, '');

    if ($differences !== []) {
        echo 'DIFFERENT: '.count($differences)." value(s)\n  ".implode("\n  ", array_slice($differences, 0, 20))."\n";
        exit(1);
    }
    echo 'IDENTICAL to '.$options['compare'].': '.count($snapshot)." entities, both books, three reports each\n";
}
