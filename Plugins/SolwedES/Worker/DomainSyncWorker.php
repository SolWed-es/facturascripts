<?php

/**
 * Plugin SolwedES - Worker for domain sync from DonDominio
 *
 * Syncs domain data from Redis (populated by bridge DonDominioSync)
 * to the FS Dominio model. Runs as background task to avoid blocking cron.
 *
 * Event: solwed.sync.domains
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Worker;

use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Dinamic\Model\Contacto;

class DomainSyncWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        // Read domains from Redis (already synced by bridge)
        $domains = RedisReader::getList('dd:domains');
        if (empty($domains)) {
            Tools::log('solwed')->info('DomainSyncWorker: no domains in Redis cache');
            return $this->done();
        }

        $cifnifIndex = $this->buildCifNifIndex();
        $existingIndex = $this->buildExistingDomainsIndex();
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($domains as $ddDomain) {
            $domainId = (string)($ddDomain['domainID'] ?? '');
            if (empty($domainId)) continue;

            // Get detailed info from Redis
            $name = $ddDomain['name'] ?? '';
            $detail = RedisReader::get("dd:domain:{$name}") ?? $ddDomain;

            // Try to find contact by owner CIF/NIF
            $contact = null;
            $ownerCifNif = $detail['contactOwner']['identNumber'] ?? $ddDomain['contactOwner']['identNumber'] ?? null;
            if ($ownerCifNif) {
                $normalized = strtoupper(preg_replace('/[\s\-\.]/', '', $ownerCifNif));
                $contact = $cifnifIndex[$normalized] ?? null;
            }

            // Parse name and TLD
            $lastDot = strrpos($name, '.');
            $nombre = $lastDot !== false ? strtolower(substr($name, 0, $lastDot)) : strtolower($name);
            $tld = '.' . strtolower($ddDomain['tld'] ?? ($lastDot !== false ? substr($name, $lastDot + 1) : ''));

            $newData = [
                'idcontacto' => $contact ? $contact->idcontacto : null,
                'nombre' => $nombre,
                'tld' => $tld,
                'dondominio_id' => $domainId,
                'fecha_expiracion' => $detail['tsExpir'] ?? $ddDomain['tsExpir'] ?? null,
                'estado' => DonDominioHelper::mapStatus($detail['status'] ?? $ddDomain['status'] ?? 'unknown'),
                'gestionado_solwed' => true,
            ];

            $existing = $existingIndex[$domainId] ?? null;

            if ($existing) {
                $changed = false;
                foreach (['fecha_expiracion', 'estado'] as $field) {
                    if (($existing->$field ?? '') !== ($newData[$field] ?? '')) {
                        $existing->$field = $newData[$field];
                        $changed = true;
                    }
                }

                if ($changed) {
                    $existing->save();
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            } else {
                $dominio = new Dominio();
                foreach ($newData as $field => $value) {
                    $dominio->$field = $value;
                }
                $dominio->privacidad_whois = false;
                $dominio->bloqueado = false;
                if ($dominio->save()) {
                    $stats['created']++;
                    $existingIndex[$domainId] = $dominio;
                }
            }
        }

        DonDominioHelper::setSetting('dondominio_last_sync', date('Y-m-d H:i:s'));

        if ($stats['created'] > 0 || $stats['updated'] > 0) {
            Tools::log('solwed')->notice(sprintf(
                'DomainSyncWorker: %d created, %d updated, %d unchanged',
                $stats['created'], $stats['updated'], $stats['unchanged']
            ));
        }

        return $this->done();
    }

    private function buildCifNifIndex(): array
    {
        $contacto = new Contacto();
        $contacts = $contacto->all([], [], 0, 0);
        $index = [];
        foreach ($contacts as $c) {
            if (!empty($c->cifnif)) {
                $normalized = strtoupper(preg_replace('/[\s\-\.]/', '', $c->cifnif));
                if (!isset($index[$normalized])) {
                    $index[$normalized] = $c;
                }
            }
        }
        return $index;
    }

    private function buildExistingDomainsIndex(): array
    {
        $dominio = new Dominio();
        $domains = $dominio->all([], [], 0, 0);
        $index = [];
        foreach ($domains as $d) {
            if (!empty($d->dondominio_id)) {
                $index[(string)$d->dondominio_id] = $d;
            }
        }
        return $index;
    }
}
