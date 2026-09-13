<?php
declare(strict_types=1);
require __DIR__ . '/../includes/g4-knockout.php';
function standings(PDO $pdo, int $id): array { return $pdo->ranking; }
class SlotDb extends PDO {
    public array $ranking=[],$links=[],$updates=[];
    public int $started=0;
    public function __construct() { $this->ranking=array_map(fn($id)=>['id'=>$id],range(101,108)); }
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new SlotStmt($this,$query); }
}
class SlotStmt extends PDOStatement {
    public function __construct(private SlotDb $db, private string $sql) {}
    public function execute(?array $params=null): bool {
        if(str_starts_with($this->sql,'UPDATE')) $this->db->updates[]=[$this->sql,$params];
        return true;
    }
    public function fetchColumn(int $column=0): mixed { return $this->db->started; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array {
        if(str_contains($this->sql,'SELECT g.*')) return $this->db->links;
        return [['id'=>1,'ordem'=>1,'jogo'=>1],['id'=>2,'ordem'=>1,'jogo'=>2],['id'=>3,'ordem'=>2,'jogo'=>1],['id'=>4,'ordem'=>2,'jogo'=>2]];
    }
}
function check(bool $ok, string $message): void { if(!$ok) throw new RuntimeException($message); }
foreach([[1,4,2,3],[5,8,6,7]] as $positions) {
    $db=new SlotDb();
    $db->links=[['campeonato_id'=>20,'origem_campeonato_id'=>10,'congelado_em'=>null,'sorteio_json'=>json_encode($positions)]];
    sync_g4_knockout_slots($db);
    check(count($db->updates)===4,'Deve atualizar quatro jogos');
    check($db->updates[0][1]===[100+$positions[0],100+$positions[1],1],'Vagas incorretas');
    check($db->updates[1][1]===[100+$positions[1],100+$positions[0],2],'Volta deve inverter mando');
    $db->ranking=array_reverse($db->ranking);$db->updates=[];
    sync_g4_knockout_slots($db);
    check($db->updates[0][1][0]===109-$positions[0],'Deve acompanhar nova classificacao');
    $db->started=1;$db->updates=[];sync_g4_knockout_slots($db);
    check(count($db->updates)===1 && str_contains($db->updates[0][0],'congelado_em=NOW()'),'Resultado deve congelar vagas');
    $db->links[0]['congelado_em']='2026-09-13';$db->updates=[];sync_g4_knockout_slots($db);
    check($db->updates===[],'Congelado nao deve mudar');
}
$db=new SlotDb();$db->ranking=array_slice($db->ranking,0,7);
$db->links=[['campeonato_id'=>20,'origem_campeonato_id'=>10,'congelado_em'=>null,'sorteio_json'=>'[5,6,7,8]']];
sync_g4_knockout_slots($db);check($db->updates===[],'G8 incompleto nao deve atualizar parcialmente');
echo "OK: G4/G8, mudanca na classificacao, ida e volta, congelamento e G8 incompleto.\n";
