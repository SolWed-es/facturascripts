<?php
/**
 * ImportarFacturasEmail - Modelo para log de emails procesados
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class FacturaEmailLog extends ModelClass
{
    use ModelTrait;

    // Estados posibles
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_PROCESADO = 'procesado';
    public const ESTADO_ERROR = 'error';
    public const ESTADO_REVISION = 'revision';

    /** @var int */
    public $id;

    /** @var string */
    public $fecha;

    /** @var string */
    public $email_from;

    /** @var string */
    public $email_subject;

    /** @var string */
    public $email_uid;

    /** @var string */
    public $pdf_filename;

    /** @var string */
    public $estado;

    /** @var int|null */
    public $idfactura;

    /** @var string|null */
    public $codproveedor;

    /** @var int|null */
    public $idfile;

    /** @var string|null */
    public $error_msg;

    /** @var string|null */
    public $ocr_text;

    /** @var string|null */
    public $parsed_data;

    public function clear(): void
    {
        parent::clear();
        $this->fecha = Tools::dateTime();
        $this->estado = self::ESTADO_PENDIENTE;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'facturas_email_log';
    }

    /**
     * Devuelve los datos parseados como array
     */
    public function getParsedData(): array
    {
        if (empty($this->parsed_data)) {
            return [];
        }
        $data = json_decode($this->parsed_data, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Establece los datos parseados desde array
     */
    public function setParsedData(array $data): void
    {
        $this->parsed_data = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Marca como procesado correctamente
     */
    public function markAsProcessed(int $idfactura, string $codproveedor, ?int $idfile = null): bool
    {
        $this->estado = self::ESTADO_PROCESADO;
        $this->idfactura = $idfactura;
        $this->codproveedor = $codproveedor;
        $this->idfile = $idfile;
        $this->error_msg = null;
        return $this->save();
    }

    /**
     * Marca como error
     */
    public function markAsError(string $errorMsg): bool
    {
        $this->estado = self::ESTADO_ERROR;
        $this->error_msg = $errorMsg;
        return $this->save();
    }

    /**
     * Marca para revisión manual
     */
    public function markForReview(string $reason): bool
    {
        $this->estado = self::ESTADO_REVISION;
        $this->error_msg = $reason;
        return $this->save();
    }

    /**
     * Verifica si ya existe un email procesado con este UID
     */
    public static function emailAlreadyProcessed(string $emailUid): bool
    {
        $log = new self();
        $where = [new DataBaseWhere('email_uid', $emailUid)];
        return $log->loadFromCode('', $where);
    }
}
