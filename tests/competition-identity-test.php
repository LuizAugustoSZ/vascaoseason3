<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$cases = [
    'Libertadores' => 'libertadores g4',
    'Libertadores II' => 'libertadores g4',
    'Libertadores do G4' => 'libertadores g4',
    'Sul-Americana' => 'sul americana g8',
    'Sul-Americana II' => 'sul americana g8',
];

foreach ($cases as $name => $expected) {
    $actual = competition_identity_match($name);
    if ($actual !== $expected) {
        throw new RuntimeException("Identidade incorreta para {$name}: " . var_export($actual, true));
    }
}

echo "competition identity tests passed\n";
