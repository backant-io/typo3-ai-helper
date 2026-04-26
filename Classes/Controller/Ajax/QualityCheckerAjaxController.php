<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Controller\Ajax;

use Doctrine\DBAL\ParameterType;
use Kairos\AiEditorialHelper\Service\QualityChecker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoint for the "Check page quality" wizard.
 *
 * Route: GET/POST /typo3/ajax/ai-editorial-helper/quality?pageUid=123[&llm=1]
 *
 * Concatenates default-language tt_content into a single HTML blob, runs the
 * QualityChecker, returns a sorted list of flags.
 */
final class QualityCheckerAjaxController
{
    public function __construct(
        private readonly QualityChecker $service,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        // PSR-7: getParsedBody() is null on GET — coalesce.
        $params = ($request->getParsedBody() ?? []) + $request->getQueryParams();
        $pageUid = (int)($params['pageUid'] ?? 0);
        $includeLlm = !isset($params['llm']) || (string)$params['llm'] !== '0';

        if ($pageUid <= 0) {
            return $this->error('Missing or invalid pageUid parameter.', 400);
        }

        $page = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($page)) {
            return $this->error(sprintf('Page %d not found.', $pageUid), 404);
        }

        $title = (string)($page['title'] ?? '');
        $html = $this->loadPageHtml($pageUid);

        $flags = $this->service->check($title, $html, $includeLlm);

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($flags as $flag) {
            $sev = $flag['severity'] ?? 'info';
            if (isset($counts[$sev])) {
                $counts[$sev]++;
            }
        }

        return new JsonResponse([
            'success' => true,
            'flags' => $flags,
            'counts' => $counts,
            'includesLlm' => $includeLlm,
        ]);
    }

    /**
     * Concatenate default-language tt_content as a single HTML blob: header is
     * wrapped in <h2>, subheader in <h3>, bodytext stays as-is. This gives the
     * checker a realistic page-shaped DOM to work on.
     */
    private function loadPageHtml(int $pageUid): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $qb
            ->select('header', 'subheader', 'bodytext', 'header_layout')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            $headerLayout = (int)($row['header_layout'] ?? 0);
            $headerTag = $headerLayout > 0 && $headerLayout <= 6 ? 'h' . $headerLayout : 'h2';
            $header = trim((string)($row['header'] ?? ''));
            if ($header !== '') {
                $parts[] = sprintf('<%s>%s</%s>', $headerTag, htmlspecialchars($header, ENT_QUOTES, 'UTF-8'), $headerTag);
            }
            $subheader = trim((string)($row['subheader'] ?? ''));
            if ($subheader !== '') {
                $parts[] = sprintf('<h3>%s</h3>', htmlspecialchars($subheader, ENT_QUOTES, 'UTF-8'));
            }
            $bodytext = trim((string)($row['bodytext'] ?? ''));
            if ($bodytext !== '') {
                $parts[] = $bodytext;
            }
        }
        return implode("\n", $parts);
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
