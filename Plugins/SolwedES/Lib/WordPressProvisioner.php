<?php
/**
 * Plugin SolwedES - WordPress Hosting Provisioner
 *
 * Orchestrates the complete WordPress hosting provisioning process:
 * 1. Creates Plesk webspace
 * 2. Installs WordPress
 * 3. Generates and stores credentials
 * 4. Sends credentials email to customer
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\PleskConfig;
use FacturaScripts\Dinamic\Model\Contacto;

class WordPressProvisioner
{
    /**
     * Maps portal plan identifiers to Plesk service plan names.
     * Keys can be short names (starter, pro, vps) or full FS service names.
     * Values must match EXACTLY the service plan names in Plesk.
     *
     * To add a new plan:
     * 1. Create the service plan in Plesk (Service Plans → Add Plan)
     * 2. Create the service in FacturaScripts with its Stripe prices
     * 3. Add the mapping here (FS nombre → Plesk plan name)
     */
    private const PLAN_MAPPING = [
        // Short names (legacy)
        'starter' => 'Portal WordPress Starter',
        'pro' => 'WordPress Pro',
        'vps' => 'WordPress VPS',
        // Full FS service names → Plesk plan names
        // Left side = service name in FacturaScripts (servicios.nombre)
        // Right side = EXACT plan name in Plesk (Service Plans)
        'WordPress Starter' => 'Portal WordPress Starter',
        'WordPress Pro' => 'WordPress Pro',
        'WordPress VPS' => 'WordPress VPS',
        // Fallback for unknown plans
        'default' => 'Portal WordPress Starter'
    ];

    /**
     * Provisions a complete WordPress hosting setup
     *
     * @param Suscripcion $suscripcion The service contract
     * @param Contacto $contacto The customer contact
     * @param string $domain Domain name for the hosting
     * @param string $plan Service plan (starter, pro, vps)
     * @return ProvisioningResult
     */
    public static function provision(
        Suscripcion $suscripcion,
        Contacto $contacto,
        string $domain,
        string $plan = 'starter'
    ): ProvisioningResult {
        SolwedLogger::stripe('=== WORDPRESS PROVISIONING START ===');
        SolwedLogger::stripe("DEBUG [PROV-1]: Domain: {$domain}, Plan: {$plan}, Contact: {$contacto->idcontacto}");

        // Update contract to in_progress
        SolwedLogger::stripe('DEBUG [PROV-2]: Setting contract provisioning_status to IN_PROGRESS');
        $suscripcion->provisioning_status = Suscripcion::PROV_IN_PROGRESS;
        $suscripcion->save();

        try {
            // 1. Get Plesk configuration
            SolwedLogger::stripe('DEBUG [PROV-3]: Loading Plesk configuration');
            $pleskConfig = self::getPleskConfig();
            if (!$pleskConfig) {
                SolwedLogger::stripe('DEBUG [PROV-ERROR]: No Plesk configuration found in database');
                return ProvisioningResult::failure('Plesk not configured');
            }
            SolwedLogger::stripe('DEBUG [PROV-3a]: Plesk config loaded - Server: ' . ($pleskConfig->server_url ?? 'N/A'));

            $plesk = new PleskApiClient($pleskConfig);

            // 2. Test Plesk connection
            SolwedLogger::stripe('DEBUG [PROV-4]: Testing Plesk connection');
            if (!$plesk->testConnection()) {
                SolwedLogger::stripe('DEBUG [PROV-ERROR]: Plesk connection test FAILED');
                return ProvisioningResult::failure('Cannot connect to Plesk server');
            }
            SolwedLogger::stripe('DEBUG [PROV-4a]: Plesk connection test PASSED');

            // 3. Generate credentials
            SolwedLogger::stripe('DEBUG [PROV-5]: Generating credentials');
            $credentials = self::generateCredentials($contacto, $domain);
            SolwedLogger::stripe('DEBUG [PROV-5a]: Credentials generated:');
            SolwedLogger::stripe('  - Username: ' . $credentials['username']);
            SolwedLogger::stripe('  - FTP User: ' . $credentials['ftp_user']);
            SolwedLogger::stripe('  - Password length: ' . strlen($credentials['password']));

            // 4. Create webspace
            $pleskPlan = self::getPleskPlan($plan);
            SolwedLogger::stripe('DEBUG [PROV-6]: Creating webspace');
            SolwedLogger::stripe("  - Domain: {$domain}");
            SolwedLogger::stripe("  - Plesk Plan: {$pleskPlan}");
            $webspaceResult = $plesk->createWebspace($domain, [
                'plan' => $pleskPlan,
                'ftp_user' => $credentials['ftp_user'],
                'ftp_password' => $credentials['ftp_password']
            ]);
            SolwedLogger::stripe('DEBUG [PROV-6a]: Webspace result: ' . json_encode($webspaceResult));

            if (!$webspaceResult['success']) {
                SolwedLogger::stripe('DEBUG [PROV-ERROR]: Failed to create webspace');
                return ProvisioningResult::failure(
                    'Failed to create webspace: ' . ($webspaceResult['error'] ?? 'Unknown error'),
                    ['step' => 'webspace']
                );
            }

            SolwedLogger::stripe('DEBUG [PROV-6b]: Webspace created successfully - ID: ' . ($webspaceResult['webspace_id'] ?? 'N/A'));

            // 5. Install WordPress
            SolwedLogger::stripe('DEBUG [PROV-7]: Installing WordPress');
            SolwedLogger::stripe("  - Admin user: {$credentials['username']}");
            SolwedLogger::stripe("  - Admin email: {$contacto->email}");
            $wpResult = $plesk->installWordPress($domain, [
                'admin_user' => $credentials['username'],
                'admin_password' => $credentials['password'],
                'admin_email' => $contacto->email,
                'site_title' => $domain
            ]);
            SolwedLogger::stripe('DEBUG [PROV-7a]: WordPress install result: ' . json_encode($wpResult));

            if (!$wpResult['success']) {
                SolwedLogger::stripe('DEBUG [PROV-ERROR]: Failed to install WordPress');
                return ProvisioningResult::failure(
                    'Failed to install WordPress: ' . ($wpResult['error'] ?? 'Unknown error'),
                    ['step' => 'wordpress', 'webspace_id' => $webspaceResult['webspace_id'] ?? null]
                );
            }

            SolwedLogger::stripe('DEBUG [PROV-7b]: WordPress installed at: ' . ($wpResult['admin_url'] ?? 'N/A'));

            // 6. Send credentials email
            SolwedLogger::stripe('DEBUG [PROV-8]: Sending credentials email to: ' . $contacto->email);
            $emailSent = self::sendCredentialsEmail($contacto, $domain, $credentials, $wpResult['admin_url']);
            SolwedLogger::stripe('DEBUG [PROV-8a]: Email sent: ' . ($emailSent ? 'YES' : 'NO'));

            // 7. Build success result
            SolwedLogger::stripe('DEBUG [PROV-9]: Building success result');
            $result = ProvisioningResult::success([
                'admin_url' => $wpResult['admin_url'],
                'username' => $credentials['username'],
                'password' => $credentials['password'],
                'ftp_user' => $credentials['ftp_user'],
                'ftp_password' => $credentials['ftp_password'],
                'metadata' => [
                    'domain' => $domain,
                    'plan' => $plan,
                    'plesk_plan' => $pleskPlan,
                    'webspace_id' => $webspaceResult['webspace_id'] ?? null,
                    'email_sent' => $emailSent
                ]
            ]);

            SolwedLogger::stripe('=== WORDPRESS PROVISIONING COMPLETE ===');
            SolwedLogger::stripe('DEBUG [PROV-10]: SUCCESS - All steps completed');

            return $result;

        } catch (\Throwable $e) {
            SolwedLogger::error('DEBUG [PROV-EXCEPTION]: ' . $e->getMessage());
            SolwedLogger::error('DEBUG [PROV-EXCEPTION]: File: ' . $e->getFile() . ':' . $e->getLine());
            SolwedLogger::error('DEBUG [PROV-EXCEPTION]: Trace: ' . $e->getTraceAsString());

            return ProvisioningResult::failure(
                'Provisioning exception: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * Generates secure WordPress admin credentials
     *
     * @param Contacto $contacto Customer contact
     * @param string $domain Domain name
     * @return array ['username' => string, 'password' => string, 'ftp_user' => string, 'ftp_password' => string]
     */
    private static function generateCredentials(Contacto $contacto, string $domain): array
    {
        // Generate username from email prefix or domain
        $emailParts = explode('@', $contacto->email);
        $baseName = preg_replace('/[^a-zA-Z0-9]/', '', $emailParts[0]);

        // Ensure username is valid (3-20 chars, alphanumeric)
        $username = strtolower(substr($baseName, 0, 12));
        if (strlen($username) < 3) {
            $domainParts = explode('.', $domain);
            $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $domainParts[0]));
        }

        // Add random suffix for uniqueness
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
        $username = $username . '_' . $suffix;

        // Generate secure passwords
        $password = self::generateSecurePassword(16);
        $ftpPassword = self::generateSecurePassword(16);

        // Generate FTP username
        $ftpUser = self::generateFtpUsername($domain);

        return [
            'username' => $username,
            'password' => $password,
            'ftp_user' => $ftpUser,
            'ftp_password' => $ftpPassword
        ];
    }

    /**
     * Generates a secure random password
     *
     * @param int $length Password length
     * @return string
     */
    private static function generateSecurePassword(int $length = 16): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $special = '!@#$%^&*';
        $allChars = $lowercase . $uppercase . $digits . $special;

        $password = '';

        // Ensure at least one of each type
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Fill remaining length
        for ($i = 4; $i < $length; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle to randomize position of guaranteed characters
        return str_shuffle($password);
    }

    /**
     * Generates FTP username from domain
     *
     * @param string $domain
     * @return string
     */
    private static function generateFtpUsername(string $domain): string
    {
        $parts = explode('.', $domain);
        $name = preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]);
        $name = strtolower(substr($name, 0, 8));

        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);

        return $name . '_' . $suffix;
    }

    /**
     * Maps service plan to Plesk service plan name
     *
     * @param string $plan Service plan (starter, pro, vps)
     * @return string Plesk service plan name
     */
    private static function getPleskPlan(string $plan): string
    {
        return self::PLAN_MAPPING[$plan] ?? self::PLAN_MAPPING['default'];
    }

    /**
     * Gets the active Plesk configuration
     *
     * @return PleskConfig|null
     */
    private static function getPleskConfig(): ?PleskConfig
    {
        $config = new PleskConfig();
        $configs = $config->all([], [], 0, 1);

        if (empty($configs)) {
            SolwedLogger::error('No Plesk configuration found');
            return null;
        }

        return $configs[0];
    }

    /**
     * Sends credentials email to customer
     *
     * @param Contacto $contacto Customer contact
     * @param string $domain Domain name
     * @param array $credentials Generated credentials
     * @param string|null $adminUrl WordPress admin URL
     * @return bool
     */
    private static function sendCredentialsEmail(
        Contacto $contacto,
        string $domain,
        array $credentials,
        ?string $adminUrl
    ): bool {
        if (empty($contacto->email)) {
            SolwedLogger::error('Cannot send credentials email: contact has no email');
            return false;
        }

        $adminUrl = $adminUrl ?? "https://{$domain}/wp-admin/";

        return EmailManager::sendWordPressCredentials(
            $contacto,
            $domain,
            $adminUrl,
            $credentials['username'],
            $credentials['password'],
            $credentials['ftp_user'],
            $credentials['ftp_password']
        );
    }
}
