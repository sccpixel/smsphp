<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testToGsmRemovesAccentsAndSpecialCharacters(): void
    {
        $this->assertSame('Joao Acoreano - promocao', Text::toGsm('João Açoreano — «promoção» ✓'));
    }

    public function testToGsmKeepsSimplePunctuation(): void
    {
        $text = '15% na venda 1234, cliente Silva.';

        $this->assertSame($text, Text::toGsm($text));
    }

    public function testToGsmCollapsesWhitespace(): void
    {
        $this->assertSame('a b c', Text::toGsm("  a \t b\n c  "));
    }

    public function testStripAccentsOnlyTouchesAccents(): void
    {
        $this->assertSame('Nao, Coracao!', Text::stripAccents('Não, Coração!'));
    }

    public function testCutNeverEndsWithSpace(): void
    {
        $this->assertSame('abc', Text::cut('abc def', 4));
        $this->assertSame('', Text::cut('abc', 0));
    }
}
