<?php
declare(strict_types=1);
require __DIR__ . '/../includes/summary-storage.php';
class StorageStatement extends PDOStatement {
    public array $rows = [];
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class StoragePDO extends PDO {
    public array $rows = [], $executed = [];
    public bool $transaction = false;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (!str_contains($query, 'TABLE_SCHEMA=DATABASE()')) throw new RuntimeException('Must inspect current database only.');
        $statement = new StorageStatement(); $statement->rows = $this->rows; return $statement;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function exec(string $statement): int|false { $this->executed[] = $statement; return 0; }
}
$pdo = new StoragePDO();
$pdo->rows = [
    ['TABLE_NAME'=>'gols_mata_mata','CHARACTER_SET_NAME'=>'latin1'],
    ['TABLE_NAME'=>'gols_mata_mata','CHARACTER_SET_NAME'=>'latin1'],
    ['TABLE_NAME'=>'artilharia','CHARACTER_SET_NAME'=>'latin1'],
    ['TABLE_NAME'=>'gols_partida','CHARACTER_SET_NAME'=>'utf8mb4'],
    ['TABLE_NAME'=>'sumulas_dreamteam','CHARACTER_SET_NAME'=>'utf8mb4'],
    ['TABLE_NAME'=>'other_table','CHARACTER_SET_NAME'=>'latin1'],
];
summary_ensure_utf8_storage($pdo);
if (count($pdo->executed) !== 2 || !str_contains($pdo->executed[0], '`gols_mata_mata`') || !str_contains($pdo->executed[1], '`artilharia`')) throw new RuntimeException('Must upgrade only legacy allowlisted tables once.');
$pdo->executed = [];
$pdo->rows = [['TABLE_NAME'=>'gols_mata_mata','CHARACTER_SET_NAME'=>'utf8mb4']];
summary_ensure_utf8_storage($pdo);
if ($pdo->executed) throw new RuntimeException('UTF-8 storage must remain unchanged.');
$pdo->rows = [['TABLE_NAME'=>'gols_mata_mata','CHARACTER_SET_NAME'=>'latin1']];
$pdo->transaction = true;
$blocked = false;
try { summary_ensure_utf8_storage($pdo); } catch (RuntimeException $error) { $blocked = true; }
if (!$blocked || $pdo->executed) throw new RuntimeException('DDL must never commit a match transaction.');
echo "Summary UTF-8 storage tests passed.\n";
