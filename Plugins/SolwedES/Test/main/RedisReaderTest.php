<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;
use PHPUnit\Framework\TestCase;

final class RedisReaderTest extends TestCase
{
    public function testGetReturnsNullForMissingKey(): void
    {
        // If Redis is available, should return null for nonexistent key
        // If Redis is not available, should also return null (graceful degradation)
        $result = RedisReader::get('test:nonexistent:' . uniqid());

        $this->assertNull($result);
    }

    public function testGetListReturnsEmptyForMissingKey(): void
    {
        $result = RedisReader::getList('test:nonexistent:' . uniqid());

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testExistsReturnsFalseForMissingKey(): void
    {
        $result = RedisReader::exists('test:nonexistent:' . uniqid());

        $this->assertFalse($result);
    }

    public function testTtlReturnsNegativeForMissingKey(): void
    {
        $result = RedisReader::ttl('test:nonexistent:' . uniqid());

        $this->assertLessThan(0, $result);
    }

    public function testReadWriteRoundtrip(): void
    {
        // Skip if Redis extension not loaded
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $key = 'test:roundtrip:' . uniqid();
        $data = ['foo' => 'bar', 'count' => 42];

        // Write using RedisWriter
        $writer = new \FacturaScripts\Plugins\SolwedES\Lib\RedisWriter();
        $written = \FacturaScripts\Plugins\SolwedES\Lib\RedisWriter::set($key, $data, 60);

        if (!$written) {
            $this->markTestSkipped('Redis not reachable');
        }

        // Read back
        $result = RedisReader::get($key);
        $this->assertIsArray($result);
        $this->assertEquals('bar', $result['foo']);
        $this->assertEquals(42, $result['count']);

        // Clean up
        \FacturaScripts\Plugins\SolwedES\Lib\RedisWriter::del($key);
    }
}
