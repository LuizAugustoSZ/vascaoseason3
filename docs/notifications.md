# Notificações

O worker inicia junto ao container e confere os eventos a cada minuto. A primeira execução estabelece o estado inicial sem enviar notícias antigas. Os avisos de mercado seguem a janela individual do clube. Preferências de e-mail são opt-in; o sino funciona independentemente delas.

## Gmail

Configure nas variáveis do serviço de homologação:

- `APP_URL`: URL HTTPS da homologação.
- `SMTP_USERNAME`: `dreambotjornal@gmail.com`
- `SMTP_PASSWORD`: senha de app do Google (nunca a senha da conta).
- `NOTIFICATION_FROM`: `dreambotjornal@gmail.com`

O envio usa SMTP Gmail com TLS na porta 587 via PHPMailer 7.1.1, distribuído com a licença em `vendor/phpmailer/LICENSE`. Sem credenciais, nenhum e-mail é enviado. Mensagens antigas expiram após 23 horas. Tentativas cujo resultado SMTP for incerto ficam com estado `uncertain`, sem repetição automática, para evitar duplicação.

Se preferir Resend, configure `RESEND_API_KEY` e um `NOTIFICATION_FROM` de domínio verificado. Gmail tem prioridade quando `SMTP_PASSWORD` estiver configurada.

Verificação manual: ativar preferências numa conta de teste; publicar uma notícia nova; aguardar um minuto; conferir sino e caixa de entrada; marcar como lida; recarregar; desativar preferências; publicar outra notícia e conferir que aparece somente no sino. Não publicar notícias de teste sem autorização.

Execução única: `php bin/notifications-worker.php --once`.
