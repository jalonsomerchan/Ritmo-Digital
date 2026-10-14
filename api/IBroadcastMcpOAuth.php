<?php
declare(strict_types=1);

/** Small, file-backed OAuth 2.1 authorization server for the single-owner MCP. */
final class IBroadcastMcpOAuth
{
    private static function apiBase(): string
    {
        return rtrim((string) (getenv('IBROADCAST_MCP_PUBLIC_URL') ?: 'https://alon.one/api'), '/');
    }

    public static function resource(): string
    {
        return self::apiBase() . '/ibroadcast_mcp/mcp';
    }

    public static function issuer(): string
    {
        return self::apiBase() . '/ibroadcast_mcp/oauth';
    }

    public static function resourceMetadataUrl(): string
    {
        return rtrim((string) (getenv('IBROADCAST_MCP_PUBLIC_ORIGIN') ?: 'https://alon.one'), '/')
            . '/.well-known/oauth-protected-resource/api/ibroadcast_mcp/mcp';
    }

    private static function stateFile(): string
    {
        return getenv('IBROADCAST_MCP_OAUTH_FILE') ?: (sys_get_temp_dir() . '/alon-sonora-ibroadcast-oauth.json');
    }

    private static function withState(callable $callback)
    {
        $file = self::stateFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado OAuth.');
        }
        $handle = fopen($file, 'c+');
        if (!$handle) {
            throw new RuntimeException('No se pudo abrir el almacenamiento privado OAuth.');
        }
        chmod($file, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el almacenamiento OAuth.');
            }
            rewind($handle);
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($decoded) ? $decoded : array();
            $result = $callback($state);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function setupAuthorized(): bool
    {
        $secret = trim((string) (getenv('IBROADCAST_MCP_SETUP_SECRET') ?: getenv('MCP_API_TOKEN')));
        $provided = (string) ($_SERVER['HTTP_X_IBROADCAST_MCP_SETUP_SECRET'] ?? '');
        if ($provided === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strtolower((string) $name) === 'x-ibroadcast-mcp-setup-secret') $provided = (string) $value;
            }
        }
        return $secret !== '' && hash_equals($secret, $provided);
    }

    private static function saveIBroadcastTokens(array $tokens): void
    {
        $file = getenv('IBROADCAST_TOKEN_FILE') ?: (sys_get_temp_dir() . '/alon-sonora-ibroadcast-token.json');
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado de iBroadcast.');
        }
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode(array(
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => (string) $tokens['refresh_token'],
            'expires_at' => time() + (int) ($tokens['expires_in'] ?? 3600),
        ), JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el archivo privado de iBroadcast.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar el vínculo de iBroadcast.');
        }
        chmod($file, 0600);
    }

    private static function ibroadcastHttp(string $url, array $form = array(), bool $get = false): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Activa la extensión cURL de PHP.');
        if ($get && $form) $url .= '?' . http_build_query($form);
        $handle = curl_init($url);
        $options = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false);
        if (!$get) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
            $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/x-www-form-urlencoded');
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $data = json_decode((string) $raw, true);
        if ($raw === false || !is_array($data)) throw new RuntimeException('No se pudo leer la respuesta de iBroadcast: ' . $error);
        if ($status < 200 || $status >= 300) {
            if (isset($data['error'])) return $data;
            throw new RuntimeException((string) ($data['error_description'] ?? $data['message'] ?? 'iBroadcast rechazó la solicitud.'));
        }
        return $data;
    }

    public static function linkStart(): void
    {
        if (!self::setupAuthorized()) { self::json(array('error' => 'unauthorized'), 401); return; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::json(array('error' => 'method_not_allowed'), 405); return; }
        $clientId = trim((string) (getenv('IBROADCAST_CLIENT_ID') ?: 'c34cc19cc2fb11f1b50eb49691aa2236'));
        try {
            $code = self::ibroadcastHttp('https://oauth.ibroadcast.com/device/code', array(
                'client_id' => $clientId, 'scope' => 'user.library:read user.library:write',
            ), true);
            if (empty($code['device_code']) || empty($code['user_code'])) throw new RuntimeException('iBroadcast no devolvió un código de dispositivo.');
            $interval = max(5, (int) ($code['interval'] ?? 5));
            $expires = time() + max(60, (int) ($code['expires_in'] ?? 600));
            self::withState(function (&$state) use ($code, $interval, $expires) {
                $state['ibroadcast_device'] = array('device_code' => $code['device_code'], 'interval' => $interval, 'expires_at' => $expires);
            });
            self::json(array('ok' => true, 'verification_uri' => $code['verification_uri_complete'] ?? $code['verification_uri'] ?? '', 'user_code' => $code['user_code'], 'retry_after' => $interval, 'expires_in' => $expires - time()));
        } catch (Throwable $error) {
            self::json(array('error' => 'ibroadcast_error', 'message' => $error->getMessage()), 502);
        }
    }

    public static function linkStatus(): void
    {
        if (!self::setupAuthorized()) { self::json(array('error' => 'unauthorized'), 401); return; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::json(array('error' => 'method_not_allowed'), 405); return; }
        $device = self::withState(function (&$state) { return $state['ibroadcast_device'] ?? null; });
        if (!is_array($device) || $device['expires_at'] < time()) { self::json(array('error' => 'no_active_device_code'), 400); return; }
        try {
            $token = self::ibroadcastHttp('https://oauth.ibroadcast.com/token', array(
                'grant_type' => 'device_code', 'device_code' => $device['device_code'],
                'client_id' => (string) (getenv('IBROADCAST_CLIENT_ID') ?: 'c34cc19cc2fb11f1b50eb49691aa2236'),
            ));
            if (!empty($token['access_token']) && !empty($token['refresh_token'])) {
                self::saveIBroadcastTokens($token);
                self::withState(function (&$state) { unset($state['ibroadcast_device']); });
                self::json(array('ok' => true, 'linked' => true));
                return;
            }
            $code = (string) ($token['error'] ?? 'authorization_pending');
            if (in_array($code, array('authorization_pending', 'slow_down'), true)) {
                if ($code === 'slow_down') $device['interval'] += 5;
                self::withState(function (&$state) use ($device) { $state['ibroadcast_device'] = $device; });
                self::json(array('ok' => true, 'linked' => false, 'pending' => true, 'retry_after' => (int) $device['interval']));
                return;
            }
            self::json(array('error' => 'ibroadcast_auth_error', 'message' => 'iBroadcast no completó la autorización. Inicia una nueva.'), 400);
        } catch (Throwable $error) {
            self::json(array('error' => 'ibroadcast_error', 'message' => $error->getMessage()), 502);
        }
    }

    public static function protectedResource(): void
    {
        self::json(array(
            'resource' => self::resource(),
            'authorization_servers' => array(self::issuer()),
            'scopes_supported' => array('ibroadcast:read', 'ibroadcast:write'),
            'bearer_methods_supported' => array('header'),
        ));
    }

    public static function metadata(): void
    {
        self::json(array(
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::issuer() . '/authorize',
            'token_endpoint' => self::issuer() . '/token',
            'registration_endpoint' => self::issuer() . '/register',
            'response_types_supported' => array('code'),
            'grant_types_supported' => array('authorization_code', 'refresh_token'),
            'code_challenge_methods_supported' => array('S256'),
            'token_endpoint_auth_methods_supported' => array('none'),
            'scopes_supported' => array('ibroadcast:read', 'ibroadcast:write'),
            'client_id_metadata_document_supported' => false,
            'authorization_response_iss_parameter_supported' => false,
        ));
    }

    private static function validRedirect($uri): bool
    {
        $parts = parse_url((string) $uri);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'chatgpt.com') {
            return false;
        }
        $path = $parts['path'] ?? '';
        return empty($parts['query']) && empty($parts['fragment']) && empty($parts['user']) && empty($parts['port'])
            && ($path === '/connector_platform_oauth_redirect'
            || preg_match('#^/connector/oauth/[A-Za-z0-9._~-]+$#', $path) === 1);
    }

    public static function register(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::json(array('error' => 'invalid_request'), 405);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        $redirects = is_array($input) ? ($input['redirect_uris'] ?? array()) : array();
        if (!is_array($redirects) || !$redirects) {
            self::json(array('error' => 'invalid_client_metadata'), 400);
            return;
        }
        foreach ($redirects as $redirect) {
            if (!self::validRedirect($redirect)) {
                self::json(array('error' => 'invalid_redirect_uri'), 400);
                return;
            }
        }
        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        self::withState(function (&$state) use ($clientId, $input, $redirects) {
            $state['clients'][$clientId] = array(
                'client_name' => (string) ($input['client_name'] ?? 'ChatGPT MCP'),
                'redirect_uris' => array_values($redirects),
                'created_at' => time(),
            );
        });
        self::json(array(
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => (string) ($input['client_name'] ?? 'ChatGPT MCP'),
            'redirect_uris' => array_values($redirects),
            'grant_types' => array('authorization_code', 'refresh_token'),
            'response_types' => array('code'),
            'token_endpoint_auth_method' => 'none',
        ), 201);
    }

    private static function authError(string $message, int $status = 400): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code($status);
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function loginAttempts(string $ip, bool $failed): bool
    {
        $key = hash('sha256', $ip);
        return self::withState(function (&$state) use ($key, $failed) {
            $now = time();
            $attempts = array_values(array_filter($state['login_attempts'][$key] ?? array(), function ($at) use ($now) {
                return (int) $at > $now - 900;
            }));
            if (count($attempts) >= 8) {
                $state['login_attempts'][$key] = $attempts;
                return false;
            }
            if ($failed) $attempts[] = $now;
            $state['login_attempts'][$key] = $attempts;
            return true;
        });
    }

    public static function authorize(): void
    {
        $params = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $clientId = (string) ($params['client_id'] ?? '');
        $redirect = (string) ($params['redirect_uri'] ?? '');
        $scope = trim((string) ($params['scope'] ?? 'ibroadcast:read ibroadcast:write'));
        $stateParam = (string) ($params['state'] ?? '');
        $resource = (string) ($params['resource'] ?? self::resource());
        $challenge = (string) ($params['code_challenge'] ?? '');
        $method = (string) ($params['code_challenge_method'] ?? '');
        $responseType = (string) ($params['response_type'] ?? '');
        $allowedScopes = array('ibroadcast:read', 'ibroadcast:write');
        $scopes = array_values(array_intersect(preg_split('/\s+/', $scope), $allowedScopes));
        $client = self::withState(function (&$saved) use ($clientId) {
            return $saved['clients'][$clientId] ?? null;
        });
        if ($responseType !== 'code' || !is_array($client) || !in_array($redirect, $client['redirect_uris'], true)
            || !self::validRedirect($redirect) || $resource !== self::resource()
            || strlen($challenge) < 43 || $method !== 'S256' || !$scopes) {
            self::authError('Solicitud OAuth no válida. Vuelve a iniciar la conexión.', 400);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $csrf = bin2hex(random_bytes(24));
            setcookie('ib_mcp_oauth_csrf', $csrf, array(
                'expires' => time() + 600, 'path' => '/api/ibroadcast_mcp/oauth',
                'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
            ));
            $hidden = array_merge($params, array('oauth_csrf' => $csrf));
            $html = '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Autorizar iBroadcast</title><style>body{font:16px system-ui;max-width:28rem;margin:12vh auto;padding:0 1rem;color:#202124}label{display:block;margin:1rem 0 .35rem}input{box-sizing:border-box;width:100%;padding:.7rem;border:1px solid #888;border-radius:.4rem}button{margin-top:1.2rem;padding:.75rem 1rem;border:0;border-radius:.4rem;background:#1769aa;color:white;font-weight:600}</style><h1>Conectar iBroadcast con ChatGPT</h1><p>Inicia sesión para permitir que ChatGPT gestione tus playlists.</p><form method="post">';
            foreach ($hidden as $key => $value) {
                if (is_scalar($value)) $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
            }
            $html .= '<label for="user">Usuario</label><input id="user" name="oauth_user" autocomplete="username" required><label for="password">Contraseña</label><input id="password" name="oauth_password" type="password" autocomplete="current-password" required><button type="submit">Autorizar</button></form></html>';
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo $html;
            return;
        }
        $csrf = (string) ($_POST['oauth_csrf'] ?? '');
        if ($csrf === '' || !hash_equals((string) ($_COOKIE['ib_mcp_oauth_csrf'] ?? ''), $csrf)) {
            self::authError('La sesión de autorización ha caducado. Vuelve a empezar.', 400);
            return;
        }
        $expectedUser = (string) (getenv('IBROADCAST_MCP_OAUTH_USER') ?: 'admin');
        $expectedPassword = (string) (getenv('IBROADCAST_MCP_OAUTH_PASSWORD') ?: getenv('MCP_API_TOKEN'));
        if ($expectedUser === '' || $expectedPassword === '') {
            self::authError('Configura MCP_API_TOKEN o IBROADCAST_MCP_OAUTH_USER e IBROADCAST_MCP_OAUTH_PASSWORD en el servidor.', 503);
            return;
        }
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!self::loginAttempts($remoteIp, false)) {
            self::authError('Demasiados intentos. Espera 15 minutos y vuelve a probar.', 429);
            return;
        }
        if (!hash_equals($expectedUser, (string) ($_POST['oauth_user'] ?? ''))
            || !hash_equals($expectedPassword, (string) ($_POST['oauth_password'] ?? ''))) {
            self::loginAttempts($remoteIp, true);
            self::authError('Usuario o contraseña incorrectos.', 401);
            return;
        }
        self::withState(function (&$saved) use ($remoteIp) { unset($saved['login_attempts'][hash('sha256', $remoteIp)]); });
        $code = bin2hex(random_bytes(32));
        self::withState(function (&$saved) use ($code, $clientId, $redirect, $challenge, $resource, $scopes) {
            $saved['codes'][hash('sha256', $code)] = array(
                'client_id' => $clientId, 'redirect_uri' => $redirect,
                'code_challenge' => $challenge, 'resource' => $resource,
                'scope' => implode(' ', $scopes), 'expires_at' => time() + 300,
            );
        });
        setcookie('ib_mcp_oauth_csrf', '', array('expires' => time() - 3600, 'path' => '/api/ibroadcast_mcp/oauth', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
        $parts = parse_url($redirect);
        $query = array();
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query['code'] = $code;
        if ($stateParam !== '') $query['state'] = $stateParam;
        $location = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '') . '?' . http_build_query($query);
        header('Location: ' . $location, true, 302);
    }

    private static function tokenResponse(string $client, string $resource, string $scope): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $exp = time() + 3600;
        $accessHash = hash('sha256', $access);
        $refreshHash = hash('sha256', $refresh);
        self::withState(function (&$state) use ($client, $resource, $scope, $accessHash, $refreshHash, $exp) {
            $state['access_tokens'][$accessHash] = array('client_id' => $client, 'resource' => $resource, 'scope' => $scope, 'expires_at' => $exp);
            $state['refresh_tokens'][$refreshHash] = array('client_id' => $client, 'resource' => $resource, 'scope' => $scope, 'expires_at' => time() + 2592000);
        });
        return array('access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $refresh, 'scope' => $scope);
    }

    public static function token(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(array('error' => 'invalid_request'), 405);
            return;
        }
        $form = $_POST;
        $clientId = (string) ($form['client_id'] ?? '');
        $client = self::withState(function (&$state) use ($clientId) { return $state['clients'][$clientId] ?? null; });
        if (!is_array($client)) {
            self::json(array('error' => 'invalid_client'), 401);
            return;
        }
        $grant = (string) ($form['grant_type'] ?? '');
        $resource = (string) ($form['resource'] ?? self::resource());
        if ($resource !== self::resource()) {
            self::json(array('error' => 'invalid_target'), 400);
            return;
        }
        if ($grant === 'authorization_code') {
            $code = (string) ($form['code'] ?? '');
            $verifier = (string) ($form['code_verifier'] ?? '');
            $redirect = (string) ($form['redirect_uri'] ?? '');
            $record = self::withState(function (&$state) use ($code) {
                $key = hash('sha256', $code);
                $row = $state['codes'][$key] ?? null;
                unset($state['codes'][$key]);
                return $row;
            });
            if (!is_array($record) || $record['expires_at'] < time() || $record['client_id'] !== $clientId
                || $record['redirect_uri'] !== $redirect || $record['resource'] !== $resource
                || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
                || !hash_equals($record['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
                self::json(array('error' => 'invalid_grant'), 400);
                return;
            }
            self::json(self::tokenResponse($clientId, $resource, $record['scope']));
            return;
        }
        if ($grant === 'refresh_token') {
            $refresh = (string) ($form['refresh_token'] ?? '');
            $record = self::withState(function (&$state) use ($refresh) {
                $key = hash('sha256', $refresh);
                $row = $state['refresh_tokens'][$key] ?? null;
                unset($state['refresh_tokens'][$key]);
                return $row;
            });
            if (!is_array($record) || $record['expires_at'] < time() || $record['client_id'] !== $clientId || $record['resource'] !== $resource) {
                self::json(array('error' => 'invalid_grant'), 400);
                return;
            }
            self::json(self::tokenResponse($clientId, $resource, $record['scope']));
            return;
        }
        self::json(array('error' => 'unsupported_grant_type'), 400);
    }

    public static function validate(string $accessToken): ?array
    {
        if ($accessToken === '') return null;
        return self::withState(function (&$state) use ($accessToken) {
            $row = $state['access_tokens'][hash('sha256', $accessToken)] ?? null;
            if (!is_array($row) || $row['expires_at'] < time() || $row['resource'] !== self::resource()) return null;
            return $row;
        });
    }
}
