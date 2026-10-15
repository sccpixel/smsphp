<?php
declare(strict_types=1);

namespace App\Domain\Callback;

/**
 * Decide para que endereços o gateway aceita enviar callbacks.
 *
 * O gateway faz pedidos ao URL que a APP indicou, e qualquer cliente da API pode indicar o que quiser.
 * Sem limites, alguém podia usar o servidor para atingir a rede interna da empresa (um "SSRF").
 * Por isso, por defeito só aceitamos:
 *   - https (o conteúdo vai cifrado e o certificado é verificado);
 *   - endereços PÚBLICOS: recusa localhost, redes privadas (192.168.x.x, 10.x.x.x, 172.16-31.x.x),
 *     link-local (169.254.x.x, onde ficam os serviços de metadados na cloud) e outras reservadas.
 *
 * Com CALLBACK_ALLOW_INTERNAL=true aceita também http e redes internas (APPs na rede da empresa).
 */
final class CallbackUrlPolicy
{
    public const MAX_LENGTH = 255;

    public function __construct(private readonly bool $allowInternal)
    {
    }

    /**
     * Verifica só a forma do URL (sem consultar a rede). Usado ao criar o pedido.
     *
     * @return string|null a mensagem de erro, ou null se for aceitável
     */
    public function checkFormat(string $url): ?string
    {
        if (mb_strlen($url) > self::MAX_LENGTH) {
            return 'O endereço do callback é demasiado longo.';
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'O endereço do callback não é um URL válido.';
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($this->allowInternal && $scheme === 'http')) {
            return $this->allowInternal
                ? 'O endereço do callback tem de começar por http:// ou https://.'
                : 'O endereço do callback tem de começar por https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'O endereço do callback não pode ter utilizador e password.';
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return 'A porta do endereço do callback é inválida.';
        }

        if (!$this->allowInternal) {
            $host = $this->host($parts['host']);
            if ($this->looksInternal($host)) {
                return 'O endereço do callback tem de ser público (não pode ser localhost nem uma rede interna).';
            }
        }

        return null;
    }

    /**
     * Resolve o nome do servidor e confirma que o destino é permitido. Usado no momento de enviar:
     * a resposta do DNS pode mudar entre o pedido e o envio, por isso verifica-se outra vez aqui,
     * e o envio liga-se ao IP verificado (e não volta a perguntar ao DNS).
     *
     * @return array{host:string,ip:string,port:int,scheme:string}|array{error:string,retry:bool}
     *   o destino; ou o erro, com retry = true se o problema pode passar (DNS temporariamente em baixo)
     *   e retry = false se o endereço é proibido (não vale a pena repetir)
     */
    public function resolve(string $url): array
    {
        if (($error = $this->checkFormat($url)) !== null) {
            return ['error' => $error, 'retry' => false];
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) $parts['scheme']);
        $host = $this->host((string) $parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Já é um IP
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
            if ($ips === []) {
                return ['error' => "Não foi possível resolver o servidor {$host}.", 'retry' => true];
            }
        }

        if (!$this->allowInternal) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    return ['error' => "O servidor {$host} aponta para um endereço interno ({$ip}), que não é permitido.", 'retry' => false];
                }
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => $port, 'scheme' => $scheme];
    }

    /** O IP é público (não é privado, reservado, loopback nem link-local)? */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 (CGNAT: redes de operador) não é apanhado pelos filtros do PHP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
                return false;
            }
        }

        return true;
    }

    /** Nomes que são claramente internos, sem precisar de consultar o DNS. */
    private function looksInternal(string $host): bool
    {
        $host = strtolower($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return !self::isPublicIp($host);
        }

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.lan')
            || !str_contains($host, '.');   // nome sem ponto (ex.: "servidor"): só existe numa rede interna
    }

    /** Tira os parênteses retos de um IPv6 ([::1] -> ::1). */
    private function host(string $host): string
    {
        return trim($host, '[]');
    }
}
