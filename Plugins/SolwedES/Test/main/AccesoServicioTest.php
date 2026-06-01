<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class AccesoServicioTest extends TestCase
{
    use LogErrorsTrait;

    private static Servicio $servicio;

    public static function setUpBeforeClass(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Servicio para Tests AccesoServicio';
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
        $acceso = new AccesoServicio();
        $acceso->idcontacto = 1;
        $acceso->idservicio = self::$servicio->id;
        $acceso->tipo_acceso = 'plesk';
        $acceso->url_acceso = 'https://plesk.test.com';
        $acceso->activo = true;

        $this->assertTrue($acceso->save(), 'No se pudo guardar el acceso');
        $this->assertNotEmpty($acceso->id);

        $acceso->delete();
    }

    public function testGetAllByCliente(): void
    {
        $acceso = new AccesoServicio();
        $acceso->idcontacto = 8888;
        $acceso->idservicio = self::$servicio->id;
        $acceso->tipo_acceso = 'wordpress';
        $acceso->url_acceso = 'https://wp.test.com';
        $acceso->activo = true;
        $acceso->save();

        $accesos = AccesoServicio::getAllByCliente(8888);
        $this->assertIsArray($accesos);
        $this->assertNotEmpty($accesos);

        $acceso->delete();
    }

    public function testTableName(): void
    {
        $this->assertEquals('solwedes_accesos_servicios', AccesoServicio::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertEquals('id', AccesoServicio::primaryColumn());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
