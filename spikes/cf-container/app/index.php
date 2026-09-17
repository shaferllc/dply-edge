<?php

header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/db') {
    $url = parse_url((string) getenv('DATABASE_URL'));
    $started = microtime(true);
    try {
        parse_str($url['query'] ?? '', $query);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $url['host'], $url['port'] ?? 5432, ltrim($url['path'] ?? '/postgres', '/'), $query['sslmode'] ?? 'require');
        $pdo = new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), [PDO::ATTR_TIMEOUT => 5]);
        echo json_encode(['ok' => true, 'version' => $pdo->query('select version()')->fetchColumn(), 'ms' => round((microtime(true) - $started) * 1000)]);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'ms' => round((microtime(true) - $started) * 1000)]);
    }
    exit;
}

echo json_encode(['ok' => true, 'php' => PHP_VERSION, 'host' => gethostname(), 'boot' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null]);
