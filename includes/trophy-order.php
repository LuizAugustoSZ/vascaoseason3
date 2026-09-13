<?php
declare(strict_types=1);

// Consulta a permissão atual no banco, inclusive se a sessão estiver desatualizada.
function trophy_order_allowed(PDO $pdo): bool
{
    if (!account_logged_in()) return false;
    $stmt = $pdo->prepare('SELECT nome,eh_admin FROM contas WHERE id=? AND ativo=1');
    $stmt->execute([(int) $_SESSION['conta_id']]);
    $account = $stmt->fetch();
    return $account && (int) $account['eh_admin'] === 1
        && strtolower(trim((string) $account['nome'])) === 'slower';
}

function trophy_order_validate(mixed $ids, array $existing): array
{
    if (!is_array($ids) || !array_is_list($ids) || !$ids) {
        throw new InvalidArgumentException('Envie a ordem completa das taças.');
    }
    foreach ($ids as $id) {
        if (!is_int($id) || $id < 1) throw new InvalidArgumentException('Ordem inválida.');
    }
    $sorted = $ids;
    sort($sorted, SORT_NUMERIC);
    $existing = array_map('intval', $existing);
    sort($existing, SORT_NUMERIC);
    if ($sorted !== $existing) {
        throw new InvalidArgumentException('A lista de taças mudou. Atualize a página e tente novamente.');
    }
    return $ids;
}
