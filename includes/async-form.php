<?php
// Small response contract for existing public forms; security stays in each route.
function async_form_request(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax']);
}
function async_form_result(bool $ok, string $message, array $extra = []): void
{
    if (!async_form_request()) return;
    http_response_code($ok ? 200 : 422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>$ok, 'message'=>$message], $extra), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
