# PROJECT_CONTEXT.md — Vascão Season 3

> Documento de contexto obrigatório para humanos e assistentes de programação.
> Leia este arquivo antes de alterar o projeto e atualize-o quando uma decisão estrutural mudar.

## 1. Visão geral

O **Vascão Season 3** é um sistema web para organizar campeonatos da comunidade DreamTeam. Ele centraliza participantes, pontos corridos, mata-mata, partidas, gols, artilharia, notícias, vídeos, títulos, regulamento e administração.

- Site atual: `https://vascaoseason3.is-best.net`
- Hospedagem atual: InfinityFree
- Backend: PHP
- Banco: MySQL/MariaDB
- Frontend: HTML, CSS e JavaScript sem framework pesado
- Idioma da interface: português do Brasil
- Linha visual: fundo escuro, vermelho como cor principal e tipografia esportiva
- Baseline deste documento: versão pública **v10.5** e painel **a2.2**
- Atualizado em: 14/08/2026

## 2. Princípios do projeto

- Toda mudança pública, incluindo correções visuais, deve gerar uma nova entrada no histórico e incrementar a versão do rodapé exatamente em **+0.1**, sem saltos (por exemplo: `7.8`, `7.9`, `8.0`, `8.1`).

1. Preservar todos os dados reais já cadastrados.
2. Nunca corrigir um problema apagando informações válidas.
3. Alterações de banco devem ser incrementais, rastreáveis e reversíveis quando possível.
4. Permissões devem ser verificadas no backend, nunca apenas escondidas com CSS ou JavaScript.
5. Ações administrativas devem preferencialmente usar AJAX/fetch e atualizar só a área afetada, sem recarregar a página inteira.
6. Listagens administrativas devem seguir o mesmo padrão de busca, filtros, paginação e ações.
7. Não inventar resultados, jogadores, estatísticas ou fatos sobre campeonatos.
8. Funcionalidades planejadas não devem ser tratadas como já implementadas.

## 3. Estrutura principal

```text
/
├── admin/                  # Painel administrativo e endpoints administrativos
├── api/                    # Endpoints públicos/assíncronos
├── assets/
│   ├── css/                # Estilos públicos e administrativos
│   ├── js/                 # JavaScript público e administrativo
│   └── img/                # Imagens estáticas do projeto
├── config/                 # Configuração local; credenciais não entram no Git
├── includes/               # Bootstrap, autenticação e funções compartilhadas
├── migrations/             # Migrações SQL incrementais
├── index.php               # Landing page
├── comandos.php
├── regulamento.php
├── noticias.php
├── noticia.php
├── login.php
├── logout.php
└── trocar-senha.php
```

Antes de criar um arquivo novo, verificar se a responsabilidade já pertence a um módulo existente.

## 4. Ambientes, Git e deploy

### Repositório

- Usar repositório **privado no GitHub**.
- `main`: produção.
- `develop`: integração e homologação.
- `feature/nome-da-feature`: desenvolvimento isolado.
- Mudanças entram por pull request depois de revisão e testes.
- Não fazer deploy de produção a cada salvamento da IDE.

### Ambientes

- Desenvolvimento local: banco local ou de desenvolvimento.
- Homologação: banco separado, sem dados sensíveis de produção.
- Produção: banco atual da hospedagem.
- Código pode ser versionado; dados de produção não.
- A homologação pode consultar a assinatura do banco de produção e exibir **Sincronizar agora** exclusivamente para o Admin Master `Slower` quando houver diferenças.
- A sincronização é sempre unidirecional, de produção para homologação, preservando contas, senhas e sessões específicas de cada ambiente.

### Deploy desejado

1. Desenvolver em `feature/*`.
2. Testar localmente.
3. Abrir PR para `develop`.
4. Validar em homologação.
5. Aprovar PR para `main`.
6. Fazer backup do banco quando houver migration.
7. Executar migrations pendentes.
8. Publicar o código da `main`.
9. Fazer teste rápido de login, painel, landing page e gravações.

No início, migrations de produção exigem confirmação manual. Automatizar somente depois de existir backup e rollback confiáveis.

## 5. Arquivos que nunca devem entrar no Git

```gitignore
.env
config/config.php
*.sql
*.sql.zip
backups/
tmp/
*.log
```

Credenciais devem vir de variáveis de ambiente. Nunca registrar no repositório:

- usuário ou senha do banco;
- hashes de senha;
- cookies ou sessões;
- dumps de produção;
- tokens, webhooks ou chaves privadas.

## 6. Banco de dados e migrations

- O banco de produção continua na hospedagem; GitHub guarda apenas código e migrations.
- Cada alteração de esquema deve gerar um novo arquivo em `migrations/`.
- Nunca substituir o banco inteiro por um dump para aplicar uma pequena mudança.
- Manter uma tabela de controle de migrations executadas.
- Fazer backup antes de migrations destrutivas ou que transformem dados.
- Preferir chaves estrangeiras e índices para relações importantes.
- Soft delete deve usar status/ativo ou `deleted_at`, conforme o padrão adotado pela tabela.
- Registros inativos não aparecem no site público, mas permanecem auditáveis e podem ser reativados.

## 7. Autenticação e hierarquia

Senhas devem usar `password_hash()` e `password_verify()`. **MD5 não é permitido.** O administrador não deve conhecer a senha definitiva de outro usuário. Para uma conta nova, usar senha temporária e obrigar troca no primeiro acesso.

| Nível | Papel | Acesso |
|---|---|---|
| `eh_admin = 0` | Usuário comum | Sem painel administrativo; futuramente administra apenas o time reivindicado |
| `eh_admin = 1` | Admin Master | Acesso completo, incluindo usuários, configurações, campeonatos, participantes, títulos, vídeos e sorteador |
| `eh_admin = 2` | Editor da Competição | Pontos corridos, mata-mata, artilharia e notícias |

Regras obrigatórias:

- Validar a sessão e a permissão em cada rota e endpoint.
- Um editor não pode acessar uma ação proibida digitando a URL diretamente.
- O menu deve exibir apenas as abas permitidas, mas isso não substitui a validação do servidor.
- A listagem de usuários permite editar dados já preenchidos, desativar e reativar.
- O usuário comum futuramente só poderá alterar recursos associados ao próprio participante aprovado.

## 8. Painel administrativo

O painel principal usa abas. Notícias fazem parte do painel e não devem abrir uma administração separada. A antiga rota administrativa de notícias pode apenas redirecionar para `admin/index.php?tab=noticias`.

Fora das abas, manter somente:

- **Sorteador**;
- **Abrir site**.

### Padrão de todas as listagens

- 5 registros por página;
- campo de busca;
- filtros adequados ao módulo, como campeonato, rodada, time, técnico, jogador e status;
- edição que preenche automaticamente o formulário;
- desativação por soft delete;
- opção de reativar registros inativos;
- confirmação para ações destrutivas ou substituições;
- feedback claro de sucesso ou erro;
- salvar sem recarregar a página completa sempre que tecnicamente seguro.

## 9. Competições e partidas

### Pontos corridos

- Suporta turno único e ida e volta.
- Com número ímpar de participantes, cada rodada tem uma folga; em ida e volta, todos enfrentam os adversários duas vezes, invertendo o mando.
- O mandante é sempre exibido à esquerda e o visitante à direita na tabela pública de jogos.
- A interface não precisa escrever uma legenda solta `MANDANTEVISITANTE`; a posição visual deve comunicar isso.
- Ao editar uma partida sorteada, mandante e visitante já vêm preenchidos e bloqueados para impedir troca acidental.

### Mata-mata

- Possui quartas, semifinal, final e disputa de terceiro lugar quando aplicável.
- Final e terceiro lugar ficam no mesmo bloco visual do chaveamento.
- Times eliminados ficam visualmente apagados, sem remover nenhuma informação.
- Classificados, campeão, vice-campeão e terceiro colocado recebem etiquetas próprias.
- Remover ícones de verificação redundantes que quebram o alinhamento do placar.
- O terceiro lugar deve existir sempre que o formato permitir.
- Final e terceiro lugar podem ser configurados individualmente como jogo único ou ida e volta, mesmo que as fases anteriores usem ida e volta.
- Em decisão por pênaltis, exibir `placar (pênaltis)` junto de cada time, por exemplo `0 (3)` e `0 (4)`, e não repetir `Pênaltis: 3 × 4` no rodapé.

## 10. Registro de gols e artilharia

Cada gol de pontos corridos e mata-mata pode registrar:

- jogador;
- time/participante;
- partida;
- campeonato;
- minuto livre, incluindo acréscimos como `45+2`;
- tipo: normal, pênalti, falta, olímpico ou contra.

Gol contra não soma para a artilharia do jogador favorecido nem cria artilheiro incorreto.

### Identidade correta do artilheiro

O nome sozinho não identifica um artilheiro. A identidade mínima é:

```text
campeonato_id + participante_id/time_id + nome_normalizado_do_jogador
```

Consequências:

- `Kylian Mbappé` no Lords FC e `Kylian Mbappé` no Comparsas FC são registros distintos.
- Gols do mesmo jogador, no mesmo time e campeonato, somam entre partidas.
- A busca por nome pode sugerir registros existentes, mas nunca deve escolher um time apenas pelo nome.
- Se a combinação exata já existir, pedir confirmação antes de atualizar manualmente o total.
- A listagem e o download devem agrupar usando a mesma chave composta.

### Página pública da artilharia

- Artilharia separada por campeonato.
- Top 10, com 5 jogadores por página.
- Download da lista completa em imagem.
- Pódio: 1º dourado, 2º prata, 3º bronze.
- Posição e quantidade de gols do pódio usam a mesma cor.
- Do 4º em diante, posição e quantidade de gols ficam em cinza.
- A imagem baixada deve preservar exatamente essas cores.

## 11. Imagens e escudos

- Não alterar as dimensões reais do arquivo ao apenas salvar dados no banco.
- Controlar tamanho e enquadramento na apresentação com CSS.
- Usar `object-fit: contain`, largura/altura máximas e contêiner estável.
- Quando existe imagem válida, mostrar somente o escudo, sem quadrado de fundo.
- O quadrado com sigla é apenas fallback quando não há imagem válida.
- O escudo nunca pode escapar da linha, sobrepor nome/placar ou alterar a altura do card.
- Aplicar a mesma regra em participantes, tabelas administrativas, partidas, chaveamento, final e terceiro lugar.

## 12. Landing page e conteúdo público

- A ordem das seções pode ser configurada no painel.
- As cores de fundo alternam conforme a ordem final, evitando seções consecutivas com o mesmo fundo.
- Notícias aparecem logo no início da experiência pública.
- O destaque inicial pode alternar entre notícias e o último vídeo.
- Vídeo pode iniciar automaticamente sem som.
- Ao rolar a página, o vídeo pode ficar flutuante no canto, com opção visível de fechar.
- Setas do carrossel devem ser visíveis, alinhadas e não sobrepor indicadores ou status da temporada.
- Finalizar um campeonato não finaliza a Season. Sem competição ativa, mostrar **Aguardando próxima competição**.

### Regulamento

- Não mostrar o número decorativo `05` no título da página.
- O link do regulamento do Brasileirão aponta sempre para a notícia de ID 8.
- Usar URL relativa: `noticia.php?id=8`, para continuar funcionando caso o domínio mude.

### Links oficiais

- Discord: `https://discord.gg/nkDynjHbMM`
- YouTube: `https://www.youtube.com/@DreamBotSeason2`

## 13. Versionamento visível

- O rodapé exibe versão pública e versão do painel.
- Toda entrega relevante atualiza o histórico correspondente.
- O número de versão só muda depois que a funcionalidade estiver implementada e testada.
- Não declarar uma versão nova apenas por alterar este documento.

## 14. Página de times e contas

A primeira etapa está implementada para homologação:

- cadastro público cria apenas contas comuns, sem vínculo automático com time;
- o Admin Master associa manualmente uma conta a `contas.participante_id`;
- a aba de usuários recomenda associações quando o nome da conta coincide exatamente com o nome do técnico;
- um participante não pode ser associado simultaneamente a duas contas;
- cada participante ativo possui página pública com identidade, escudo, técnico, descrição, artilheiros e partidas;
- nomes de times nas áreas públicas com identidade disponível apontam para a página do time;
- formação, cofre, grito do time e escalação aparecem apenas como módulos futuros, sem edição ou regra de negócio ativa.

### Funcionalidades planejadas — próximas etapas

### Página oficial de cada time

Cada participante poderá ter uma página própria com:

- identidade, descrição e banner;
- técnico responsável;
- classificação e desempenho automáticos;
- jogos, resultados e próximos confrontos;
- histórico de confrontos;
- artilheiros do time;
- competições e títulos;
- elenco atual;
- escalação e formação;
- contratações recentes;
- cofre;
- avisos e postagens do clube.

### Cadastro e reivindicação

Fluxo proposto:

1. Pessoa cria uma conta comum.
2. Solicita a reivindicação de um participante/time.
3. Admin Master analisa e aprova ou rejeita.
4. Depois da aprovação, a conta recebe vínculo com aquele participante.
5. O usuário administra apenas a parte permitida do próprio clube.
6. Dados oficiais de competição continuam automáticos e não podem ser editados pelo técnico.

### Mercado de transferências

Regras implementadas:

- o ciclo é individual por clube e usa somente as partidas concluídas daquele participante;
- as primeiras 5 partidas ficam travadas, as próximas 3 ficam abertas e o ciclo de 8 partidas se repete;
- assim que o clube conclui a 5ª partida, pode alterar o elenco sem esperar os demais jogos da rodada;
- o técnico cadastra livremente nome, overall e posição, sem catálogo interno de cartas;
- compras debitam o cofre, vendas creditam o valor e todas as movimentações ficam no histórico;
- escalação exige exatamente 11 titulares; os demais jogadores ficam no banco;
- somente a conta associada ao participante pode acessar o Mercado pela navegação pública;
- o Admin Master pode gerenciar qualquer clube e campeonato pela aba Mercado do painel;
- mural, descrição e jogador favorito são editados em modal na página pública pelo responsável associado;
- elenco, banco, cofre, mural e jogador favorito são públicos depois da configuração.

## 15. Produção de notícias

Notícias sobre campeonatos devem usar apenas dados confirmados pelo sistema ou fornecidos pela organização. Podem destacar:

- resultados;
- classificação;
- artilharia;
- hat-tricks;
- gols de pênalti, falta ou olímpicos;
- estatísticas reais;
- contexto da rodada.

O texto pode ser imersivo e esportivo, mas não pode inventar lances, declarações ou números.

## 16. Regras para assistentes de programação

Ao receber uma tarefa neste projeto:

1. Ler este documento e as instruções do repositório.
2. Inspecionar o código e o esquema relacionados antes de editar.
3. Diferenciar claramente bug, melhoria e funcionalidade futura.
4. Fazer a menor alteração segura que resolva a causa do problema.
5. Preservar mudanças não relacionadas existentes no workspace.
6. Não editar dados de produção para esconder bug de código.
7. Não criar dependência externa sem justificar.
8. Não alterar banco sem migration incremental.
9. Não expor segredos em logs, commits ou respostas.
10. Validar permissão no backend de todas as novas ações.
11. Testar caminhos de sucesso, erro, edição e reativação.
12. Verificar desktop e mobile quando houver mudança visual.
13. Informar os arquivos alterados, testes executados e migration necessária.
14. Atualizar este documento se a tarefa mudar uma regra estrutural.

## 17. Definition of Done

Uma mudança só está concluída quando:

- [ ] atende à regra de negócio solicitada;
- [ ] não apaga nem mistura dados existentes;
- [ ] respeita os níveis `0`, `1` e `2`;
- [ ] funciona sem recarga total quando esse é o padrão do módulo;
- [ ] listagens mantêm busca, filtros, paginação e ações esperadas;
- [ ] migrations foram criadas e testadas, se necessárias;
- [ ] nenhuma credencial entrou no Git;
- [ ] PHP/JavaScript/CSS foram validados;
- [ ] layout foi conferido em desktop e mobile;
- [ ] histórico e versão foram atualizados quando aplicável;
- [ ] PR descreve risco, teste e procedimento de deploy.

## 18. Decisões ainda pendentes

- Provedor definitivo de hospedagem após a migração para GitHub.
- Processo de deploy: GitHub Actions, integração da hospedagem ou publicação por FTP/SFTP.
- Ambiente de homologação definitivo.
- Regras exatas de preço, cofre e negociação do mercado.
- Quem pode aprovar transferências e em quais situações.
- Se jogador pode trocar de clube durante o mesmo campeonato e como o histórico de artilharia será exibido.
- Política de armazenamento de imagens fora do banco.
