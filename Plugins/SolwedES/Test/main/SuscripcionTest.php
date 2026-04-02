<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class SuscripcionTest extends TestCase
{
    use LogErrorsTrait;

    private static Servicio $servicio;

    public static function setUpBeforeClass(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Servicio para Tests Suscripcion';
        $servicio->activo = true;
        $servicio->save();
        self::$servicio = $servicio;
    }

    public static function tearDownAfterClass(): void
    {
        self::$servicio->delete();
    }

    public function testCreate(): void
    {
        $suscripcion = new Suscripcion();
        $suscripcion->idcontacto = 1;
        $suscripcion->idservicio = self::$servicio->id;
        $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
        $suscripcion->fecha_inicio = date('Y-m-d');
        $suscripcion->fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
        $suscripcion->metodo_pago = Suscripcion::METODO_MANUAL;
        $suscripcion->importe = 99.99;
        $suscripcion->auto_renovar = false;

        $this->assertTrue($suscripcion->save(), 'No se pudo guardar la suscripcion');
        $this->assertNotEmpty($suscripcion->id);

        $suscripcion->delete();
    }

    public function testCreateRequiresServicio(): void
    {
        $suscripcion = new Suscripcion();
        $suscripcion->idcontacto = 1;

        $this->assertFalse($suscripcion->save(), 'Deberia fallar sin idservicio');
    }

    public function testEstadoConstants(): void
    {
        $this->assertEquals('activo', Suscripcion::ESTADO_ACTIVO);
        $this->assertEquals('suspendido', Suscripcion::ESTADO_SUSPENDIDO);
        $this->assertEquals('cancelado', Suscripcion::ESTADO_CANCELADO);
        $this->assertEquals('vencido', Suscripcion::ESTADO_VENCIDO);
        $this->assertEquals('pendiente', Suscripcion::ESTADO_PENDIENTE);
    }

    public function testMetodoPagoConstants(): void
    {
        $this->assertEquals('stripe', Suscripcion::METODO_STRIPE);
        $this->assertEquals('transferencia', Suscripcion::METODO_TRANSFERENCIA);
        $this->assertEquals('domiciliacion', Suscripcion::METODO_DOMICILIACION);
        $this->assertEquals('manual', Suscripcion::METODO_MANUAL);
    }

    public function testGetActivosByContacto(): void
    {
        $suscripcion = new Suscripcion();
        $suscripcion->idcontacto = 9999;
        $suscripcion->idservicio = self::$servicio->id;
        $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
        $suscripcion->fecha_inicio = date('Y-m-d');
        $suscripcion->fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
        $suscripcion->metodo_pago = Suscripcion::METODO_MANUAL;
        $suscripcion->importe = 50.0;
        $suscripcion->save();

        $suscripciones = Suscripcion::getActivosByContacto(9999);
        $this->assertIsArray($suscripciones);
        $this->assertNotEmpty($suscripciones);

        $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
        $suscripcion->save();
        $canceladas = Suscripcion::getActivosByContacto(9999);
        $this->assertEmpty($canceladas);

        $suscripcion->delete();
    }

    public function testTableName(): void
    {
        $this->assertEquals('solwedes_suscripciones', Suscripcion::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertEquals('id', Suscripcion::primaryColumn());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
