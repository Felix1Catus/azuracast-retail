<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Radio;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use Plugin\AzanQuran\Service\Store;

/**
 * Adds a telnet command to the Liquidsoap configuration of stations that use the plugin:
 *   azan_quran.fade_skip  fade the current track out over fade_seconds, skip it, then fade the next one in over
 *                         3 s (which also hides the crossfade's tail of the skipped track)
 * Sync\CueTask sends it at opening time, to end the Quran. Runs after the live-DJ fallback (priority 20) and
 * before the pre-broadcast section (10). The station needs a restart to load it (the page says so).
 */
final class LiquidsoapFade
{
    public function __construct(
        private readonly Store $store,
    ) {
    }

    public function __invoke(WriteLiquidsoapConfiguration $event): void
    {
        try {
            $station = $event->getStation();
            if (!$this->store->getStation($station->id)['is_enabled']) {
                return;
            }
            $fade = number_format(max(1, (int)$this->store->getGlobal()['fade_seconds']), 1, '.', '');
            $event->appendBlock(self::script($fade));
        } catch (\Throwable $e) {
            error_log('[azan-quran] Liquidsoap configuration failed: ' . $e->getMessage());
        }
    }

    public static function script(string $fade): string
    {
        return <<<LIQ
            # Azan & Quran plugin: "azan_quran.fade_skip" fades the current track out over {$fade} s, skips it, then
            # fades the next one in over 3 s. Sent at opening time to end the Quran.
            azan_quran_fade_at = ref(-1.)
            def azan_quran_gain() =
              if azan_quran_fade_at() < 0. then
                1.
              else
                t = time() - azan_quran_fade_at()
                if t < {$fade} then
                  1. - t / {$fade}
                elsif t < {$fade} + 3. then
                  (t - {$fade}) / 3.
                else
                  azan_quran_fade_at := -1.
                  1.
                end
              end
            end
            radio = amplify(override=null, azan_quran_gain, radio)
            azan_quran_source = radio
            def azan_quran_fade_skip(_) =
              azan_quran_fade_at := time()
              thread.run(delay={$fade}, fun () -> source.skip(azan_quran_source))
              "OK"
            end
            server.register(namespace="azan_quran", description="Fade out the current track, skip it, fade the next one in.", "fade_skip", azan_quran_fade_skip)
            LIQ;
    }
}
