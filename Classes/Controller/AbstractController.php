<?php

namespace NITSAN\NsWpMigration\Controller;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/***
 *
 * This file is part of the "Wp Migration" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2023 Navdeepsinh Jethwa <navdeep@nitsantech.com>, Nitsan Technologies Pvt. Ltd.
 *
 ***/

/**
 * AbstractController
 */
abstract class AbstractController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected ?PersistenceManager $persistenceManager = null;
    protected $constants;

    public function initializeAction(): void
    {
        $this->persistenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    /**
     * Checks is file is valide type or not
     * @param array $file
     * @return bool
     */
    public function checkValideFile(array $file): bool
    {
        $fileMimes = [
            'text/x-comma-separated-values',
            'text/comma-separated-values',
            'application/octet-stream',
            'application/vnd.ms-excel',
            'application/x-csv',
            'text/x-csv',
            'text/csv',
            'application/csv',
            'application/excel',
            'application/vnd.msexcel',
            'text/plain'
        ];

        return in_array($file['type'], $fileMimes);
    }
}
