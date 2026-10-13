<?php

declare(strict_types=1);

namespace Plugin\AzanQuran\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the "Azan & Quran" button to Acacia Radio's panel pages. Acacia Radio has no plugin hook for its
 * precompiled Vue menus, so this appends one <script> tag to the panel's HTML; the script (templates/nav.js)
 * shows the button on station pages only if the user has "manage azan quran". Any failure leaves the page as is.
 * (Same approach as the Tracker plugin; once Acacia Radio Retail has a native menu item, this is switched off.)
 */
final class InjectNavButton implements MiddlewareInterface
{
    public const string SCRIPT_PATH = '/azan-quran-assets/nav.js';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            if (
                'GET' !== $request->getMethod()
                || !preg_match('#^/(station|dashboard|admin|profile)(/|$)#', $request->getUri()->getPath())
                || 200 !== $response->getStatusCode()
                || !str_contains($response->getHeaderLine('Content-Type'), 'text/html')
            ) {
                return $response;
            }

            $html = (string)$response->getBody();
            $pos = strripos($html, '</body>');
            if (false === $pos || str_contains($html, self::SCRIPT_PATH)) {
                return $response;
            }

            $tag = '<script src="' . self::SCRIPT_PATH . '" defer></script>';
            $body = \GuzzleHttp\Psr7\Utils::streamFor(substr($html, 0, $pos) . $tag . substr($html, $pos));

            return $response->withBody($body)->withoutHeader('Content-Length');
        } catch (\Throwable) {
            return $response;
        }
    }
}
