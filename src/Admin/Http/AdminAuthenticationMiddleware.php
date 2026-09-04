<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spezitest\Admin\Security\AdminAuthenticator;

final readonly class AdminAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminAuthenticator $authenticator,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->authenticator->isAuthenticated()) {
            return $handler->handle($request);
        }

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', '/admin/login');
    }
}
