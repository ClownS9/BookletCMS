<?php

namespace Tests\Feature;

use App\Support\Sizes;
use Tests\TestCase;

class SizesTest extends TestCase
{
    public function test_file_sizes_read_naturally_without_intl(): void
    {
        $this->assertSame('0 B', Sizes::file(0));
        $this->assertSame('512 B', Sizes::file(512));
        $this->assertSame('1 KB', Sizes::file(1024));
        $this->assertSame('1.5 KB', Sizes::file(1536));
        $this->assertSame('240 KB', Sizes::file(240 * 1024));
        $this->assertSame('2.3 MB', Sizes::file((int) (2.3 * 1048576)));
    }
}
