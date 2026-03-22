<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class ContratServicioTest extends TestCase
{
    use LogErrorsTrait;

    private static Servicio $servicio;

    public static function setUpBeforeClass(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Servicio para Tests ContratServicio';
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
        $contrato = new ContratServicio();
        $contrato->idcontacto = 1;
        $contrato->idservicio = self::$servicio->id;
        $contrato->estado = ContratServicio::ESTADO_ACTIVO;
        $contrato->fecha_inicio = date('Y-m-d');
        $contrato->fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
        $contrato->metodo_pago = ContratServicio::METODO_MANUAL;
        $contrato->importe = 99.99;
        $contrato->auto_renovar = false;

        $this->assertTrue($contrato->save(), 'No se pudo guardar el contrato');
        $this->assertNotEmpty($contrato->id);

        $contrato->delete();
    }

    public function testCreateRequiresServicio(): void
    {
        $contrato = new ContratServicio();
        $contrato->idcontacto = 1;
        // No se asigna idservicio

        $this->assertFalse($contrato->save(), 'Debería fallar sin idservicio');
    }

    public function testEstadoConstants(): void
    {
        $this->assertEquals('activo', ContratServicio::ESTADO_ACTIVO);
        $this->assertEquals('suspendido', ContratServicio::ESTADO_SUSPENDIDO);
        $this->assertEquals('cancelado', ContratServicio::ESTADO_CANCELADO);
        $this->assertEquals('vencido', ContratServicio::ESTADO_VENCIDO);
        $this->assertEquals('pendiente', ContratServicio::ESTADO_PENDIENTE);
    }

    public function testMetodoPagoConstants(): void
    {
        $this->assertEquals('stripe', ContratServicio::METODO_STRIPE);
        $this->assertEquals('transferencia', ContratServicio::METODO_TRANSFERENCIA);
        $this->assertEquals('domiciliacion', ContratServicio::METODO_DOMICILIACION);
        $this->assertEquals('manual', ContratServicio::METODO_MANUAL);
    }

    public function testGetActivosByContacto(): void
    {
        $contrato = new ContratServicio();
        $contrato->idcontacto = 9999;
        $contrato->idservicio = self::$servicio->id;
        $contrato->estado = ContratServicio::ESTADO_ACTIVO;
        $contrato->fecha_inicio = date('Y-m-d');
        $contrato->fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
        $contrato->metodo_pago = ContratServicio::METODO_MANUAL;
        $contrato->importe = 50.0;
        $contrato->save();

        $contratos = ContratServicio::getActivosByContacto(9999);
        $this->assertIsArray($contratos);
        $this->assertNotEmpty($contratos);

        // El contrato cancelado no aparece
        $contrato->estado = ContratServicio::ESTADO_CANCELADO;
        $contrato->save();
        $cancelados = ContratServicio::getActivosByContacto(9999);
        $this->assertEmpty($cancelados);

        $contrato->delete();
    }

    public function testTableName(): void
    {
        $this->assertEquals('solwedes_contratos', ContratServicio::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertEquals('id', ContratServicio::primaryColumn());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
