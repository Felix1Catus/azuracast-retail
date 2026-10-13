<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Version;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /source: redirects to the source code of the running version in the public repository.
 *
 * Acacia Radio is licensed under the GNU AGPL v3 (or later); section 13 requires that users interacting with it
 * over a network can get its Corresponding Source. The repository is public, and the link points at the exact
 * commit this server runs (from the image's .gitinfo), or at the repository when the commit isn't known.
 */
final class SourceCodeAction implements SingleActionInterface
{
    public const string REPOSITORY = 'https://github.com/Felix1Catus/azuracast-retail';

    public function __construct(
        private readonly Version $version,
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $commit = (string)$this->version->getCommitHash();
        $url = preg_match('/^[0-9a-f]{7,40}$/', $commit)
            ? self::REPOSITORY . '/tree/' . $commit
            : self::REPOSITORY;

        return $response
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withRedirect($url, 302);
    }
}
