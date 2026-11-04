<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigQuant\Owner;

final class OwnerTest extends TestCase
{
    public function testARequestIsWholeOnceItsBodyHasArrived(): void
    {
        $head = "POST /order HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Quant-Token: abc\r\nContent-Length: 15\r\n\r\n";

        $this->assertNull(Owner::parse("POST /order HTTP/1.1\r\nX-Quant"));
        $this->assertNull(Owner::parse($head . '{"side":'));

        $request = Owner::parse($head . '{"side":"buy"}' . ' ');

        $this->assertNotNull($request);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/order', $request['path']);
        $this->assertSame('abc', $request['token']);
        $this->assertSame(['side' => 'buy'], $request['body']);
    }

    public function testAGetHasNoBodyAndMayLackTheToken(): void
    {
        $request = Owner::parse("GET /status?limit=3 HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertNotNull($request);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/status?limit=3', $request['path']);
        $this->assertSame('', $request['token']);
        $this->assertSame([], $request['body']);
    }
}
