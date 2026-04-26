<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Controller\Ajax;

use Kairos\AiEditorialHelper\Controller\Ajax\CategorySuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\MetaDescriptionAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\SlugSuggesterAjaxController;
use Kairos\AiEditorialHelper\Controller\Ajax\TeaserAjaxController;
use Kairos\AiEditorialHelper\Service\CategorySuggesterService;
use Kairos\AiEditorialHelper\Service\MetaDescriptionService;
use Kairos\AiEditorialHelper\Service\SlugSuggesterService;
use Kairos\AiEditorialHelper\Service\TeaserService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Regression for issue #19 (Bug A).
 *
 * Every AJAX controller used to do `$request->getParsedBody() + $request->getQueryParams()`.
 * For GET requests `getParsedBody()` is null, and PHP 8.x raises TypeError on
 * `null + array`. The controllers now coalesce with `?? []`. These tests
 * exercise the GET path (no parsed body) on each controller and assert the
 * response is a clean 400 (the controllers' own validation), not a 500
 * caused by the TypeError.
 *
 * pageUid=0 deliberately triggers the early-return validation branch so we
 * don't need to mock BackendUtility::getRecord (a static call).
 */
final class AjaxControllersGetRequestTest extends TestCase
{
    public function testMetaDescriptionControllerHandlesGetRequest(): void
    {
        $controller = new MetaDescriptionAjaxController(
            $this->createMock(MetaDescriptionService::class),
            $this->createMock(ConnectionPool::class),
        );
        $response = $controller->generate($this->getRequest());
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('pageUid', (string)$response->getBody());
    }

    public function testSlugSuggesterControllerHandlesGetRequest(): void
    {
        $controller = new SlugSuggesterAjaxController(
            $this->createMock(SlugSuggesterService::class),
            $this->createMock(ConnectionPool::class),
        );
        // SlugSuggester accepts pageUid=0 if a title is given. Test the strict
        // case: empty everything → expects an error response, not a TypeError.
        $response = $controller->suggest($this->getRequest([]));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTeaserControllerHandlesGetRequest(): void
    {
        $controller = new TeaserAjaxController(
            $this->createMock(TeaserService::class),
            $this->createMock(ConnectionPool::class),
        );
        $response = $controller->generate($this->getRequest());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testCategorySuggesterControllerHandlesGetRequest(): void
    {
        $controller = new CategorySuggesterAjaxController(
            $this->createMock(CategorySuggesterService::class),
            $this->createMock(ConnectionPool::class),
        );
        $response = $controller->suggest($this->getRequest());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testAllControllersAcceptNullParsedBodyWithoutTypeError(): void
    {
        // Belt-and-suspenders: the regression specifically asserts that a GET
        // request (parsedBody=null) does NOT raise PHP's "null + array" TypeError.
        // If the coalesce were ever removed, every assertion below would
        // surface as an exception, not a status-code mismatch.
        foreach ([
            new MetaDescriptionAjaxController(
                $this->createMock(MetaDescriptionService::class),
                $this->createMock(ConnectionPool::class),
            ),
            new TeaserAjaxController(
                $this->createMock(TeaserService::class),
                $this->createMock(ConnectionPool::class),
            ),
            new CategorySuggesterAjaxController(
                $this->createMock(CategorySuggesterService::class),
                $this->createMock(ConnectionPool::class),
            ),
        ] as $controller) {
            $method = method_exists($controller, 'generate') ? 'generate' : 'suggest';
            $response = $controller->{$method}($this->getRequest(['pageUid' => 0]));
            self::assertSame(
                400,
                $response->getStatusCode(),
                sprintf('%s::%s did not return 400 on missing pageUid', $controller::class, $method),
            );
        }

        $slugController = new SlugSuggesterAjaxController(
            $this->createMock(SlugSuggesterService::class),
            $this->createMock(ConnectionPool::class),
        );
        $slugResponse = $slugController->suggest($this->getRequest([]));
        self::assertSame(400, $slugResponse->getStatusCode());
    }

    /**
     * Build a GET-shaped ServerRequest: parsedBody is null (PSR-7 norm for GET),
     * query params come from the array.
     *
     * @param array<string, mixed> $queryParams
     */
    private function getRequest(array $queryParams = ['pageUid' => 0]): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getQueryParams')->willReturn($queryParams);
        return $request;
    }
}
