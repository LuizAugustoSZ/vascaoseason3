# Auditoria de CRUD assíncrono — v27.4 / a3.1

Data: 13/10/2026. Escopo: painel administrativo, Gestão da Competição, Elenco Geral, perfil do clube, conteúdo público e formulários de conta. PHP/MySQL e JavaScript existentes foram mantidos; não foi adicionada dependência de produção nem migration de esquema.

## Causas identificadas no código

1. `admin-loading.js` tornava o conteúdo `inert` e mantinha `navigating=true` após submits/navegações. `admin.js` bloqueava toda a página com **SALVANDO ALTERAÇÕES** para uma escrita pequena e, depois da confirmação AJAX, chamava `adminLoading.navigate()`, que executava `location.replace()`. O fluxo dependia de outra carga completa para liberar a interface.
2. Os `fetch` de escrita não tinham limite de espera, inclusive para consumir o corpo da resposta. Uma requisição pendente podia manter o loading global indefinidamente. Agora há abort da requisição, mensagem de resultado não confirmado, preservação dos campos e restauração em `finally`, sem repetir o POST.
3. A tabela do mata-mata continha um botão adicional de data com `origem=pontos`. Como IDs de tabelas diferentes podem coincidir, esse botão podia modificar uma partida da liga em vez do confronto exibido. Foi removido. A atualização usa origem + ID e a data confirmada pelo backend.
4. A resposta da data da liga apontava para `partidas`, mas a aba real é `jogos`. A referência foi corrigida.
5. A confirmação dos formulários do clube agora aguarda a transição real do modal antes de prosseguir, sem substituir uma confirmação ainda pendente. Os fechamentos após salvar também esperam `shown/hidden.bs.modal` e detectam cancelamento, evitando promises presas em transições.

Não foram obtidos logs da sessão original da imagem. Portanto, não se afirma que houve deadlock, erro SQL ou perda de persistência naquele salvamento específico. Os problemas acima são verificáveis no código; a gravação corrigida foi exercitada com PHP, navegador e MySQL descartável.

## Padrão adotado

- `async-request.js`: transporte compartilhado com limite de espera, consumo completo da resposta, validação do JSON/HTTP e encerramento garantido. Não reenviar escrita automaticamente após timeout ou falha de conexão.
- Datas: o backend valida, bloqueia o registro na transação, grava e confirma `{game: {id, origin, date, formatted}}`. Somente os dados do jogo, a data visível e o modal correspondente são atualizados.
- Demais operações do painel: POST estruturado seguido de leitura da representação persistida. Renovar a aba afetada, reinicializar seus controles sem duplicar os eventos globais e preservar filtros/página/rolagem e rascunhos independentes. Abas dependentes são reconciliadas quando abertas.
- Gestão da Competição: reaproveitar o padrão público de HTML confirmado em JSON; atualizar os componentes de inscrição/escalação e manter os filtros e os rascunhos do editor existente. O seletor de competição e a estrutura externa da página permanecem.
- Elenco Geral/perfil: atualizar os componentes já usados por `ClubInstantSave`, agora com o transporte compartilhado e fechamento seguro dos modais.
- Conta, importação, tela antiga de campeonatos e exclusão pública de notícia: delegado `public-form-save.js`, resposta explícita de sucesso/erro, atualização local e links de continuidade escolhidos pelo usuário.
- Loading localizado, prevenção de cliques repetidos e liberação dos controles em `finally`. Sucesso de persistência e falha posterior de atualização visual são mensagens distintas.

## Inventário auditado

| Área | Escritas e comportamento final |
|---|---|
| Pontos corridos e mata-mata | Cadastro manual, edição, resultados/W.O./penalidade, exclusão lógica, gols e data: POST assíncrono; data altera apenas o jogo confirmado |
| Campeonatos, padrões e Supercopa | Edição de nome/data/artes, status, exclusão de edição, identidade e criação de Supercopa passam pelo delegado assíncrono preservando o backend |
| Sorteador | Prévia, confirmação, cancelamento e G4; remove reload do documento pai e renova opções/listas dependentes |
| Técnicos/participantes | Cadastro, edição, escudo, participação futura, desativação e reativação; atualizar listagem preservando contexto |
| Contas do painel | Criar/editar, nível de acesso, vínculo com participante, ativar/desativar; validações e permissão mantidas |
| Artilharia e títulos | Cadastro/edição de gols e título atual/histórico, incluindo arte; renovar componentes relacionados |
| Notícias, regulamentos e vídeos | Publicar/editar/excluir notícia, escolher notícias de regulamento, publicar vídeo; publicação/exclusão preservam o editor independente e filtros |
| Configurações e packs | Configurações públicas e importação revisada de packs; renovar somente o painel correspondente |
| Gestão da Competição | Formação, inscrição, escalação e fluxos legados de movimentação; confirmação estruturada e atualização dos componentes |
| Elenco Geral | Compra/venda, edição, troca de passe, cofre, movimentações e desfazer; padrão público existente reaproveitado |
| Perfil do time | Sobre, cofre, herói, perfil, imagem da escalação e remoção; atualizadores existentes preservados |
| Importação de elenco | Análise sem gravar e confirmação da inclusão; preview e sucesso na mesma tela |
| Conta pública | Cadastro, troca/redefinição de senha e pedido de recuperação assíncronos; nenhuma remoção de validação/token |
| Notificações | Preferências e marcar como lidas assíncronos, com feedback/recuperação e prevenção de duplicidade |
| Sincronização | Transporte com limite, botão recuperável e invalidação local das abas; direção/autorizações e lógica dos bancos preservadas |

## Reloads eliminados

- `adminLoading.navigate()` deixou de chamar `location.replace()`.
- CRUD genérico do painel e publicação/exclusão de notícias deixaram de usar navegação para reconstruir os dados.
- `draw-preview.js` deixou de executar `window.parent.location.reload()`.
- O sorteador deixou de executar `location.reload()` após confirmar.
- Submissões tradicionais da Gestão da Competição, importação, cadastro, troca/redefinição de senha, tela antiga de campeonatos e exclusão pública de notícia foram interceptadas.
- A detecção de uma nova versão deixou de navegar automaticamente: a atualização depende de clique explícito, preservando os rascunhos.

Navegação intencional de login/logout, links públicos e troca GET de competição permanece. Essas exceções estão documentadas em `PROJECT_CONTEXT.md`, na seção **REGRA GLOBAL — OPERAÇÕES CRUD SEM RELOAD**. O fallback sem JavaScript não é o fluxo normal de CRUD.

## Verificação

`tests/crud-flow-test.py` copia código e esquema de fixture para uma pasta temporária e cria um banco `crud_test_*` exclusivamente em `127.0.0.1`. Não usa `config/config.php` do projeto. Executa `tests/crud-browser-test.js` com Chrome/Playwright e consulta os registros gravados. A pasta e o banco descartáveis são removidos ao terminar.

O navegador verifica ausência de pedidos de navegação do documento principal, atualização dos valores confirmados, encerramento do loading, funcionamento dos controles após renovações repetidas, rejeição do backend, JSON inválido, falha de rede, timeout e clique duplicado. A matriz adicional exerce os endpoints reais e seus atualizadores de fragmentos.

Resultado: **PASS** para datas de liga/mata-mata com consulta direta do MySQL; criação/edição/desativação de participante; inscrição e escalação; criação/edição/exclusão de notícia pela interface, preservando outro rascunho; criação/edição/exclusão de jogos manuais; regulamentos, vídeo, configuração, artilharia, título histórico, contas/vínculos/status, competição, identidade e packs; cadastro público, troca/redefinição de senha, autorização e CSRF; análise/confirmação de importação; data em viewport móvel 390×844. Um trigger temporário do banco rejeitou a gravação de data: o valor persistido ficou intacto, o campo digitado foi preservado e os controles foram liberados. Capturas desktop e móvel foram geradas com dados sintéticos para conferir a apresentação.

Também foram executados os testes existentes de loading administrativo, Elenco Geral/perfil em DOM, ciclos do mercado, exceção da nona rodada, packs, parser DreamTeam, troca de passe, finalização de competições, identidade, calendário/divulgação e feedback. Os testes do sorteador cobrem 4/8/10/16 participantes, confirmação exata, tentativa de replay, cancelamento, G4, proteção de dependências e reutilização de edição.

## Limitações e riscos explicitamente separados

- Os testes de escrita usam dados sintéticos locais, sem alterar partidas ou contas reais dos ambientes Railway.
- Upload/remoção de imagem do perfil, rascunhos e paginação têm regressão de DOM; não houve gravação de arquivo de usuário nos serviços remotos.
- Não foi acionada sincronização de bancos nem entrega SMTP real. A verificação desses fluxos é de código e recuperação de controles; preservar a autorização/direção não equivale a testar uma sincronização real.
- Resultado detalhado com gols, W.O., Supercopa completa e toda combinação de formatos possuem testes de regras existentes e auditoria, não uma sessão manual de cada variante no Railway.
- Alguns atualizadores administrativos ainda obtêm HTML renderizado pelo servidor para renovar a aba afetada. A estrutura da página não é substituída nem navegada; esse caminho segue o padrão público existente. Uma resposta perdida depois do commit exige conferência dos dados, nunca retry automático.

## Arquivos da entrega

Transporte/atualizadores: `assets/js/async-request.js`, `admin-crud.js`, `public-form-save.js`, `competition-save.js`, `competition-roster.js`, `draw-complete.js`, `includes/async-form.php`.

Painel: `admin/index.php`, `admin/campeonatos.php`, `admin/sorteador.php`, `assets/js/admin.js`, `admin-loading.js`, `admin-lists.js`, `admin-pack-import.js`, `admin-sharing-schedule.js`, `news-editor.js`, `news-round-prompt.js`, `draw-preview.js`, `sync-admin.js`.

Público: `mercado.php`, `importar-elenco.php`, `cadastro.php`, `trocar-senha.php`, `esqueci-senha.php`, `redefinir-senha.php`, `noticia.php`, `includes/public-layout.php`, `api/notifications.php`, `assets/js/market.js`, `market-quick-edit.js`, `club-instant-save.js`, `site-toolbar.js`, `deployment-refresh.js`, `version-history.js`.

Documentação/testes: `PROJECT_CONTEXT.md`, este relatório, `tests/crud-flow-test.py`, `crud-browser-test.js`, `admin-browser-fixture.py`, `admin-loading-test.js`, `club-instant-save-test.js`.


## Complemento v27.5 — data real de jogos finalizados

A ação **Editar data** passou a aparecer para todos os jogos ativos de pontos corridos e mata-mata, inclusive finalizados, W.O. e penalidades. O endpoint conserva autorização, CSRF, validação da data e bloqueio transacional; remove somente a restrição de status e continua atualizando exclusivamente `data_partida`. Não reabre jogos nem altera placar, gols, pênaltis, vencedor ou súmulas.

O histórico do clube (`time.php`), o histórico do jogador (`api/jogador-detalhes.php`), as sequências estatísticas (`includes/statistics.php`) e a ordenação das competições (`api/data.php`) passaram a priorizar a data do confronto de mata-mata. Datas antigas de cadastro permanecem como fallback quando não há data informada. Nenhuma migration é necessária.

A regressão PHP/MySQL/Chromium foi ampliada com jogos finalizados corrigidos para 11/10/2026. Ela compara todas as demais colunas das partidas e os registros reais de gols e súmulas antes/depois, verifica ausência de navegação durante os salvamentos e consulta a linha do tempo do clube, o histórico do jogador, as estatísticas e a data de última partida da API pública. O teste estatístico também verifica a prioridade da data corrigida.

Arquivos deste complemento: `admin/index.php`, `assets/js/admin-sharing-schedule.js`, `time.php`, `api/jogador-detalhes.php`, `api/data.php`, `includes/statistics.php`, `assets/js/version-history.js`, `PROJECT_CONTEXT.md`, `tests/crud-flow-test.py`, `tests/crud-browser-test.js`, `tests/statistics-test.php` e este relatório. Testes continuam usando banco local isolado; não são alterados jogos reais dos ambientes Railway.
