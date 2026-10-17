<?php

test('local development baseline includes minio storage defaults and worker scripts', function () {
    $projectRoot = dirname(__DIR__, 2);
    $environment = file_get_contents($projectRoot.'/.env.example');
    $composer = json_decode(file_get_contents($projectRoot.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($environment)
        ->toContain('APP_NAME="Filtroven"')
        ->toContain('DB_DATABASE=filtroven')
        ->toContain('REDIS_PREFIX=filtroven-local-database-')
        ->toContain('CACHE_PREFIX=filtroven-local-cache-')
        ->toContain('SESSION_COOKIE=filtroven-local-session')
        ->toContain('FILESYSTEM_DISK=s3')
        ->toContain('MINIO_ROOT_USER=minio-laravel13')
        ->toContain('MINIO_ENDPOINT=http://127.0.0.1:9000')
        ->toContain('MINIO_BUCKET=filtroven-local')
        ->toContain('AWS_ENDPOINT="${MINIO_ENDPOINT}"')
        ->toContain('AWS_USE_PATH_STYLE_ENDPOINT=true');

    expect($composer['require'])
        ->toHaveKey('league/flysystem-aws-s3-v3');

    expect($composer['scripts'])
        ->toHaveKey('local:queue')
        ->toHaveKey('local:schedule');
});
