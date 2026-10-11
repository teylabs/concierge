<?php

// Fixture only: the smallest HTTP and FastCGI clients that can drive a real server through the
// GET-session-CSRF-POST sign-in. Both return the same shape.

/**
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $form
 * @return array{status: int, body: mixed, cookies: array<string, string>}
 */
function local_login_http(int $port, string $method, string $path, array $cookies = [], array $form = []): array
{
    $headers = ['Host: localhost'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: '.local_login_cookie_header($cookies);
    }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $body = file_get_contents('http://127.0.0.1:'.$port.$path, false, stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => http_build_query($form),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 15,
    ]]));

    return local_login_response($http_response_header, (string) $body);
}

/**
 * One FastCGI request to php-fpm, the way a web server forwards it. `$params` are extra FastCGI
 * parameters (a web server's fastcgi_param, or HTTP_* for request headers).
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $form
 * @param  array<string, string>  $params
 * @return array{status: int, body: mixed, cookies: array<string, string>}
 */
function local_login_fastcgi(int $port, string $script, string $method, string $path, array $cookies = [], array $form = [], array $params = []): array
{
    $content = http_build_query($form);
    $params = [
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REQUEST_METHOD' => $method,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/index.php',
        'DOCUMENT_ROOT' => dirname($script),
        'REQUEST_URI' => $path,
        'QUERY_STRING' => '',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
        'CONTENT_TYPE' => $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
        'CONTENT_LENGTH' => (string) strlen($content),
        ...($cookies === [] ? [] : ['HTTP_COOKIE' => local_login_cookie_header($cookies)]),
        ...$params,
    ];

    $record = fn (int $type, string $data) => pack('CCnnCx', 1, $type, 1, strlen($data), 0).$data;
    $length = fn (string $value) => strlen($value) < 128 ? chr(strlen($value)) : pack('N', strlen($value) | 0x80000000);
    $pairs = '';
    foreach ($params as $name => $value) {
        $pairs .= $length($name).$length($value).$name.$value;
    }

    $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $code, $message, 15) ?: throw new RuntimeException($message);
    stream_set_timeout($socket, 15);
    fwrite($socket, $record(1, pack('nCx5', 1, 0)).$record(4, $pairs).$record(4, '').($content === '' ? '' : $record(5, $content)).$record(5, ''));

    $stdout = '';
    while (($header = fread($socket, 8)) !== false && strlen($header) === 8) {
        ['type' => $type, 'length' => $size, 'padding' => $padding] = unpack('Cversion/Ctype/nid/nlength/Cpadding/x', $header);
        $data = $size + $padding > 0 ? stream_get_contents($socket, $size + $padding) : '';
        if ($type === 6) {
            $stdout .= substr($data, 0, $size);
        } elseif ($type === 3) {
            break;
        }
    }
    fclose($socket);

    [$head, $body] = explode("\r\n\r\n", $stdout, 2) + [1 => ''];
    $lines = explode("\r\n", $head);
    $status = 200;
    foreach ($lines as $line) {
        if (preg_match('/^Status: (\d{3})/i', $line, $match)) {
            $status = (int) $match[1];
        }
    }

    return local_login_response(['HTTP/1.1 '.$status, ...$lines], $body);
}

/**
 * @param  list<string>  $headers  status line first
 * @return array{status: int, body: mixed, cookies: array<string, string>}
 */
function local_login_response(array $headers, string $body): array
{
    preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0], $match);
    $cookies = [];
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $cookie)) {
            $cookies[$cookie[1]] = urldecode($cookie[2]);
        }
    }

    return ['status' => (int) $match[1], 'body' => json_decode($body, true), 'cookies' => $cookies];
}

/**
 * @param  array<string, string>  $cookies
 */
function local_login_cookie_header(array $cookies): string
{
    return implode('; ', array_map(fn ($name, $value) => $name.'='.rawurlencode($value), array_keys($cookies), $cookies));
}
