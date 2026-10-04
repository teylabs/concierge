<?php

// Scope raw environment changes to each test, including failures and random order.
function local_login_preserve_process_environment(): void
{
    beforeEach(function () {
        $this->rawLocalLoginEnvironment = [getenv('APP_ENV'), array_key_exists('APP_ENV', $_SERVER), $_SERVER['APP_ENV'] ?? null];
        local_login_process_environment('testing');
    });

    afterEach(function () {
        [$process, $hasServer, $server] = $this->rawLocalLoginEnvironment;
        putenv($process === false ? 'APP_ENV' : 'APP_ENV='.$process);
        if ($hasServer) {
            $_SERVER['APP_ENV'] = $server;
        } else {
            unset($_SERVER['APP_ENV']);
        }
    });
}

function local_login_process_environment(?string $environment): void
{
    putenv($environment === null ? 'APP_ENV' : 'APP_ENV='.$environment);
    if ($environment === null) {
        unset($_SERVER['APP_ENV']);
    } else {
        $_SERVER['APP_ENV'] = $environment;
    }
}
