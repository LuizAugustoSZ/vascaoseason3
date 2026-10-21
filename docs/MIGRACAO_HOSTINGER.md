# Migração Railway → Hostinger

Auditoria iniciada em 20/10/2026 (America/Sao_Paulo). Este documento registra
evidências, não declara a migração concluída. DNS e Railway não foram alterados.

## Destinos e isolamento

| Ambiente | Domínio final | Branch | Banco |
|---|---|---|---|
| Produção | `vascaoseason3.online` | `main` | Exclusivo de produção |
| Staging | `staging.vascaoseason3.online` | `develop` | Exclusivo de staging |

Site PHP/HTML separado criado no plano existente:
`https://palegreen-dogfish-295486.hostingersite.com` (pré-visualização de produção).
Deploy GitHub da branch `codex/migracao-hostinger` concluído em `public_html`;
é uma implantação técnica de validação, ainda sem o banco real.
Staging separado criado em `https://lavender-dogfish-627181.hostingersite.com`.
O endereço temporário ainda não representa a aplicação integralmente migrada.
Não associar o domínio definitivo por um fluxo que altere DNS automaticamente.
Não tocar em `anajubyfashion.com.br` ou seus arquivos/bancos/configurações.

## Evidências da auditoria

- hPanel autenticado após segundo fator concluído pelo proprietário.
- Plano Unlimited Web Hosting: 50 GB, 3 GB RAM, 2 CPUs, 600 mil inodes,
  60 PHP workers, 120 processos, sites ilimitados. Servidor no Brasil.
- GitHub já autorizado na conta: integração do Vascão conectada e deploy concluído.
  Nenhuma permissão GitHub nova foi solicitada. Branch de migração usada somente
  para preview; main/develop continuam sem alterações por esta tarefa.
- PHP 8.3 já selecionado no novo site. DOM, fileinfo, GD, mbstring, mysqlnd,
  nd_pdo_mysql e PDO habilitados; openssl, iconv, session e zlib integrados.
- Banco exclusivo `u665200586_vascao_prod` e usuário de mesmo nome criados pelo
  proprietário. Credencial local protegida por DPAPI fora do workspace/Git.
- Cron e integração final main/develop ainda não foram configurados.
- Railway `vibrant-embrace`, projeto `93b276e6-e5cb-4f7f-8bb8-0b6ebb43dca7`.
- Production `222916da-7d3d-4b95-8d39-58abe0998d08`: web e MySQL offline,
  confirmado pelo conector e pelo painel. Nenhuma reinicialização realizada.
- Proprietário autorizou reativação temporária somente do MySQL. Tentativa de
  redeploy do build `06299374-ff8b-418f-badd-62165e54eb8f` recusada:
  “Deploys have been paused temporarily”; o conector também informou ausência de
  build reutilizável. Painel confirmou “Limited Access” e a mesma pausa.
  Nenhuma reativação efetivada, alteração de plano, limite ou restauração realizada.
- Logs do último MySQL registram shutdown completo em 20/10/2026 às 09:12 BRT,
  versão 9.7.2. Preservar compatibilidade com essa versão na recuperação do volume.
- Staging `0367548b-d918-45c6-8079-c54c3a8d3372`: deploy web FAILED em 03/10.
- MySQL usa imagem `mysql:9`; isso não comprova a versão dos arquivos no volume.
  Não iniciar outra versão de MySQL sobre o volume existente.
- Volume `205d5845-db10-4fda-be51-c196ee8b74b6`, `/var/lib/mysql`, 500 MB.
  O inventário devolveu o mesmo ID em ambos os ambientes: isolamento dos dados
  deve ser confirmado antes de qualquer operação de restauração ou escrita.
- Backup Railway existente: “Pre-Security-Patch Backup”, 319 MB, aproximadamente
  um mês. É uma referência de recuperação; não é um dump atual validado.
  Criar novos backups/PITR exige Pro segundo o painel; nenhum upgrade autorizado.
- Conector OAuth retorna nomes de variáveis, sem valores. Não há TCP proxy no MySQL.
- O container inicia correção histórica e worker contínuo. A Hostinger não deve
  executar `docker-entrypoint.sh`, nem repetir a correção histórica automaticamente.
- Imagens de escalação podem existir em `assets/uploads/lineups`; conferir também
  `imagens_escalacao.conteudo`. Demais artes usam dados/base64 no banco.
- PHPMailer está versionado em vendor; e-mail suporta Brevo, Resend e Gmail SMTP.
- Há criação/ajuste de esquema em tempo de execução; a primeira navegação não é
  estritamente read-only. Validar numa cópia, com backup, antes de abrir produção.

## Checklist persistente

- [x] Ler contexto, deploy, Docker, worker e sincronização.
- [x] Confirmar domínios definitivos e branches.
- [x] Preservar código em Git bundle externo ao webroot e verificar integridade.
- [x] Criar branch `codex/migracao-hostinger` de `origin/develop`.
- [x] Auditar Railway e hPanel sem modificar recursos existentes.
- [x] Criar novo site PHP/HTML com domínio temporário.
- [x] Criar site separado de staging com domínio temporário.
- [x] Publicar branch de migração via GitHub no preview de produção.
- [x] Confirmar PHP 8.3 e extensões necessárias no site novo.
- [x] Preparar bloqueio HTTP de arquivos internos e configuração privada externa.
- [x] Adaptar sincronização aos novos hosts, bloqueando importação em produção.
- [x] Preparar flags para impedir envios/worker antes do cutover.
- [ ] Recuperar acesso read-only ao MySQL real e confirmar banco de produção.
- [ ] Exportar e restaurar backup integral numa instância isolada.
- [ ] Preservar arquivos não versionados da origem e comparar hashes.
- [ ] Criar bancos e usuários distintos por ambiente.
- [ ] Validar limites PHP, configuração cron e runtime de staging.
- [ ] Publicar código por GitHub com destinos independentes para main/develop.
- [ ] Criar staging separado com dados sanitizados e envio desativado.
- [ ] Validar importação MySQL/MariaDB, tabelas, dados, índices e AUTO_INCREMENT.
- [ ] Executar testes reais públicos, login, permissões, CRUD assíncrono e uploads.
- [ ] Validar SSL para os domínios finais e ligação ao site sem alterar DNS.
- [ ] Preparar e revisar cutover final com aprovação específica do proprietário.

## Configuração privada

Cada ambiente deve ter seu próprio diretório de site, com `public_html` próprio.
O bootstrap procura `vascao-config.php` no pai de `public_html`, fora do alcance
HTTP. Esse arquivo retorna o mesmo array de `config/config.example.php`; pode
definir variáveis de processo via `putenv()` antes de retornar a configuração.
O fallback `config/config.php` local e as variáveis Railway continuam compatíveis.
Não publicar dumps, segredos, sessões ou logs no webroot. Usar permissões mínimas
que permitam leitura somente pelo usuário PHP. Não usar permissões 777.

Antes do cutover, inclusive em staging:

```text
EMAIL_ENABLED=0
NOTIFICATIONS_WORKER_ENABLED=0
```

Definir APP_URL para a URL efetivamente usada nos testes. Na pré-visualização de
produção, manter SYNC_SOURCE_URL vazio; o domínio final em app.base_url também
bloqueia a importação. No staging temporário, apontar explicitamente para a origem
de produção validada. No staging final, a origem é `https://vascaoseason3.online`.
Manter SYNC_SECRET privado e igual somente entre os ambientes autorizados.
Segredos de transporte não devem ser copiados para staging.

PHP esperado: 8.3, pdo_mysql, GD com JPEG/PNG/WebP, mbstring, DOM, fileinfo,
iconv, openssl, session e zlib. As consultas HTTPS usam streams/allow_url_fopen.
Limites do container: upload 12M, POST 14M, memória 256M, 10 uploads.
Aplicar apenas ao Vascão, sem alterar a configuração global dos demais sites.
Validar rotas PHP existentes, sessões, cookies Secure/SameSite, CSRF e logs.

## Backup e restauração integral

O Git bundle não contém o banco real nem arquivos persistentes da Railway.
Backup local do código: `C:\Users\luiza\.codex\backups\vascaoseason3-20261020\repository-before-hostinger.bundle`.
SHA-256: `F9BB8C032DDCC09B69100B411433D4D78AB761540D529A569FF5C2946F113A82`.
`git bundle verify` confirmou histórico completo.

Para o banco, após recuperar acesso oficial sem criar serviço pago:

1. Confirmar ambiente, nome do schema, versão, engines, tamanho e contagens reais.
2. Usar cliente mysqldump compatível com a origem; o cliente MariaDB do XAMPP não
   deve ser presumido compatível com MySQL 9. Credenciais em arquivo privado,
   sem senha na linha de comando. Exportar um schema, não os usuários do servidor.
3. Exportar estrutura, dados, BLOBs (`--hex-blob`), triggers, rotinas e eventos;
   usar `--single-transaction --quick` para InnoDB e registrar tabelas não InnoDB.
   Nenhuma mudança de esquema concorrente durante o dump. Não ativar eventos
   agendados na cópia. Conferir DEFINER, GTID e collations antes da importação.
4. Guardar original imutável e hash SHA-256 fora do webroot/Git. Se precisar
   converter incompatibilidades, gerar derivado rastreável, preservando original.
5. Restaurar primeiro em banco vazio de validação; nunca sobrescrever Ana Juby
   nem staging/prod existentes. Dumps grandes devem usar SSH/cliente, conforme
   limites reais de importação e pacote MySQL do destino.
6. Comparar todas as tabelas/views, contagens exatas por tabela (não estimativas
   de information_schema), conteúdo ordenado por chave, BLOBs, índices, FKs,
   rotinas, triggers, AUTO_INCREMENT, charset e fusos. Registrar exceções.
7. Conferir contas/hashes sem exibi-los e amostrar campeonatos, partidas, títulos,
   notícias, elencos e históricos por IDs conhecidos. Nenhum seed substitui dados.
8. Sanitizar contas, e-mails, tokens e filas para staging antes da homologação.

## GitHub e manutenção

Fonte: `LuizAugustoSZ/vascaoseason3`. `feature/*` → PR develop → homologação →
PR main → produção. A branch de migração ainda precisa de revisão e testes no
destino. Não apontar produção para essa branch antecipadamente.
Cada integração deve fixar branch e raiz do respectivo site. Nunca usar a raiz
da conta, de outro domínio ou um destino compartilhado entre ambientes.
Não automatizar migrations ou o script histórico no hook de deploy.
Preservar `assets/uploads` e a configuração privada em publicações futuras.
Usar SOURCE_COMMIT para identificar a versão quando não houver variável Railway.

## Cron e e-mail

Na Hostinger usar execução única, nunca loop contínuo:

```sh
/caminho/confirmado/php /home/USUARIO/domains/DOMINIO/public_html/bin/notifications-worker.php --once >> /home/USUARIO/logs/vascao-worker.log 2>&1
```

Periodicidade pretendida: a cada minuto, sujeita ao limite efetivo do plano.
Caminhos são placeholders a substituir somente após verificar SSH/cron.
O worker usa lock local e os locks/índices de idempotência já existentes no banco.
Uma falha em --once retorna status 1 sem imprimir credenciais. Esses locks não
coordenam dois bancos independentes: manter Hostinger desativado enquanto Railway
envia. Não configurar cron ativo ou disparar e-mails reais durante os testes.

## Testes e evidências

- Local PHP 8.1.25: lint dos arquivos alterados e teste de isolamento de sync OK.
  Isso não substitui execução no PHP 8.3 da Hostinger.
- Apache local: config, includes, bin, sql e docs retornaram 403;
  api/version.php e CSS público retornaram 200.
- Hostinger HTTPS: API de versão, CSS, login e landing retornaram 200;
  config, .git/HEAD, bin, SQL e documentação retornaram 403.
- Navegador confirmou renderização do layout v27.5 e conteúdo indisponível
  por ausência do banco real. HTTP 200 da landing não comprova funcionamento.
- Testes de banco real, restore, navegação com dados e CRUD Hostinger: pendentes.
- SSL definitivo, login, notificações, staging e deploy automático: pendentes.

## Cutover e rollback (exigem autorização específica)

Somente após todos os itens de prontidão: registrar DNS atual e destinos,
estabelecer janela e bloquear gravações na origem, gerar dump final, restaurar e
comparar, conferir uploads finais, desligar worker antigo, ativar o novo e mudar
DNS autorizado. Emitir/verificar certificado e HTTPS antes de liberar o público.
Se a associação no hPanel mudar DNS automaticamente, adiar essa ação até aprovação.

Manter Railway e backups disponíveis. Em falha antes de novas gravações, restaurar
DNS anterior e ativar um único worker. Se houver gravações no destino, reconciliar
primeiro esses dados: rollback por simples DNS pode perder alterações. Não excluir
serviços, volumes ou cancelar Railway automaticamente.
