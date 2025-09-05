<?php

namespace NITSAN\NsWpMigration\Controller;

use DOMDocument;
use HTMLPurifier;
use HTMLPurifier_Config;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use NITSAN\NsWpMigration\Domain\Model\LogManage;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use NITSAN\NsWpMigration\Domain\Repository\ContentRepository;
use TYPO3\CMS\Beuser\Domain\Repository\BackendUserRepository;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use NITSAN\NsWpMigration\Domain\Repository\LogManageRepository;

/***
 *
 * This file is part of the "Wp Migration" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2023 T3: Navdeep <sanjay@nitsan.in>, NITSAN Technologies Pvt Ltd
 *
 ***/

/**
 * PostController
 */
class PostController extends AbstractController
{
    protected $pageRepository = null;
    protected $contentRepository = null;
    protected $logManageRepository = null;
    protected $backendUserRepository = null;
    protected $uribuilder = null;
    public function __construct(
        PageRepository $pageRepository,
        ContentRepository $contentRepository,
        LogManageRepository $logManageRepository,
        BackendUserRepository $backendUserRepository,
        UriBuilder $uriBuilder,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
    ) {
        $this->pageRepository = $pageRepository;
        $this->contentRepository = $contentRepository;
        $this->logManageRepository = $logManageRepository;
        $this->backendUserRepository = $backendUserRepository;
        $this->uribuilder = $uriBuilder;
    }

    /**
     * action formsSettings
     *
     * @return ResponseInterface
     */
    public function importAction(): ResponseInterface
    {
        $assign = [
            'action' => 'import',
            'constant' => $this->constants,
            'version' => 11
        ];

        if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
            $this->view->assignMultiple($assign);
            return $this->htmlResponse();
        } else {
            $assign['version'] = 12;
            $view = $this->initializeModuleTemplate($this->request);
            $view->assignMultiple($assign);
            return $view->renderResponse();
        }
    }

    /**
     * Import action for the store migration data
     * @return ResponseInterface
     */
    public function importFormAction(): ResponseInterface
    {
        $requestData = $this->request->getArguments();
        // log url Action
        $loguri = $this->uriBuilder
            ->reset()
            ->uriFor('logManager', [], 'Post', 'NsWpMigration', 'importModule');
        $loguri = $this->addBaseUriIfNecessary($loguri);
        // Import url Action
        $importAction = $this->uriBuilder
            ->reset()
            ->uriFor('import', [], 'Post', 'NsWpMigration', 'importModule');
        $importAction = $this->addBaseUriIfNecessary($importAction);

        $response = 0;

        if (!$requestData['storageId']) {
            $massage = LocalizationUtility::translate('storageId.require', 'ns_wp_migration');
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                // @extensionScannerIgnoreLine
                $this->addFlashMessage($massage, 'Error', FlashMessage::ERROR);
                return $this->redirect('import');
            } else {
                $this->addFlashMessage($massage, 'Error', ContextualFeedbackSeverity::ERROR);
                return new RedirectResponse($importAction);
            }
        }

        if ($this->pageRepository->getPage($requestData['storageId'])) {
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                $fileArray = $requestData['dataFile'];
            } else {
                $fileArray = $_FILES['dataFile'];
            }
            $response = $this->importCsvData(
                $fileArray,
                $requestData['postType'],
                (int)$requestData['storageId'],
                $requestData['fileadminFolder'] ?? ''
            );
        } else {
            $massage = LocalizationUtility::translate('error.pageId', 'ns_wp_migration');
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                // @extensionScannerIgnoreLine
                $this->addFlashMessage($massage, 'Error', FlashMessage::ERROR);
                return $this->redirect('import');
            } else {
                $this->addFlashMessage($massage, 'Error', ContextualFeedbackSeverity::ERROR);
                return new RedirectResponse($importAction);
            }
        }

        if ($response === 0) {
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                $response = $this->redirect('import');
            } else {
                $response = new RedirectResponse($importAction);
            }
        } else {
            $massage = LocalizationUtility::translate('import.success', 'ns_wp_migration');
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                // @extensionScannerIgnoreLine
                $this->addFlashMessage($massage, 'Success', FlashMessage::OK);
                $response = $this->redirect('logManager');
            } else {
                $this->addFlashMessage($massage, 'Success', ContextualFeedbackSeverity::OK);
                $response = new RedirectResponse($loguri);
            }
        }
        return $response;
    }

    /**
     * Get the csv files and
     * @param array $file
     * @param string $dockType
     * @param int $storageId
     * @param string $customFileadminFolder
     * @return int
     */
    public function importCsvData(array $file, string $dockType, int $storageId, string $customFileadminFolder = ''): int
    {
        if ($this->checkValideFile($file)) {
            $handle = fopen($file['tmp_name'], 'r');
            $columns = fgetcsv($handle, 500000, ",", "\"", "");
            $record = 1;
            $data = [];
            while (($row = fgetcsv($handle, 500000, ",", "\"", "")) !== false) {
                $data[$record] = array_combine($columns, $row);
                $record++;
            }

            if (is_array($data) && isset($data[1], $data[1]['post_title'], $data[1]['post_type'])) {
                if ($dockType === 'news') {
                    $this->createNews($data, $storageId, $customFileadminFolder);
                } else {
                    $this->createPages($data, $storageId, $customFileadminFolder);
                }
                return 1;
            } else {
                $massage = LocalizationUtility::translate('error.invalidfileData', 'ns_wp_migration');
                if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                    $this->addFlashMessage($massage, 'Error', FlashMessage::ERROR);
                } else {
                    $this->addFlashMessage($massage, 'Error', ContextualFeedbackSeverity::ERROR);
                }
                return 0;
            }
        } else {
            $massage = LocalizationUtility::translate('error.invalidFile', 'ns_wp_migration');
            if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                $this->addFlashMessage($massage, 'Error', FlashMessage::ERROR);
            } else {
                $this->addFlashMessage($massage, 'Error', ContextualFeedbackSeverity::ERROR);
            }
            return 0;
        }
    }

    /**
     * Create pages for sites
     * @param array $data
     * @param int $storageId
     * @param string $customFileadminFolder
     * @return array
     */
    public function createPages(array $data, int $storageId, string $customFileadminFolder = ''): array
    {
        $response = [];
        $numberOfRecords = count($data);
        $success = 0;
        $fails = 0;
        $updatedRecords = 0;
        $beUserId = 0;
        $context = GeneralUtility::makeInstance(Context::class);
        if ($context->getPropertyFromAspect('backend.user', 'id')) {
            $beUserId = $context->getPropertyFromAspect('backend.user', 'id');
        }
        $beUser = $this->backendUserRepository->findByUid($beUserId);
        $logManager = GeneralUtility::makeInstance(LogManage::class);
        $logManager->setPid($storageId);
        $logManager->setNumberOfRecords($numberOfRecords);

        // Store mapping of WordPress ID to TYPO3 page ID for building page tree
        $wpIdToTypo3IdMap = [];

        // First pass: Create all pages without parent relationships
        foreach ($data as $pageItem) {
            // Validate Pages Items First
            if ($pageItem['post_title']) {
                // Creating Pages
                $slugString = preg_replace('/[^A-Za-z0-9 ]/', '', $pageItem['post_title']);
                $slug = strtolower(str_replace(' ', '-', $slugString));
                if ($pageItem['post_name'] && !empty($pageItem['post_name'])) {
                    $slug = $pageItem['post_name'];
                }

                $postDate = explode(" ", $pageItem['post_date']);
                if (isset($postDate[0])) {
                    $date = \DateTime::createFromFormat('d/m/y', $postDate[0]);
                    if ($date) {
                        $formattedDate = $date->format('Y-m-d');
                    } else {
                        $formattedDate = date($postDate[0]);
                    }
                }
                $pageData = [
                    'title' => $pageItem['post_title'],
                    'hidden' => 0,
                    'tstamp' => time(),
                    'crdate' => $formattedDate ? strtotime($formattedDate) : time(),
                    'pid' => $storageId, // Initially set all pages under storage root
                    'slug' => '/' . $slug,
                    'sys_language_uid' => 0,
                    'doktype' => 1
                ];

                if ($pageItem['post_status'] === 'draft') {
                    $pageData['hidden'] = 1;
                }

                if (isset($pageItem['post_status']) && $pageItem['post_status'] != 'trash') {
                    $existingRecordId = $this->contentRepository->findPageBySlug('/' . $slug, $storageId);
                    if ($existingRecordId) {
                        $recordId = $this->contentRepository->updatePageRecord($pageData, $existingRecordId);
                        $updatedRecords++;
                    } else {
                        $recordId = $this->contentRepository->createPageRecord($pageData);
                        $this->logger->error($recordId, $pageData);
                        $success++;
                    }

                    // Store the mapping of WordPress ID to TYPO3 page ID
                    if (isset($pageItem['ID']) && $recordId) {
                        $wpIdToTypo3IdMap[$pageItem['ID']] = $recordId;
                    }

                    // post content create
                    if (isset($pageItem['post_content']) && !empty($pageItem['post_content'])) {
                        $htmlContent = $this->processPostContentHtml($pageItem, $customFileadminFolder);
                        $contentElements = [
                            'pid' => $recordId,
                            'hidden' => 0,
                            'tstamp' => time(),
                            'crdate' => time(),
                            'CType' => 'textpic',
                            'bodytext' => $htmlContent,
                            'colPos' => 1,
                            'sectionIndex' => 1
                        ];
                        $this->contentRepository->insertContnetElements($contentElements);
                    }
                }
            } else {
                $fails++;
            }
        }

        // Second pass: Build page tree structure based on post_parent relationships
        $this->buildPageTree($data, $wpIdToTypo3IdMap, $storageId);

        // Third pass: Build hierarchical slugs after page tree is established
        $this->buildHierarchicalSlugs($data, $wpIdToTypo3IdMap, $storageId);

        $logManager->setTotalSuccess($success);
        $logManager->setTotalFails($fails);
        $logManager->setTotalUpdate($updatedRecords);
        $dateTime = new \DateTime(date('Y-m-d'));
        $logManager->setCreatedDate($dateTime);
        $logManager->setAddedBy($beUser);
        $this->logManageRepository->add($logManager);
        $persistanceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persistanceManager->persistAll();
        $massage = LocalizationUtility::translate('import.success', 'ns_wp_migration');
        $response['message'] = $massage;
        $response['result'] = true;
        return $response;
    }

    /**
     * Create news records for georgringer/news extension
     * @param array $data
     * @param int $storageId
     * @param string $customFileadminFolder
     * @return array
     */
    public function createNews(array $data, int $storageId, string $customFileadminFolder = ''): array
    {
        $response = [];
        $numberOfRecords = count($data);
        $success = 0;
        $fails = 0;
        $updatedRecords = 0;
        $beUserId = 0;
        $context = GeneralUtility::makeInstance(Context::class);
        if ($context->getPropertyFromAspect('backend.user', 'id')) {
            $beUserId = $context->getPropertyFromAspect('backend.user', 'id');
        }
        $beUser = $this->backendUserRepository->findByUid($beUserId);
        $logManager = GeneralUtility::makeInstance(LogManage::class);
        $logManager->setPid($storageId);
        $logManager->setNumberOfRecords($numberOfRecords);

        // Process each news item
        foreach ($data as $newsItem) {
            // Validate news item
            if (!isset($newsItem['post_title']) || empty($newsItem['post_title'])) {
                $fails++;
                continue;
            }

            try {
                // Generate slug from title or use post_name
                $slugString = preg_replace('/[^A-Za-z0-9 ]/', '', $newsItem['post_title']);
                $slug = strtolower(str_replace(' ', '-', $slugString));
                if (isset($newsItem['post_name']) && !empty($newsItem['post_name'])) {
                    $slug = $newsItem['post_name'];
                }

                // Parse and format date
                $formattedDate = time(); // Default to current time
                if (isset($newsItem['post_date']) && !empty($newsItem['post_date'])) {
                    $postDate = explode(" ", $newsItem['post_date']);
                    if (isset($postDate[0])) {
                        $date = \DateTime::createFromFormat('d/m/y', $postDate[0]);
                        if (!$date) {
                            // Try alternative date formats
                            $date = \DateTime::createFromFormat('Y-m-d', $postDate[0]);
                            if (!$date) {
                                $date = \DateTime::createFromFormat('m/d/Y', $postDate[0]);
                            }
                        }
                        if ($date) {
                            $formattedDate = $date->getTimestamp();
                        }
                    }
                }

                // Prepare news data
                $newsData = [
                    'pid' => $storageId,
                    'title' => $newsItem['post_title'],
                    'path_segment' => $slug,
                    'datetime' => $formattedDate,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'cruser_id' => $beUserId,
                    'sys_language_uid' => 0,
                    'l10n_parent' => 0,
                    'starttime' => 0,
                    'endtime' => 0,
                    'fe_group' => '',
                    'hidden' => 0,
                    'deleted' => 0,
                    'archive' => $formattedDate,
                    'author' => isset($newsItem['post_author']) ? $newsItem['post_author'] : '',
                    'author_email' => isset($newsItem['author_email']) ? $newsItem['author_email'] : '',
                    'keywords' => isset($newsItem['post_excerpt']) ? $newsItem['post_excerpt'] : '',
                    'description' => isset($newsItem['post_excerpt']) ? $newsItem['post_excerpt'] : '',
                    'alternative_title' => '',
                    'istopnews' => 0,
                    'content_elements' => 0,
                    'tags' => 0,
                    'path_segment' => $slug,
                    'editlock' => 0,
                    'sorting' => 0,
                    'notes' => ''
                ];

                // Handle post status
                if (isset($newsItem['post_status'])) {
                    switch ($newsItem['post_status']) {
                        case 'draft':
                            $newsData['hidden'] = 1;
                            break;
                        case 'private':
                            $newsData['hidden'] = 1;
                            $newsData['fe_group'] = '-2'; // Hide at login
                            break;
                        case 'trash':
                            $newsData['deleted'] = 1;
                            break;
                        case 'publish':
                        case 'published':
                        default:
                            $newsData['hidden'] = 0;
                            break;
                    }
                }

                // Process content
                if (isset($newsItem['post_content']) && !empty($newsItem['post_content'])) {
                    $htmlContent = $this->processPostContentHtml($newsItem, $customFileadminFolder);
                    $newsData['bodytext'] = $htmlContent;
                    $newsData['teaser'] = $this->generateTeaser($htmlContent);
                }

                // Check if news already exists
                $existingNewsId = $this->contentRepository->findNewsByTitle($newsItem['post_title'], $storageId);

                if ($existingNewsId) {
                    // Update existing news
                    $recordId = $this->contentRepository->updateNewsRecord($newsData, (int)$existingNewsId);
                    $updatedRecords++;
                } else {
                    // Create new news record
                    $recordId = $this->contentRepository->createNewsRecord($newsData);
                    $success++;
                }

                // Handle categories if present
                if (isset($newsItem['categories']) && !empty($newsItem['categories']) && $recordId) {
                    $this->processNewsCategories($newsItem['categories'], $recordId, $storageId);
                }

                // Handle tags if present
                if (isset($newsItem['tags']) && !empty($newsItem['tags']) && $recordId) {
                    $this->processNewsTags($newsItem['tags'], $recordId);
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to create/update news record', [
                    'title' => $newsItem['post_title'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $fails++;
            }
        }

        // Update log manager
        $logManager->setTotalSuccess($success);
        $logManager->setTotalFails($fails);
        $logManager->setTotalUpdate($updatedRecords);
        $dateTime = new \DateTime(date('Y-m-d'));
        $logManager->setCreatedDate($dateTime);
        $logManager->setAddedBy($beUser);
        $this->logManageRepository->add($logManager);

        $persistanceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persistanceManager->persistAll();

        $massage = LocalizationUtility::translate('import.success', 'ns_wp_migration');
        $response['message'] = $massage;
        $response['result'] = true;
        $response['success'] = $success;
        $response['fails'] = $fails;
        $response['updated'] = $updatedRecords;

        return $response;
    }

    /**
     * Build page tree structure based on WordPress parent-child relationships
     * @param array $data
     * @param array $wpIdToTypo3IdMap
     * @param int $storageId
     * @return void
     */
    protected function buildPageTree(array $data, array $wpIdToTypo3IdMap, int $storageId): void
    {
        foreach ($data as $pageItem) {
            // Skip if page doesn't have required fields or is trash
            if (
                !isset($pageItem['ID']) || !isset($pageItem['post_parent']) ||
                !isset($pageItem['post_status']) || $pageItem['post_status'] === 'trash'
            ) {
                continue;
            }

            $wpId = $pageItem['ID'];
            $wpParentId = $pageItem['post_parent'];

            // Skip if this page wasn't successfully created or has no parent
            if (!isset($wpIdToTypo3IdMap[$wpId]) || empty($wpParentId) || $wpParentId == '0') {
                continue;
            }

            // Check if parent exists in our mapping
            if (isset($wpIdToTypo3IdMap[$wpParentId])) {
                $typo3PageId = $wpIdToTypo3IdMap[$wpId];
                $typo3ParentId = $wpIdToTypo3IdMap[$wpParentId];

                // Update the page's parent ID
                try {
                    $this->contentRepository->updatePageParent($typo3PageId, $typo3ParentId);
                } catch (\Exception $e) {
                    // Log error but continue processing other pages
                    $this->logger->error('Failed to update page tree structure', [
                        'wp_id' => $wpId,
                        'wp_parent_id' => $wpParentId,
                        'typo3_page_id' => $typo3PageId,
                        'typo3_parent_id' => $typo3ParentId,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // Parent page doesn't exist in our mapping - log this for debugging
                $this->logger->warning('Parent page not found in mapping', [
                    'wp_id' => $wpId,
                    'wp_parent_id' => $wpParentId,
                    'page_title' => $pageItem['post_title'] ?? 'Unknown'
                ]);
            }
        }
    }

    /**
     * Build hierarchical slugs for pages based on their tree structure
     * @param array $data
     * @param array $wpIdToTypo3IdMap
     * @param int $storageId
     * @return void
     */
    protected function buildHierarchicalSlugs(array $data, array $wpIdToTypo3IdMap, int $storageId): void
    {
        // Store WordPress data for easy lookup
        $wpDataMap = [];
        foreach ($data as $pageItem) {
            if (isset($pageItem['ID'])) {
                $wpDataMap[$pageItem['ID']] = $pageItem;
            }
        }

        // Process each page to build hierarchical slugs
        foreach ($data as $pageItem) {
            if (
                !isset($pageItem['ID']) || !isset($wpIdToTypo3IdMap[$pageItem['ID']]) ||
                !isset($pageItem['post_status']) || $pageItem['post_status'] === 'trash'
            ) {
                continue;
            }

            $wpId = $pageItem['ID'];
            $typo3PageId = $wpIdToTypo3IdMap[$wpId];

            // Build the hierarchical slug path
            $hierarchicalSlug = $this->buildSlugPath($pageItem, $wpDataMap, $storageId);

            // Update the page slug if it's different from the current one
            if ($hierarchicalSlug !== '/' . $this->getPageSlug($pageItem)) {
                try {
                    $this->contentRepository->updatePageSlug($typo3PageId, $hierarchicalSlug);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to update page slug', [
                        'wp_id' => $wpId,
                        'typo3_page_id' => $typo3PageId,
                        'hierarchical_slug' => $hierarchicalSlug,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Build the full slug path for a page based on its hierarchy
     * @param array $pageItem
     * @param array $wpDataMap
     * @param int $storageId
     * @return string
     */
    protected function buildSlugPath(array $pageItem, array $wpDataMap, int $storageId): string
    {
        $slugParts = [];
        $currentPage = $pageItem;

        // Start with the current page's slug
        $slug = $this->getPageSlug($currentPage);
        array_unshift($slugParts, $slug);

        // Traverse up the hierarchy to build the path
        while ($currentPage && isset($currentPage['post_parent']) && $currentPage['post_parent'] != '0') {
            // Move to parent page
            $parentId = $currentPage['post_parent'];
            if (isset($wpDataMap[$parentId])) {
                $currentPage = $wpDataMap[$parentId];
                // Get the parent page's slug and add it to the beginning
                $parentSlug = $this->getPageSlug($currentPage);
                array_unshift($slugParts, $parentSlug);
            } else {
                // Parent not found, break the loop
                break;
            }
        }

        // Build the final hierarchical slug
        return '/' . implode('/', $slugParts);
    }

    /**
     * Get the slug for a page item
     * @param array $pageItem
     * @return string
     */
    protected function getPageSlug(array $pageItem): string
    {
        if (isset($pageItem['post_name']) && !empty($pageItem['post_name'])) {
            return $pageItem['post_name'];
        }

        // Fallback to generating slug from title
        $slugString = preg_replace('/[^A-Za-z0-9 ]/', '', $pageItem['post_title']);
        return strtolower(str_replace(' ', '-', $slugString));
    }

    /**
     * Process data and return post types array
     * @param array $data
     * @param string $customFileadminFolder
     * @return string
     */
    public function processPostContentHtml(array $data, string $customFileadminFolder = ''): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('AutoFormat.RemoveEmpty', true); // remove empty tag pairs
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true); // remove empty, even if it contains an &nbsp;
        $config->set('AutoFormat.AutoParagraph', false); // remove empty tag pairs
        $config->getHTMLDefinition(true)->addAttribute('img', 'data-htmlarea-file-uid', 'Number');
        $config->getHTMLDefinition(true)->addAttribute('img', 'data-htmlarea-file-table', 'CDATA');
        $config->getHTMLDefinition(true)->addAttribute('img', 'data-title-override', 'CDATA');
        $config->getHTMLDefinition(true)->addAttribute('img', 'data-alt-override', 'CDATA');
        $purifier = new HTMLPurifier($config);
        $htmlString = $purifier->purify(trim($data['post_content']));
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        // Create a DOMDocument object with proper UTF-8 encoding
        $dom = new DOMDocument('1.0', 'UTF-8');
        try {
            // Ensure proper UTF-8 handling by adding meta charset and using mb_convert_encoding
            $htmlString = mb_convert_encoding($htmlString, 'HTML-ENTITIES', 'UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlString, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            // Process images
            $this->processMediaElements($dom, 'img', 'src', $resourceFactory, $customFileadminFolder);

            // Process links to files (PDFs, documents, etc.)
            $this->processFileLinks($dom, $resourceFactory, $customFileadminFolder);

            // Get the modified HTML content with proper UTF-8 encoding
            $htmlString = $dom->saveHTML();
            // Remove the XML encoding declaration that was added for UTF-8 support
            $htmlString = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace('<?xml encoding="UTF-8">', '', $htmlString));
            $htmlString = $purifier->purify($htmlString);
            return $htmlString;
        } catch (\Throwable $th) {
            $this->logger->error($th->getMessage(), $data);
            return $htmlString;
        }
    }

    /**
     * Process media elements (images, videos, etc.)
     * @param DOMDocument $dom
     * @param string $tagName
     * @param string $attribute
     * @param ResourceFactory $resourceFactory
     * @param string $customFileadminFolder
     * @return void
     */
    protected function processMediaElements(DOMDocument $dom, string $tagName, string $attribute, ResourceFactory $resourceFactory, string $customFileadminFolder = ''): void
    {
        $elements = $dom->getElementsByTagName($tagName);

        foreach ($elements as $element) {
            $src = $element->getAttribute($attribute);
            $element->setAttribute('alt', '');
            if ($src) {
                $fileInfo = $this->downloadAndStoreFile($src, $resourceFactory, $customFileadminFolder);
                if ($fileInfo) {
                    $element->setAttribute($attribute, $fileInfo['url']);

                    // Add TYPO3-specific attributes for images
                    if ($tagName === 'img') {
                        $element->setAttribute('data-htmlarea-file-uid', $fileInfo['uid']);
                        $element->setAttribute('data-htmlarea-file-table', 'sys_file');
                        $element->setAttribute('data-title-override', 'true');
                        $element->setAttribute('data-alt-override', 'true');
                    }
                }
            }
        }
    }

    /**
     * Process file links (PDFs, documents, spreadsheets, etc.)
     * @param DOMDocument $dom
     * @param ResourceFactory $resourceFactory
     * @param string $customFileadminFolder
     * @return void
     */
    protected function processFileLinks(DOMDocument $dom, ResourceFactory $resourceFactory, string $customFileadminFolder = ''): void
    {
        $links = $dom->getElementsByTagName('a');

        // Define file extensions we want to process
        $fileExtensions = [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'zip',
            'rar',
            'txt',
            'rtf',
            'odt',
            'ods',
            'odp',
            'csv',
            'xml',
            'json',
            'mp3',
            'mp4',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm',
            'ogg',
            'wav',
            'wma'
        ];

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href) {
                // Check if the link points to a file we want to process
                $extension = strtolower(pathinfo(parse_url($href, PHP_URL_PATH), PATHINFO_EXTENSION));

                if (in_array($extension, $fileExtensions)) {
                    $fileInfo = $this->downloadAndStoreFile($href, $resourceFactory, $customFileadminFolder);
                    if ($fileInfo) {
                        $link->setAttribute('href', $fileInfo['url']);
                        $link->setAttribute('target', '_blank'); // Open in new tab

                        // Add TYPO3-specific attributes for file links
                        $link->setAttribute('data-htmlarea-file-uid', $fileInfo['uid']);
                        $link->setAttribute('data-htmlarea-file-table', 'sys_file');

                        // If the link text is the same as the URL, update it to the filename
                        if ($link->textContent === $href) {
                            $link->textContent = $fileInfo['name'];
                        }
                    }
                }
            }
        }
    }

    /**
     * Get file information from existing files in fileadmin (assumes files are pre-prepared)
     * @param string $url Original URL from WordPress content (used to determine file path structure)
     * @param ResourceFactory $resourceFactory
     * @param string $customFileadminFolder
     * @return array|null Returns file info if found, null if file doesn't exist
     */
    protected function downloadAndStoreFile(string $url, ResourceFactory $resourceFactory, string $customFileadminFolder = ''): ?array
    {
        try {
            // Parse the URL to get the path
            $parsedUrl = parse_url($url);
            $srcPath = $parsedUrl['path'] ?? '';

            // Extract the relative path from the source URL
            // This preserves the directory structure
            $fileName = basename($srcPath);
            $relativePath = dirname($srcPath);

            // Clean up the relative path (remove leading slashes, etc.)
            $relativePath = trim($relativePath, '/');
            if ($relativePath === '.' || $relativePath === '') {
                $relativePath = '';
            } else {
                $relativePath = $relativePath . '/';
            }

            // Determine the folder to use - custom or default
            if (!empty($customFileadminFolder)) {
                // Clean up the custom folder path
                $customFolder = trim($customFileadminFolder, '/');
                if (!str_starts_with($customFolder, 'fileadmin/')) {
                    $customFolder = 'fileadmin/' . $customFolder;
                }
                $baseFolder = rtrim($customFolder, '/') . '/';
                $storageFolder = str_replace('fileadmin/', '', $baseFolder);
                $storageFolder = trim($storageFolder, '/');
                $storageSubFolder = $storageFolder . '/' . $relativePath;
                $storageSubFolder = trim($storageSubFolder, '/');
            } else {
                // Use default folder
                $baseFolder = 'fileadmin/user_upload/';
                $storageFolder = 'user_upload';
                $storageSubFolder = $storageFolder . '/' . $relativePath;
                $storageSubFolder = trim($storageSubFolder, '/');
            }

            // Get TYPO3 file storage
            $fileStorage = $resourceFactory->getDefaultStorage();

            // Try to get the folder and file (assume they already exist)
            try {
                $folderObject = $fileStorage->getFolder($storageSubFolder);
                $fileObject = $fileStorage->getFileInFolder($fileName, $folderObject);
                $properties = $fileObject->getProperties();

                return [
                    'url' => $fileObject->getPublicUrl(),
                    'uid' => $properties['uid'],
                    'name' => $properties['name']
                ];
            } catch (\Exception $e) {
                // File or folder doesn't exist - log warning and return null
                $this->logger->warning('File not found in fileadmin (assuming pre-prepared files): ' . $fileName, [
                    'expected_path' => $storageSubFolder . '/' . $fileName,
                    'original_url' => $url,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to locate file in fileadmin: ' . $url, [
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return null;
        }
    }

    /**
     * action Log Manager
     *
     * @return ResponseInterface
     */
    public function logManagerAction(): ResponseInterface
    {
        $data = $this->logManageRepository->getAllLogs();
        $assign = [
            'action' => 'logManager',
            'constant' => $this->constants,
            'loglist' => $data,
            'version' => 11
        ];
        if ((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
            $this->view->assignMultiple($assign);
            return $this->htmlResponse();
        } else {
            $assign['version'] = 12;
            $view = $this->initializeModuleTemplate($this->request);
            $view->assignMultiple($assign);
            return $view->renderResponse();
        }
    }

    /**
     * Generates the action menu
     *
     * @param ServerRequestInterface $request
     * @return ModuleTemplate
     */
    protected function initializeModuleTemplate(ServerRequestInterface $request): ModuleTemplate
    {
        return $this->moduleTemplateFactory->create($request);
    }

    /**
     * Generate a teaser from HTML content
     * @param string $htmlContent
     * @param int $maxLength
     * @return string
     */
    protected function generateTeaser(string $htmlContent, int $maxLength = 200): string
    {
        // Strip HTML tags and decode entities
        $plainText = html_entity_decode(strip_tags($htmlContent), ENT_QUOTES, 'UTF-8');

        // Remove extra whitespace
        $plainText = preg_replace('/\s+/', ' ', trim($plainText));

        // Truncate to max length
        if (strlen($plainText) > $maxLength) {
            $plainText = substr($plainText, 0, $maxLength);
            // Find the last space to avoid cutting words
            $lastSpace = strrpos($plainText, ' ');
            if ($lastSpace !== false) {
                $plainText = substr($plainText, 0, $lastSpace);
            }
            $plainText .= '...';
        }

        return $plainText;
    }

    /**
     * Process news categories
     * @param string $categories Comma-separated category names
     * @param int $newsId
     * @param int $storageId
     * @return void
     */
    protected function processNewsCategories(string $categories, int $newsId, int $storageId): void
    {
        if (empty($categories)) {
            return;
        }

        $categoryNames = array_map('trim', explode(',', $categories));

        foreach ($categoryNames as $categoryName) {
            if (empty($categoryName)) {
                continue;
            }

            try {
                // Check if category already exists
                $existingCategoryId = $this->contentRepository->findCategoryByTitle($categoryName, $storageId);

                if ($existingCategoryId) {
                    $categoryId = (int)$existingCategoryId;
                } else {
                    // Create new category
                    $categoryData = [
                        'pid' => $storageId,
                        'title' => $categoryName,
                        'parent' => $storageId,
                        'tstamp' => time(),
                        'crdate' => time(),
                        'hidden' => 0,
                        'deleted' => 0,
                        'sys_language_uid' => 0,
                        'l10n_parent' => 0,
                        'sorting' => 0
                    ];

                    $categoryId = $this->contentRepository->createNewsCategory($categoryData);
                }

                // Create relation between news and category
                if ($categoryId) {
                    $this->contentRepository->createNewsCategoryRelation($newsId, $categoryId);
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to process news category', [
                    'category_name' => $categoryName,
                    'news_id' => $newsId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Process news tags
     * @param string $tags Comma-separated tag names
     * @param int $newsId
     * @return void
     */
    protected function processNewsTags(string $tags, int $newsId): void
    {
        if (empty($tags)) {
            return;
        }

        // For now, we'll store tags as a simple string in the news record
        // The georgringer/news extension has its own tag system, but this is a basic implementation
        try {
            $tagNames = array_map('trim', explode(',', $tags));
            $cleanTags = array_filter($tagNames, function ($tag) {
                return !empty($tag);
            });

            if (!empty($cleanTags)) {
                // Update the news record with tags (stored as comma-separated string)
                $tagsString = implode(', ', $cleanTags);
                $this->contentRepository->updateNewsRecord(['keywords' => $tagsString], $newsId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to process news tags', [
                'tags' => $tags,
                'news_id' => $newsId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the sample file for downloadings
     */
    protected function downloadSampleAction()
    {
        $file = ExtensionManagementUtility::extPath('ns_wp_migration') . 'Resources/Public/sample.csv';
        if (file_exists($file)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="sample.csv"');
            header('Content-Length: ' . filesize(GeneralUtility::getFileAbsFileName($file)));
            // Read the file and output its contents
            readfile(GeneralUtility::getFileAbsFileName($file));
            exit;
        }
    }
}
