<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Exception\ApiException;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Autenticação do grupo /api: lista de IPs permitidos + chave no header X-API-Key.
 * Guarda o cliente identificado no atributo "api_client" do pedido.
 */
final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->clientIp($request);

        // 1. Lista de IPs (vazia = qualquer um)
        $allowed = $this->settings->get('auth.allowed_ips', []);
        if ($allowed !== [] && !in_array($ip, $allowed, true)) {
            throw new ApiException(403, 'IP não autorizado');
        }

        // 2. Autenticação desligada: passa, identificado pelo IP
        if (!$this->settings->get('auth.enabled', true)) {
            return $handler->handle($request->withAttribute('api_client', 'ip:' . $ip));
        }

        // 3. Sem chaves configuradas: falha fechada (nunca deixa passar)
        $keys = $this->settings->get('auth.api_keys', []);
        if ($keys === []) {
            throw new ApiException(503, 'API sem chaves configuradas');
        }

        // 4. Compara a chave recebida com todas (hash_equals evita ataques de tempo)
        $given = $request->getHeaderLine('X-API-Key');
        foreach ($keys as $key) {
            if ($given !== '' && hash_equals($key, $given)) {
                return $handler->handle($request->withAttribute('api_client', substr($key, 0, 8)));
            }
        }

        throw new ApiException(401, 'Chave de API inválida ou em falta');
    }

    /**
     * IP real do cliente. Só confiamos no header CF-Connecting-IP quando o pedido
     * vem da própria máquina (o cloudflared); caso contrário qualquer um o podia forjar.
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $cf = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
                return $cf;
            }
        }
        return $remote;
    }
}
