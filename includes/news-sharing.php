<?php
declare(strict_types=1);

function news_public_base_url(): string
{
    global $config;
    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($base !== '') return $base;
    // Ambiente local sem APP_URL.
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $directory = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\');
    if (preg_match('#/(admin|api)$#', $directory)) $directory = dirname($directory);
    return (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $host . ($directory === '/' ? '' : $directory);
}

function news_discord_instructions(string $link): string
{
    return "\n\nDIVULGAÇÃO NO DISCORD:\nGere também um texto pronto para copiar e postar no Discord, usando somente os fatos e datas da matéria. Siga este modelo editorial, adaptando os blocos aos dados disponíveis:\n🏆 **[MANCHETE EM MAIÚSCULAS]** | @everyone\n\n[Introdução com competição, data e principal acontecimento; números importantes em **negrito**.]\n\n🚀 [Trajetória ou próximos confrontos, com placares e datas comprovados.]\n\n🔥 [Momento decisivo e protagonistas.]\n\n⭐ [Destaques individuais e estatísticas.]\n\n🏆 [Contexto histórico comprovado, quando houver.]\n\n[Uma ou duas perguntas que convidem à leitura, sem inventar fatos.]\n\n🗞️ **Confira a matéria completa:** {$link}\n\nUse parágrafos curtos e até 1.900 caracteres no total. Não use bloco de código e não coloque o link entre < >, para permitir a prévia da capa. Omita blocos sem dados. Nunca reutilize nomes, placares ou títulos do exemplo como fatos. Não confunda data de publicação com a data dos jogos.";
}

function news_discord_prompt(array $article): string
{
    $link = news_public_base_url() . '/noticia.php?id=' . (int)$article['id'];
    $content = html_entity_decode(strip_tags(preg_replace('#</(?:p|h[1-6]|div|li)>#i', "\n", (string)$article['conteudo'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return 'Crie a divulgação desta notícia publicada. ID REAL: ' . (int)$article['id'] . "\nLINK OFICIAL: {$link}\nTÍTULO: {$article['titulo']}\nRESUMO: {$article['resumo']}\nMATÉRIA:\n{$content}" . news_discord_instructions($link);
}
