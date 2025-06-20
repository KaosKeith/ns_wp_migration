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
            if((GeneralUtility::makeInstance(Typo3Version::class))->getMajorVersion() < 12) {
                $fileArray = $requestData['dataFile'];
            } else {
                $fileArray = $_FILES['dataFile'];
            }
            $response = $this->importCsvData(
                $fileArray,
                $requestData['postType'],
                (int)$requestData['storageId']
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

        if($response === 0) {
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
     * @return int
     */
    public function importCsvData(array $file, string $dockType, int $storageId): int
    {
        if ($this->checkValideFile($file)) {

            $handle = fopen($file['tmp_name'], 'r');
            $columns = fgetcsv($handle, 10000, ",");
            $record = 1;
            $data = [];
            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                $data[$record] = array_combine($columns, $row);
                $record++;
            }

            if(is_array($data) && isset($data[1], $data[1]['post_title'], $data[1]['post_type'])) {
                $this->createPagesAndBlog($data, $storageId, $dockType);
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
     * @param string $dockType
     * @return array
     */
    public function createPagesAndBlog(array $data, int $storageId, string $dockType): array
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
            if($pageItem['post_title']) {
                // Creating Pages
                $slugString = preg_replace('/[^A-Za-z0-9 ]/', '', $pageItem['post_title']);
                $slug = strtolower(str_replace(' ', '-', $slugString));
                if($pageItem['post_name'] && !empty($pageItem['post_name'])) {
                    $slug = $pageItem['post_name'];
                }

                $postDate = explode(" ", $pageItem['post_date']);
                if(isset($postDate[0])){
                    $date = \DateTime::createFromFormat('d/m/y', $postDate[0]);
                    if($date) {
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
                    'slug' => '/'.$slug,
                    'sys_language_uid' => 0,
                    'doktype' => 1
                ];

                if ($pageItem['post_status'] === 'draft') {
                    $pageData['hidden'] = 1;
                }

                if (isset($pageItem['post_status']) && $pageItem['post_status'] != 'trash') {
                    $existingRecordId = $this->contentRepository->findPageBySlug('/'.$slug, $storageId);
                    if($existingRecordId) {
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
                        $htmlContent = $this->processPostContentHtml($pageItem);
                        $contentElements = ['pid' => $recordId,
                                            'hidden' => 0,
                                            'tstamp' => time(),
                                            'crdate' => time(),
                                            'CType' => 'text',
                                            'bodytext' => $htmlContent,
                                            'colPos' => 0,
                                            'sectionIndex' => 1];
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
            if (!isset($pageItem['ID']) || !isset($pageItem['post_parent']) || 
                !isset($pageItem['post_status']) || $pageItem['post_status'] === 'trash') {
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
            if (!isset($pageItem['ID']) || !isset($wpIdToTypo3IdMap[$pageItem['ID']]) || 
                !isset($pageItem['post_status']) || $pageItem['post_status'] === 'trash') {
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
     * @return string
     */
    public function processPostContentHtml(array $data): string
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

        // Create a DOMDocument object
        $dom = new DOMDocument();
        try {
            $dom->loadHTML($htmlString);
            // Get all image tags in the HTML
            $imageTags = $dom->getElementsByTagName('img');

            // Loop through each image tag
            foreach ($imageTags as $img) {
                // Get the value of the src attribute
                $src = $img->getAttribute('src');
                if($src) {
                    $fileName = basename($src);
                    $folder = 'fileadmin/user_upload/';
                    $dstFolder = Environment::getPublicPath() .'/'. $folder;
                    if (!file_exists($dstFolder)) {
                        GeneralUtility::mkdir_deep($dstFolder);
                    }
                    $out = file_get_contents($src);
                    file_put_contents($dstFolder . '/' . $fileName, $out);
                    // Get TYPO3 file storage
                    $fileStorage = $resourceFactory->getDefaultStorage();
                    $folder = $fileStorage->getFolder('user_upload');
                    $fileObject = $fileStorage->getFileInFolder($fileName, $folder);
                    $properties = $fileObject->getProperties();

                    // Get the accessible URL of the file
                    $accessibleUrl = $fileObject->getPublicUrl();
                    $img->setAttribute('src', $accessibleUrl);
                    $img->setAttribute('data-htmlarea-file-uid', $properties['uid']);
                    $img->setAttribute('data-htmlarea-file-table', 'sys_file');
                    $img->setAttribute('width', 930);
                    $img->setAttribute('height', 523);
                    $img->setAttribute('title', $properties['name']);
                    $img->setAttribute('alt', $properties['name']);
                    $img->setAttribute('data-title-override', 'true');
                    $img->setAttribute('data-alt-override', 'true');
                }
            }

            // Get the modified HTML content
            $htmlString = $dom->saveHTML();
            $htmlString = $purifier->purify($htmlString);
            return $htmlString;
        } catch (\Throwable $th) {
            $this->logger->error($th->getMessage(), $data);
            return $htmlString;
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
