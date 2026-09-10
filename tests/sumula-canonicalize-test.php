<?php
declare(strict_types=1);

// Carrega apenas as funções, sem executar autenticação ou o endpoint HTTP.
$source = file_get_contents(__DIR__ . '/../admin/sumula-importar.php');
$start = strpos($source, 'function normalized_team_name');
$end = strpos($source, 'function summary_roster_issues');
eval(substr($source, $start, $end - $start));

class SummaryRosterStatement extends PDOStatement
{
    private int $teamId;
    public function execute(?array $params = null): bool
    {
        $this->teamId = $params[1];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->teamId === 1 ? ['Lautaro Martínez'] : ['Gabigol'];
    }
}

class SummaryRosterPDO extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new SummaryRosterStatement();
    }
}

foreach (['316', 'BR', '0316'] as $code) {
    $parsed = [
        'teams' => [['code' => $code, 'scorers' => []], ['code' => 'LOC', 'scorers' => []]],
        'events' => [], 'goals' => [],
        'man_of_match' => 'Lautaro', 'man_of_match_team_code' => null,
    ];
    $result = summary_canonicalize_players(new SummaryRosterPDO(), $parsed, ['home' => ['id' => 1], 'away' => ['id' => 2]], 1);
    if ($result['man_of_match_team_code'] !== $code || $result['man_of_match'] !== 'Lautaro Martínez') {
        throw new RuntimeException('Falha ao resolver craque com sigla ' . $code);
    }
}
echo "Summary canonicalization tests passed.\n";
