<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Model\PagoStripe;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

final class PagoStripeTest extends TestCase
{
    use LogErrorsTrait;

    public function testCreate(): void
    {
        $pago = new PagoStripe();
        $pago->idcontacto = 1;
        $pago->stripe_payment_intent = 'pi_test_phpunit_' . uniqid();
        $pago->importe = 99.99;
        $pago->moneda = 'EUR';
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;

        $this->assertTrue($pago->save(), 'No se pudo guardar el pago Stripe');
        $this->assertNotEmpty($pago->id);

        $pago->delete();
    }

    public function testRequiresStripePaymentIntent(): void
    {
        $pago = new PagoStripe();
        $pago->idcontacto = 1;
        $pago->importe = 50.0;
        $pago->moneda = 'EUR';
        // Sin stripe_payment_intent

        $this->assertFalse($pago->save(), 'Debería fallar sin stripe_payment_intent');
    }

    public function testLoadById(): void
    {
        $pago = new PagoStripe();
        $pago->idcontacto = 1;
        $pago->stripe_payment_intent = 'pi_test_load_' . uniqid();
        $pago->importe = 150.00;
        $pago->moneda = 'EUR';
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
        $pago->save();

        $id = $pago->id;
        $cargado = new PagoStripe();
        $this->assertTrue($cargado->load($id));
        $this->assertEquals(150.00, $cargado->importe);
        $this->assertEquals('EUR', $cargado->moneda);

        $pago->delete();
    }

    public function testTableName(): void
    {
        $this->assertEquals('solwedes_pagos', PagoStripe::tableName());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
