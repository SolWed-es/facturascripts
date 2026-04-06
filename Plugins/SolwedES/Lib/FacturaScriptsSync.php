<?php

/**
 * Plugin SolwedES - Sync FacturaScripts data → Redis
 *
 * Dumps ERP business data to Redis for consumption by MIND, portal, and bridge.
 * Called from Cron every 30 min, same cadence as bridge sync workers.
 *
 * Redis keys:
 *   fs:suscripciones            — all active/pending subscriptions (array)
 *   fs:suscripcion:{id}         — individual subscription detail
 *   fs:pagos:recientes          — last 100 Stripe payments
 *   fs:facturas:recientes       — last 100 invoices
 *   fs:clientes                 — all clients with basic info
 *   fs:cliente:{codcliente}     — client detail with contact + suscripciones
 *   fs:dominios                 — all domains from FS model
 *   fs:stats                    — summary counts (MRR, active subs, etc.)
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\PagoStripe;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;

class FacturaScriptsSync
{
    /**
     * Run all syncs
     */
    public static function syncAll(): array
    {
        $stats = [];

        $stats['suscripciones'] = self::syncSuscripciones();
        $stats['pagos'] = self::syncPagos();
        $stats['facturas'] = self::syncFacturas();
        $stats['clientes'] = self::syncClientes();
        $stats['dominios'] = self::syncDominios();
        $stats['servicios'] = self::syncServicios();
        $stats['stats'] = self::syncStats();

        return $stats;
    }

    /**
     * Sync active subscriptions to Redis
     */
    public static function syncSuscripciones(): int
    {
        $model = new Suscripcion();
        $where = [
            new DataBaseWhere('estado', 'cancelado', '!='),
        ];
        $suscripciones = $model->all($where, ['last_update' => 'DESC'], 0, 0);

        $list = [];
        foreach ($suscripciones as $s) {
            $data = [
                'id' => $s->id,
                'idcontacto' => $s->idcontacto,
                'idservicio' => $s->idservicio,
                'estado' => $s->estado,
                'fecha_inicio' => $s->fecha_inicio,
                'fecha_vencimiento' => $s->fecha_vencimiento,
                'fecha_proximo_pago' => $s->fecha_proximo_pago,
                'metodo_pago' => $s->metodo_pago,
                'importe' => (float)$s->importe,
                'moneda' => $s->moneda ?? 'EUR',
                'auto_renovar' => (bool)$s->auto_renovar,
                'dominio' => $s->dominio,
                'referencia_externa' => $s->referencia_externa,
                'stripe_customer_id' => $s->stripe_customer_id,
                'cancel_at_period_end' => (bool)$s->cancel_at_period_end,
                'provisioning_status' => $s->provisioning_status,
                'creation_date' => $s->creation_date,
            ];

            $list[] = $data;
            RedisWriter::set("fs:suscripcion:{$s->id}", $data);
        }

        RedisWriter::set('fs:suscripciones', $list);
        return count($list);
    }

    /**
     * Sync recent payments to Redis
     */
    public static function syncPagos(): int
    {
        $model = new PagoStripe();
        $pagos = $model->all([], ['fecha_pago' => 'DESC'], 0, 100);

        $list = [];
        foreach ($pagos as $p) {
            $list[] = [
                'id' => $p->id,
                'idcontacto' => $p->idcontacto,
                'idsuscripcion' => $p->idsuscripcion,
                'idservicio' => $p->idservicio,
                'tipo' => $p->tipo,
                'concepto' => $p->concepto,
                'importe' => (float)$p->importe,
                'moneda' => $p->moneda ?? 'EUR',
                'estado' => $p->estado,
                'metodo_pago' => $p->metodo_pago,
                'fecha_pago' => $p->fecha_pago,
                'idfactura' => $p->idfactura,
                'stripe_payment_intent' => $p->stripe_payment_intent,
            ];
        }

        RedisWriter::set('fs:pagos:recientes', $list);
        return count($list);
    }

    /**
     * Sync recent invoices to Redis
     */
    public static function syncFacturas(): int
    {
        $model = new FacturaCliente();
        $facturas = $model->all([], ['idfactura' => 'DESC'], 0, 100);

        $list = [];
        foreach ($facturas as $f) {
            $list[] = [
                'idfactura' => $f->idfactura,
                'codigo' => $f->codigo,
                'codcliente' => $f->codcliente,
                'nombrecliente' => $f->nombrecliente,
                'cifnif' => $f->cifnif,
                'fecha' => $f->fecha,
                'hora' => $f->hora,
                'total' => (float)$f->total,
                'neto' => (float)$f->neto,
                'totaliva' => (float)$f->totaliva,
                'pagada' => (bool)$f->pagada,
                'codserie' => $f->codserie,
                'codejercicio' => $f->codejercicio,
            ];
        }

        RedisWriter::set('fs:facturas:recientes', $list);
        return count($list);
    }

    /**
     * Sync clients to Redis
     */
    public static function syncClientes(): int
    {
        $model = new Cliente();
        $clientes = $model->all([], ['nombre' => 'ASC'], 0, 0);

        $list = [];
        foreach ($clientes as $c) {
            $data = [
                'codcliente' => $c->codcliente,
                'nombre' => $c->nombre,
                'razonsocial' => $c->razonsocial,
                'cifnif' => $c->cifnif,
                'email' => $c->email,
                'telefono1' => $c->telefono1,
                'codpais' => $c->codpais,
                'provincia' => $c->provincia,
                'ciudad' => $c->ciudad,
                'debaja' => (bool)$c->debaja,
            ];

            $list[] = $data;
            RedisWriter::set("fs:cliente:{$c->codcliente}", $data);
        }

        RedisWriter::set('fs:clientes', $list);
        return count($list);
    }

    /**
     * Sync domains (FS model, not DonDominio API) to Redis
     */
    public static function syncDominios(): int
    {
        $model = new Dominio();
        $dominios = $model->all([], ['nombre' => 'ASC'], 0, 0);

        $list = [];
        foreach ($dominios as $d) {
            $list[] = [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'tld' => $d->tld,
                'nombre_completo' => $d->getNombreCompleto(),
                'idcontacto' => $d->idcontacto,
                'estado' => $d->estado,
                'fecha_expiracion' => $d->fecha_expiracion,
                'fecha_registro' => $d->fecha_registro ?? null,
                'gestionado_solwed' => (bool)$d->gestionado_solwed,
                'idsuscripcion' => $d->idsuscripcion,
                'dondominio_id' => $d->dondominio_id,
            ];
        }

        RedisWriter::set('fs:dominios', $list);
        return count($list);
    }

    /**
     * Sync service catalog to Redis
     */
    public static function syncServicios(): int
    {
        $grouped = Servicio::getServiciosAgrupadosPorCategoria();

        $catalog = [];
        $allServices = [];
        foreach ($grouped as $categoria => $servicios) {
            $items = [];
            foreach ($servicios as $s) {
                $precios = [];
                foreach ($s->getPrecios(true) as $p) {
                    $precios[] = [
                        'id' => $p->id,
                        'nombre' => $p->nombre ?? '',
                        'precio' => (float)$p->precio,
                        'periodo' => $p->periodo ?? 'month',
                        'stripe_price_id' => $p->stripe_price_id ?? '',
                        'activo' => (bool)($p->activo ?? true),
                    ];
                }

                $data = [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'descripcion' => $s->descripcion,
                    'categoria' => $s->categoria,
                    'icono' => $s->icono,
                    'color' => $s->color,
                    'imagen' => $s->imagen,
                    'activo' => (bool)$s->activo,
                    'comprable' => (bool)$s->comprable,
                    'orden' => (int)$s->orden,
                    'genera_suscripcion' => (bool)$s->genera_suscripcion,
                    'caracteristicas' => $s->getCaracteristicas(),
                    'precios' => $precios,
                ];

                $items[] = $data;
                $allServices[] = $data;
                RedisWriter::set("fs:servicio:{$s->id}", $data);
            }

            if (!empty($items)) {
                $catalog[] = ['categoria' => $categoria, 'servicios' => $items];
            }
        }

        RedisWriter::set('fs:servicios', $allServices);
        RedisWriter::set('fs:servicios:catalogo', $catalog);
        return count($allServices);
    }

    /**
     * Sync summary stats (MRR, counts)
     */
    public static function syncStats(): array
    {
        $suscModel = new Suscripcion();
        $activeWhere = [new DataBaseWhere('estado', Suscripcion::ESTADO_ACTIVO)];
        $activeSubs = $suscModel->all($activeWhere, [], 0, 0);

        $mrr = 0.0;
        foreach ($activeSubs as $s) {
            $importe = (float)$s->importe;
            $intervalo = strtolower($s->intervalo ?? 'month');
            if ($intervalo === 'year' || $intervalo === 'anual') {
                $mrr += $importe / 12;
            } else {
                $mrr += $importe;
            }
        }

        $dominioModel = new Dominio();
        $clienteModel = new Cliente();

        $stats = [
            'suscripciones_activas' => count($activeSubs),
            'mrr' => round($mrr, 2),
            'dominios_total' => $dominioModel->count(),
            'clientes_total' => $clienteModel->count(),
            'synced_at' => date('c'),
        ];

        RedisWriter::set('fs:stats', $stats);
        return $stats;
    }
}
