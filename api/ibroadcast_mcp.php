<?php
declare(strict_types=1);

/**
 * MCP HTTP API for iBroadcast playlists. Compatible with PHP 7.4.
 * No framework, Composer packages, Node.js or database required.
 */

const IB_API = 'https://api.ibroadcast.com/';
const IB_LIBRARY = 'https://library.ibroadcast.com/';
const IB_OAUTH_TOKEN = 'https://oauth.ibroadcast.com/token';
const APP_CLIENT = 'alonsonora';
const APP_VERSION = '1.0.0';
const APP_DEVICE = 'Alon Sonora MCP';
const APP_USER_AGENT = 'Alon Sonora Playlists MCP/1.0.0';

ini_set('display_errors', '0');
require_once __DIR__ . '/IBroadcastMcpOAuth.php';

final class IBroadcastException extends RuntimeException
{
    public ?bool $authenticated = null;
}

$tokenFile = getenv('IBROADCAST_TOKEN_FILE') ?: (sys_get_temp_dir() . '/alon-sonora-ibroadcast-token.json');
$mcpApiToken = trim((string) (getenv('MCP_API_TOKEN') ?: ''));
$storedTokens = [];
if (is_file($tokenFile)) {
    $decodedTokens = json_decode((string) file_get_contents($tokenFile), true);
    if (is_array($decodedTokens)) {
        $storedTokens = $decodedTokens;
    }
}
$accessToken = trim((string) (($storedTokens['access_token'] ?? '') ?: (getenv('IBROADCAST_ACCESS_TOKEN') ?: '')));
$refreshToken = trim((string) (($storedTokens['refresh_token'] ?? '') ?: (getenv('IBROADCAST_REFRESH_TOKEN') ?: '')));
$clientId = trim((string) (getenv('IBROADCAST_CLIENT_ID') ?: 'c34cc19cc2fb11f1b50eb49691aa2236'));

function writeMessage(array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $json;
}

function mcpError($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function requestBody(array $extra = []): array
{
    return array_merge([
        'client' => APP_CLIENT,
        'version' => APP_VERSION,
        'device_name' => APP_DEVICE,
        'user_agent' => APP_USER_AGENT,
    ], $extra);
}

function httpJson(string $url, array $body, ?string $token = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP necesita la extensión cURL habilitada.');
    }
    $headers = ['Content-Type: application/json', 'User-Agent: ' . APP_USER_AGENT];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('Error de conexión con iBroadcast: ' . $curlError);
    }
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('iBroadcast respondió con un JSON no válido (HTTP ' . $status . ').');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('iBroadcast respondió HTTP ' . $status . '.');
    }
    if (($payload['result'] ?? true) === false) {
        $error = new IBroadcastException((string) ($payload['message'] ?? 'iBroadcast rechazó la solicitud.'));
        if (($payload['authenticated'] ?? null) === false) {
            $error->authenticated = false;
        }
        throw $error;
    }
    return $payload;
}

function saveTokens(string $file, string $access, string $refresh): void
{
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el directorio local para los tokens OAuth.');
    }
    $temporary = $file . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
    if (file_put_contents($temporary, json_encode([
        'access_token' => $access,
        'refresh_token' => $refresh,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('No se pudieron guardar los tokens OAuth renovados.');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo actualizar el archivo local de tokens OAuth.');
    }
    chmod($file, 0600);
}

function refreshAccessToken(): void
{
    global $accessToken, $refreshToken, $clientId, $tokenFile;
    if ($refreshToken === '') {
        throw new RuntimeException('El token iBroadcast venció. Vuelve a vincular la cuenta con /ibroadcast_mcp/link_start.');
    }
    $handle = curl_init(IB_OAUTH_TOKEN);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('No se pudo renovar el token de iBroadcast: ' . $error);
    }
    $payload = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($payload) || empty($payload['access_token'])) {
        throw new RuntimeException('No se pudo renovar el token de iBroadcast; vuelve a autorizar la aplicación.');
    }
    $accessToken = (string) $payload['access_token'];
    $refreshToken = (string) ($payload['refresh_token'] ?? $refreshToken);
    saveTokens($tokenFile, $accessToken, $refreshToken);
}

function ibRequest(string $url, array $body): array
{
    global $accessToken;
    try {
        return httpJson($url, $body, $accessToken);
    } catch (Throwable $error) {
        if (!$error instanceof IBroadcastException || $error->authenticated !== false) {
            throw $error;
        }
        refreshAccessToken();
        return httpJson($url, $body, $accessToken);
    }
}

function getLibrary(): array
{
    $payload = ibRequest(IB_LIBRARY, requestBody(['mode' => 'library']));
    if (!isset($payload['library']) || !is_array($payload['library'])) {
        throw new RuntimeException('iBroadcast no devolvió la biblioteca.');
    }
    return $payload['library'];
}

function collectionEntries(array $collection): array
{
    $items = [];
    foreach ($collection as $id => $values) {
        if ($id !== 'map' && is_array($values) && arrayIsList($values)) {
            $items[] = ['id' => (string) $id, 'values' => $values];
        }
    }
    return $items;
}

function arrayIsList(array $values): bool
{
    $expected = 0;
    foreach ($values as $key => $value) {
        if ($key !== $expected++) {
            return false;
        }
    }
    return true;
}

function containsText(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function field(array $collection, array $values, string $name, $fallback = null)
{
    $index = $collection['map'][$name] ?? null;
    return is_int($index) && array_key_exists($index, $values) ? $values[$index] : $fallback;
}

function trackSummary(array $library, string $id): ?array
{
    $tracks = $library['tracks'] ?? [];
    $values = $tracks[$id] ?? null;
    if (!is_array($values)) {
        return null;
    }
    $artist = $library['artists'][(string) field($tracks, $values, 'artist_id', '')] ?? [];
    $album = $library['albums'][(string) field($tracks, $values, 'album_id', '')] ?? [];
    return [
        'id' => $id,
        'title' => (string) field($tracks, $values, 'title', ''),
        'artist' => is_array($artist) ? (string) field($library['artists'] ?? [], $artist, 'name', '') : '',
        'album' => is_array($album) ? (string) field($library['albums'] ?? [], $album, 'name', '') : '',
        'year' => field($tracks, $values, 'year'),
        'length_seconds' => field($tracks, $values, 'length'),
    ];
}

function playlistSummary(array $library, string $id, array $values): array
{
    $trackIds = field($library['playlists'] ?? [], $values, 'tracks', []);
    if (!is_array($trackIds)) {
        $trackIds = [];
    }
    return [
        'id' => $id,
        'name' => (string) field($library['playlists'] ?? [], $values, 'name', ''),
        'description' => (string) (field($library['playlists'] ?? [], $values, 'description', '') ?? ''),
        'track_ids' => array_map('strval', $trackIds),
        'track_count' => count($trackIds),
    ];
}

function apiPlaylist(array $params): array
{
    return ibRequest(IB_API, requestBody($params));
}

function normalizeText(string $value): string
{
    if (function_exists('iconv')) {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }
    return strtolower($value);
}

function textLength(string $value): int
{
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function requirePlaylist(array $library, string $id): array
{
    $values = $library['playlists'][$id] ?? null;
    if (!is_array($values)) {
        throw new InvalidArgumentException('No se encontró la playlist ' . $id . '.');
    }
    return $values;
}

function requireIds($value, string $name, int $max, bool $allowEmpty = true): array
{
    if (!is_array($value) || count($value) > $max || (!$allowEmpty && count($value) < 1)) {
        throw new InvalidArgumentException($name . ' debe ser una lista de entre ' . ($allowEmpty ? '0' : '1') . ' y ' . $max . ' IDs.');
    }
    foreach ($value as $id) {
        if (!is_string($id) && !is_int($id)) {
            throw new InvalidArgumentException('Todos los IDs deben ser numéricos.');
        }
        if (!preg_match('/^\d+$/', (string) $id)) {
            throw new InvalidArgumentException('Todos los IDs deben ser numéricos.');
        }
    }
    return array_map('strval', $value);
}

function toolDefinitions(): array
{
    $id = ['type' => 'string', 'pattern' => '^\\d+$'];
    $tools = [
        ['name' => 'buscar_canciones', 'description' => 'Busca canciones por título, artista o álbum en la biblioteca de iBroadcast.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['query']]],
        ['name' => 'listar_playlists', 'description' => 'Lista y filtra las playlists de iBroadcast.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]]],
        ['name' => 'consultar_playlist', 'description' => 'Muestra una playlist y sus canciones.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id], 'required' => ['playlist_id']]],
        ['name' => 'crear_playlist', 'description' => 'Crea una playlist privada vacía o con canciones de la biblioteca.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'track_ids' => ['type' => 'array', 'items' => $id, 'maxItems' => 500]], 'required' => ['name']]],
        ['name' => 'editar_playlist', 'description' => 'Cambia el nombre o la descripción de una playlist.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id, 'name' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => ['playlist_id']]],
        ['name' => 'anadir_canciones_playlist', 'description' => 'Añade canciones al final de una playlist.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id, 'track_ids' => ['type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100]], 'required' => ['playlist_id', 'track_ids']]],
        ['name' => 'quitar_canciones_playlist', 'description' => 'Quita todas las apariciones de las canciones indicadas.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id, 'track_ids' => ['type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100]], 'required' => ['playlist_id', 'track_ids']]],
        ['name' => 'reordenar_playlist', 'description' => 'Establece el orden completo de las canciones de una playlist.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id, 'track_ids' => ['type' => 'array', 'items' => $id, 'maxItems' => 1000]], 'required' => ['playlist_id', 'track_ids']]],
        ['name' => 'eliminar_playlist', 'description' => 'Elimina permanentemente una playlist. Pide confirmación al usuario antes de ejecutar.', 'inputSchema' => ['type' => 'object', 'properties' => ['playlist_id' => $id], 'required' => ['playlist_id']]],
    ];
    $readTools = array('buscar_canciones', 'listar_playlists', 'consultar_playlist');
    foreach ($tools as &$tool) {
        $scope = in_array($tool['name'], $readTools, true) ? 'ibroadcast:read' : 'ibroadcast:write';
        $tool['securitySchemes'] = array(array('type' => 'oauth2', 'scopes' => array($scope)));
    }
    unset($tool);
    return $tools;
}

function callTool(string $name, array $args): array
{
    switch ($name) {
        case 'buscar_canciones':
            $query = trim((string) ($args['query'] ?? ''));
            if ($query === '') throw new InvalidArgumentException('query es obligatorio.');
            $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
            $library = getLibrary();
            $needle = normalizeText($query);
            $matches = [];
            foreach (collectionEntries($library['tracks'] ?? []) as $entry) {
                $track = trackSummary($library, $entry['id']);
                if ($track && containsText(normalizeText($track['title'] . ' ' . $track['artist'] . ' ' . $track['album']), $needle)) {
                    $matches[] = $track;
                    if (count($matches) >= $limit) break;
                }
            }
            return ['query' => $query, 'count' => count($matches), 'items' => $matches];

        case 'listar_playlists':
            $library = getLibrary();
            $needle = normalizeText(trim((string) ($args['query'] ?? '')));
            $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            $all = [];
            foreach (collectionEntries($library['playlists'] ?? []) as $entry) {
                $item = playlistSummary($library, $entry['id'], $entry['values']);
                if ($needle === '' || containsText(normalizeText($item['name']), $needle)) $all[] = $item;
            }
            return ['total' => count($all), 'items' => array_slice($all, $offset, $limit)];

        case 'consultar_playlist':
            $library = getLibrary();
            $id = (string) ($args['playlist_id'] ?? '');
            $summary = playlistSummary($library, $id, requirePlaylist($library, $id));
            $summary['tracks'] = array_values(array_filter(array_map(fn($trackId) => trackSummary($library, $trackId), $summary['track_ids'])));
            return $summary;

        case 'crear_playlist':
            $name = trim((string) ($args['name'] ?? ''));
            $description = (string) ($args['description'] ?? '');
            if ($name === '' || textLength($name) > 200 || textLength($description) > 1000) throw new InvalidArgumentException('name es obligatorio (máximo 200 caracteres); description admite hasta 1000.');
            $ids = requireIds($args['track_ids'] ?? [], 'track_ids', 500);
            $library = getLibrary();
            foreach ($ids as $trackId) if (!is_array($library['tracks'][$trackId] ?? null)) throw new InvalidArgumentException('La canción ' . $trackId . ' no está en la biblioteca.');
            $created = apiPlaylist(['mode' => 'createplaylist', 'name' => $name, 'description' => $description, 'make_public' => false, 'tracks' => array_map('intval', $ids)]);
            return ['playlist_id' => (string) ($created['playlist_id'] ?? ''), 'name' => $name, 'track_count' => count($created['tracks'] ?? []), 'created' => true];

        case 'editar_playlist':
            $id = (string) ($args['playlist_id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) throw new InvalidArgumentException('playlist_id debe ser numérico.');
            $library = getLibrary();
            $values = requirePlaylist($library, $id);
            $params = ['mode' => 'updateplaylist', 'playlist_id' => (int) $id];
            if (array_key_exists('name', $args)) {
                $name = trim((string) $args['name']);
                if ($name === '' || textLength($name) > 200) throw new InvalidArgumentException('name debe tener entre 1 y 200 caracteres.');
                $params['name'] = $name;
            }
            if (array_key_exists('description', $args)) {
                $description = (string) $args['description'];
                if (textLength($description) > 1000) throw new InvalidArgumentException('description admite hasta 1000 caracteres.');
                $params['description'] = $description;
            }
            if (count($params) === 2) throw new InvalidArgumentException('Indica name o description para editar.');
            apiPlaylist($params);
            return ['playlist_id' => $id, 'name' => $params['name'] ?? (string) field($library['playlists'], $values, 'name', ''), 'updated' => true];

        case 'anadir_canciones_playlist':
            $id = (string) ($args['playlist_id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) throw new InvalidArgumentException('playlist_id debe ser numérico.');
            $ids = requireIds($args['track_ids'] ?? null, 'track_ids', 100, false);
            $library = getLibrary();
            requirePlaylist($library, $id);
            foreach ($ids as $trackId) if (!is_array($library['tracks'][$trackId] ?? null)) throw new InvalidArgumentException('La canción ' . $trackId . ' no está en la biblioteca.');
            $updated = apiPlaylist(['mode' => 'appendplaylist', 'playlist_id' => (int) $id, 'tracks' => array_map('intval', $ids)]);
            return ['playlist_id' => $id, 'added' => count($ids), 'track_count' => count($updated['tracks'] ?? [])];

        case 'quitar_canciones_playlist':
            $id = (string) ($args['playlist_id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) throw new InvalidArgumentException('playlist_id debe ser numérico.');
            $ids = requireIds($args['track_ids'] ?? null, 'track_ids', 100, false);
            $library = getLibrary();
            $values = requirePlaylist($library, $id);
            $current = field($library['playlists'], $values, 'tracks', []);
            $remove = array_fill_keys($ids, true);
            $next = array_values(array_filter(array_map('strval', is_array($current) ? $current : []), fn($trackId) => !isset($remove[$trackId])));
            $removed = count(is_array($current) ? $current : []) - count($next);
            if ($removed === 0) throw new InvalidArgumentException('Ninguna de las canciones indicadas está en la playlist.');
            apiPlaylist(['mode' => 'updateplaylist', 'playlist_id' => (int) $id, 'tracks' => array_map('intval', $next)]);
            return ['playlist_id' => $id, 'removed' => $removed, 'track_count' => count($next)];

        case 'reordenar_playlist':
            $id = (string) ($args['playlist_id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) throw new InvalidArgumentException('playlist_id debe ser numérico.');
            $ids = requireIds($args['track_ids'] ?? null, 'track_ids', 1000);
            $library = getLibrary();
            requirePlaylist($library, $id);
            foreach ($ids as $trackId) if (!is_array($library['tracks'][$trackId] ?? null)) throw new InvalidArgumentException('La canción ' . $trackId . ' no está en la biblioteca.');
            apiPlaylist(['mode' => 'playlistorder', 'playlist_id' => (int) $id, 'tracks' => array_map('intval', $ids)]);
            return ['playlist_id' => $id, 'track_count' => count($ids), 'reordered' => true];

        case 'eliminar_playlist':
            $id = (string) ($args['playlist_id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) throw new InvalidArgumentException('playlist_id debe ser numérico.');
            requirePlaylist(getLibrary(), $id);
            apiPlaylist(['mode' => 'deleteplaylist', 'playlist_id' => (int) $id]);
            return ['playlist_id' => $id, 'deleted' => true];
    }
    throw new InvalidArgumentException('Herramienta desconocida: ' . $name);
}

function bearerToken(): string
{
    $authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $authorization = (string) $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function sendHttpError(int $status, $id, int $code, string $message): void
{
    http_response_code($status);
    writeMessage(mcpError($id, $code, $message));
}

function serverInfoMeta(): array
{
    return ['io.modelcontextprotocol/serverInfo' => ['name' => 'alon-sonora-playlists', 'version' => APP_VERSION]];
}

function sendMcpResult($id, array $result, bool $modern, string $method): void
{
    if ($modern) {
        $result['resultType'] = 'complete';
        $result['_meta'] = serverInfoMeta();
        if ($method === 'tools/list') {
            $result['ttlMs'] = 300000;
            $result['cacheScope'] = 'private';
        }
    }
    writeMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

function handleHttpRequest(): void
{
    global $accessToken, $mcpApiToken;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        sendHttpError(405, null, -32600, 'Usa POST para enviar mensajes MCP.');
        return;
    }
    $raw = file_get_contents('php://input');
    $message = json_decode((string) $raw, true);
    if (!is_array($message) || json_last_error() !== JSON_ERROR_NONE) {
        sendHttpError(400, null, -32700, 'JSON inválido.');
        return;
    }
    $method = isset($message['method']) ? (string) $message['method'] : '';
    $hasId = array_key_exists('id', $message);
    $id = $hasId ? $message['id'] : null;
    $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];
    $legacyVersions = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];
    $modernVersion = '2026-07-28';
    $supported = array_merge($legacyVersions, [$modernVersion]);
    $requestProtocol = isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']) ? (string) $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] : '';
    $meta = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : [];
    $metaProtocol = isset($meta['io.modelcontextprotocol/protocolVersion']) ? (string) $meta['io.modelcontextprotocol/protocolVersion'] : '';
    $protocolVersion = $requestProtocol !== '' ? $requestProtocol : $metaProtocol;

    if ($method === 'initialize') {
        $requestedVersion = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '';
        $protocolVersion = in_array($requestedVersion, $legacyVersions, true) ? $requestedVersion : '2025-11-25';
    }
    $modern = $protocolVersion === $modernVersion;

    if ($protocolVersion !== '' && !in_array($protocolVersion, $supported, true)) {
        sendHttpError(400, $id, -32022, 'Versión MCP no compatible.');
        return;
    }
    if ($modern) {
        $headerMethod = isset($_SERVER['HTTP_MCP_METHOD']) ? (string) $_SERVER['HTTP_MCP_METHOD'] : '';
        $headerName = isset($_SERVER['HTTP_MCP_NAME']) ? (string) $_SERVER['HTTP_MCP_NAME'] : '';
        $toolName = $method === 'tools/call' && isset($params['name']) ? (string) $params['name'] : '';
        if ($requestProtocol !== $modernVersion || $metaProtocol !== $modernVersion || $headerMethod !== $method || ($toolName !== '' && $headerName !== $toolName)) {
            sendHttpError(400, $id, -32020, 'Los encabezados MCP no coinciden con el mensaje JSON-RPC.');
            return;
        }
    }
    if ($method === 'notifications/initialized' || strpos($method, 'notifications/') === 0) {
        http_response_code(202);
        return;
    }
    if (!$hasId) {
        http_response_code(202);
        return;
    }

    if ($method === 'server/discover' && $modern) {
        sendMcpResult($id, [
            'supportedVersions' => [$modernVersion],
            'capabilities' => ['tools' => (object) []],
            'instructions' => 'Permite buscar y gestionar playlists de iBroadcast. Confirma con el usuario antes de borrar una playlist.',
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
        ], true, $method);
        return;
    }
    if ($method === 'initialize') {
        writeMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => $protocolVersion,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'alon-sonora-playlists', 'version' => APP_VERSION],
        ]]);
        return;
    }
    if ($method === 'ping') {
        sendMcpResult($id, [], $modern, $method);
        return;
    }
    if ($method === 'tools/list') {
        sendMcpResult($id, ['tools' => toolDefinitions()], $modern, $method);
        return;
    }
    if ($method === 'tools/call') {
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        $bearer = bearerToken();
        $scopes = array();
        $validBearer = false;
        if ($mcpApiToken !== '' && hash_equals($mcpApiToken, $bearer)) {
            $scopes = array('ibroadcast:read', 'ibroadcast:write');
            $validBearer = true;
        } else {
            $claims = IBroadcastMcpOAuth::validate($bearer);
            if (is_array($claims)) {
                $scopes = preg_split('/\\s+/', trim((string) ($claims['scope'] ?? '')));
                $validBearer = true;
            }
        }
        $neededScope = in_array($name, array('buscar_canciones', 'listar_playlists', 'consultar_playlist'), true)
            ? 'ibroadcast:read' : 'ibroadcast:write';
        if (!in_array($neededScope, $scopes, true)) {
            $oauthError = $validBearer ? 'insufficient_scope' : 'invalid_token';
            $challenge = 'Bearer resource_metadata="' . IBroadcastMcpOAuth::resourceMetadataUrl()
                . '", error="' . $oauthError . '", error_description="Autoriza tu cuenta para usar las herramientas de iBroadcast."';
            if ($validBearer) $challenge .= ', scope="' . $neededScope . '"';
            header('WWW-Authenticate: ' . $challenge);
            writeMessage(array('jsonrpc' => '2.0', 'id' => $id, 'result' => array(
                'content' => array(array('type' => 'text', 'text' => 'Necesitas autorizar el acceso a iBroadcast.')),
                'isError' => true,
                '_meta' => array('mcp/www_authenticate' => array($challenge)),
            )));
            return;
        }
        try {
            $result = callTool($name, $args);
            sendMcpResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)]],
            ], $modern, $method);
        } catch (Throwable $error) {
            sendMcpResult($id, [
                'content' => [['type' => 'text', 'text' => $error->getMessage()]],
                'isError' => true,
            ], $modern, $method);
        }
        return;
    }
    sendHttpError(200, $id, -32601, 'Método no encontrado: ' . $method);
}

class ibroadcast_mcp
{
    public function mcp(): void { handleHttpRequest(); }
}

class ibroadcast_mcp_oauth
{
    public function authorize(): void { IBroadcastMcpOAuth::authorize(); }
    public function token(): void { IBroadcastMcpOAuth::token(); }
    public function register(): void { IBroadcastMcpOAuth::register(); }
    public function metadata(): void { IBroadcastMcpOAuth::metadata(); }
    public function resource(): void { IBroadcastMcpOAuth::protectedResource(); }
    public function link_start(): void { IBroadcastMcpOAuth::linkStart(); }
    public function link_status(): void { IBroadcastMcpOAuth::linkStatus(); }
}
