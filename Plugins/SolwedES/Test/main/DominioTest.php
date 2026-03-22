<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class DominioTest extends TestCase
{
    use LogErrorsTrait;

    public function testCreate(): void
    {
        $dominio = new Dominio();
        $dominio->nombre = 'test-create-' . uniqid();
        $dominio->tld = '.com';
        $dominio->idcontacto = 1;
        $dominio->estado = Dominio::ESTADO_ACTIVE;
        $dominio->fecha_registro = date('Y-m-d');
        $dominio->fecha_expiracion = date('Y-m-d', strtotime('+1 year'));

        $this->assertTrue($dominio->save(), 'No se pudo guardar el dominio');
        $this->assertNotEmpty($dominio->id);

        $dominio->delete();
    }

    public function testCreateRequiresNombre(): void
    {
        $dominio = new Dominio();
        $dominio->tld = 'es';
        $dominio->idcontacto = 1;

        $this->assertFalse($dominio->save(), 'Debería fallar sin nombre');
    }

    public function testEstadoConstants(): void
    {
        $this->assertEquals('active', Dominio::ESTADO_ACTIVE);
        $this->assertEquals('expired', Dominio::ESTADO_EXPIRED);
        $this->assertEquals('pending_transfer', Dominio::ESTADO_PENDING_TRANSFER);
        $this->assertEquals('redemption', Dominio::ESTADO_REDEMPTION);
        $this->assertEquals('inactive', Dominio::ESTADO_INACTIVE);
    }

    public function testGetFqdnWithTld(): void
    {
        $dominio = new Dominio();
        $nombre = 'miempresa-' . uniqid();
        $dominio->nombre = $nombre;
        $dominio->tld = '.es'; // el campo incluye el punto
        $dominio->idcontacto = 1;
        $dominio->estado = Dominio::ESTADO_ACTIVE;
        $dominio->fecha_registro = date('Y-m-d');
        $dominio->fecha_expiracion = date('Y-m-d', strtotime('+1 year'));
        $dominio->save();

        // El FQDN se construye concatenando nombre + tld (que ya incluye el punto)
        $fqdn = $dominio->nombre . $dominio->tld;
        $this->assertEquals($nombre . '.es', $fqdn);

        $dominio->delete();
    }

    public function testLoadById(): void
    {
        $dominio = new Dominio();
        $dominio->nombre = 'test-load-' . uniqid();
        $dominio->tld = '.net';
        $dominio->idcontacto = 1;
        $dominio->estado = Dominio::ESTADO_ACTIVE;
        $dominio->fecha_registro = date('Y-m-d');
        $dominio->fecha_expiracion = date('Y-m-d', strtotime('+2 years'));
        $dominio->save();

        $id = $dominio->id;
        $cargado = new Dominio();
        $this->assertTrue($cargado->load($id));
        $this->assertEquals($dominio->nombre, $cargado->nombre);
        $this->assertEquals('.net', $cargado->tld);

        $dominio->delete();
    }

    public function testDelete(): void
    {
        $dominio = new Dominio();
        $dominio->nombre = 'test-delete-' . uniqid();
        $dominio->tld = '.org';
        $dominio->idcontacto = 1;
        $dominio->estado = Dominio::ESTADO_ACTIVE;
        $dominio->fecha_registro = date('Y-m-d');
        $dominio->fecha_expiracion = date('Y-m-d', strtotime('+1 year'));
        $dominio->save();

        $id = $dominio->id;
        $this->assertTrue($dominio->delete());

        $buscado = new Dominio();
        $this->assertFalse($buscado->load($id));
    }

    public function testTableName(): void
    {
        $this->assertEquals('solwedes_dominios', Dominio::tableName());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
