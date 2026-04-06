<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Lib\FacturaScriptsSync;
use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;
use PHPUnit\Framework\TestCase;

final class FacturaScriptsSyncTest extends TestCase
{
    public function testSyncAllReturnsStats(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $stats = FacturaScriptsSync::syncAll();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('suscripciones', $stats);
        $this->assertArrayHasKey('pagos', $stats);
        $this->assertArrayHasKey('facturas', $stats);
        $this->assertArrayHasKey('clientes', $stats);
        $this->assertArrayHasKey('dominios', $stats);
        $this->assertArrayHasKey('servicios', $stats);
        $this->assertArrayHasKey('stats', $stats);

        // Stats should be an array with MRR
        $this->assertIsArray($stats['stats']);
        $this->assertArrayHasKey('mrr', $stats['stats']);
    }

    public function testSyncStatsWritesToRedis(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        FacturaScriptsSync::syncStats();

        $stats = RedisReader::get('fs:stats');

        // May be null if Redis is not reachable
        if ($stats === null) {
            $this->markTestSkipped('Redis not reachable');
        }

        $this->assertArrayHasKey('mrr', $stats);
        $this->assertArrayHasKey('suscripciones_activas', $stats);
        $this->assertArrayHasKey('synced_at', $stats);
    }
}
