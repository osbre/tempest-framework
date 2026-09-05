<?php

namespace Tempest\Support\Tests\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tempest\Support\Str;
use Tempest\Support\Str\ImmutableString;
use Tempest\Support\Str\MutableString;

final class FunctionsTest extends TestCase
{
    #[Test]
    public function parse(): void
    {
        $this->assertSame('foo', Str\parse('foo'));
        $this->assertSame('1', Str\parse('1'));
        $this->assertSame('1', Str\parse(1));
        $this->assertSame('', Str\parse(new stdClass()));
        $this->assertNull(Str\parse(new stdClass(), default: null));
        $this->assertSame('', Str\parse(new stdClass(), default: ''));
        $this->assertSame('foo', Str\parse(new stdClass(), default: 'foo'));
        $this->assertSame('foo', Str\parse(new MutableString('foo')));
        $this->assertSame('foo', Str\parse(new ImmutableString('foo')));
        $this->assertSame('', Str\parse(['a', 'b']));
    }

    #[Test]
    public function substring_helpers_cut_on_character_boundaries(): void
    {
        $this->assertSame('wörld', Str\after_first('héllo wörld', ' '));
        $this->assertSame('héllo', Str\before_first('héllo wörld', ' '));
        $this->assertSame('テキスト', Str\after_first('日本語テキスト', '語'));
        $this->assertSame('日本', Str\before_last('日本語', '語'));
        $this->assertSame('c', Str\after_last('a/b/日本/c', '/'));
    }
}
