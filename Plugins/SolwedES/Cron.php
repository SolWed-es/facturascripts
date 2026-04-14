<?php

/**
 * Plugin SolwedES - Tareas programadas
 *
 * @author Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Plugins\SolwedES\Lib\ClienteServiciosManager;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;
use FacturaScripts\Core\Tools;

class Cron extends CronClass
{

    /**
     * Ejecuta las tareas programadas del plugin
     * Using FacturaScripts 2025 fluent API
     */
    public function run(): void
    {
        // Sincronizar dominios con DonDominio (cada hora)
        $this->job('solwed-sync-domains')
            ->every('1 hour')
            ->run(function () {
                $this->syncDominiosDonDominio();
            });

        // Limpiar tokens SSO expirados (cada hora)
        $this->job('solwed-clean-tokens')
            ->every('1 hour')
            ->run(function () {
                $this->cleanExpiredTokens();
            });

        // Verificar servicios próximos a vencer (diario a las 8:00)
        $this->job('solwed-check-expiring')
            ->everyDayAt(8)
            ->run(function () {
                $this->checkExpiringServices();
            });

        // Sincronizar estado de servicios con Plesk (cada 6 horas)
        $this->job('solwed-sync-plesk')
            ->every('6 hours')
            ->run(function () {
                $this->syncPleskServices();
            });
    }

    /**
     * Sincroniza dominios desde DonDominio API a FacturaScripts
     * - Crea nuevos dominios (con o sin contacto asociado)
     * - Actualiza dominios existentes si hay cambios
     */
    protected function syncDominiosDonDominio(): void
    {
        // Check if auto-sync is enabled
        if (!DonDominioHelper::getSetting('dondominio_auto_sync', true)) {
            return; // Auto-sync disabled
        }

        // Check if configured
        if (!DonDominioHelper::isConfigured()) {
            Tools::log('solwed-cron')->warning('DonDominio API not configured. Go to Settings > DonDominio to configure.');
            return;
        }

        try {
            // List domains using helper
            $result = DonDominioHelper::listDomains();

            if (!$result['success']) {
                Tools::log('solwed-cron')->error('DonDominio API error: ' . ($result['error'] ?? 'Unknown error'));
                return;
            }

            $ddDomains = $result['domains'];

            if (empty($ddDomains)) {
                return; // No domains to sync, exit silently
            }

            // Load contacts for CIF/NIF matching (single query)
            $cifnifIndex = $this->buildCifNifIndex();

            // Load existing domains indexed by dondominio_id (single query)
            $existingIndex = $this->buildExistingDomainsIndex();

            // Process each domain
            $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];

            foreach ($ddDomains as $ddDomain) {
                try {
                    $this->processDomain($ddDomain, $cifnifIndex, $existingIndex, $stats);
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Tools::log('solwed-cron')->error('Error processing domain ' . ($ddDomain['name'] ?? 'unknown') . ': ' . $e->getMessage());
                }
            }

            // Update last sync timestamp
            DonDominioHelper::setSetting('dondominio_last_sync', date('Y-m-d H:i:s'));

            // Update account balance
            $this->updateAccountBalance();

            // Only log if there were changes or errors
            if ($stats['created'] > 0 || $stats['updated'] > 0 || $stats['errors'] > 0) {
                Tools::log('solwed-cron')->notice(sprintf(
                    'Domain sync: %d created, %d updated, %d unchanged, %d errors',
                    $stats['created'],
                    $stats['updated'],
                    $stats['unchanged'],
                    $stats['errors']
                ));
            }
        } catch (\Exception $e) {
            Tools::log('solwed-cron')->error('Domain sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Build index of contacts by normalized CIF/NIF
     * @return array<string, Contacto>
     */
    private function buildCifNifIndex(): array
    {
        $contacto = new Contacto();
        $contacts = $contacto->all([], [], 0, 0); // All contacts

        $index = [];
        foreach ($contacts as $c) {
            if (!empty($c->cifnif)) {
                $normalized = $this->normalizeCifNif($c->cifnif);
                if ($normalized && !isset($index[$normalized])) {
                    $index[$normalized] = $c;
                }
            }
        }

        return $index;
    }

    /**
     * Build index of existing domains by dondominio_id
     * @return array<string, Dominio>
     */
    private function buildExistingDomainsIndex(): array
    {
        $dominio = new Dominio();
        $domains = $dominio->all([], [], 0, 0); // All domains

        $index = [];
        foreach ($domains as $d) {
            if (!empty($d->dondominio_id)) {
                $index[(string)$d->dondominio_id] = $d;
            }
        }

        return $index;
    }

    /**
     * Normalize CIF/NIF for comparison
     */
    private function normalizeCifNif(string $cifnif): string
    {
        return strtoupper(preg_replace('/[\s\-\.]/', '', $cifnif));
    }

    /**
     * Process a single domain from DonDominio
     */
    private function processDomain(
        array $ddDomain,
        array $cifnifIndex,
        array &$existingIndex,
        array &$stats
    ): void {
        $domainId = (string)($ddDomain['domainID'] ?? '');
        if (empty($domainId)) {
            return;
        }

        // Try to find contact by owner CIF/NIF
        $contact = null;
        $ownerCifNif = $ddDomain['contactOwner']['identNumber'] ?? null;
        if ($ownerCifNif) {
            $normalized = $this->normalizeCifNif($ownerCifNif);
            $contact = $cifnifIndex[$normalized] ?? null;
        }

        // Build domain data
        $newData = $this->buildDomainData($ddDomain, $contact);

        // Check if domain exists
        $existing = $existingIndex[$domainId] ?? null;

        if ($existing) {
            // Check if update needed
            $changes = $this->getChanges($existing, $newData);

            if (empty($changes)) {
                $stats['unchanged']++;
                return;
            }

            // Update existing domain
            foreach ($changes as $field => $value) {
                $existing->$field = $value;
            }

            if ($existing->save()) {
                $stats['updated']++;
            } else {
                $stats['errors']++;
            }
        } else {
            // Create new domain
            $dominio = new Dominio();
            foreach ($newData as $field => $value) {
                $dominio->$field = $value;
            }

            if ($dominio->save()) {
                $stats['created']++;
                // Add to index to prevent duplicates in same run
                $existingIndex[$domainId] = $dominio;
            } else {
                $stats['errors']++;
            }
        }
    }

    /**
     * Build domain data array from DonDominio response
     */
    private function buildDomainData(array $ddDomain, ?Contacto $contact): array
    {
        // Parse name and TLD
        $fullName = $ddDomain['name'] ?? '';
        $tld = $ddDomain['tld'] ?? '';

        $lastDot = strrpos($fullName, '.');
        $nombre = $lastDot !== false ? substr($fullName, 0, $lastDot) : $fullName;

        // Build owner info for observations
        $ownerInfo = 'Unknown';
        if (isset($ddDomain['contactOwner'])) {
            $owner = $ddDomain['contactOwner'];
            $ownerInfo = trim(
                ($owner['orgName'] ?? '') . ' ' .
                    ($owner['firstName'] ?? '') . ' ' .
                    ($owner['lastName'] ?? '')
            );
        }

        return [
            'idcontacto' => $contact ? $contact->idcontacto : null,
            'nombre' => strtolower($nombre),
            'tld' => '.' . strtolower($tld),
            'dondominio_id' => $ddDomain['domainID'] ?? null,
            'fecha_expiracion' => $ddDomain['tsExpir'] ?? null,
            'estado' => DonDominioHelper::mapStatus($ddDomain['status'] ?? 'unknown'),
            'gestionado_solwed' => true,
            'privacidad_whois' => false,
            'bloqueado' => false,
            'observaciones' => 'Synced from DonDominio. Owner: ' . $ownerInfo,
        ];
    }

    /**
     * Get changed fields between existing domain and new data
     * Only checks fields that can change over time
     */
    private function getChanges(Dominio $existing, array $newData): array
    {
        $updatableFields = ['fecha_expiracion', 'estado'];
        $changes = [];

        foreach ($updatableFields as $field) {
            $oldVal = $existing->$field ?? '';
            $newVal = $newData[$field] ?? '';

            // Normalize for comparison
            $oldNorm = ($oldVal === null) ? '' : (string)$oldVal;
            $newNorm = ($newVal === null) ? '' : (string)$newVal;

            if ($oldNorm !== $newNorm) {
                $changes[$field] = $newVal;
            }
        }

        return $changes;
    }

    /**
     * Elimina tokens SSO expirados de la base de datos
     */
    protected function cleanExpiredTokens(): void
    {
        // TODO: Implementar cuando exista modelo SSOToken
    }

    /**
     * Verifica servicios próximos a vencer y envía notificaciones
     */
    protected function checkExpiringServices(): void
    {
        $serviciosProximosVencer = ClienteServiciosManager::getServiciosProximosAVencer(7);

        foreach ($serviciosProximosVencer as $servicio) {
            Tools::log('solwed-cron')->info(sprintf(
                'Servicio próximo a vencer: %s (Cliente: %s, Vence: %s)',
                $servicio['nombre'] ?? 'N/A',
                $servicio['codcliente'] ?? 'N/A',
                $servicio['fecha_renovacion'] ?? 'N/A'
            ));
        }
    }

    /**
     * Sincroniza el estado de servicios de hosting con Plesk
     */
    protected function syncPleskServices(): void
    {
        // Placeholder para futura integración con API de Plesk
    }

    /**
     * Actualiza el saldo de la cuenta DonDominio en settings
     */
    protected function updateAccountBalance(): void
    {
        try {
            $result = DonDominioHelper::getAccountBalance();

            if ($result['success'] && $result['balance'] !== null) {
                DonDominioHelper::setSetting('dondominio_account_balance', $result['balance']);
            }
        } catch (\Exception $e) {
            Tools::log('solwed-cron')->warning('Could not fetch DonDominio balance: ' . $e->getMessage());
        }
    }
}
