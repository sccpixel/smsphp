<?php
declare(strict_types=1);

namespace Tests\Domain\Callback;

use App\Domain\Callback\CallbackSigner;
use PHPUnit\Framework\TestCase;

final class CallbackSignerTest extends TestCase
{
    public function testSignatureMatchesTheDocumentedFormula(): void
    {
        $sig = (new CallbackSigner())->sign('a-chave-da-app', 1760000000, '{"id":7}');

        // Isto é exatamente o que a APP deve calcular para verificar
        $this->assertSame('sha256=' . hash_hmac('sha256', '1760000000.{"id":7}', 'a-chave-da-app'), $sig);
        $this->assertMatchesRegularExpression('/^sha256=[a-f0-9]{64}$/', $sig);
    }

    public function testDifferentBodyTimestampOrKeyChangeTheSignature(): void
    {
        $signer = new CallbackSigner();
        $base = $signer->sign('chave', 100, 'corpo');

        $this->assertNotSame($base, $signer->sign('chave', 100, 'corpo!'));
        $this->assertNotSame($base, $signer->sign('chave', 101, 'corpo'));
        $this->assertNotSame($base, $signer->sign('outra', 100, 'corpo'));
        $this->assertSame($base, $signer->sign('chave', 100, 'corpo'));
    }
}
