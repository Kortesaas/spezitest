<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use Spezitest\Admin\DrinkService;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Image\ImageValidationException;
use Spezitest\Admin\Image\UploadedImageValidator;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Admin\Security\AdminAuthenticator;
use Spezitest\Admin\Security\CsrfTokenManager;
use Spezitest\Admin\Validation\DrinkInputValidator;
use Spezitest\Admin\Validation\ValidationException;
use Spezitest\Application\AdminRuntime;

final class AdminController
{
    private ?PDO $connection = null;

    private readonly DrinkInputValidator $validator;

    private readonly ImageStorage $imageStorage;

    public function __construct(
        private readonly AdminRuntime $runtime,
        private readonly AdminAuthenticator $authenticator,
        private readonly CsrfTokenManager $csrfTokens,
        private readonly HtmlRenderer $renderer,
    ) {
        $this->validator = new DrinkInputValidator();
        $configuration = $this->runtime->configuration();
        $this->imageStorage = new ImageStorage(
            $configuration->imageStorageRoot(),
            $configuration->legacyImageStorageRoot(),
        );
    }

    public function loginForm(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        if ($this->authenticator->isAuthenticated()) {
            return $this->redirect($response, '/admin');
        }

        return $this->html($response, $this->renderer->login($this->csrfTokens->token()));
    }

    public function login(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $this->body($request);
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;

        if (
            !is_string($username)
            || !is_string($password)
            || strlen($username) > 190
            || strlen($password) > 4096
            || !$this->authenticator->login($username, $password)
        ) {
            return $this->html(
                $response,
                $this->renderer->login($this->csrfTokens->token(), 'Anmeldung fehlgeschlagen.'),
                401,
            );
        }

        $this->csrfTokens->rotate();

        return $this->redirect($response, '/admin');
    }

    public function logout(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->authenticator->logout();

        return $this->redirect($response, '/admin/login');
    }

    public function dashboard(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        return $this->html(
            $response,
            $this->renderer->dashboard(
                $this->repository()->lifecycleCounts(),
                $this->csrfTokens->token(),
            ),
        );
    }

    public function drinks(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        try {
            $search = $this->validator->validateSearch($query['q'] ?? null);
            $statusValue = $query['lifecycle_status'] ?? null;
            $status = $statusValue === null || $statusValue === ''
                ? null
                : $this->validator->validateStatus($statusValue);
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->drinks([], '', null, $this->csrfTokens->token(), $exception->getMessage()),
                422,
            );
        }

        return $this->html(
            $response,
            $this->renderer->drinks(
                $this->repository()->search($search, $status),
                $search,
                $status,
                $this->csrfTokens->token(),
            ),
        );
    }

    public function createForm(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        return $this->html(
            $response,
            $this->renderer->createForm($this->csrfTokens->token()),
        );
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $this->body($request);

        try {
            $input = $this->validator->validate($body, true);
            $drinkId = $this->service()->create($input, $this->picture($request));
        } catch (ValidationException|ImageValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->createForm($this->csrfTokens->token(), $body, $exception->getMessage()),
                422,
            );
        }

        return $this->redirect($response, '/admin/drinks/' . $drinkId . '/edit');
    }

    /** @param array<string, string> $arguments */
    public function editForm(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);
        $drink = $this->repository()->find($drinkId);

        if ($drink === null) {
            return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->editForm(
                $drink,
                $this->repository()->primaryImage($drinkId) !== null,
                $this->csrfTokens->token(),
            ),
        );
    }

    /** @param array<string, string> $arguments */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);
        $body = $this->body($request);

        try {
            $input = $this->validator->validate($body);
            $updated = $this->service()->update(
                $drinkId,
                $input,
                $this->picture($request),
                ($body['remove_image'] ?? null) === '1',
            );
        } catch (ValidationException|ImageValidationException $exception) {
            $drink = $this->repository()->find($drinkId);

            if ($drink === null) {
                return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->editForm(
                    $drink,
                    $this->repository()->primaryImage($drinkId) !== null,
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        if (!$updated) {
            return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
        }

        return $this->redirect($response, '/admin/drinks/' . $drinkId . '/edit');
    }

    /** @param array<string, string> $arguments */
    public function changeStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);

        try {
            $status = $this->validator->validateStatus($this->body($request)['lifecycle_status'] ?? null);
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->drinks([], '', null, $this->csrfTokens->token(), $exception->getMessage()),
                422,
            );
        }

        try {
            $updated = $this->service()->changeStatus($drinkId, $status);
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->drinks(
                    $this->repository()->search('', null),
                    '',
                    null,
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        if (!$updated) {
            return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
        }

        return $this->redirect($response, '/admin/drinks');
    }

    /** @param array<string, string> $arguments */
    public function deleteConfirmation(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drink = $this->repository()->find($this->drinkId($arguments));

        if ($drink === null) {
            return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->deleteConfirmation($drink, $this->csrfTokens->token()),
        );
    }

    /** @param array<string, string> $arguments */
    public function delete(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);

        try {
            $deleted = $this->service()->delete($drinkId);
        } catch (ValidationException $exception) {
            $drink = $this->repository()->find($drinkId);

            if ($drink === null) {
                return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->deleteConfirmation($drink, $this->csrfTokens->token(), $exception->getMessage()),
                409,
            );
        }

        if (!$deleted) {
            return $this->html($response, $this->renderer->notFound($this->csrfTokens->token()), 404);
        }

        return $this->redirect($response, '/admin/drinks');
    }

    /** @param array<string, string> $arguments */
    public function image(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $image = $this->repository()->primaryImage($this->drinkId($arguments));

        if ($image === null) {
            return $response->withStatus(404);
        }

        if (!in_array($image['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $response->withStatus(404);
        }

        $path = $this->imageStorage->absolutePath($image['storage_path']);

        if ($path === null || !is_file($path) || is_link($path)) {
            return $response->withStatus(404);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return $response->withStatus(404);
        }

        $size = filesize($path);

        if ($size === false) {
            fclose($handle);

            return $response->withStatus(404);
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $image['mime_type'])
            ->withHeader('Content-Length', (string) $size);
    }

    private function repository(): DrinkRepository
    {
        return new DrinkRepository($this->connection());
    }

    private function service(): DrinkService
    {
        $configuration = $this->runtime->configuration();

        return new DrinkService(
            $this->connection(),
            $this->repository(),
            new UploadedImageValidator($configuration->imageMaximumBytes()),
            $this->imageStorage,
        );
    }

    private function connection(): PDO
    {
        return $this->connection ??= $this->runtime->connection();
    }

    /** @return array<array-key, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function picture(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $picture = $request->getUploadedFiles()['picture'] ?? null;

        if ($picture === null) {
            return null;
        }

        if (!$picture instanceof UploadedFileInterface) {
            throw new ValidationException('Der Bild-Upload ist ungültig.');
        }

        return $picture;
    }

    /** @param array<string, string> $arguments */
    private function drinkId(array $arguments): int
    {
        $id = $arguments['id'] ?? '';

        if (!ctype_digit($id) || (int) $id < 1) {
            throw new ValidationException('Die Getränke-ID ist ungültig.');
        }

        return (int) $id;
    }

    private function html(ResponseInterface $response, string $html, int $status = 200): ResponseInterface
    {
        $response->getBody()->write($html);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $location);
    }
}
