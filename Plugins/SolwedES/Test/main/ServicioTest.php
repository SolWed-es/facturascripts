<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class ServicioTest extends TestCase
{
    use LogErrorsTrait;

    public function testCreate(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Test Servicio';
        $servicio->descripcion = 'Descripción de prueba';
        $servicio->categoria = 'Test';
        $servicio->activo = true;
        $servicio->comprable = false;

        $this->assertTrue($servicio->save(), 'No se pudo guardar el servicio');
        $this->assertNotEmpty($servicio->id, 'El servicio no tiene ID');
        $this->assertNotEmpty($servicio->creation_date, 'No se asignó creation_date');
        $this->assertNotEmpty($servicio->last_update, 'No se asignó last_update');

        $servicio->delete();
    }

    public function testCreateWithoutName(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = '';

        $this->assertFalse($servicio->save(), 'Debería fallar al guardar sin nombre');
    }

    public function testUpdateTimestamp(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Test Timestamp';
        $this->assertTrue($servicio->save());

        $originalUpdate = $servicio->last_update;
        sleep(1);
        $servicio->nombre = 'Test Timestamp Updated';
        $servicio->save();

        $this->assertNotEquals($originalUpdate, $servicio->last_update, 'last_update debería cambiar al actualizar');

        $servicio->delete();
    }

    public function testCaracteristicasJson(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Test Características';
        $features = ['RAM' => '8GB', 'CPU' => '4 cores', 'Disco' => '100GB SSD'];
        $servicio->setCaracteristicas($features);

        $this->assertNotEmpty($servicio->caracteristicas);
        $decoded = $servicio->getCaracteristicas();
        $this->assertEquals('8GB', $decoded['RAM']);
        $this->assertEquals('4 cores', $decoded['CPU']);
        $this->assertEquals('100GB SSD', $decoded['Disco']);
    }

    public function testCaracteristicasInvalidJson(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Test JSON Inválido';
        $servicio->caracteristicas = '{invalid json';

        $this->assertFalse($servicio->save(), 'Debería fallar con JSON inválido en características');
    }

    public function testGetCaracteristicasEmpty(): void
    {
        $servicio = new Servicio();
        $servicio->caracteristicas = null;

        $this->assertIsArray($servicio->getCaracteristicas());
        $this->assertEmpty($servicio->getCaracteristicas());
    }

    public function testDefaults(): void
    {
        $servicio = new Servicio();
        $servicio->clear();

        $this->assertTrue($servicio->activo);
        $this->assertTrue($servicio->comprable);
        $this->assertEquals(0, $servicio->orden);
        $this->assertFalse($servicio->genera_suscripcion);
        $this->assertEquals(0.0, $servicio->precio);
        $this->assertEquals(0, $servicio->meses_recurrencia);
    }

    public function testGetServiciosActivos(): void
    {
        $activo = new Servicio();
        $activo->nombre = 'Servicio Activo Test';
        $activo->activo = true;
        $this->assertTrue($activo->save());

        $inactivo = new Servicio();
        $inactivo->nombre = 'Servicio Inactivo Test';
        $inactivo->activo = false;
        $this->assertTrue($inactivo->save());

        $activos = Servicio::getServiciosActivos();
        $nombresActivos = array_column($activos, 'nombre');

        $this->assertContains('Servicio Activo Test', $nombresActivos);
        $this->assertNotContains('Servicio Inactivo Test', $nombresActivos);

        $activo->delete();
        $inactivo->delete();
    }

    public function testGetServiciosAgrupadosPorCategoria(): void
    {
        $s1 = new Servicio();
        $s1->nombre = 'Hosting Básico Test';
        $s1->categoria = 'Hosting';
        $s1->activo = true;
        $s1->save();

        $s2 = new Servicio();
        $s2->nombre = 'Hosting Pro Test';
        $s2->categoria = 'Hosting';
        $s2->activo = true;
        $s2->save();

        $s3 = new Servicio();
        $s3->nombre = 'Email Test';
        $s3->categoria = 'Email';
        $s3->activo = true;
        $s3->save();

        $agrupados = Servicio::getServiciosAgrupadosPorCategoria();

        $this->assertArrayHasKey('Hosting', $agrupados);
        $this->assertArrayHasKey('Email', $agrupados);

        // Cleanup
        $s1->delete();
        $s2->delete();
        $s3->delete();
    }

    public function testDelete(): void
    {
        $servicio = new Servicio();
        $servicio->nombre = 'Test Delete';
        $this->assertTrue($servicio->save());

        $id = $servicio->id;
        $this->assertTrue($servicio->delete(), 'No se pudo eliminar el servicio');

        $servicioBuscado = new Servicio();
        $this->assertFalse($servicioBuscado->load($id), 'El servicio debería haber sido eliminado');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
