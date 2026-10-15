<?php
declare(strict_types=1);

namespace App\Domain\Callback;

use App\Domain\Approval\ApprovalRepository;
use App\Domain\Approval\ApprovalService;
use App\Support\Clock;
use App\Support\Settings;
use Psr\Log\LoggerInterface;

/**
 * Avisa a APP (POST ao callback_url) quando um pedido de aprovação é decidido.
 *
 * Os pedidos decididos com callback_url ficam com callback_status = 'pending' (marcado pelo
 * ApprovalRepository). Este serviço é chamado em ciclo pelo CallbackWorker e envia os que já
 * podem ser enviados. Entrega "pelo menos uma vez": se a APP responder tarde e o gateway repetir,
 * a APP pode receber o mesmo aviso duas vezes, por isso deve tratá-lo de forma idempotente.
 */
final class CallbackService
{
    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly ApprovalService $approvalService,
        private readonly CallbackUrlPolicy $policy,
        private readonly CallbackSender $sender,
        private readonly CallbackSigner $signer,
        private readonly CallbackSchedule $schedule,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int quantos callbacks foram tentados neste ciclo */
    public function processDue(): int
    {
        $due = $this->approvals->findDueCallbacks($this->clock->now(), (int) $this->settings->get('callbacks.batch_size', 5));

        foreach ($due as $row) {
            $this->deliver($row);
        }

        return count($due);
    }

    /** @param array{id:int,callback_url:string,api_client:?string,callback_attempts:int} $row */
    private function deliver(array $row): void
    {
        $id = $row['id'];
        $attempts = $row['callback_attempts'] + 1;
        $now = $this->clock->now();

        // O destino tem de continuar a ser permitido (o DNS pode ter mudado desde o pedido)
        $target = $this->policy->resolve($row['callback_url']);
        if (isset($target['error'])) {
            if (!$target['retry']) {
                // Um endereço proibido não melhora com o tempo: não vale a pena repetir
                $this->approvals->recordCallback($id, 'failed', $attempts, null, $target['error'], $now);
                $this->logger->warning('Callback recusado: destino não permitido', ['approval' => $id, 'motivo' => $target['error']]);
                return;
            }

            // O DNS pode estar só temporariamente em baixo: trata-se como uma tentativa falhada normal
            $this->scheduleRetry($id, $attempts, $target['error'], $now);
            return;
        }

        // O conteúdo é o mesmo do GET /api/approvals/{id}, mais o nome do evento. O estado do próprio
        // callback fica de fora: neste momento ainda é "pending" e só confundiria a APP.
        $payload = $this->approvalService->get($id);
        unset($payload['callback_status']);
        $body = json_encode(
            ['event' => 'approval.updated'] + $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $timestamp = $now->getTimestamp();
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-Gateway-Event: approval.updated',
            "X-Gateway-Delivery: {$id}-{$attempts}",
            "X-Gateway-Timestamp: {$timestamp}",
        ];
        $secret = $this->secretFor($row['api_client']);
        if ($secret !== null) {
            $headers[] = 'X-Gateway-Signature: ' . $this->signer->sign($secret, $timestamp, $body);
        }

        $result = $this->sender->send($row['callback_url'], $target, $body, $headers);

        if ($result['ok']) {
            $this->approvals->recordCallback($id, 'sent', $attempts, null, null, $now);
            $this->logger->info('Callback entregue', ['approval' => $id, 'tentativa' => $attempts]);
            return;
        }

        $this->scheduleRetry($id, $attempts, (string) $result['error'], $now);
    }

    /** Uma tentativa falhou: marca a próxima, ou desiste se já não há mais. */
    private function scheduleRetry(int $id, int $attempts, string $error, \DateTimeImmutable $now): void
    {
        $next = $this->schedule->nextAttemptAt($attempts, $now);
        if ($next === null) {
            $this->approvals->recordCallback($id, 'failed', $attempts, null, $error, $now);
            $this->logger->warning('Callback desistiu depois de várias tentativas', ['approval' => $id, 'tentativas' => $attempts, 'erro' => $error]);
            return;
        }

        $this->approvals->recordCallback($id, 'pending', $attempts, $next, $error, $now);
        $this->logger->info('Callback falhou, vai repetir', [
            'approval' => $id, 'tentativa' => $attempts, 'proxima' => $next->format('H:i:s'), 'erro' => $error,
        ]);
    }

    /**
     * A chave de API da APP que criou o pedido, usada para assinar. O pedido só guarda os 8 primeiros
     * caracteres da chave (api_client), por isso procuramos a chave completa nas configuradas.
     */
    private function secretFor(?string $apiClient): ?string
    {
        if ($apiClient === null || $apiClient === '' || str_starts_with($apiClient, 'ip:')) {
            return null;   // sem autenticação (modo de testes): não há chave para assinar
        }

        foreach ((array) $this->settings->get('auth.api_keys', []) as $key) {
            if (substr((string) $key, 0, 8) === $apiClient) {
                return (string) $key;
            }
        }

        return null;
    }
}
