<?php

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class DireccionEnvio extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var string */
    public $label;

    /** @var string|null */
    public $nombre_destinatario;

    /** @var string */
    public $direccion;

    /** @var string */
    public $ciudad;

    /** @var string|null */
    public $provincia;

    /** @var string|null */
    public $codpostal;

    /** @var string */
    public $pais;

    /** @var string|null */
    public $telefono;

    /** @var string|null */
    public $notas;

    /** @var bool */
    public $is_default;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->pais = 'ES';
        $this->is_default = false;
        $this->creation_date = Tools::dateTime();
        $this->last_update = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_direcciones_envio';
    }

    public static function getByContacto(int $idcontacto): array
    {
        $model = new self();
        $where = [Where::column('idcontacto', $idcontacto)];
        return $model->all($where, ['is_default' => 'DESC', 'label' => 'ASC']);
    }

    public static function getDefaultByContacto(int $idcontacto): ?self
    {
        $model = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('is_default', true),
        ];
        $results = $model->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    public function setAsDefault(): bool
    {
        // Quitar default de las demas del mismo contacto
        $db = self::toolBox()::db();
        $db->exec("UPDATE " . self::tableName() . " SET is_default = false WHERE idcontacto = " . (int)$this->idcontacto);

        $this->is_default = true;
        return $this->save();
    }

    public function test(): bool
    {
        if (empty($this->creation_date)) {
            $this->creation_date = Tools::dateTime();
        }
        $this->last_update = Tools::dateTime();

        if (empty($this->idcontacto)) {
            Tools::log()->error('contact-required');
            return false;
        }

        if (empty($this->direccion)) {
            Tools::log()->error('address-required');
            return false;
        }

        if (empty($this->ciudad)) {
            Tools::log()->error('city-required');
            return false;
        }

        if (empty($this->label)) {
            $this->label = 'Principal';
        }

        return parent::test();
    }
}
