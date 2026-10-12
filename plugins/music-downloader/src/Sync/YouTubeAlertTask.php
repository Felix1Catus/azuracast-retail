<?php

declare(strict_types=1);

namespace Plugin\MusicDownloader\Sync;

use App\Enums\GlobalPermissions;
use App\Service\Mail;
use App\Sync\Task\AbstractTask;
use Plugin\MusicDownloader\Service\WorkerClient;
use Plugin\MusicDownloader\Service\WorkerUnavailable;

/**
 * Every 5 minutes: when the worker reports a new YouTube account alert (signed out, or the check failing),
 * email the administrators once, if outgoing mail is set up (Administration > Settings > Email).
 * The dashboard notice (Notification\YouTubeNotice) shows the same state either way.
 */
final class YouTubeAlertTask extends AbstractTask
{
    public function __construct(
        private readonly WorkerClient $worker,
        private readonly Mail $mail,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '*/5 * * * *';
    }

    public function run(bool $force = false): void
    {
        if (!WorkerClient::isConfigured() || !$this->mail->isEnabled()) {
            return;
        }

        try {
            [$status, $data] = $this->worker->call('GET', '/youtube/alert', timeout: 5.0);
        } catch (WorkerUnavailable) {
            return;
        }
        $alert = $data['alert'] ?? null;
        if (200 !== $status || !is_array($alert)) {
            return;
        }

        $emails = $this->em->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT u.email FROM users u
                JOIN user_has_role ur ON ur.user_id = u.id
                JOIN role_permissions p ON p.role_id = ur.role_id
                WHERE p.station_id IS NULL AND p.action_name IN (:all, :settings)
            SQL,
            ['all' => GlobalPermissions::All->value, 'settings' => GlobalPermissions::Settings->value]
        );
        if ([] === $emails) {
            return;
        }

        $subject = 'expired' === ($alert['status'] ?? '')
            ? 'Music Downloader: the YouTube account was signed out'
            : 'Music Downloader: the YouTube account check is failing';
        $body = (string)($alert['message'] ?? '') . "\n\n"
            . "Until this is fixed, downloads from YouTube may fail. Open a station's Music Downloader page, "
            . "then Settings > YouTube account, and sign in again.\n";

        $sent = false;
        foreach ($emails as $address) {
            try {
                $email = $this->mail->createMessage();
                $email->to((string)$address);
                $email->subject($subject);
                $email->text($body);
                $this->mail->send($email);
                $sent = true;
            } catch (\Throwable $e) {
                $this->logger->error('Music Downloader alert email failed', ['to' => $address, 'error' => $e->getMessage()]);
            }
        }

        if ($sent) {
            $this->worker->call('POST', '/youtube/alert/ack', json: ['at' => (int)($alert['at'] ?? 0)], timeout: 5.0);
        }
    }
}
