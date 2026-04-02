<?php

/**
 * Plugin SolwedES - Modelo Wallet (Wcoins)
 *
 * Registra transacciones del monedero virtual de clientes.
 * El saldo se calcula como la suma de todas las transacciones de un codcliente.
 *
 * Tipos: recarga, gasto, regalo, reembolso
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class Wallet extends ModelClass
{
    use ModelTrait;

    const TIPO_RECARGA = 'recarga';
    const TIPO_GASTO = 'gasto';
    const TIPO_REGALO = 'regalo';
    const TIPO_REEMBOLSO = 'reembolso';

    const VALID_TYPES = [self::TIPO_RECARGA, self::TIPO_GASTO, self::TIPO_REGALO, self::TIPO_REEMBOLSO];

    /** @var int */
    public $id;

    /** @var string */
    public $codcliente;

    /** @var string recarga|gasto|regalo|reembolso */
    public $tipo;

    /** @var float Cantidad (positiva para ingresos, negativa para gastos) */
    public $cantidad;

    /** @var float Saldo después de esta transacción */
    public $saldo_resultante;

    /** @var string */
    public $concepto;

    /** @var string|null Referencia externa (stripe session, tool name, etc.) */
    public $referencia_externa;

    /** @var string|null JSON metadata */
    public $metadata;

    /** @var string */
    public $creation_date;

    public static function tableName(): string
    {
        return 'solwedes_wallet';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TIPO_RECARGA;
        $this->cantidad = 0;
        $this->saldo_resultante = 0;
        $this->creation_date = Tools::dateTime();
    }

    /**
     * Obtiene el saldo actual de un cliente
     */
    public static function getBalance(string $codcliente): float
    {
        $model = new self();
        $where = [new DataBaseWhere('codcliente', $codcliente)];
        $order = ['id' => 'DESC'];
        $items = $model->all($where, $order, 0, 1);

        return count($items) > 0 ? round((float)$items[0]->saldo_resultante, 2) : 0.0;
    }

    /**
     * Obtiene el historial de transacciones de un cliente
     */
    public static function getHistory(string $codcliente, int $offset = 0, int $limit = 20): array
    {
        $model = new self();
        $where = [new DataBaseWhere('codcliente', $codcliente)];
        $order = ['id' => 'DESC'];
        return $model->all($where, $order, $offset, $limit);
    }

    /**
     * Registra una transacción y actualiza el saldo.
     * Uses DB transaction with row locking to prevent race conditions.
     *
     * @return self|null La transacción creada, o null si falla
     */
    public static function addTransaction(
        string $codcliente,
        string $tipo,
        float $cantidad,
        string $concepto,
        ?string $referenciaExterna = null,
        ?array $metadata = null
    ): ?self {
        // Validate tipo
        if (!in_array($tipo, self::VALID_TYPES)) {
            return null;
        }

        $db = self::getDatabase();
        $db->beginTransaction();

        try {
            // Lock the latest row for this client to prevent race conditions
            $sql = "SELECT saldo_resultante FROM " . self::tableName()
                 . " WHERE codcliente = " . $db->var2str($codcliente)
                 . " ORDER BY id DESC LIMIT 1 FOR UPDATE";
            $rows = $db->select($sql);
            $currentBalance = !empty($rows) ? round((float)$rows[0]['saldo_resultante'], 2) : 0.0;

            // Calculate delta
            $delta = in_array($tipo, [self::TIPO_GASTO]) ? -abs($cantidad) : abs($cantidad);
            $newBalance = round($currentBalance + $delta, 2);

            // Prevent negative balance on spend
            if ($newBalance < 0 && $tipo === self::TIPO_GASTO) {
                $db->rollback();
                return null; // Saldo insuficiente
            }

            $tx = new self();
            $tx->codcliente = $codcliente;
            $tx->tipo = $tipo;
            $tx->cantidad = round($delta, 2);
            $tx->saldo_resultante = $newBalance;
            $tx->concepto = $concepto;
            $tx->referencia_externa = $referenciaExterna;
            $tx->metadata = $metadata ? json_encode($metadata) : null;
            $tx->creation_date = Tools::dateTime();

            if ($tx->save()) {
                $db->commit();
                return $tx;
            }

            $db->rollback();
            return null;
        } catch (\Exception $e) {
            $db->rollback();
            Tools::log('solwed')->error('Wallet transaction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cuenta total de transacciones de un cliente
     */
    public static function countByClient(string $codcliente): int
    {
        $model = new self();
        $where = [new DataBaseWhere('codcliente', $codcliente)];
        return $model->count($where);
    }

    /**
     * Get the FS database connection
     */
    private static function getDatabase()
    {
        return new \FacturaScripts\Core\Base\DataBase();
    }
}
