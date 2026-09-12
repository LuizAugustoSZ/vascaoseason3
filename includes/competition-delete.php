<?php

function competition_delete_edition(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id FROM campeonatos WHERE id=? AND ativo=1 FOR UPDATE');
        $query->execute([$id]);
        if (!$query->fetchColumn()) throw new RuntimeException('Esta edição não existe ou já foi excluída.');
        foreach ([['mata_mata_g4', 'origem_campeonato_id=?'], ['supercopas', '(origem_a_campeonato_id=? OR origem_b_campeonato_id=?)']] as [$table, $where]) {
            if (!$pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn()) continue;
            $query = $pdo->prepare("SELECT c.nome FROM $table s JOIN campeonatos c ON c.id=s.campeonato_id WHERE c.ativo=1 AND $where LIMIT 1");
            $query->execute($table === 'supercopas' ? [$id, $id] : [$id]);
            if ($dependent = $query->fetchColumn()) throw new RuntimeException('Exclua primeiro a edição dependente: ' . $dependent . '.');
        }
        // Mantém os registros para auditoria, mas remove a edição e seus jogos das consultas públicas.
        foreach (['partidas', 'jogos_mata_mata'] as $table) {
            $pdo->prepare("UPDATE $table SET ativo=0 WHERE campeonato_id=?")->execute([$id]);
        }
        foreach (['titulos', 'artilharia'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE campeonato_id=?")->execute([$id]);
        }
        $pdo->prepare('UPDATE campeonatos SET ativo=0 WHERE id=?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
