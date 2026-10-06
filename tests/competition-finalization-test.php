<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

function leg(int $a, int $b, ?int $ga, ?int $gb, string $status = 'finalizado', ?int $pa = null, ?int $pb = null): array {
    return ['time_a_id'=>$a,'time_b_id'=>$b,'gols_a'=>$ga,'gols_b'=>$gb,'status'=>$status,'penaltis_a'=>$pa,'penaltis_b'=>$pb];
}
$cases = [
    'Recopa 7 x 5 com mandos invertidos' => [[leg(1,2,3,2),leg(2,1,3,4)],2,1],
    'Vencedor da volta perde no agregado' => [[leg(1,2,5,0),leg(2,1,2,0)],2,1],
    'Ida sozinha não encerra edição' => [[leg(1,2,3,0)],2,null],
    'Volta pendente' => [[leg(1,2,3,0),leg(2,1,null,null,'agendado')],2,null],
    'Agregado empatado exige desempate' => [[leg(1,2,3,2),leg(2,1,3,2)],2,null],
    'Pênaltis na volta' => [[leg(1,2,3,2),leg(2,1,3,2,'finalizado',5,4)],2,2],
    'Pênaltis da ida não decidem agregado' => [[leg(1,2,1,1,'finalizado',5,4),leg(2,1,0,0)],2,null],
    'W.O. único' => [[leg(1,2,0,3,'wo')],1,2],
    'W.O. ida e volta' => [[leg(1,2,3,0,'wo'),leg(2,1,0,3,'wo')],2,1],
    'Times inconsistentes' => [[leg(1,2,3,0),leg(3,1,0,3)],2,null],
    'Decisão ausente' => [[],1,null],
    'Jogo único empatado com pênaltis' => [[leg(1,2,0,0,'finalizado',3,4)],1,2],
];
foreach ($cases as $name => [$games,$legs,$expected]) {
    if (competition_final_winner($games,$legs) !== $expected) throw new RuntimeException($name);
}
echo 'OK: '.count($cases)." cenários de decisão, agregado, pendências e W.O.\n";

class ChampionStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class ChampionDatabase extends PDO {
    public function __construct(public array $competition, public array $games) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ChampionStatement(str_contains($query, 'FROM campeonatos') ? [$this->competition] : $this->games);
    }
}
$db = new ChampionDatabase(['tipo'=>'mata_mata','status'=>'finalizado','formato'=>'ida_volta'], [leg(6,5,0,2)]);
if (competition_champion_id($db,7) !== 5) throw new RuntimeException('Final única com semifinais de ida e volta');
$db->games[] = leg(5,6,null,null,'agendado');
if (competition_champion_id($db,7) !== null) throw new RuntimeException('Volta agendada não pode entregar título');
$db->games = [leg(6,5,0,2)];
$db->competition['tipo'] = 'supercopa';
if (competition_champion_id($db,7) !== null) throw new RuntimeException('Supercopa exige as pernas configuradas');
echo "OK: formato independente da final e proteção contra entrega antecipada\n";
