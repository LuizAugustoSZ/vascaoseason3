<?php
declare(strict_types=1);
require __DIR__ . '/../includes/trophy-order.php';
if (trophy_order_validate([3, 1, 2], [1, 2, 3]) !== [3, 1, 2]) throw new RuntimeException('Ordem não preservada.');
foreach ([[1,1,2], [1,2], [1,2,4], ['1',2,3], [], null, ['x'=>1,2,3]] as $invalid) {
    try {
        trophy_order_validate($invalid, [1,2,3]);
        throw new RuntimeException('Aceitou lista inválida.');
    } catch (InvalidArgumentException $expected) {}
}
echo "Ordem completa aceita; duplicados, IDs ausentes, desconhecidos e tipos inválidos rejeitados.\n";
