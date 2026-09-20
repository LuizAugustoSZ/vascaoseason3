<?php

declare(strict_types=1);

function app_release_id(): string
{
    $release = trim((string)(getenv('RAILWAY_GIT_COMMIT_SHA') ?: getenv('SOURCE_COMMIT') ?: ''));
    if ($release !== '') return $release;

    return (string)(filemtime(__FILE__) ?: 0);
}
