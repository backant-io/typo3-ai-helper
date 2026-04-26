<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads sys_category records for the suggester.
 *
 * Top-level only by default (parent = 0). Multi-tree / nested category trees
 * are out of scope for v1 per the issue spec.
 */
class CategoryRepository
{
    public const TABLE = 'sys_category';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<array{uid: int, title: string, description: string}>
     */
    public function findTopLevelCategories(int $limit = 200): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $rows = $qb
            ->select('uid', 'title', 'description')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('parent', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('title', 'ASC')
            ->setMaxResults($limit > 0 ? $limit : 200)
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $result[] = [
                'uid' => (int)$row['uid'],
                'title' => $title,
                'description' => trim((string)($row['description'] ?? '')),
            ];
        }
        return $result;
    }
}
