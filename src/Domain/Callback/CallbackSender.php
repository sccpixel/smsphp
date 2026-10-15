<?php
declare(strict_types=1);

namespace App\Domain\Callback;

/**
 * Faz o pedido HTTP (POST) ao callback_url da APP, com a extensão curl do PHP.
 *
 * Cuidados de segurança:
 *  - liga-se ao IP que o CallbackUrlPolicy já verificou (CURLOPT_RESOLVE), sem voltar a perguntar ao DNS;
 *  - não segue redirecionamentos (uma resposta 302 podia mandar-nos para um endereço interno);
 *  - o certificado https é verificado;
 *  - tempo limite curto, para uma APP lenta não bloquear o worker.
 */
final class CallbackSender
{
    public function __construct(private readonly int $timeoutSeconds)
    {
    }

    /**
     * @param array{host:string,ip:string,port:int,scheme:string} $target resultado de CallbackUrlPolicy::resolve()
     * @param list<string> $headers cabeçalhos "Nome: valor"
     * @return array{ok:bool,status:?int,error:?string}  ok = a APP respondeu 2xx
     */
    public function send(string $url, array $target, string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => null, 'error' => 'Não foi possível iniciar o pedido.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'SMS-Gateway-Callback/1',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // host:porta:ip -> usa este IP para este nome (o que verificámos), sem consultar o DNS
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'status' => null, 'error' => mb_substr("Falha na ligação: {$error}", 0, 255)];
        }
        if ($status < 200 || $status > 299) {
            return ['ok' => false, 'status' => $status, 'error' => "A APP respondeu HTTP {$status}."];
        }

        return ['ok' => true, 'status' => $status, 'error' => null];
    }
}
