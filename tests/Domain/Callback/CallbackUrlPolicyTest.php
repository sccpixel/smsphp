<?php
declare(strict_types=1);

namespace Tests\Domain\Callback;

use App\Domain\Callback\CallbackUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallbackUrlPolicyTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function acceptedByDefault(): array
    {
        return [
            'dominio https'    => ['https://app.exemplo.pt/hooks/sms'],
            'com porta'        => ['https://app.exemplo.pt:8443/hook'],
            'com query string' => ['https://app.exemplo.pt/hook?token=abc'],
            'ip publico'       => ['https://8.8.8.8/hook'],
            'trycloudflare'    => ['https://mild-funky-sorry-banner.trycloudflare.com/cb'],
        ];
    }

    #[DataProvider('acceptedByDefault')]
    public function testAcceptsPublicHttpsUrls(string $url): void
    {
        $this->assertNull((new CallbackUrlPolicy(false))->checkFormat($url));
    }

    /** @return array<string,array{string}> */
    public static function rejectedByDefault(): array
    {
        return [
            'http simples'         => ['http://app.exemplo.pt/hook'],
            'localhost'            => ['https://localhost/hook'],
            'subdominio localhost' => ['https://app.localhost/hook'],
            'loopback'             => ['https://127.0.0.1/hook'],
            'rede privada 192'     => ['https://192.168.1.50/hook'],
            'rede privada 10'      => ['https://10.0.0.5/hook'],
            'rede privada 172'     => ['https://172.16.4.1/hook'],
            'link-local cloud'     => ['https://169.254.169.254/latest/meta-data'],
            'cgnat'                => ['https://100.64.1.1/hook'],
            'ipv6 loopback'        => ['https://[::1]/hook'],
            'nome sem ponto'       => ['https://servidor/hook'],
            'dominio .local'       => ['https://impressora.local/hook'],
            'dominio .lan'         => ['https://nas.lan/hook'],
            'com utilizador'       => ['https://user:pass@app.exemplo.pt/hook'],
            'porta invalida'       => ['https://app.exemplo.pt:99999/hook'],
            'sem esquema'          => ['app.exemplo.pt/hook'],
            'esquema ftp'          => ['ftp://app.exemplo.pt/hook'],
            'javascript'           => ['javascript:alert(1)'],
            'file'                 => ['file:///etc/passwd'],
            'lixo'                 => ['isto nao e um url'],
        ];
    }

    #[DataProvider('rejectedByDefault')]
    public function testRejectsInternalOrUnsafeUrls(string $url): void
    {
        $this->assertNotNull((new CallbackUrlPolicy(false))->checkFormat($url), $url);
    }

    public function testRejectsUrlsThatAreTooLong(): void
    {
        $url = 'https://app.exemplo.pt/' . str_repeat('a', 300);

        $this->assertNotNull((new CallbackUrlPolicy(false))->checkFormat($url));
    }

    public function testInternalModeAcceptsHttpAndPrivateNetworks(): void
    {
        $policy = new CallbackUrlPolicy(true);

        $this->assertNull($policy->checkFormat('http://192.168.1.50:8080/hook'));
        $this->assertNull($policy->checkFormat('http://servidor-interno/hook'));
        $this->assertNull($policy->checkFormat('https://localhost/hook'));
    }

    public function testInternalModeStillRejectsOtherSchemesAndCredentials(): void
    {
        $policy = new CallbackUrlPolicy(true);

        $this->assertNotNull($policy->checkFormat('ftp://192.168.1.50/x'));
        $this->assertNotNull($policy->checkFormat('http://user:pass@192.168.1.50/x'));
    }

    /** @return array<string,array{string,bool}> */
    public static function ips(): array
    {
        return [
            'google dns'      => ['8.8.8.8', true],
            'privado 192'     => ['192.168.0.1', false],
            'privado 10'      => ['10.1.2.3', false],
            'loopback'        => ['127.0.0.1', false],
            'link-local'      => ['169.254.1.1', false],
            'cgnat inicio'    => ['100.64.0.1', false],
            'cgnat fim'       => ['100.127.255.254', false],
            'logo apos cgnat' => ['100.128.0.1', true],
            'ipv6 loopback'   => ['::1', false],
            'ipv6 privado'    => ['fd00::1', false],
            'ipv6 publico'    => ['2606:4700:4700::1111', true],
            'nao e um ip'     => ['abc', false],
        ];
    }

    #[DataProvider('ips')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        $this->assertSame($expected, CallbackUrlPolicy::isPublicIp($ip), $ip);
    }

    public function testResolveReturnsTheLiteralIpWithoutDnsAndKeepsPortAndScheme(): void
    {
        $target = (new CallbackUrlPolicy(true))->resolve('http://127.0.0.1:9000/hook');

        $this->assertSame(['host' => '127.0.0.1', 'ip' => '127.0.0.1', 'port' => 9000, 'scheme' => 'http'], $target);
    }

    public function testResolveRejectsAPrivateIpByDefault(): void
    {
        $result = (new CallbackUrlPolicy(false))->resolve('https://10.0.0.5/hook');

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['retry']);   // proibido: não vale a pena repetir
    }

    public function testResolveRejectsLocalhostByDefault(): void
    {
        $result = (new CallbackUrlPolicy(false))->resolve('https://localhost/hook');

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['retry']);
    }

    public function testResolveFailureOfAnUnknownNameIsRetryable(): void
    {
        // O domínio .invalid nunca existe (reservado pela norma): o DNS devolve "não encontrado"
        $result = (new CallbackUrlPolicy(false))->resolve('https://servidor-que-nao-existe.exemplo.invalid/hook');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($result['retry']);   // pode ser só o DNS em baixo: volta a tentar
    }
}
