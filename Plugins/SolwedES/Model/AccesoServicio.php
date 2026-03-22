<?php

/**
 * Plugin SolwedES - Gestión de accesos a servicios contratados
 * Modelo de accesos con sistema SSO
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Modelo para gestionar accesos de clientes a sus servicios contratados
 * Incluye sistema de tokens SSO temporales con seguridad HMAC SHA-256
 */
class AccesoServicio extends ModelClass
{
    use ModelTrait;

    /** @var int ID único del acceso */
    public $id;

    /** @var int ID del contacto (cliente) */
    public $idcontacto;

    /** @var int ID del servicio */
    public $idservicio;

    /** @var string URL de acceso al servicio */
    public $url_acceso;

    /** @var string Tipo de acceso: wordpress|facturascripts|plesk|otro */
    public $tipo_acceso;

    /** @var string Usuario para el acceso */
    public $usuario;

    /** @var string Hash BCRYPT de la contraseña */
    public $password_hash;

    /** @var string|null Token SSO temporal */
    public $token_sso;

    /** @var string|null Fecha de expiración del token */
    public $token_expires_at;

    /** @var bool Estado activo/inactivo */
    public $activo;

    /** @var int Máximo de intentos de SSO permitidos */
    public $max_intentos_sso;

    /** @var int Contador de intentos fallidos */
    public $intentos_fallidos;

    /** @var string Último acceso exitoso */
    public $last_access;

    /** @var string Notas adicionales */
    public $notas;

    /** @var string Fecha de creación */
    public $creation_date;

    /** @var string Última actualización */
    public $last_update;

    /** @var string Usuario que creó el registro */
    public $created_by;

    /**
     * Nombre de la tabla principal
     *
     * @return string
     */
    public static function tableName(): string
    {
        return 'solwedes_accesos_servicios';
    }

    /**
     * Nombre de la columna de clave primaria
     *
     * @return string
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Descripción del modelo
     *
     * @return string
     */
    public function primaryDescription(): string
    {
        return "Acceso #" . $this->id . " - " . $this->tipo_acceso;
    }

    /**
     * Resetea los valores por defecto
     */
    public function clear(): void
    {
        parent::clear();
        $this->activo = true;
        $this->max_intentos_sso = 5;
        $this->intentos_fallidos = 0;
        $this->tipo_acceso = 'wordpress';
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
    }

    /**
     * Actualiza la fecha de última modificación antes de guardar
     *
     * @return bool
     */
    protected function saveUpdate(array $values = []): bool
    {
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveUpdate();
    }

    /**
     * Obtiene el acceso de un cliente para un servicio específico
     *
     * @param int $idcontacto ID del contacto
     * @param int $idservicio ID del servicio
     * @return AccesoServicio|null
     */
    public static function getByClienteServicio(int $idcontacto, int $idservicio): ?AccesoServicio
    {
        $acceso = new self();
        $where = [
            new DataBaseWhere('idcontacto', $idcontacto),
            new DataBaseWhere('idservicio', $idservicio),
            new DataBaseWhere('activo', true)
        ];

        $results = $acceso->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Obtiene todos los accesos activos de un cliente
     *
     * @param int $idcontacto ID del contacto
     * @return array
     */
    public static function getAllByCliente(int $idcontacto): array
    {
        $acceso = new self();
        $where = [
            new DataBaseWhere('idcontacto', $idcontacto),
            new DataBaseWhere('activo', true)
        ];

        return $acceso->all($where, ['id' => 'ASC']);
    }

    /**
     * Genera un token SSO temporal seguro con HMAC SHA-256
     * Validez: 15 minutos
     * El token es de un solo uso
     *
     * @return string Token generado
     */
    public function generateSSOToken(): string
    {
        // Generar datos aleatorios
        $randomBytes = bin2hex(random_bytes(32));
        $timestamp = time();
        $expiresAt = $timestamp + (15 * 60); // 15 minutos

        // Crear payload
        $payload = $this->id . '|' . $this->idcontacto . '|' . $timestamp . '|' . $randomBytes;

        // Generar HMAC SHA-256
        $secret = $this->getTokenSecret();
        $hmac = hash_hmac('sha256', $payload, $secret);

        // Token final: payload + hmac
        $token = base64_encode($payload . '|' . $hmac);

        // Guardar en BD
        $this->token_sso = $token;
        $this->token_expires_at = date('Y-m-d H:i:s', $expiresAt);
        $this->save();

        return $token;
    }

    /**
     * Valida un token SSO
     *
     * @param string $token Token a validar
     * @return bool True si es válido
     */
    public function validateSSOToken(string $token): bool
    {
        // Verificar que el token coincida
        if ($this->token_sso !== $token) {
            $this->incrementarIntentosFallidos();
            return false;
        }

        // Verificar expiración
        if ($this->token_expires_at < date('Y-m-d H:i:s')) {
            $this->limpiarToken();
            return false;
        }

        // Decodificar token
        $decoded = base64_decode($token);
        if (!$decoded) {
            $this->incrementarIntentosFallidos();
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            $this->incrementarIntentosFallidos();
            return false;
        }

        // Extraer componentes
        [$id, $idcontacto, $timestamp, $randomBytes, $hmac] = $parts;

        // Verificar que coincidan los IDs
        if ((int)$id !== $this->id || (int)$idcontacto !== $this->idcontacto) {
            $this->incrementarIntentosFallidos();
            return false;
        }

        // Validar HMAC
        $payload = $id . '|' . $idcontacto . '|' . $timestamp . '|' . $randomBytes;
        $secret = $this->getTokenSecret();
        $expectedHmac = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedHmac, $hmac)) {
            $this->incrementarIntentosFallidos();
            return false;
        }

        return true;
    }

    /**
     * Obtiene el secreto para generar tokens
     * Basado en configuración del sistema + datos del acceso
     *
     * @return string
     */
    private function getTokenSecret(): string
    {
        // Usar constante del sistema si existe, sino crear una basada en datos
        $baseSecret = defined('FS_COOKIES_EXPIRE') ? FS_COOKIES_EXPIRE : 'solwed-sso-secret-2025';
        return hash('sha256', $baseSecret . $this->id . $this->idcontacto . $this->url_acceso);
    }

    /**
     * Verifica una contraseña
     *
     * @param string $plainPassword Contraseña en texto plano
     * @return bool True si coincide
     */
    public function verifyPassword(string $plainPassword): bool
    {
        if (empty($this->password_hash)) {
            return false;
        }

        return password_verify($plainPassword, $this->password_hash);
    }

    /**
     * Registra un acceso exitoso
     * Limpia el token usado y resetea intentos fallidos
     *
     * @return void
     */
    public function registrarAcceso(): void
    {
        $this->last_access = date('Y-m-d H:i:s');
        $this->intentos_fallidos = 0;
        $this->limpiarToken();
        $this->save();
    }

    /**
     * Limpia el token SSO (después de usarlo o si expiró)
     *
     * @return void
     */
    public function limpiarToken(): void
    {
        $this->token_sso = null;
        $this->token_expires_at = null;
        $this->save();
    }

    /**
     * Incrementa el contador de intentos fallidos
     * Si supera el máximo, desactiva el acceso
     *
     * @return void
     */
    public function incrementarIntentosFallidos(): void
    {
        $this->intentos_fallidos++;

        if ($this->intentos_fallidos >= $this->max_intentos_sso) {
            $this->activo = false;
        }

        $this->save();
    }

    /**
     * Verifica si el acceso está bloqueado por intentos fallidos
     *
     * @return bool
     */
    public function estaBloqueado(): bool
    {
        return $this->intentos_fallidos >= $this->max_intentos_sso;
    }

    /**
     * Obtiene el acceso por token SSO
     *
     * @param string $token Token SSO
     * @return AccesoServicio|null
     */
    public static function getByToken(string $token): ?AccesoServicio
    {
        $acceso = new self();
        $where = [
            new DataBaseWhere('token_sso', $token)
        ];

        $results = $acceso->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Limpia todos los tokens expirados del sistema
     * Para ejecutar en cron diario
     *
     * @return int Número de tokens limpiados
     */
    public static function cleanExpiredTokens(): int
    {
        $acceso = new self();
        $where = [
            new DataBaseWhere('token_expires_at', date('Y-m-d H:i:s'), '<'),
            new DataBaseWhere('token_sso', null, 'IS NOT')
        ];

        $expired = $acceso->all($where);
        $count = 0;

        foreach ($expired as $item) {
            $item->limpiarToken();
            $count++;
        }

        return $count;
    }

    /**
     * Validaciones antes de guardar
     *
     * @return bool
     */
    public function test(): bool
    {
        // Validar URL
        if (empty($this->url_acceso)) {
            Tools::log()->error('URL de acceso es obligatoria');
            return false;
        }

        // Validar formato de URL
        if (!filter_var($this->url_acceso, FILTER_VALIDATE_URL)) {
            Tools::log()->error('URL de acceso no es válida');
            return false;
        }

        // Forzar HTTPS
        if (strpos($this->url_acceso, 'https://') !== 0) {
            $this->url_acceso = str_replace('http://', 'https://', $this->url_acceso);
        }

        // Validar tipo de acceso
        $tiposValidos = ['wordpress', 'facturascripts', 'plesk', 'otro'];
        if (!in_array($this->tipo_acceso, $tiposValidos)) {
            Tools::log()->error('Tipo de acceso no válido');
            return false;
        }

        // Validar que exista el contacto
        if (empty($this->idcontacto)) {
            Tools::log()->error('Cliente es obligatorio');
            return false;
        }

        // Validar que exista el servicio
        if (empty($this->idservicio)) {
            Tools::log()->error('Servicio es obligatorio');
            return false;
        }

        return parent::test();
    }
}
