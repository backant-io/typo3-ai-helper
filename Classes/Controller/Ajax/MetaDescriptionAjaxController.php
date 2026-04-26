<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Controller\Ajax;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\MetaDescriptionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoint for the "Generate meta description" wizard.
 *
 * Route: GET/POST /typo3/ajax/ai-editorial-helper/meta?pageUid=123
 * Requires a valid backend user session (TYPO3 dispatches AJAX routes through
 * the backend authentication middleware).
 */
final class MetaDescriptionAjaxController
{
    public function __construct(
        private readonly MetaDescriptionService $service,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getParsedBody() + $request->getQueryParams();
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
            $result = $this->service->generate($title, $body);
        } catch (LmStudioException $e) {
            return $this->error($this->humanizeException($e), 502);
        }

        return new JsonResponse([
            'success' => true,
            'metaDescription' => $result['metaDescription'],
            'seoTitle' => $result['seoTitle'],
        ]);
    }

    /**
     * Concatenate visible text from the page's tt_content elements (default
     * language only — translation handling is out of scope for #1).
     */
    private function loadPageBody(int $pageUid): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $qb
            ->select('header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
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
