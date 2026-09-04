<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spezitest\Admin\Security\CsrfTokenManager;

final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CsrfTokenManager $tokens,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $candidate = is_array($body) ? ($body['_csrf'] ?? null) : null;

        if ($this->tokens->validate($candidate)) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(400);
        $response->getBody()->write(
            '<!doctype html><html lang="de"><meta charset="utf-8">'
            . '<title>Ungültige Anfrage</title><h1>Ungültige Anfrage</h1>'
            . '<p>Das Sicherheitsmerkmal ist ungültig oder abgelaufen.</p></html>',
        );

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
