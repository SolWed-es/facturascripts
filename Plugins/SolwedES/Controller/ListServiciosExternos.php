<?php

/**
 * Plugin SolwedES - Dashboard de servicios externos (Dominios, Emails, Webs, ERPs)
 *
 * Reads cached data from Redis (populated by solwed-bridge sync workers).
 * Shows tabs: Dominios (DonDominio), Emails (Kolab), Webs (Plesk), ERPs (Plesk).
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Response;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;

class ListServiciosExternos extends Controller
{
    /** @var string Active tab */
    public $activeTab = 'dominios';

    /** @var array Domains from DonDominio (dd:domains) */
    public $dominios = [];

    /** @var array Mailboxes from Kolab (kolab:domains + kolab:users:*) */
    public $emails = [];

    /** @var array Web sites from Plesk (plesk:sites) */
    public $webs = [];

    /** @var array ERP/FacturaScripts installations detected in Plesk */
    public $erps = [];

    /** @var array Sync worker status info */
    public $syncStatus = [];

    /** @var string Search query */
    public $query = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'external-services';
        $data['icon'] = 'fa-solid fa-server';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->activeTab = $this->request->get('activetab', 'dominios');
        $this->query = trim($this->request->get('query', ''));

        $this->loadDominios();
        $this->loadEmails();
        $this->loadWebs();
        $this->loadERPs();
        $this->loadSyncStatus();
    }

    private function loadDominios(): void
    {
        $raw = RedisReader::getList('dd:domains');

        $this->dominios = [];
        foreach ($raw as $domain) {
            $name = $domain['name'] ?? $domain['domain'] ?? (is_string($domain) ? $domain : '');
            if (empty($name)) continue;

            // Get detail from Redis
            $detail = RedisReader::get("dd:domain:{$name}") ?? [];

            $entry = [
                'nombre' => $name,
                'estado' => $detail['status'] ?? $domain['status'] ?? 'unknown',
                'expiracion' => $detail['tsExpir'] ?? $domain['tsExpir'] ?? $detail['expiration'] ?? '',
                'creacion' => $detail['tsCreate'] ?? $domain['tsCreate'] ?? '',
                'nameservers' => $detail['nameservers'] ?? [],
                'autorenew' => $detail['autorenew'] ?? $domain['autorenew'] ?? false,
            ];

            if (!empty($this->query) && stripos($entry['nombre'], $this->query) === false) {
                continue;
            }

            $this->dominios[] = $entry;
        }

        // Sort by name
        usort($this->dominios, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
    }

    private function loadEmails(): void
    {
        $kolabDomains = RedisReader::getList('kolab:domains');

        $this->emails = [];
        foreach ($kolabDomains as $domain) {
            $domainName = is_string($domain) ? $domain : ($domain['namespace'] ?? $domain['name'] ?? '');
            if (empty($domainName)) continue;

            $users = RedisReader::get("kolab:users:{$domainName}");
            $domainInfo = RedisReader::get("kolab:domain:{$domainName}");

            $mailboxes = $users['users'] ?? $users ?? [];
            if (!is_array($mailboxes)) continue;

            foreach ($mailboxes as $user) {
                $email = $user['email'] ?? $user['uid'] ?? '';
                if (empty($email)) continue;

                $entry = [
                    'email' => $email,
                    'dominio' => $domainName,
                    'nombre' => $user['cn'] ?? $user['name'] ?? '',
                    'cuota_mb' => $user['quotaMB'] ?? $user['mailquota'] ?? 0,
                    'uso_mb' => $user['usoMB'] ?? 0,
                    'uso_pct' => $user['usoPercentaje'] ?? 0,
                    'estado' => $user['status'] ?? 'active',
                ];

                if (!empty($this->query) && stripos($entry['email'], $this->query) === false
                    && stripos($entry['nombre'], $this->query) === false) {
                    continue;
                }

                $this->emails[] = $entry;
            }
        }

        usort($this->emails, fn($a, $b) => strcasecmp($a['email'], $b['email']));
    }

    private function loadWebs(): void
    {
        $sites = RedisReader::getList('plesk:sites');

        $this->webs = [];
        foreach ($sites as $site) {
            $domain = $site['name'] ?? $site['domain'] ?? (is_string($site) ? $site : '');
            if (empty($domain)) continue;

            $detail = RedisReader::get("plesk:site:{$domain}") ?? $site;
            $ssl = RedisReader::get("plesk:ssl:{$domain}");
            $php = RedisReader::get("plesk:php:{$domain}");

            $entry = [
                'dominio' => $domain,
                'estado' => $detail['status'] ?? $site['status'] ?? 'active',
                'hosting_type' => $detail['hosting_type'] ?? $detail['htype'] ?? 'virtual',
                'ip' => $detail['ip_address'] ?? $detail['ip'] ?? '',
                'ssl_activo' => !empty($ssl),
                'ssl_expira' => $ssl['valid_to'] ?? $ssl['validTo'] ?? '',
                'php_version' => $php['version'] ?? $php['php_version'] ?? '',
                'disco_uso' => $detail['disk_usage'] ?? 0,
            ];

            // Filter out ERP sites (they go in the ERPs tab)
            // A site is an ERP if it has facturascripts in the path or detected apps
            $isErp = stripos($domain, 'erp') !== false || stripos($domain, 'factura') !== false;

            if (!empty($this->query) && stripos($entry['dominio'], $this->query) === false) {
                continue;
            }

            if (!$isErp) {
                $this->webs[] = $entry;
            }
        }

        usort($this->webs, fn($a, $b) => strcasecmp($a['dominio'], $b['dominio']));
    }

    private function loadERPs(): void
    {
        $sites = RedisReader::getList('plesk:sites');

        $this->erps = [];
        foreach ($sites as $site) {
            $domain = $site['name'] ?? $site['domain'] ?? (is_string($site) ? $site : '');
            if (empty($domain)) continue;

            // Detect ERP installations by domain name pattern
            $isErp = stripos($domain, 'erp') !== false || stripos($domain, 'factura') !== false;
            if (!$isErp) continue;

            $detail = RedisReader::get("plesk:site:{$domain}") ?? $site;
            $ssl = RedisReader::get("plesk:ssl:{$domain}");
            $php = RedisReader::get("plesk:php:{$domain}");

            $entry = [
                'dominio' => $domain,
                'estado' => $detail['status'] ?? 'active',
                'ip' => $detail['ip_address'] ?? $detail['ip'] ?? '',
                'ssl_activo' => !empty($ssl),
                'php_version' => $php['version'] ?? $php['php_version'] ?? '',
                'disco_uso' => $detail['disk_usage'] ?? 0,
            ];

            if (!empty($this->query) && stripos($entry['dominio'], $this->query) === false) {
                continue;
            }

            $this->erps[] = $entry;
        }

        usort($this->erps, fn($a, $b) => strcasecmp($a['dominio'], $b['dominio']));
    }

    private function loadSyncStatus(): void
    {
        $this->syncStatus = [
            'dd:domains' => RedisReader::ttl('dd:domains'),
            'kolab:domains' => RedisReader::ttl('kolab:domains'),
            'plesk:sites' => RedisReader::ttl('plesk:sites'),
        ];
    }
}
