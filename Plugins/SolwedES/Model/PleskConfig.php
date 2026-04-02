<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Modelo para configuración de servidores Plesk
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Modelo para gestionar la configuración de servidores Plesk
 */
class PleskConfig extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $server_name;

    /** @var string */
    public $server_url;

    /** @var string */
    public $api_token;

    /** @var string rest|xmlrpc */
    public $api_type;

    /** @var bool */
    public $verify_ssl;

    /** @var bool */
    public $activo;

    /** @var string */
    public $connection_status;

    /** @var string */
    public $last_connection_test;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_plesk_config';
    }

    public function clear(): void
    {
        parent::clear();
        $this->api_type = 'rest';
        $this->verify_ssl = true;
        $this->activo = true;
    }

    /**
     * Obtiene la configuración activa de Plesk
     *
     * @return PleskConfig|null
     */
    public static function getActiveConfig(): ?PleskConfig
    {
        $config = new self();
        $where = [new DataBaseWhere('activo', true)];
        $configs = $config->all($where, [], 0, 1);

        return empty($configs) ? null : $configs[0];
    }

    /**
     * Prueba la conexión al servidor Plesk
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $client = new \FacturaScripts\Plugins\SolwedES\Lib\PleskApiClient($this);
            $result = $client->testConnection();

            $this->connection_status = $result ? 'success' : 'failed';
            $this->last_connection_test = date('Y-m-d H:i:s');
            $this->save();

            return $result;
        } catch (\Throwable $e) {
            $this->connection_status = 'failed';
            $this->last_connection_test = date('Y-m-d H:i:s');
            $this->save();
            return false;
        }
    }

    public function test(): bool
    {
        if (empty($this->server_name)) {
            return false;
        }

        if (empty($this->server_url)) {
            return false;
        }

        if (empty($this->api_token)) {
            return false;
        }

        if (!in_array($this->api_type, ['rest', 'xmlrpc'])) {
            return false;
        }

        return parent::test();
    }
}
