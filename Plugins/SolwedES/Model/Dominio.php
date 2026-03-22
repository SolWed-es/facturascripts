<?php

/**
 * Plugin SolwedES - Modelo de Dominios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Contacto;

/**
 * Modelo para gestión de dominios de clientes SOLWED
 */
class Dominio extends ModelClass
{
    use ModelTrait;

    // Estados de dominio
    const ESTADO_ACTIVE = 'active';
    const ESTADO_EXPIRED = 'expired';
    const ESTADO_PENDING_TRANSFER = 'pending_transfer';
    const ESTADO_REDEMPTION = 'redemption';
    const ESTADO_INACTIVE = 'inactive';

    // @deprecated - Use idcontrato to link domains to services via ContratServicio
    const USADO_WORDPRESS = 'wordpress';
    const USADO_EMAIL = 'email';
    const USADO_AMBOS = 'wordpress,email';

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var string */
    public $nombre;

    /** @var string */
    public $tld;

    /** @var int|null */
    public $dondominio_id;

    /** @var string */
    public $fecha_registro;

    /** @var string */
    public $fecha_expiracion;

    /** @var string */
    public $estado;

    /** @var bool */
    public $gestionado_solwed;

    /** @var bool */
    public $privacidad_whois;

    /** @var bool */
    public $bloqueado;

    /** @var string|null */
    public $usado_por;

    /** @var int|null */
    public $idcontrato;

    /** @var string|null */
    public $observaciones;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->estado = self::ESTADO_ACTIVE;
        $this->gestionado_solwed = true;
        $this->privacidad_whois = false;
        $this->bloqueado = false;
        $this->fecha_registro = Tools::date();
        $this->creation_date = Tools::dateTime();
        $this->last_update = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_dominios';
    }

    public function getContacto(): Contacto
    {
        $contacto = new Contacto();
        $contacto->load($this->idcontacto);
        return $contacto;
    }

    public function getContrato(): ContratServicio
    {
        $contrato = new ContratServicio();
        $contrato->load($this->idcontrato);
        return $contrato;
    }

    public function hasAutoRenewal(): bool
    {
        if (empty($this->idcontrato)) {
            return false;
        }
        $contrato = $this->getContrato();
        return $contrato->auto_renovar &&
               $contrato->metodo_pago === ContratServicio::METODO_STRIPE &&
               $contrato->isActivo();
    }

    public function getNombreCompleto(): string
    {
        $tld = ltrim($this->tld, '.');

        if (empty($tld)) {
            return $this->nombre;
        }

        return $this->nombre . '.' . $tld;
    }

    public static function parseDomainName(string $fullDomain): array
    {
        $fullDomain = strtolower(trim($fullDomain));

        if (empty($fullDomain) || strpos($fullDomain, '.') === false) {
            return ['nombre' => $fullDomain, 'tld' => ''];
        }

        $multiPartTlds = [
            '.co.uk', '.org.uk', '.me.uk', '.com.es', '.org.es', '.nom.es',
            '.com.ar', '.com.mx', '.com.br', '.co.nz', '.co.za', '.com.au',
            '.net.au', '.org.au', '.co.jp', '.ne.jp', '.or.jp'
        ];

        foreach ($multiPartTlds as $multiTld) {
            if (str_ends_with($fullDomain, $multiTld)) {
                return [
                    'nombre' => substr($fullDomain, 0, -strlen($multiTld)),
                    'tld' => $multiTld
                ];
            }
        }

        $lastDot = strrpos($fullDomain, '.');
        return [
            'nombre' => substr($fullDomain, 0, $lastDot),
            'tld' => substr($fullDomain, $lastDot)
        ];
    }

    public function getDiasHastaExpiracion(): int
    {
        if (empty($this->fecha_expiracion)) {
            return -999;
        }

        $hoy = new \DateTime();
        $expiracion = new \DateTime($this->fecha_expiracion);
        $diff = $hoy->diff($expiracion);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    public function isProximoAExpirar(): bool
    {
        $dias = $this->getDiasHastaExpiracion();
        return $dias > 0 && $dias <= 30;
    }

    public function isExpirado(): bool
    {
        return $this->getDiasHastaExpiracion() <= 0 || $this->estado === self::ESTADO_EXPIRED;
    }

    /**
     * Checks if domain is linked to any active contract
     */
    public function hasActiveContract(): bool
    {
        if (empty($this->idcontrato)) {
            return false;
        }
        $contrato = $this->getContrato();
        return $contrato->id && $contrato->isActivo();
    }

    /**
     * Gets the service linked to this domain via its contract
     */
    public function getServicio(): ?Servicio
    {
        if (empty($this->idcontrato)) {
            return null;
        }
        $contrato = $this->getContrato();
        if ($contrato->id) {
            return $contrato->getServicio();
        }
        return null;
    }

    public static function getByNombre(string $nombreCompleto): ?self
    {
        $nombreCompleto = strtolower(trim($nombreCompleto));

        if (empty($nombreCompleto) || strpos($nombreCompleto, '.') === false) {
            return null;
        }

        $parsed = self::parseDomainName($nombreCompleto);
        $nombre = rtrim($parsed['nombre'], '.');
        $tld = $parsed['tld'];

        $dominio = new self();

        // Try exact match
        $where = [
            Where::column('nombre', $nombre),
            Where::column('tld', $tld)
        ];
        $results = $dominio->all($where, [], 0, 1);

        if (!empty($results)) {
            return $results[0];
        }

        // Try without leading dot in TLD
        $tldWithoutDot = ltrim($tld, '.');
        $where = [
            Where::column('nombre', $nombre),
            Where::column('tld', $tldWithoutDot)
        ];
        $results = $dominio->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    public static function getByNombreCompleto(string $nombreCompleto): ?self
    {
        return self::getByNombre($nombreCompleto);
    }

    public static function getByDonDominioId(int $donDominioId): ?self
    {
        $dominio = new self();
        $where = [Where::column('dondominio_id', $donDominioId)];
        $results = $dominio->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    public static function getActivosByContacto(int $idcontacto): array
    {
        $dominio = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('estado', self::ESTADO_ACTIVE)
        ];
        return $dominio->all($where, ['nombre' => 'ASC']);
    }

    public static function getAllByContacto(int $idcontacto): array
    {
        $dominio = new self();
        $where = [Where::column('idcontacto', $idcontacto)];
        return $dominio->all($where, ['nombre' => 'ASC']);
    }

    public static function getProximosAExpirar(): array
    {
        $dominio = new self();
        $fechaLimite = date('Y-m-d', strtotime('+30 days'));
        $where = [
            Where::column('estado', self::ESTADO_ACTIVE),
            Where::column('fecha_expiracion', $fechaLimite, '<='),
            Where::column('fecha_expiracion', date('Y-m-d'), '>=')
        ];
        return $dominio->all($where, ['fecha_expiracion' => 'ASC']);
    }

    /**
     * Gets domains available for a new service (not linked to any active contract)
     */
    public static function getDisponibles(int $idcontacto): array
    {
        $dominios = self::getActivosByContacto($idcontacto);
        $disponibles = [];

        foreach ($dominios as $dominio) {
            if (!$dominio->hasActiveContract()) {
                $disponibles[] = $dominio;
            }
        }

        return $disponibles;
    }

    public static function getByContrato(int $idcontrato): array
    {
        $dominio = new self();
        $where = [Where::column('idcontrato', $idcontrato)];
        return $dominio->all($where, ['nombre' => 'ASC']);
    }

    public function test(): bool
    {
        if (empty($this->creation_date)) {
            $this->creation_date = Tools::dateTime();
        }
        $this->last_update = Tools::dateTime();

        // Validar nombre
        if (empty($this->nombre)) {
            Tools::log()->error('domain-name-required');
            return false;
        }

        $this->nombre = strtolower(trim($this->nombre));

        // Validar TLD
        if (empty($this->tld)) {
            Tools::log()->error('tld-required');
            return false;
        }

        $this->tld = strtolower(trim($this->tld));
        if (strlen($this->tld) > 0 && $this->tld[0] !== '.') {
            $this->tld = '.' . $this->tld;
        }

        // Validar estado
        $estadosValidos = [
            self::ESTADO_ACTIVE,
            self::ESTADO_EXPIRED,
            self::ESTADO_PENDING_TRANSFER,
            self::ESTADO_REDEMPTION,
            self::ESTADO_INACTIVE
        ];
        if (!in_array($this->estado, $estadosValidos)) {
            $this->estado = self::ESTADO_ACTIVE;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListDominio'): string
    {
        return parent::url($type, $list);
    }

    public static function getWithAutoRenewal(): array
    {
        $dominio = new self();
        $where = [
            Where::column('idcontrato', null, 'IS NOT'),
            Where::column('estado', self::ESTADO_ACTIVE),
        ];
        $dominios = $dominio->all($where, ['fecha_expiracion' => 'ASC']);

        return array_filter($dominios, function ($d) {
            if (empty($d->idcontrato)) {
                return false;
            }
            $contrato = $d->getContrato();
            return $contrato->id && $contrato->auto_renovar && $contrato->isActivo();
        });
    }

    public function extendExpiration(int $years = 1): bool
    {
        if (empty($this->fecha_expiracion)) {
            $this->fecha_expiracion = Tools::date();
        }

        $expDate = new \DateTime($this->fecha_expiracion);
        $expDate->add(new \DateInterval('P' . $years . 'Y'));
        $this->fecha_expiracion = $expDate->format('Y-m-d');
        $this->estado = self::ESTADO_ACTIVE;

        return $this->save();
    }
}
