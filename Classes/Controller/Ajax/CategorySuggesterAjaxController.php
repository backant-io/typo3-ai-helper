<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Controller\Ajax;

use Doctrine\DBAL\ParameterType;
use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\CategorySuggesterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoint for the "Suggest categories" wizard.
 *
 * Route: GET/POST /typo3/ajax/ai-editorial-helper/categories?pageUid=123
 */
final class CategorySuggesterAjaxController
{
    public function __construct(
        private readonly CategorySuggesterService $service,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function suggest(ServerRequestInterface $request): ResponseInterface
    {
        // getParsedBody() is null for GET requests. Coalesce to avoid TypeError
        // on the array merge below. (See issue #19.)
        $params = ($request->getParsedBody() ?? []) + $request->getQueryParams();
        $pageUid = (int)($params['pageUid'] ?? 0);

        if ($pageUid <= 0) {
            return $this->error('Missing or invalid pageUid parameter.', 400);
        }

        $page = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($page)) {
            return $this->error(sprintf('Page %d not found.', $pageUid), 404);
        }

        $title = (string)($page['title'] ?? '');
        $body = $this->loadPageBody($pageUid);

        try {
            $suggestions = $this->service->suggest($title, $body);
        } catch (LmStudioException $e) {
            return $this->error($this->humanizeException($e), 502);
        }

        return new JsonResponse([
            'success' => true,
            'suggestions' => array_map(
                static fn (array $s): array => [
                    'uid' => $s['uid'],
                    'title' => $s['title'],
                    'confidence' => round($s['confidence'], 2),
                ],
                $suggestions,
            ),
        ]);
    }

    private function loadPageBody(int $pageUid): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $qb
            ->select('header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            foreach (['header', 'subheader', 'bodytext'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }
        return implode("\n\n", $parts);
    }

    private function humanizeException(LmStudioException $e): string
    {
        return match ($e->getCode()) {
            LmStudioException::CODE_UNREACHABLE
                => 'LM Studio is not reachable. Start LM Studio and load the configured model, then retry.',
            LmStudioException::CODE_NO_MODEL_LOADED
                => 'No model is loaded in LM Studio. Load the configured model in the LM Studio UI and retry.',
            LmStudioException::CODE_INVALID_RESPONSE
                => 'The model returned an unexpected response. Try again, or switch to a more capable model.',
            default => 'LM Studio request failed: ' . $e->getMessage(),
        };
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(
            ['success' => false, 'error' => $message],
            $status,
        );
    }
}
