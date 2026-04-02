<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Model\OAuthToken;

/**
 * OAuth token management API.
 *
 * Endpoints:
 *   POST /ApiOAuth?action=store              — store/update tokens
 *   GET  /ApiOAuth?action=token&provider=X&idcontacto=Y — get valid token (auto-refresh)
 *   GET  /ApiOAuth?action=status&provider=X&idcontacto=Y — connection status
 *   GET  /ApiOAuth?action=connections&idcontacto=Y — list all connections
 *   POST /ApiOAuth?action=revoke&provider=X&idcontacto=Y — revoke tokens
 *   GET  /ApiOAuth?action=authorize&provider=google&idcontacto=Y — redirect to Google consent
 *   GET  /ApiOAuth?action=callback&provider=google — handle Google callback
 */
class ApiOAuth extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API OAuth';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $action = $this->request->get('action', '');

        try {
            switch ($action) {
                case 'store':
                    $this->handleStore();
                    break;
                case 'token':
                    $this->handleGetToken();
                    break;
                case 'status':
                    $this->handleStatus();
                    break;
                case 'connections':
                    $this->handleConnections();
                    break;
                case 'revoke':
                    $this->handleRevoke();
                    break;
                case 'authorize':
                    $this->handleAuthorize();
                    break;
                case 'callback':
                    $this->handleCallback();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            Tools::log('solwed')->error('ApiOAuth error: ' . $e->getMessage());
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Store tokens ────────────────────────────────────────────────

    private function handleStore(): void
    {
        $body = $this->getJsonBody();
        $provider = $body['provider'] ?? '';
        $idcontacto = (int)($body['idcontacto'] ?? $body['userId'] ?? 0);
        $accessToken = $body['accessToken'] ?? $body['access_token'] ?? '';

        if (empty($provider) || $idcontacto <= 0 || empty($accessToken)) {
            $this->jsonResponse(['error' => 'provider, idcontacto, and accessToken required'], 400);
            return;
        }

        $alias = $body['alias'] ?? 'default';

        // Upsert: find existing or create new
        $token = OAuthToken::getByContactoProvider($idcontacto, $provider, $alias) ?? new OAuthToken();
        $token->idcontacto = $idcontacto;
        $token->provider = $provider;
        $token->alias = $alias;
        $token->access_token = $accessToken;

        if (!empty($body['refreshToken'] ?? $body['refresh_token'] ?? '')) {
            $token->refresh_token = $body['refreshToken'] ?? $body['refresh_token'];
        }

        if (!empty($body['expiresAt'] ?? $body['expires_at'] ?? '')) {
            $expiresAt = $body['expiresAt'] ?? $body['expires_at'];
            // Accept epoch ms, epoch s, or ISO string
            if (is_numeric($expiresAt)) {
                $ts = $expiresAt > 1e12 ? $expiresAt / 1000 : $expiresAt;
                $token->expires_at = date('Y-m-d H:i:s', (int)$ts);
            } else {
                $token->expires_at = $expiresAt;
            }
        } elseif (!empty($body['expires_in'])) {
            $token->expires_at = date('Y-m-d H:i:s', time() + (int)$body['expires_in']);
        }

        if (isset($body['scopes'])) {
            $scopes = is_array($body['scopes']) ? $body['scopes'] : [$body['scopes']];
            $token->setScopesArray($scopes);
        }

        if (!empty($body['provider_user_id'])) {
            $token->provider_user_id = $body['provider_user_id'];
        }
        if (!empty($body['provider_email'])) {
            $token->provider_email = $body['provider_email'];
        }
        if (!empty($body['metadata'] ?? $body['extra'] ?? null)) {
            $existing = $token->getMetadataArray();
            $new = $body['metadata'] ?? $body['extra'];
            $token->setMetadataArray(array_merge($existing, is_array($new) ? $new : []));
        }

        if ($token->save()) {
            $this->jsonResponse(['success' => true, 'id' => $token->id]);
        } else {
            $this->jsonResponse(['error' => 'Failed to save token'], 500);
        }
    }

    // ─── Get valid token (auto-refresh) ──────────────────────────────

    private function handleGetToken(): void
    {
        $provider = $this->request->get('provider', '');
        $idcontacto = (int)$this->request->get('idcontacto', 0);
        $alias = $this->request->get('alias', '') ?: null;

        if (empty($provider) || $idcontacto <= 0) {
            $this->jsonResponse(['error' => 'provider and idcontacto required'], 400);
            return;
        }

        $token = OAuthToken::getByContactoProvider($idcontacto, $provider, $alias);
        if (!$token) {
            $this->jsonResponse(['error' => 'No token found'], 404);
            return;
        }

        $accessToken = $token->getValidAccessToken();
        if (!$accessToken) {
            $this->jsonResponse(['error' => 'Token expired and refresh failed'], 401);
            return;
        }

        $this->jsonResponse([
            'accessToken' => $accessToken,
            'expires_at' => $token->expires_at,
            'scopes' => $token->getScopesArray(),
            'metadata' => $token->getMetadataArray(),
            'provider_email' => $token->provider_email,
        ]);
    }

    // ─── Status ──────────────────────────────────────────────────────

    private function handleStatus(): void
    {
        $provider = $this->request->get('provider', '');
        $idcontacto = (int)$this->request->get('idcontacto', 0);
        $alias = $this->request->get('alias', '') ?: null;

        if (empty($provider) || $idcontacto <= 0) {
            $this->jsonResponse(['error' => 'provider and idcontacto required'], 400);
            return;
        }

        // If no alias, return all accounts for this provider
        if (!$alias) {
            $tokens = OAuthToken::getAllByContactoProvider($idcontacto, $provider);
            if (empty($tokens)) {
                $this->jsonResponse(['provider' => $provider, 'connected' => false, 'accounts' => []]);
                return;
            }

            $accounts = [];
            foreach ($tokens as $t) {
                $accounts[] = [
                    'id' => $t->id,
                    'alias' => $t->alias,
                    'status' => $t->isExpired() ? 'expired' : 'active',
                    'provider_email' => $t->provider_email,
                    'scopes' => $t->getScopesArray(),
                    'expires_at' => $t->expires_at,
                ];
            }

            $this->jsonResponse(['provider' => $provider, 'connected' => true, 'accounts' => $accounts]);
            return;
        }

        $token = OAuthToken::getByContactoProvider($idcontacto, $provider, $alias);
        if (!$token) {
            $this->jsonResponse(['provider' => $provider, 'alias' => $alias, 'connected' => false]);
            return;
        }

        $this->jsonResponse([
            'provider' => $provider,
            'alias' => $token->alias,
            'connected' => true,
            'status' => $token->isExpired() ? 'expired' : 'active',
            'hasRefreshToken' => !empty($token->refresh_token),
            'expires_at' => $token->expires_at,
            'scopes' => $token->getScopesArray(),
            'provider_email' => $token->provider_email,
            'last_refreshed' => $token->last_refreshed,
        ]);
    }

    // ─── List all connections ────────────────────────────────────────

    private function handleConnections(): void
    {
        $idcontacto = (int)$this->request->get('idcontacto', 0);
        if ($idcontacto <= 0) {
            $this->jsonResponse(['error' => 'idcontacto required'], 400);
            return;
        }

        $tokens = OAuthToken::getByContacto($idcontacto);
        $connections = [];

        foreach ($tokens as $token) {
            $connections[] = [
                'id' => $token->id,
                'provider' => $token->provider,
                'alias' => $token->alias,
                'connected' => true,
                'status' => $token->isExpired() ? 'expired' : 'active',
                'hasRefreshToken' => !empty($token->refresh_token),
                'expires_at' => $token->expires_at,
                'scopes' => $token->getScopesArray(),
                'provider_email' => $token->provider_email,
                'metadata' => $token->getMetadataArray(),
                'created_at' => $token->creation_date,
            ];
        }

        $this->jsonResponse(['connections' => $connections]);
    }

    // ─── Revoke ──────────────────────────────────────────────────────

    private function handleRevoke(): void
    {
        $body = $this->getJsonBody();
        $provider = $body['provider'] ?? $this->request->get('provider', '');
        $idcontacto = (int)($body['idcontacto'] ?? $this->request->get('idcontacto', 0));
        $alias = $body['alias'] ?? $this->request->get('alias', '') ?: null;

        if (empty($provider) || $idcontacto <= 0) {
            $this->jsonResponse(['error' => 'provider and idcontacto required'], 400);
            return;
        }

        $token = OAuthToken::getByContactoProvider($idcontacto, $provider, $alias);
        if (!$token) {
            $this->jsonResponse(['success' => true, 'message' => 'No token to revoke']);
            return;
        }

        // Try to revoke at provider (best-effort)
        if ($provider === 'google' && !empty($token->access_token)) {
            @file_get_contents('https://oauth2.googleapis.com/revoke?token=' . urlencode($token->access_token));
        }

        $token->delete();
        $this->jsonResponse(['success' => true]);
    }

    // ─── Google OAuth authorize (redirect to consent screen) ─────────

    private function handleAuthorize(): void
    {
        $provider = $this->request->get('provider', '');
        $idcontacto = (int)$this->request->get('idcontacto', 0);
        $returnUrl = $this->request->get('return_url', '');
        $alias = $this->request->get('alias', 'default');

        $configs = $this->getProviderConfig($provider);
        if (!$configs) {
            $this->jsonResponse(['error' => "Provider '$provider' not supported or not configured"], 400);
            return;
        }

        $state = base64_encode(json_encode([
            'provider' => $provider,
            'idcontacto' => $idcontacto,
            'alias' => $alias,
            'return_url' => $returnUrl,
        ]));

        $params = http_build_query([
            'client_id' => $configs['client_id'],
            'redirect_uri' => $configs['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $configs['scopes']),
            'access_type' => $configs['access_type'] ?? 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        $url = $configs['auth_url'] . '?' . $params;

        if ($this->request->get('format', '') === 'json') {
            $this->jsonResponse(['url' => $url]);
        } else {
            header('Location: ' . $url);
            exit;
        }
    }

    /**
     * Returns OAuth config per provider. Null if not configured.
     */
    private function getProviderConfig(string $provider): ?array
    {
        $baseRedirectUri = Tools::settings('google', 'redirect_uri', '');
        // All providers share the same callback endpoint, differentiated by state
        $callbackBase = preg_replace('/provider=\w+/', '', $baseRedirectUri);

        switch ($provider) {
            case 'google':
                $clientId = Tools::settings('google', 'client_id', '');
                $clientSecret = Tools::settings('google', 'client_secret', '');
                if (empty($clientId) || empty($clientSecret)) return null;
                return [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => Tools::settings('google', 'redirect_uri', ''),
                    'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                    'token_url' => 'https://oauth2.googleapis.com/token',
                    'access_type' => 'offline',
                    'scopes' => [
                        'https://www.googleapis.com/auth/userinfo.email',
                        'https://www.googleapis.com/auth/userinfo.profile',
                        'https://www.googleapis.com/auth/calendar',
                        'https://www.googleapis.com/auth/contacts.readonly',
                        'https://www.googleapis.com/auth/tasks',
                        'https://www.googleapis.com/auth/analytics.readonly',
                        'https://www.googleapis.com/auth/webmasters.readonly',
                        'https://www.googleapis.com/auth/business.manage',
                        'https://www.googleapis.com/auth/adwords',
                    ],
                ];

            case 'linkedin':
                $clientId = Tools::settings('linkedin', 'client_id', '');
                $clientSecret = Tools::settings('linkedin', 'client_secret', '');
                if (empty($clientId) || empty($clientSecret)) return null;
                return [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => Tools::settings('linkedin', 'redirect_uri',
                        str_replace('provider=google', 'provider=linkedin', Tools::settings('google', 'redirect_uri', ''))),
                    'auth_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                    'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                    'access_type' => 'offline',
                    'scopes' => ['openid', 'profile', 'email', 'w_member_social', 'r_organization_social', 'w_organization_social'],
                ];

            case 'meta':
            case 'instagram':
                $appId = Tools::settings('meta', 'app_id', '');
                $appSecret = Tools::settings('meta', 'app_secret', '');
                if (empty($appId) || empty($appSecret)) return null;
                return [
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                    'redirect_uri' => Tools::settings('meta', 'redirect_uri',
                        str_replace('provider=google', 'provider=meta', Tools::settings('google', 'redirect_uri', ''))),
                    'auth_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
                    'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                    'access_type' => 'offline',
                    'scopes' => ['email', 'pages_show_list', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
                ];

            default:
                return null;
        }
    }

    // ─── OAuth callback (all providers) ─────────────────────────────

    private function handleCallback(): void
    {
        $code = $this->request->get('code', '');
        $stateRaw = $this->request->get('state', '');
        $error = $this->request->get('error', '');

        if (!empty($error)) {
            $this->jsonResponse(['error' => 'OAuth denied: ' . $error], 400);
            return;
        }

        if (empty($code) || empty($stateRaw)) {
            $this->jsonResponse(['error' => 'Invalid callback (missing code or state)'], 400);
            return;
        }

        $state = json_decode(base64_decode($stateRaw), true) ?? [];
        $provider = $state['provider'] ?? $this->request->get('provider', '');
        $idcontacto = (int)($state['idcontacto'] ?? 0);
        $alias = $state['alias'] ?? 'default';
        $returnUrl = $state['return_url'] ?? '';

        if (empty($provider) || $idcontacto <= 0) {
            $this->jsonResponse(['error' => 'Missing provider or idcontacto in state'], 400);
            return;
        }

        $configs = $this->getProviderConfig($provider);
        if (!$configs) {
            $this->jsonResponse(['error' => "Provider '$provider' not configured"], 500);
            return;
        }

        $clientId = $configs['client_id'];
        $clientSecret = $configs['client_secret'];
        $redirectUri = $configs['redirect_uri'];
        $tokenUrl = $configs['token_url'];

        // Exchange code for tokens
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->jsonResponse(['error' => 'Token exchange failed: ' . ($response ?: 'no response')], 500);
            return;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            $this->jsonResponse(['error' => 'No access_token in response'], 500);
            return;
        }

        // Get user info (provider-specific)
        $userInfo = null;
        if ($provider === 'google') {
            $userInfo = $this->getProviderUserInfo($data['access_token'], 'https://www.googleapis.com/oauth2/v3/userinfo');
        } elseif ($provider === 'linkedin') {
            $userInfo = $this->getProviderUserInfo($data['access_token'], 'https://api.linkedin.com/v2/userinfo');
        } elseif ($provider === 'meta' || $provider === 'instagram') {
            $userInfo = $this->getProviderUserInfo($data['access_token'], 'https://graph.facebook.com/me?fields=id,name,email');
        }

        // Store token
        $token = OAuthToken::getByContactoProvider($idcontacto, $provider, $alias) ?? new OAuthToken();
        $token->idcontacto = $idcontacto;
        $token->provider = $provider;
        $token->alias = $alias;
        $token->access_token = $data['access_token'];
        $token->refresh_token = $data['refresh_token'] ?? $token->refresh_token;
        $token->expires_at = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));

        if (!empty($data['scope'])) {
            $token->setScopesArray(explode(' ', $data['scope']));
        }

        if ($userInfo) {
            $token->provider_user_id = $userInfo['sub'] ?? $userInfo['id'] ?? null;
            $token->provider_email = $userInfo['email'] ?? null;
        }

        if (!$token->save()) {
            $this->jsonResponse(['error' => 'Failed to save tokens'], 500);
            return;
        }

        Tools::log('solwed')->info("{$provider} OAuth connected for contacto {$idcontacto} (alias: {$alias}): " . ($token->provider_email ?? 'unknown'));

        // Redirect to return URL or show success
        if (!empty($returnUrl)) {
            $separator = strpos($returnUrl, '?') !== false ? '&' : '?';
            header('Location: ' . $returnUrl . $separator . 'oauth=success&provider=' . $provider . '&alias=' . urlencode($alias));
            exit;
        }

        $this->jsonResponse([
            'success' => true,
            'provider' => $provider,
            'alias' => $alias,
            'provider_email' => $token->provider_email,
            'expires_at' => $token->expires_at,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function getProviderUserInfo(string $accessToken, string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }
        return json_decode($response, true) ?: null;
    }

    private function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return $this->request->request->all();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
