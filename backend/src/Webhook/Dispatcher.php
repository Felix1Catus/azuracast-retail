<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Message;
use Throwable;

/**
 * Handles the now-playing "dispatch" message for a station.
 *
 * Retail edition: outgoing webhooks (Discord, Telegram, TuneIn, analytics, ...) are not part of the product, so
 * only the local handler runs. It writes the station's static now-playing files and pushes to Centrifugo.
 */
final class Dispatcher
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly LocalWebhookHandler $localHandler,
    ) {
    }

    public function __invoke(Message\AbstractMessage $message): void
    {
        if (!$message instanceof Message\DispatchWebhookMessage) {
            return;
        }

        $station = $this->em->find(Station::class, $message->station_id);
        if (!$station instanceof Station) {
            return;
        }

        $np = $message->np;

        // Recalculate elapsed/remaining in case this message spent a long time in queue.
        $np->update();

        try {
            $this->localHandler->dispatch($station, $np, $message->triggers);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s L%d: %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                [
                    'exception' => $e,
                ]
            );
        }
    }
}
