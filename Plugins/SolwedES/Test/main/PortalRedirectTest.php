<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Controller\PortalRedirect;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests para la redirección del portal PHP de FS a app.solwed.es.
 *
 * Verifica que cada ruta del portal genera la URL de destino correcta
 * en el portal Next.js, incluyendo el passthrough de IDs.
 */
final class PortalRedirectTest extends TestCase
{
    use LogErrorsTrait;

    private const PORTAL_URL = 'https://app.solwed.es';

    private function makeRedirect(string $url): PortalRedirect
    {
        return new PortalRedirect('PortalRedirect', $url);
    }

    // =========================================================
    // RUTAS SIN ID
    // =========================================================

    public function testPortalLoginRedirect(): void
    {
        $redirect = $this->makeRedirect('/PortalLogin');
        $url = $redirect->buildDestinationUrl('PortalLogin');
        $this->assertEquals(self::PORTAL_URL . '/login', $url);
    }

    public function testPortalClienteRedirect(): void
    {
        $redirect = $this->makeRedirect('/PortalCliente');
        $url = $redirect->buildDestinationUrl('PortalCliente');
        $this->assertEquals(self::PORTAL_URL, $url);
    }

    public function testPortalTicketRedirect(): void
    {
        $redirect = $this->makeRedirect('/PortalTicket');
        $url = $redirect->buildDestinationUrl('PortalTicket');
        $this->assertEquals(self::PORTAL_URL . '/support', $url);
    }

    public function testPortalNoteRedirect(): void
    {
        $redirect = $this->makeRedirect('/PortalNote');
        $url = $redirect->buildDestinationUrl('PortalNote');
        $this->assertEquals(self::PORTAL_URL . '/support', $url);
    }

    // =========================================================
    // RUTAS CON TAB (sin ID)
    // =========================================================

    public function testPortalFacturaSinId(): void
    {
        $redirect = $this->makeRedirect('/PortalFactura');
        $url = $redirect->buildDestinationUrl('PortalFactura');
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=facturas', $url);
    }

    public function testPortalPresupuestoSinId(): void
    {
        $redirect = $this->makeRedirect('/PortalPresupuesto');
        $url = $redirect->buildDestinationUrl('PortalPresupuesto');
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=presupuestos', $url);
    }

    public function testPortalPedidoSinId(): void
    {
        $redirect = $this->makeRedirect('/PortalPedido');
        $url = $redirect->buildDestinationUrl('PortalPedido');
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=pedidos', $url);
    }

    public function testPortalAlbaranSinId(): void
    {
        $redirect = $this->makeRedirect('/PortalAlbaran');
        $url = $redirect->buildDestinationUrl('PortalAlbaran');
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=albaranes', $url);
    }

    // =========================================================
    // RUTAS CON ID (passthrough)
    // =========================================================

    public function testPortalFacturaConId(): void
    {
        $redirect = $this->makeRedirect('/PortalFactura?idfactura=42');
        $url = $redirect->buildDestinationUrl('PortalFactura', ['idfactura' => 42]);
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=facturas&idfactura=42', $url);
    }

    public function testPortalPresupuestoConId(): void
    {
        $redirect = $this->makeRedirect('/PortalPresupuesto?idpresupuesto=7');
        $url = $redirect->buildDestinationUrl('PortalPresupuesto', ['idpresupuesto' => 7]);
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=presupuestos&idpresupuesto=7', $url);
    }

    public function testPortalPedidoConId(): void
    {
        $redirect = $this->makeRedirect('/PortalPedido?idpedido=15');
        $url = $redirect->buildDestinationUrl('PortalPedido', ['idpedido' => 15]);
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=pedidos&idpedido=15', $url);
    }

    public function testPortalAlbaranConId(): void
    {
        $redirect = $this->makeRedirect('/PortalAlbaran?idalbaran=99');
        $url = $redirect->buildDestinationUrl('PortalAlbaran', ['idalbaran' => 99]);
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=albaranes&idalbaran=99', $url);
    }

    // =========================================================
    // EDGE CASES
    // =========================================================

    public function testIdNoEntero(): void
    {
        // IDs no enteros deben ignorarse
        $redirect = $this->makeRedirect('/PortalFactura');
        $url = $redirect->buildDestinationUrl('PortalFactura', ['idfactura' => 'hack']);
        $this->assertEquals(self::PORTAL_URL . '/billing?tab=facturas', $url);
    }

    public function testControladorDesconocido(): void
    {
        // Controlador no mapeado → redirige a la raíz del portal
        $redirect = $this->makeRedirect('/PortalDesconocido');
        $url = $redirect->buildDestinationUrl('PortalDesconocido');
        $this->assertEquals(self::PORTAL_URL, $url);
    }

    public function testPortalUrlPersonalizada(): void
    {
        // Respetar la URL configurada en AppSettings (solo caché en memoria, sin DB)
        Tools::settingsSet('solwed', 'portal_url', 'http://localhost:3000');

        $redirect = $this->makeRedirect('/PortalLogin');
        $url = $redirect->buildDestinationUrl('PortalLogin');
        $this->assertEquals('http://localhost:3000/login', $url);

        // Restaurar
        Tools::settingsSet('solwed', 'portal_url', 'https://app.solwed.es');
    }

    public function testPortalUrlConTrailingSlash(): void
    {
        // La URL con trailing slash debe normalizarse
        Tools::settingsSet('solwed', 'portal_url', 'https://app.solwed.es/');

        $redirect = $this->makeRedirect('/PortalLogin');
        $url = $redirect->buildDestinationUrl('PortalLogin');
        $this->assertEquals('https://app.solwed.es/login', $url);

        // Restaurar
        Tools::settingsSet('solwed', 'portal_url', 'https://app.solwed.es');
    }

    // =========================================================
    // HERENCIA — los stubs deben ser instancias de PortalRedirect
    // =========================================================

    public function testPortalLoginExtendsRedirect(): void
    {
        $ctrl = new \FacturaScripts\Plugins\SolwedES\Controller\PortalLogin('PortalLogin', '/PortalLogin');
        $this->assertInstanceOf(PortalRedirect::class, $ctrl);
    }

    public function testPortalFacturaExtendsRedirect(): void
    {
        $ctrl = new \FacturaScripts\Plugins\SolwedES\Controller\PortalFactura('PortalFactura', '/PortalFactura');
        $this->assertInstanceOf(PortalRedirect::class, $ctrl);
    }

    public function testPortalAlbaranExtendsRedirect(): void
    {
        $ctrl = new \FacturaScripts\Plugins\SolwedES\Controller\PortalAlbaran('PortalAlbaran', '/PortalAlbaran');
        $this->assertInstanceOf(PortalRedirect::class, $ctrl);
    }
}
