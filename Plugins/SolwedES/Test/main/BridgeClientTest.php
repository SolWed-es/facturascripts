<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Lib\BridgeClient;
use PHPUnit\Framework\TestCase;

final class BridgeClientTest extends TestCase
{
    public function testBuildUrlDefault(): void
    {
        // BridgeClient should work without throwing even if bridge is unreachable
        // This tests the error handling path
        $result = BridgeClient::get('/health');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        // ok=false is expected if bridge is not running in test environment
    }

    public function testPostReturnsArray(): void
    {
        $result = BridgeClient::post('/nonexistent', ['test' => true]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeleteReturnsArray(): void
    {
        $result = BridgeClient::delete('/nonexistent');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
    }
}
