<?php
declare(strict_types=1);
require __DIR__ . '/../includes/elenco-geral.php';

// SQLite executa as consultas reais; apenas o bloqueio exclusivo do MySQL é removido.
class CorrectionPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check_correction(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
foreach ([false, true] as $duplicate) {
    $pdo = new CorrectionPDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE jogadores_gerais (id INTEGER PRIMARY KEY, participante_id INT, nome TEXT, overall INT, posicao TEXT, ativo INT, saiu_em TEXT, UNIQUE(participante_id,nome,overall,posicao))');
    $pdo->exec('CREATE TABLE jogadores_elenco (id INTEGER PRIMARY KEY, participante_id INT, jogador_geral_id INT, nome TEXT)');
    $pdo->exec('CREATE TABLE movimentacoes_elenco_geral (id INTEGER PRIMARY KEY, jogador_geral_id INT, jogador_nome TEXT, conta_id INT, valor INT)');
    $pdo->exec("INSERT INTO jogadores_gerais VALUES (1,1,'ALEXANDER-ARNOLD',91,'LD',1,NULL),(3,2,'TRENT ALEXANDER-ARNOLD',91,'LD',1,NULL),(4,1,'TRENT ALEXANDER-ARNOLD',90,'LD',1,NULL),(5,1,'TRENT ALEXANDER-ARNOLD',91,'MC',1,NULL)");
    if ($duplicate) $pdo->exec("INSERT INTO jogadores_gerais VALUES (2,1,'TRENT ALEXANDER-ARNOLD',91,'LD',0,'2026-01-01')");
    $pdo->exec("INSERT INTO jogadores_elenco VALUES (1,1,1,'ALEXANDER-ARNOLD'),(2,1,1,'ALEXANDER-ARNOLD'),(3,2,3,'TRENT ALEXANDER-ARNOLD')");
    $pdo->exec("INSERT INTO movimentacoes_elenco_geral VALUES (1,1,'ALEXANDER-ARNOLD',77,1000)");
    $history = $pdo->query('SELECT * FROM movimentacoes_elenco_geral')->fetchAll();
    $others = $pdo->query('SELECT * FROM jogadores_gerais WHERE id>=3')->fetchAll();
    $pdo->beginTransaction();
    elenco_geral_corrigir_nome($pdo, 1, 1, 'TRENT ALEXANDER-ARNOLD');
    $pdo->commit();
    $expectedId = $duplicate ? 2 : 1;
    check_correction((int)$pdo->query("SELECT COUNT(*) FROM jogadores_elenco WHERE participante_id=1 AND jogador_geral_id=$expectedId AND nome='TRENT ALEXANDER-ARNOLD'")->fetchColumn() === 2, 'Vínculos das competições incorretos');
    check_correction($history === $pdo->query('SELECT * FROM movimentacoes_elenco_geral')->fetchAll(), 'Histórico alterado');
    check_correction($others === $pdo->query('SELECT * FROM jogadores_gerais WHERE id>=3')->fetchAll(), 'Outra carta ou clube alterado');
    if ($duplicate) {
        check_correction((int)$pdo->query('SELECT ativo FROM jogadores_gerais WHERE id=1')->fetchColumn() === 0, 'Cadastro antigo deve ser preservado inativo');
        check_correction((int)$pdo->query('SELECT ativo FROM jogadores_gerais WHERE id=2')->fetchColumn() === 1, 'Carta existente deve estar ativa');
        check_correction($pdo->query('SELECT saiu_em FROM jogadores_gerais WHERE id=2')->fetchColumn() === null, 'Carta reativada conserva data de saída');
    }
    $pdo->beginTransaction();
    elenco_geral_corrigir_nome($pdo, $expectedId, 1, 'TRENT ALEXANDER-ARNOLD');
    $pdo->commit();
    check_correction($history === $pdo->query('SELECT * FROM movimentacoes_elenco_geral')->fetchAll(), 'Repetição alterou histórico');
    $pdo->beginTransaction();
    try {
        elenco_geral_corrigir_nome($pdo, $expectedId, 999, 'INVALID');
        throw new LogicException('Clube incorreto foi aceito');
    } catch (RuntimeException $e) {
        $pdo->rollBack();
    }
}
echo "Summary player correction tests passed.\n";
