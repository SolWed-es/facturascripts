<?php

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class OAuthToken extends ModelClass
{
    use ModelTrait;

    const PROVIDER_GOOGLE = 'google';
    const PROVIDER_LINKEDIN = 'linkedin';
    const PROVIDER_INSTAGRAM = 'instagram';
    const PROVIDER_META = 'meta';
    const PROVIDER_TWITTER = 'twitter';
    const PROVIDER_CANVA = 'canva';

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var string */
    public $provider;

    /** @var string Alias to distinguish multiple accounts of same provider (e.g. "Ads cuenta 1", "Ads cuenta 2") */
    public $alias;

    /** @var string */
    public $access_token;

    /** @var string|null */
    public $refresh_token;

    /** @var string|null */
    public $expires_at;

    /** @var string|null JSON array of scopes */
    public $scopes;

    /** @var string|null */
    public $provider_user_id;

    /** @var string|null */
    public $provider_email;

    /** @var string|null JSON metadata (GA4 propertyId, SC siteUrl, etc.) */
    public $metadata;

    /** @var string|null */
    public $last_refreshed;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->alias = 'default';
        $this->creation_date = Tools::dateTime();
        $this->last_update = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_oauth_tokens';
    }

    /**
     * Get a specific token by contacto + provider + alias.
     * If alias is null, returns the 'default' one.
     */
    public static function getByContactoProvider(int $idcontacto, string $provider, ?string $alias = null): ?self
    {
        $model = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('provider', $provider),
            Where::column('alias', $alias ?? 'default'),
        ];
        $results = $model->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Get ALL tokens for a contacto + provider (multiple accounts).
     */
    public static function getAllByContactoProvider(int $idcontacto, string $provider): array
    {
        $model = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('provider', $provider),
        ];
        return $model->all($where, ['alias' => 'ASC']);
    }

    /**
     * Get all OAuth connections for a contacto (all providers).
     */
    public static function getByContacto(int $idcontacto): array
    {
        $model = new self();
        $where = [Where::column('idcontacto', $idcontacto)];
        return $model->all($where, ['provider' => 'ASC', 'alias' => 'ASC']);
    }

    public function isExpired(): bool
    {
        if (empty($this->expires_at)) {
            return false;
        }
        // Expired if less than 5 minutes remaining
        return strtotime($this->expires_at) < (time() + 300);
    }

    public function getScopesArray(): array
    {
        if (empty($this->scopes)) {
            return [];
        }
        $decoded = json_decode($this->scopes, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setScopesArray(array $scopes): void
    {
        $this->scopes = json_encode($scopes);
    }

    public function getMetadataArray(): array
    {
        if (empty($this->metadata)) {
            return [];
        }
        $decoded = json_decode($this->metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setMetadataArray(array $data): void
    {
        $this->metadata = json_encode($data);
    }

    /**
     * Refresh the Google access token using the refresh token.
     * Returns true if refreshed successfully.
     */
    public function refreshGoogleToken(): bool
    {
        if (empty($this->refresh_token)) {
            return false;
        }

        $clientId = Tools::settings('google', 'client_id', '');
        $clientSecret = Tools::settings('google', 'client_secret', '');

        if (empty($clientId) || empty($clientSecret)) {
            Tools::log('solwed')->error('Google OAuth not configured (client_id/client_secret missing)');
            return false;
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            Tools::log('solwed')->error('Google token refresh failed: ' . ($response ?: 'no response'));
            return false;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            Tools::log('solwed')->error('Google token refresh: no access_token in response');
            return false;
        }

        $this->access_token = $data['access_token'];
        if (!empty($data['refresh_token'])) {
            $this->refresh_token = $data['refresh_token'];
        }
        $expiresIn = $data['expires_in'] ?? 3600;
        $this->expires_at = date('Y-m-d H:i:s', time() + $expiresIn);
        $this->last_refreshed = Tools::dateTime();
        $this->last_update = Tools::dateTime();

        return $this->save();
    }

    /**
     * Generic OAuth2 refresh for any provider that follows the standard flow.
     */
    public function refreshOAuth2Token(string $tokenUrl, string $clientId, string $clientSecret): bool
    {
        if (empty($this->refresh_token) || empty($clientId) || empty($clientSecret)) {
            return false;
        }

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            Tools::log('solwed')->error("{$this->provider} token refresh failed: " . ($response ?: 'no response'));
            return false;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            return false;
        }

        $this->access_token = $data['access_token'];
        if (!empty($data['refresh_token'])) {
            $this->refresh_token = $data['refresh_token'];
        }
        $this->expires_at = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
        $this->last_refreshed = Tools::dateTime();
        $this->last_update = Tools::dateTime();

        return $this->save();
    }

    /**
     * Get a valid access token, refreshing if needed.
     * Returns null if unable to provide a valid token.
     */
    public function getValidAccessToken(): ?string
    {
        if (!$this->isExpired()) {
            return $this->access_token;
        }

        // Try to refresh based on provider
        $refreshed = false;
        switch ($this->provider) {
            case self::PROVIDER_GOOGLE:
                $refreshed = $this->refreshGoogleToken();
                break;
            case self::PROVIDER_LINKEDIN:
                $refreshed = $this->refreshOAuth2Token(
                    'https://www.linkedin.com/oauth/v2/accessToken',
                    Tools::settings('linkedin', 'client_id', ''),
                    Tools::settings('linkedin', 'client_secret', '')
                );
                break;
            case self::PROVIDER_META:
            case self::PROVIDER_INSTAGRAM:
                $refreshed = $this->refreshOAuth2Token(
                    'https://graph.facebook.com/v19.0/oauth/access_token',
                    Tools::settings('meta', 'app_id', ''),
                    Tools::settings('meta', 'app_secret', '')
                );
                break;
        }

        return $refreshed ? $this->access_token : null;
    }

    public function test(): bool
    {
        if (empty($this->creation_date)) {
            $this->creation_date = Tools::dateTime();
        }
        $this->last_update = Tools::dateTime();

        if (empty($this->idcontacto)) {
            Tools::log()->error('contact-required');
            return false;
        }

        if (empty($this->provider)) {
            Tools::log()->error('provider-required');
            return false;
        }

        if (empty($this->access_token)) {
            Tools::log()->error('access-token-required');
            return false;
        }

        $validProviders = [
            self::PROVIDER_GOOGLE, self::PROVIDER_LINKEDIN, self::PROVIDER_INSTAGRAM,
            self::PROVIDER_META, self::PROVIDER_TWITTER, self::PROVIDER_CANVA,
        ];
        if (!in_array($this->provider, $validProviders)) {
            Tools::log()->error('invalid-provider');
            return false;
        }

        return parent::test();
    }
}
