<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Container\EnvironmentAwareTrait;
use App\Controller\SingleActionInterface;
use App\Flysystem\Adapter\LocalFilesystemAdapter;
use App\Flysystem\LocalFilesystem;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Lock\LockFactory;
use App\Version;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

/**
 * GET /source: the complete source code of the version running on this server, as a .tar.gz.
 *
 * Acacia Radio is licensed under the GNU AGPL v3 (or later). Section 13 requires that users interacting
 * with a modified version over a network can get its Corresponding Source. Serving it from the running
 * install guarantees the download always matches what is deployed, even if the repository is private.
 *
 * Third-party dependencies (vendor/, node_modules/) are left out; they are pinned by composer.lock and
 * package-lock.json, which are included. Build output and caches are left out too.
 */
final class SourceCodeAction implements SingleActionInterface
{
    use EnvironmentAwareTrait;

    private const array EXCLUDE = [
        './.git',
        './vendor',
        './node_modules',
        './frontend/node_modules',
        './web/static/vite_dist',
        './www_tmp',
    ];

    public function __construct(
        private readonly Version $version,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $name = 'azuracast-retail-' . $this->version->getVersion()
            . '-' . ($this->version->getCommitShort() ?: 'unknown');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $file = $this->environment->getTempDirectory() . '/source/' . $name . '.tar.gz';

        if (!is_file($file)) {
            $lock = $this->lockFactory->createLock('source-archive');
            $lock->acquire(true);
            try {
                if (!is_file($file)) {
                    $this->build($file);
                }
            } finally {
                $lock->release();
            }
        }

        $fs = new LocalFilesystem(new LocalFilesystemAdapter(dirname($file)));
        return $response
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->streamFilesystemFile($fs, basename($file), $name . '.tar.gz', 'attachment', false);
    }

    private function build(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Old archives belong to previous versions; keep only the current one.
        foreach (glob($dir . '/*.tar.gz') ?: [] as $old) {
            @unlink($old);
        }

        $tmp = $file . '.part';
        $cmd = ['tar', '-czf', $tmp, '-C', $this->environment->getBaseDirectory()];
        foreach (self::EXCLUDE as $path) {
            $cmd[] = '--exclude=' . $path;
        }
        $cmd[] = '.';

        $process = new Process($cmd);
        $process->setTimeout(300);
        $process->mustRun();
        rename($tmp, $file);
    }
}
