<?php

namespace  NITSAN\NsWpMigration\Domain\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Extbase\Persistence\Repository;

/***
 *
 * This file is part of the "Wp Migration" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2023 T3: Navdeepsinh Jethwa <sanjay@nitsan.in>, NITSAN Technologies Pvt Ltd
 *
 ***/

/**
 * The repository for NsWpMigration
 */
class ContentRepository extends Repository
{
    /**
     * @param array $contentElement
     * @return int
     */
    public function insertContnetElements(array $contentElement): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->insert('tt_content')->values($contentElement)->executeStatement();
        $id = $queryBuilder->getConnection()->lastInsertId();
        return (int)$id;
    }

    /**
     * @param array $pageItems
     * @return mixed
     */
    public function createPageRecord(array $pageItems): mixed
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder
            ->insert('pages')
            ->values($pageItems)
            ->executeStatement();
        return (int)$queryBuilder->getConnection()->lastInsertId();
    }

    /**
     * Remove and create a new pages
     * @param $data
     * @param $recordId
     * @return int
     */
    public function updatePageRecord($data, $recordId): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder
            ->delete('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($recordId, \PDO::PARAM_INT))
            )
            ->executeStatement();
        return $this->createPageRecord($data);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function findPageBySlug($slug, $storageId): string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        // Remove all restrictions first
        $queryBuilder->getRestrictions()->removeAll();
        // Add back only the deleted restriction to exclude deleted pages
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));
        
        return $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($storageId, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Update page parent ID for building page tree structure
     * @param int $pageId
     * @param int $parentId
     * @return bool
     */
    public function updatePageParent(int $pageId, int $parentId): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $affectedRows = $queryBuilder
            ->update('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->set('pid', $parentId)
            ->set('tstamp', time())
            ->executeStatement();
        
        return $affectedRows > 0;
    }

    /**
     * Update page slug for hierarchical URL structure
     * @param int $pageId
     * @param string $slug
     * @return bool
     */
    public function updatePageSlug(int $pageId, string $slug): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $affectedRows = $queryBuilder
            ->update('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->set('slug', $slug)
            ->set('tstamp', time())
            ->executeStatement();
        
        return $affectedRows > 0;
    }

    /**
     * Get page data by ID
     * @param int $pageId
     * @return array|false
     */
    public function getPageById(int $pageId): array|false
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        return $queryBuilder
            ->select('uid', 'pid', 'title', 'slug')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();
    }
}
