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

use FacturaScripts\Core\Where;
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
        $where = [Where::isEqual('codcliente', $codcliente)];
        $order = ['id' => 'DESC'];
        $items = $model->all($where, $order, 0, 1);

        return count($items) > 0 ? (float)$items[0]->saldo_resultante : 0.0;
    }

    /**
     * Obtiene el historial de transacciones de un cliente
     */
    public static function getHistory(string $codcliente, int $offset = 0, int $limit = 20): array
    {
        $model = new self();
        $where = [Where::isEqual('codcliente', $codcliente)];
        $order = ['id' => 'DESC'];
        return $model->all($where, $order, $offset, $limit);
    }

    /**
     * Registra una transacción y actualiza el saldo
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
        $currentBalance = self::getBalance($codcliente);

        // Para gastos, la cantidad viene positiva pero se resta
        $delta = in_array($tipo, [self::TIPO_GASTO]) ? -abs($cantidad) : abs($cantidad);
        $newBalance = $currentBalance + $delta;

        // No permitir saldo negativo en gastos
        if ($newBalance < 0 && $tipo === self::TIPO_GASTO) {
            return null; // Saldo insuficiente
        }

        $tx = new self();
        $tx->codcliente = $codcliente;
        $tx->tipo = $tipo;
        $tx->cantidad = $delta;
        $tx->saldo_resultante = $newBalance;
        $tx->concepto = $concepto;
        $tx->referencia_externa = $referenciaExterna;
        $tx->metadata = $metadata ? json_encode($metadata) : null;
        $tx->creation_date = Tools::dateTime();

        if ($tx->save()) {
            return $tx;
        }

        return null;
    }

    /**
     * Cuenta total de transacciones de un cliente
     */
    public static function countByClient(string $codcliente): int
    {
        $model = new self();
        $where = [Where::isEqual('codcliente', $codcliente)];
        return $model->count($where);
    }
}
