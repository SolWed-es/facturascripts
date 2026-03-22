<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Modelo para caché de respuestas API Plesk
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Modelo para gestionar el caché de respuestas de la API Plesk
 */
class PleskCache extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idacceso;

    /** @var string */
    public $cache_key;

    /** @var string JSON */
    public $data;

    /** @var string */
    public $expires_at;

    /** @var string */
    public $created_at;

    /** TTL del caché en minutos */
    const TTL_MINUTES = 10;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_plesk_cache';
    }

    /**
     * Obtiene datos cacheados si existen y no han expirado
     *
     * @param int $idacceso
     * @param string $key
     * @return array|null
     */
    public static function getCached(int $idacceso, string $key): ?array
    {
        $cache = new self();
        $where = [
            new DataBaseWhere('idacceso', $idacceso),
            new DataBaseWhere('cache_key', $key),
            new DataBaseWhere('expires_at', date('Y-m-d H:i:s'), '>')
        ];

        $result = $cache->all($where, [], 0, 1);

        if (empty($result)) {
            return null;
        }

        $decoded = json_decode($result[0]->data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Guarda datos en caché
     *
     * @param int $idacceso
     * @param string $key
     * @param array $data
     * @return bool
     */
    public static function set(int $idacceso, string $key, array $data): bool
    {
        // Buscar si ya existe un registro para actualizar
        $cache = new self();
        $where = [
            new DataBaseWhere('idacceso', $idacceso),
            new DataBaseWhere('cache_key', $key)
        ];
        $existing = $cache->all($where, [], 0, 1);

        if (!empty($existing)) {
            $cache = $existing[0];
        }

        $cache->idacceso = $idacceso;
        $cache->cache_key = $key;
        $cache->data = json_encode($data);
        $cache->expires_at = date('Y-m-d H:i:s', strtotime('+' . self::TTL_MINUTES . ' minutes'));

        return $cache->save();
    }

    /**
     * Invalida caché para un acceso específico
     *
     * @param int $idacceso
     * @return bool
     */
    public static function invalidate(int $idacceso): bool
    {
        $cache = new self();
        $where = [new DataBaseWhere('idacceso', $idacceso)];

        foreach ($cache->all($where) as $item) {
            $item->delete();
        }

        return true;
    }

    /**
     * Limpia entradas expiradas (para ejecutar en cron)
     *
     * @return int Número de registros eliminados
     */
    public static function cleanExpired(): int
    {
        $cache = new self();
        $where = [new DataBaseWhere('expires_at', date('Y-m-d H:i:s'), '<=')];
        $count = 0;

        foreach ($cache->all($where) as $item) {
            if ($item->delete()) {
                $count++;
            }
        }

        return $count;
    }
}
