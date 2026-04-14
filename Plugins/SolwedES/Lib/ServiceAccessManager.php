<?php
/**
 * Plugin SolwedES - Gestión de URLs de acceso a servicios
 * Servicio para generar URLs SSO y gestionar accesos
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Model\PleskCache;
use FacturaScripts\Plugins\SolwedES\Lib\BridgeClient;
use FacturaScripts\Core\Tools;

/**
 * Gestor de accesos a servicios con SSO
 */
class ServiceAccessManager
{
    /**
     * Genera la URL de acceso según el tipo de servicio
     * Añade rutas específicas según el tipo (wp-admin, panel, etc.)
     *
     * @param AccesoServicio $acceso
     * @return string URL completa de acceso
     */
    public static function generateAccessUrl(AccesoServicio $acceso): string
    {
        $baseUrl = rtrim($acceso->url_acceso, '/');

        switch ($acceso->tipo_acceso) {
            case 'wordpress':
                return $baseUrl . '/wp-admin';

            case 'facturascripts':
                return $baseUrl;

            case 'plesk':
                return $baseUrl . ':8443';

            default:
                return $baseUrl;
        }
    }

    /**
     * Genera la URL del portal con token SSO
     * El cliente hace clic en esta URL y el portal lo redirige al servicio
     *
     * @param AccesoServicio $acceso
     * @return string URL del portal con token
     */
    public static function generatePortalSSOUrl(AccesoServicio $acceso): string
    {
        // Generar token SSO temporal
        $token = $acceso->generateSSOToken();

        // Construir URL del portal manualmente (compatible con todas las versiones de FS)
        $siteUrl = Tools::settings('default', 'site_url', '');
        $portalUrl = rtrim($siteUrl, '/') . '/PortalCliente';

        return $portalUrl . '?action=ssoServiceAccess&token=' . urlencode($token) . '&idacceso=' . $acceso->id;
    }

    /**
     * Procesa un acceso SSO desde el portal
     * Valida el token y retorna la URL de destino
     *
     * @param string $token Token SSO
     * @param int $idacceso ID del acceso
     * @param int $idcontacto ID del contacto (para validar permisos)
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public static function processSSOAccess(string $token, int $idacceso, int $idcontacto): array
    {
        // Obtener el acceso por token
        $acceso = AccesoServicio::getByToken($token);

        if (!$acceso) {
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('invalid-sso-token')
            ];
        }

        // Validar que el ID coincida
        if ($acceso->id !== $idacceso) {
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('invalid-access-id')
            ];
        }

        // Validar que el contacto sea el dueño
        if ($acceso->idcontacto !== $idcontacto) {
            $acceso->incrementarIntentosFallidos();
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('unauthorized-access')
            ];
        }

        // Validar que esté activo
        if (!$acceso->activo) {
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('access-disabled')
            ];
        }

        // Verificar si está bloqueado por intentos fallidos
        if ($acceso->estaBloqueado()) {
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('access-blocked-too-many-attempts')
            ];
        }

        // Validar el token
        if (!$acceso->validateSSOToken($token)) {
            return [
                'success' => false,
                'url' => null,
                'error' => Tools::lang()->trans('invalid-or-expired-token')
            ];
        }

        // Token válido, registrar acceso exitoso
        $acceso->registrarAcceso();

        // Generar URL de destino
        $destinationUrl = self::generateAccessUrl($acceso);

        return [
            'success' => true,
            'url' => $destinationUrl,
            'error' => null
        ];
    }

    /**
     * Obtiene todas las URLs de acceso de un cliente
     * Retorna array indexado por ID de servicio
     *
     * @param int $idcontacto ID del contacto
     * @return array Array de URLs por servicio
     */
    public static function getClientAccessUrls(int $idcontacto): array
    {
        $accesos = AccesoServicio::getAllByCliente($idcontacto);
        $urls = [];

        foreach ($accesos as $acceso) {
            // Generar URL del portal con token SSO
            $ssoUrl = self::generatePortalSSOUrl($acceso);

            // Obtener información del servicio
            $servicio = new Servicio();
            $servicio->loadFromCode($acceso->idservicio);

            $urls[$acceso->idservicio] = [
                'url' => $ssoUrl,
                'tipo' => $acceso->tipo_acceso,
                'label' => self::getAccessLabel($acceso->tipo_acceso),
                'icon' => self::getAccessIcon($acceso->tipo_acceso),
                'servicio_nombre' => $servicio->nombre ?? 'Servicio',
                'last_access' => $acceso->last_access
            ];
        }

        return $urls;
    }

    /**
     * Obtiene la etiqueta de un tipo de acceso
     *
     * @param string $tipo Tipo de acceso
     * @return string Etiqueta traducida
     */
    public static function getAccessLabel(string $tipo): string
    {
        $labels = [
            'wordpress' => Tools::lang()->trans('access-wordpress'),
            'facturascripts' => Tools::lang()->trans('access-facturascripts'),
            'plesk' => Tools::lang()->trans('access-plesk'),
            'otro' => Tools::lang()->trans('access-service')
        ];

        return $labels[$tipo] ?? Tools::lang()->trans('access-service');
    }

    /**
     * Obtiene el icono de un tipo de acceso
     *
     * @param string $tipo Tipo de acceso
     * @return string Clase CSS del icono
     */
    public static function getAccessIcon(string $tipo): string
    {
        $icons = [
            'wordpress' => 'fa-brands fa-wordpress',
            'facturascripts' => 'fa-solid fa-cash-register',
            'plesk' => 'fa-solid fa-database',
            'otro' => 'fa-solid fa-right-to-bracket'
        ];

        return $icons[$tipo] ?? 'fa-solid fa-right-to-bracket';
    }

    /**
     * Limpia todos los tokens SSO expirados
     * Para ejecutar en cron diario
     *
     * @return int Número de tokens limpiados
     */
    public static function cleanExpiredTokens(): int
    {
        return AccesoServicio::cleanExpiredTokens();
    }

    /**
     * Prueba la conectividad a una URL de servicio
     *
     * @param string $url URL a probar
     * @return array ['reachable' => bool, 'status_code' => int|null, 'error' => string|null]
     */
    public static function testServiceUrl(string $url): array
    {
        try {
            // Intentar hacer una petición HEAD para verificar que responde
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para entornos de desarrollo
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($statusCode >= 200 && $statusCode < 400) {
                return [
                    'reachable' => true,
                    'status_code' => $statusCode,
                    'error' => null
                ];
            }

            return [
                'reachable' => false,
                'status_code' => $statusCode,
                'error' => "HTTP $statusCode"
            ];
        } catch (\Exception $e) {
            return [
                'reachable' => false,
                'status_code' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Genera una contraseña aleatoria segura
     *
     * @param int $length Longitud de la contraseña (por defecto 16)
     * @return string Contraseña generada
     */
    public static function generateSecurePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Obtiene información de servicios Plesk para un acceso específico
     * Utiliza caché con TTL de 10 minutos para reducir carga en la API
     *
     * @param AccesoServicio $acceso
     * @return array Array con dominios, aplicaciones, emails y facturascripts
     */
    public static function getPleskServicesInfo(AccesoServicio $acceso): array
    {
        // Solo procesar si el tipo de acceso es Plesk
        if ($acceso->tipo_acceso !== 'plesk') {
            return [
                'domains' => [],
                'applications' => [],
                'emails' => [],
                'facturascripts' => []
            ];
        }

        // Intentar obtener de caché
        $cacheKey = 'services_info';
        $cached = PleskCache::getCached($acceso->id, $cacheKey);

        if ($cached !== null) {
            Tools::log()->info("[Plesk] Datos obtenidos de caché para acceso #{$acceso->id}");
            return $cached;
        }

        // No hay caché válida, consultar bridge (que lee de Redis/Plesk directamente)
        Tools::log()->info("[Plesk] Consultando bridge para acceso #{$acceso->id}");

        try {
            // Obtener dominios desde bridge (/plesk/sites — lee Redis populado por sync worker)
            $domainsRes = BridgeClient::get('/plesk/sites');
            $domains = ($domainsRes['ok'] ?? false) ? ($domainsRes['data'] ?? []) : [];
            Tools::log()->info("[Plesk] Dominios obtenidos: " . count($domains));

            // Obtener aplicaciones y correos para cada dominio
            $allApplications = [];
            $allEmails = [];

            foreach ($domains as $domain) {
                $domainName = $domain['name'] ?? '';

                if (empty($domainName)) {
                    continue;
                }

                // Aplicaciones (requiere CLI, ejecutado por bridge)
                $appsRes = BridgeClient::post('/plesk/cli', [
                    'path' => '/usr/local/psa/bin/site',
                    'params' => ['--list-apps', '-name', $domainName],
                ]);
                $apps = ($appsRes['ok'] ?? false) ? ($appsRes['data'] ?? []) : [];
                if (is_array($apps)) {
                    foreach ($apps as $app) {
                        if (is_array($app)) {
                            $app['domain'] = $domainName;
                            $allApplications[] = $app;
                        }
                    }
                }

                // Cuentas de correo (bridge lee de Redis)
                $emailsRes = BridgeClient::get("/plesk/sites/{$domainName}/mailboxes");
                $emails = ($emailsRes['ok'] ?? false) ? ($emailsRes['data'] ?? []) : [];
                if (is_array($emails)) {
                    foreach ($emails as $email) {
                        if (is_array($email)) {
                            $email['domain'] = $domainName;
                            $allEmails[] = $email;
                        }
                    }
                }
            }

            // Instalaciones FacturaScripts: filtrar apps por nombre/path que contenga "facturascripts"
            $facturascripts = [];
            foreach ($allApplications as $app) {
                $path = strtolower($app['path'] ?? '');
                $name = strtolower($app['name'] ?? '');
                if (strpos($path, 'facturascripts') !== false || strpos($name, 'facturascripts') !== false) {
                    $facturascripts[] = [
                        'domain' => $app['domain'] ?? '',
                        'path' => $app['path'] ?? '',
                        'version' => $app['version'] ?? 'Unknown',
                        'name' => $app['name'] ?? 'FacturaScripts',
                    ];
                }
            }
            Tools::log()->info("[Plesk] Instancias FacturaScripts encontradas: " . count($facturascripts));

            // Construir resultado
            $result = [
                'domains' => $domains,
                'applications' => $allApplications,
                'emails' => $allEmails,
                'facturascripts' => $facturascripts
            ];

            // Guardar en caché (TTL 10 minutos)
            PleskCache::set($acceso->id, $cacheKey, $result);

            Tools::log()->info(
                "[Plesk] Datos guardados en caché: " .
                count($domains) . " dominios, " .
                count($allApplications) . " aplicaciones, " .
                count($allEmails) . " correos, " .
                count($facturascripts) . " FacturaScripts"
            );

            return $result;

        } catch (\Throwable $e) {
            Tools::log()->error("[Plesk] Error al obtener servicios: " . $e->getMessage());

            // Retornar estructura vacía en caso de error
            return [
                'domains' => [],
                'applications' => [],
                'emails' => [],
                'facturascripts' => []
            ];
        }
    }
}
