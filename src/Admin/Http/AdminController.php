<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Spezitest\Admin\DrinkService;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Image\ImageValidationException;
use Spezitest\Admin\Image\UploadedImageValidator;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Admin\Persistence\TestRepository;
use Spezitest\Admin\Persistence\TestRunRepository;
use Spezitest\Admin\Security\AdminAuthenticator;
use Spezitest\Admin\Security\CsrfTokenManager;
use Spezitest\Admin\TestService;
use Spezitest\Admin\Testing\TestEntryValidator;
use Spezitest\Admin\Testing\TestFormData;
use Spezitest\Admin\Testing\TestRunValidator;
use Spezitest\Admin\Testing\TestStreamPosition;
use Spezitest\Admin\Validation\DrinkInputValidator;
use Spezitest\Admin\Validation\ValidationException;
use Spezitest\Application\AdminRuntime;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterRatingFactory;
use Spezitest\Media\ImageResponder;
use Spezitest\Website\Catalog\CatalogRepository;
use Spezitest\Website\Catalog\StreamEpisode;
use Spezitest\Website\Catalog\RatedDrink;

final class AdminController
{
    private ?PDO $connection = null;

    /** @var array{identified: int, acquired: int, tested: int}|null */
    private ?array $lifecycleCounts = null;

    private readonly DrinkInputValidator $validator;

    private readonly TestEntryValidator $testValidator;

    private readonly TestRunValidator $runValidator;

    private readonly ImageStorage $imageStorage;

    public function __construct(
        private readonly AdminRuntime $runtime,
        private readonly AdminAuthenticator $authenticator,
        private readonly CsrfTokenManager $csrfTokens,
        private readonly HtmlRenderer $renderer,
    ) {
        $this->validator = new DrinkInputValidator();
        $this->testValidator = new TestEntryValidator();
        $this->runValidator = new TestRunValidator();
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
                $this->counts(),
                $this->repository()->qualityCounts(),
                $this->repository()->search('', 'acquired'),
                $this->csrfTokens->token(),
            ),
        );
    }

    public function drinks(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        try {
            $filters = $this->listFilters($request->getQueryParams(), '/admin/drinks');
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->drinks(
                    [],
                    new DrinkListFilters(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        return $this->html(
            $response,
            $this->renderer->drinks(
                $this->repository()->searchDetailed($filters->search, $filters->status, $filters->flag, $filters->sort),
                $filters,
                $this->counts(),
                $this->csrfTokens->token(),
            ),
        );
    }

    public function testQueue(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        try {
            $filters = $this->listFilters($request->getQueryParams(), '/admin/test');
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->testQueue(
                    [],
                    new DrinkListFilters(path: '/admin/test'),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        // The queue is always the acquired drinks, so the status chips stay off
        // this page and the filter object never carries a status of its own.
        return $this->html(
            $response,
            $this->renderer->testQueue(
                $this->repository()->searchDetailed($filters->search, 'acquired', $filters->flag, $filters->sort),
                $filters,
                $this->counts(),
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
            $this->renderer->createForm($this->counts(), $this->csrfTokens->token()),
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
                $this->renderer->createForm($this->counts(), $this->csrfTokens->token(), $body, $exception->getMessage()),
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
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->editForm(
                $drink,
                $this->repository()->primaryImage($drinkId) !== null,
                $this->counts(),
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
                return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->editForm(
                    $drink,
                    $this->repository()->primaryImage($drinkId) !== null,
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        if (!$updated) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
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
                $this->renderer->drinks(
                    [],
                    new DrinkListFilters(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        try {
            $updated = $this->service()->changeStatus($drinkId, $status);
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->drinks(
                    $this->repository()->searchDetailed('', null),
                    new DrinkListFilters(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        if (!$updated) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
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
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->deleteConfirmation($drink, $this->counts(), $this->csrfTokens->token()),
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
                return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->deleteConfirmation($drink, $this->counts(), $this->csrfTokens->token(), $exception->getMessage()),
                409,
            );
        }

        if (!$deleted) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
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

        return (new ImageResponder($this->imageStorage))->respond(
            $response,
            $image['storage_path'],
            $image['mime_type'],
        );
    }

    /** @param array<string, string> $arguments */
    public function testForm(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);
        $drink = $this->repository()->find($drinkId);

        if ($drink === null) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->testForm(
                $drink,
                $this->loadTestFormData($drinkId),
                $this->runRepository()->all(),
                $this->counts(),
                $this->csrfTokens->token(),
                $this->repository()->primaryImage($drinkId) !== null,
            ),
        );
    }

    /** @param array<string, string> $arguments */
    public function saveTestDraft(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        return $this->handleTestSubmission($request, $response, $arguments, false);
    }

    /** @param array<string, string> $arguments */
    public function completeTest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        return $this->handleTestSubmission($request, $response, $arguments, true);
    }

    /** @param array<string, string> $arguments */
    private function handleTestSubmission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
        bool $complete,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);
        $body = $this->body($request);

        try {
            $input = $this->testValidator->validate($body, $complete);
            $position = $this->streamPosition($body);

            if ($complete) {
                $this->testService()->complete($drinkId, $input, $position);
            } else {
                $this->testService()->saveDraft($drinkId, $input, $position);
            }
        } catch (ValidationException $exception) {
            $drink = $this->repository()->find($drinkId);

            if ($drink === null) {
                return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->testForm(
                    $drink,
                    $this->testFormDataFromBody($body, $drink['lifecycle_status']),
                    $this->runRepository()->all(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $this->repository()->primaryImage($drinkId) !== null,
                    $exception->getMessage(),
                ),
                422,
            );
        }

        $target = $complete ? '/admin/drinks/' . $drinkId . '/test/result' : '/admin/drinks/' . $drinkId . '/test';

        return $this->redirect($response, $target);
    }

    /** @param array<string, string> $arguments */
    public function testResult(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $drinkId = $this->drinkId($arguments);
        $collection = (new CatalogRepository($this->connection()))->ratedDrinks();
        $drink = $collection->find($drinkId);

        if ($drink === null || !$drink->isTested()) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
        }

        $ranked = $collection->ranked();
        $gesamtTotal = count($ranked);
        [$rankAbove, $rankBelow] = $this->neighbors($ranked, $drink);

        $pricePosition = null;
        $priceTotal = null;
        $priceAbove = null;
        $priceBelow = null;

        if ($drink->pricePerformance !== null) {
            $priceRanked = $collection->pricePerformanceRanked();
            $priceTotal = count($priceRanked);
            $index = $this->indexOf($priceRanked, $drink);

            if ($index !== null) {
                $pricePosition = $index + 1;
                [$priceAbove, $priceBelow] = $this->neighbors($priceRanked, $drink);
            }
        }

        return $this->html(
            $response,
            $this->renderer->testResult(
                $drink,
                $gesamtTotal,
                $rankAbove,
                $rankBelow,
                $pricePosition,
                $priceTotal,
                $priceAbove,
                $priceBelow,
                $this->counts(),
                $this->csrfTokens->token(),
            ),
        );
    }

    /**
     * @param list<RatedDrink> $ranked
     * @return array{0: ?RatedDrink, 1: ?RatedDrink}
     */
    private function neighbors(array $ranked, RatedDrink $drink): array
    {
        $index = $this->indexOf($ranked, $drink);

        if ($index === null) {
            return [null, null];
        }

        return [
            $ranked[$index - 1] ?? null,
            $ranked[$index + 1] ?? null,
        ];
    }

    /** @param list<RatedDrink> $ranked */
    private function indexOf(array $ranked, RatedDrink $drink): ?int
    {
        foreach ($ranked as $index => $candidate) {
            if ($candidate->id === $drink->id) {
                return $index;
            }
        }

        return null;
    }

    private function loadTestFormData(int $drinkId): TestFormData
    {
        $repository = $this->testRepository();
        $test = $repository->currentTest($drinkId);

        if ($test === null) {
            return TestFormData::empty();
        }

        $grades = $repository->ratingsByTesterCode($test['id']);
        $result = (new RatingCalculator())->calculate(TesterRatingFactory::fromMap($grades));

        return new TestFormData(
            $grades,
            $test['notes'] ?? '',
            $test['status'],
            $result,
            $test['stream_reference'],
            $test['recorded_time'],
            $test['duration_value'],
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function testFormDataFromBody(array $body, string $status): TestFormData
    {
        $run = $body['stream_reference'] ?? null;
        $offset = $body['recorded_time'] ?? null;
        $duration = $body['duration_value'] ?? null;

        $grades = [];

        foreach (['manu', 'fabi', 'schorsch'] as $code) {
            $set = [
                'optik' => $this->digitField($body, $code . '_optik'),
                'sueffigkeit' => $this->digitField($body, $code . '_sueffigkeit'),
                'geschmack' => $this->digitField($body, $code . '_geschmack'),
            ];

            if ($set['optik'] !== '' || $set['sueffigkeit'] !== '' || $set['geschmack'] !== '') {
                $grades[$code] = $set;
            }
        }

        $notes = $body['notes'] ?? '';

        return new TestFormData(
            $grades,
            is_string($notes) ? $notes : '',
            $status === 'tested' ? 'completed' : 'draft',
            null,
            is_string($run) && ctype_digit($run) ? (int) $run : null,
            is_string($offset) && $offset !== '' ? $offset : null,
            is_string($duration) && ctype_digit($duration) ? (int) $duration : null,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function digitField(array $body, string $key): string
    {
        $raw = $body[$key] ?? null;

        if (!is_string($raw)) {
            return '';
        }

        $raw = trim($raw);

        return ctype_digit($raw) ? $raw : '';
    }

    public function testRuns(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        $repository = $this->runRepository();

        return $this->html(
            $response,
            $this->renderer->testRuns(
                $repository->all(),
                $repository->nextNumber(),
                $this->counts(),
                $this->csrfTokens->token(),
            ),
        );
    }

    /** @param array<string, string> $arguments */
    public function testRun(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $repository = $this->runRepository();
        $run = $repository->find($this->runNumber($arguments));

        if ($run === null) {
            return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
        }

        return $this->html(
            $response,
            $this->renderer->testRun(
                $run,
                $repository->tests($run->number),
                $this->episode($run->number),
                $this->counts(),
                $this->csrfTokens->token(),
            ),
        );
    }

    /** Start the next Testabend, so tonight's completed tests are filed under it. */
    public function startTestRun(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $repository = $this->runRepository();

        try {
            $number = $this->runValidator->validateNumber($this->body($request)['number'] ?? null);
        } catch (ValidationException $exception) {
            return $this->html(
                $response,
                $this->renderer->testRuns(
                    $repository->all(),
                    $repository->nextNumber(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        $open = $repository->openRun();

        if ($open !== null && $open->number !== $number) {
            return $this->html(
                $response,
                $this->renderer->testRuns(
                    $repository->all(),
                    $repository->nextNumber(),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    'Testabend #' . $open->number . ' läuft noch. Bitte zuerst abschließen.',
                ),
                422,
            );
        }

        $repository->open($number);

        return $this->redirect($response, '/admin/testabende/' . $number);
    }

    /** @param array<string, string> $arguments */
    public function updateTestRun(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $number = $this->runNumber($arguments);
        $repository = $this->runRepository();
        $body = $this->body($request);

        try {
            $repository->save(
                $number,
                $this->runValidator->validateTitle($body['title'] ?? null),
                $this->runValidator->validateDate($body['recorded_on'] ?? null),
                $this->runValidator->validateStreamUrl($body['stream_url'] ?? null),
                $this->runValidator->validateNotes($body['notes'] ?? null),
            );
        } catch (ValidationException $exception) {
            $run = $repository->find($number);

            if ($run === null) {
                return $this->html($response, $this->renderer->notFound($this->counts(), $this->csrfTokens->token()), 404);
            }

            return $this->html(
                $response,
                $this->renderer->testRun(
                    $run,
                    $repository->tests($number),
                    $this->episode($number),
                    $this->counts(),
                    $this->csrfTokens->token(),
                    $exception->getMessage(),
                ),
                422,
            );
        }

        return $this->redirect($response, '/admin/testabende/' . $number);
    }

    /** @param array<string, string> $arguments */
    public function completeTestRun(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $number = $this->runNumber($arguments);
        $this->runRepository()->complete($number);

        return $this->redirect($response, '/admin/testabende/' . $number);
    }

    /**
     * The stream fields of the test form. Absent fields mean "leave as is":
     * the form always submits all three, so an empty set only happens on a
     * request that does not carry them at all.
     *
     * @param array<array-key, mixed> $body
     */
    private function streamPosition(array $body): ?TestStreamPosition
    {
        if (!array_key_exists('stream_reference', $body)) {
            return null;
        }

        $run = $body['stream_reference'] ?? null;

        return new TestStreamPosition(
            $run === null || $run === '' ? null : $this->runValidator->validateNumber($run),
            $this->runValidator->validateOffset($body['recorded_time'] ?? null),
            $this->runValidator->validateDuration($body['duration_value'] ?? null),
        );
    }

    /**
     * The evening's figures, derived exactly as the public site derives them
     * — same engine, same rules — so the admin report and the page agree.
     * Null while nothing in the run is completed yet.
     */
    private function episode(int $number): ?StreamEpisode
    {
        $episodes = StreamEpisode::fromCollection(
            (new CatalogRepository($this->connection()))->ratedDrinks(),
        );

        foreach ($episodes as $episode) {
            if ($episode->number === $number) {
                return $episode;
            }
        }

        return null;
    }

    /** @param array<string, string> $arguments */
    private function runNumber(array $arguments): int
    {
        return $this->runValidator->validateNumber($arguments['number'] ?? '');
    }

    /**
     * The lifecycle counts every authenticated page needs for the sidebar and     * the filter chips: one cheap GROUP BY per request, memoised.
     *
     * @return array{identified: int, acquired: int, tested: int}
     */
    private function counts(): array
    {
        return $this->lifecycleCounts ??= $this->repository()->lifecycleCounts();
    }

    /**
     * Parses the shared list controls. Every value is validated against a
     * whitelist before it can reach the repository.
     *
     * @param array<array-key, mixed> $query
     */
    private function listFilters(array $query, string $path): DrinkListFilters
    {
        $statusValue = $query['lifecycle_status'] ?? null;

        return new DrinkListFilters(
            $this->validator->validateSearch($query['q'] ?? null),
            $statusValue === null || $statusValue === ''
                ? null
                : $this->validator->validateStatus($statusValue),
            $this->validator->validateListFilter($query['filter'] ?? null),
            $this->validator->validateSort($query['sort'] ?? null),
            $path,
        );
    }

    private function repository(): DrinkRepository
    {
        return new DrinkRepository($this->connection());
    }

    private function testRepository(): TestRepository
    {
        return new TestRepository($this->connection());
    }

    private function runRepository(): TestRunRepository
    {
        return new TestRunRepository($this->connection());
    }

    private function testService(): TestService
    {
        return new TestService(
            $this->connection(),
            $this->repository(),
            $this->testRepository(),
            $this->runRepository(),
        );
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
