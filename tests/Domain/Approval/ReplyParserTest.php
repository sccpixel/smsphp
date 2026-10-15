<?php
declare(strict_types=1);

namespace Tests\Domain\Approval;

use App\Domain\Approval\ReplyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplyParserTest extends TestCase
{
    /** @return array<string,array{string,bool,string}> */
    public static function recognized(): array
    {
        return [
            'SIM maiusculas'       => ['SIM 4821', true, '4821'],
            'sim minusculas'       => ['sim 4821', true, '4821'],
            'Sim misto'            => ['Sim 4821', true, '4821'],
            'espacos nas pontas'   => ['  SIM 4821  ', true, '4821'],
            'NAO sem acento'       => ['NAO 4821', false, '4821'],
            'nao minusculas'       => ['nao 4821', false, '4821'],
            'NÃO com acento'       => ['NÃO 4821', false, '4821'],
            'não com acento'       => ['não 4821', false, '4821'],
        ];
    }

    #[DataProvider('recognized')]
    public function testRecognizesStrictFormat(string $text, bool $approve, string $code): void
    {
        $reply = (new ReplyParser())->parse($text);

        $this->assertNotNull($reply);
        $this->assertSame($approve, $reply->approve);
        $this->assertSame($code, $reply->code);
    }

    /** @return array<string,array{string}> */
    public static function ignored(): array
    {
        return [
            'so SIM'              => ['SIM'],
            'so NAO'              => ['NAO'],
            'ponto final'         => ['Sim.'],
            'OK'                  => ['ok 4821'],
            'S'                   => ['s 4821'],
            'aprovo'              => ['aprovo 4821'],
            'yes'                 => ['yes 4821'],
            'codigo curto'        => ['SIM 482'],
            'codigo longo'        => ['SIM 48211'],
            'texto a mais'        => ['SIM 4821 obrigado'],
            'dois codigos'        => ['SIM 4821 4822'],
            'dois espacos'        => ['SIM  4821'],
            'sem espaco'          => ['SIM4821'],
            'virgula'             => ['SIM, 4821'],
            'so o codigo'         => ['4821'],
            'codigo antes'        => ['4821 SIM'],
            'texto qualquer'      => ['blabla'],
            'vazio'               => [''],
        ];
    }

    #[DataProvider('ignored')]
    public function testIgnoresAnythingElse(string $text): void
    {
        $this->assertNull((new ReplyParser())->parse($text));
    }
}
