<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Exception\ValidationException;
use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function portugueseFormats(): array
    {
        return [
            'com +351'            => ['+351912345678'],
            'com 00351 e espacos' => ['00351 912 345 678'],
            'com 351'             => ['351912345678'],
            'so 9 digitos'        => ['912345678'],
            'com tracos'          => ['+351 912-345-678'],
        ];
    }

    #[DataProvider('portugueseFormats')]
    public function testNormalizeRemovesCountryCode(string $input): void
    {
        $this->assertSame('912345678', Phone::normalize($input));
    }

    /** @return array<string,array{string}> */
    public static function invalid(): array
    {
        return [
            'letras'         => ['abc'],
            'outro pais +'   => ['+44123456789'],
            'outro pais 00'  => ['0044123456789'],
            'muito curto'    => ['123'],
            'vazio'          => [''],
            'so o indicativo' => ['+351'],
        ];
    }

    #[DataProvider('invalid')]
    public function testNormalizeRejectsInvalidNumbers(string $input): void
    {
        $this->expectException(ValidationException::class);

        Phone::normalize($input);
    }

    public function testErrorUsesTheGivenFieldName(): void
    {
        try {
            Phone::normalize('abc', 'messages.3.to');
            $this->fail('devia ter lançado ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['messages.3.to' => 'Número de telefone inválido'], $e->errors());
        }
    }

    #[DataProvider('portugueseFormats')]
    public function testKeyIsTheLastNineDigits(string $input): void
    {
        $this->assertSame('912345678', Phone::key($input));
    }
}
