<?php
/**
 * Plugin SolwedES - WordPress Hosting Provisioner
 *
 * Thin orchestration layer:
 *   1. Maps the FS service plan to a Plesk plan name
 *   2. Delegates webspace creation + WP install to solwed-bridge
 *   3. Sends credentials email via EmailManager
 *   4. Updates Suscripcion status
 *
 * All Plesk API calls live in solwed-bridge (/provision/wordpress endpoint).
 * This class does NOT talk to Plesk directly.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Dinamic\Model\Contacto;

class WordPressProvisioner
{
    /**
     * Maps portal plan identifiers to Plesk service plan names on server0.
     *
     * Keys can be short names (starter, pro, vps) or full FS service names.
     * Values must match EXACTLY the service plan names in server0 Plesk.
     *
     * server0 available plans (verified 2026-04-11):
     *   - Admin Simple, Default Domain, Unlimited, Default Simple,
     *     Estándar 10Gb, Básico 5Gb, ERP
     *
     * To add a new plan:
     * 1. Create the service plan in server0 Plesk (Service Plans → Add Plan)
     * 2. Create the service in FacturaScripts with its Stripe prices
     * 3. Add the mapping here (FS nombre → Plesk plan name)
     */
    private const PLAN_MAPPING = [
        // Short names (legacy)
        'starter' => 'Básico 5Gb',
        'pro' => 'Estándar 10Gb',
        'vps' => 'Unlimited',
        // Full FS service names → server0 Plesk plan names
        'WordPress Starter' => 'Básico 5Gb',
        'WordPress Pro' => 'Estándar 10Gb',
        'WordPress VPS' => 'Unlimited',
        // Fallback for unknown plans
        'default' => 'Básico 5Gb',
    ];

    /**
     * Provisions a complete WordPress hosting setup.
     *
     * Delegates the Plesk work to solwed-bridge via POST /provision/wordpress,
     * then sends credentials email and updates Suscripcion state.
     *
     * @param Suscripcion $suscripcion The service contract
     * @param Contacto $contacto The customer contact
     * @param string $domain Domain name for the hosting
     * @param string $plan Service plan (starter, pro, vps, or full FS name)
     * @return ProvisioningResult
     */
    public static function provision(
        Suscripcion $suscripcion,
        Contacto $contacto,
        string $domain,
        string $plan = 'starter'
    ): ProvisioningResult {
        SolwedLogger::stripe('=== WORDPRESS PROVISIONING START (via bridge) ===');
        SolwedLogger::stripe("DEBUG [PROV-1]: Domain: {$domain}, Plan: {$plan}, Contact: {$contacto->idcontacto}");

        $suscripcion->provisioning_status = Suscripcion::PROV_IN_PROGRESS;
        $suscripcion->save();

        try {
            $pleskPlan = self::getPleskPlan($plan);
            SolwedLogger::stripe("DEBUG [PROV-2]: Resolved plan: {$plan} → {$pleskPlan}");

            // Call bridge with 120s timeout (WP install can take 60-90s)
            SolwedLogger::stripe('DEBUG [PROV-3]: Calling bridge POST /provision/wordpress');
            $response = BridgeClient::post('/provision/wordpress', [
                'domain' => $domain,
                'plan' => $pleskPlan,
                'contactEmail' => $contacto->email,
                'siteTitle' => $domain,
            ], 120);

            if (!($response['ok'] ?? false)) {
                $error = $response['error'] ?? 'Bridge unreachable';
                SolwedLogger::stripe("DEBUG [PROV-ERROR]: Bridge call failed: {$error}");
                return ProvisioningResult::failure('Bridge error: ' . $error);
            }

            // Bridge returns { success: true, data: ProvisionResult }
            // BridgeClient wraps it in { ok: true, ...decoded }
            // so actual payload is at $response['data']
            $data = $response['data'] ?? [];

            if (!($data['success'] ?? false)) {
                $error = $data['error'] ?? 'Unknown provisioning error';
                $failedStep = $data['failedStep'] ?? 'bridge';
                SolwedLogger::stripe("DEBUG [PROV-ERROR]: Provisioning failed at step {$failedStep}: {$error}");
                return ProvisioningResult::failure(
                    'Provisioning failed: ' . $error,
                    ['step' => $failedStep]
                );
            }

            SolwedLogger::stripe('DEBUG [PROV-4]: Bridge provisioning success');
            SolwedLogger::stripe('  - Admin URL: ' . ($data['adminUrl'] ?? 'N/A'));
            SolwedLogger::stripe('  - Username: ' . ($data['username'] ?? 'N/A'));
            SolwedLogger::stripe('  - Webspace ID: ' . ($data['webspaceId'] ?? 'N/A'));

            // Send credentials email via FS EmailManager
            SolwedLogger::stripe('DEBUG [PROV-5]: Sending credentials email to: ' . $contacto->email);
            $emailSent = EmailManager::sendWordPressCredentials(
                $contacto,
                $domain,
                $data['adminUrl'] ?? '',
                $data['username'] ?? '',
                $data['password'] ?? '',
                $data['ftpUser'] ?? '',
                $data['ftpPassword'] ?? ''
            );
            SolwedLogger::stripe('DEBUG [PROV-5a]: Email sent: ' . ($emailSent ? 'YES' : 'NO'));

            $result = ProvisioningResult::success([
                'admin_url' => $data['adminUrl'] ?? '',
                'username' => $data['username'] ?? '',
                'password' => $data['password'] ?? '',
                'ftp_user' => $data['ftpUser'] ?? '',
                'ftp_password' => $data['ftpPassword'] ?? '',
                'metadata' => [
                    'domain' => $domain,
                    'plan' => $plan,
                    'plesk_plan' => $pleskPlan,
                    'webspace_id' => $data['webspaceId'] ?? null,
                    'email_sent' => $emailSent,
                    'provisioned_via' => 'bridge',
                ],
            ]);

            SolwedLogger::stripe('=== WORDPRESS PROVISIONING COMPLETE ===');
            return $result;
        } catch (\Throwable $e) {
            SolwedLogger::error('DEBUG [PROV-EXCEPTION]: ' . $e->getMessage());
            SolwedLogger::error('DEBUG [PROV-EXCEPTION]: File: ' . $e->getFile() . ':' . $e->getLine());
            return ProvisioningResult::failure(
                'Provisioning exception: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * Maps a service plan to the Plesk service plan name.
     *
     * @param string $plan Service plan (starter, pro, vps, or full FS name)
     * @return string Plesk service plan name
     */
    private static function getPleskPlan(string $plan): string
    {
        return self::PLAN_MAPPING[$plan] ?? self::PLAN_MAPPING['default'];
    }
}
