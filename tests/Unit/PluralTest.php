<?php

namespace Tests\Unit;

use App\Support\Plural;
use PHPUnit\Framework\TestCase;

class PluralTest extends TestCase
{
    public function test_one_and_many(): void
    {
        $this->assertSame('1 family', Plural::of(1, 'family', 'families'));
        $this->assertSame('2 families', Plural::of(2, 'family', 'families'));
        $this->assertSame('0 people', Plural::of(0, 'person', 'people'));
        $this->assertSame('1,234 people', Plural::of(1234, 'person', 'people'));
    }
}
