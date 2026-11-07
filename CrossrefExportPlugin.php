<?php

/**
 * @file plugins/generic/crossref/CrossrefExportPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportPlugin
 *
 * @brief Crossref XML metadata export and deposit plugin.
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\DOIPubIdExportPlugin;
use APP\submission\Submission;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use PKP\config\Config;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\plugins\Hook;
use PKP\submission\reviewAssignment\ReviewAssignment;

class CrossrefExportPlugin extends DOIPubIdExportPlugin
{
    // The status of the Crossref DOI.
    // any, notDeposited, and markedRegistered are reserved
    public const CROSSREF_STATUS_FAILED = 'failed';
    public const CROSSREF_API_DEPOSIT_OK = 200;
    public const CROSSREF_API_DEPOSIT_ERROR_UNAUTHORIZED = 401;
    public const CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF = 403;
    public const CROSSREF_API_URL = 'https://api.crossref.org/v2/deposits';
    public const CROSSREF_API_URL_DEV = 'https://test.crossref.org/v2/deposits';
    public const CROSSREF_API_STATUS_URL = 'https://doi.crossref.org/servlet/submissionDownload';
    public const CROSSREF_API_STATUS_URL_DEV = 'https://test.crossref.org/servlet/submissionDownload';
    // The name of the setting used to save the registered DOI and the URL with the deposit status.
    public const CROSSREF_DEPOSIT_STATUS = 'depositStatus';

    public function __construct(protected CrossrefPlugin $agencyPlugin)
    {
        parent::__construct();
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'CrossrefExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.importexport.crossref.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.importexport.crossref.description');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSubmissionFilter()
     */
    public function getSubmissionFilter()
    {
        return 'article=>crossref-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getPeerReviewFilter()
     */
    public function getPeerReviewFilter(): string
    {
        return 'peerReview=>crossref-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getIssueFilter()
     */
    public function getIssueFilter()
    {
        return 'issue=>crossref-xml';
    }

    /**
     * @copydoc CrossrefPlugin::getSetting()
     */
    public function getSetting($contextId, $name)
    {
        return $this->agencyPlugin->getSetting($contextId, $name);
    }

    /**
     * @copydoc PubObjectsExportPlugin::getStatusMessage()
     */
    public function getStatusMessage($request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with crossref external service');
            return __('common.sandbox');
        }

        // if the failure occurred on request and the message was saved
        // return that message
        $failedMsg = null;
        $doiId = $request->getUserVar('doiId');
        if (!empty($doiId)) {
            $doi = Repo::doi()->get((int)$doiId);
            $failedMsg = $doi?->getData($this->getFailedMsgSettingName());
        }

        if (empty($failedMsg)) {
            $articleId = $request->getUserVar('articleId') ?? $request->getUserVar('submissionId');
            if (!empty($articleId)) {
                $article = Repo::submission()->get((int)$articleId);
                if ($article) {
                    if (method_exists(Repo::doi(), 'getPublishedDoisForSubmission')) {
                        $publishedDois = Repo::doi()->getPublishedDoisForSubmission($article->getId());
                    } else {
                        $publishedDois = Repo::doi()->getDoisForSubmission($article->getId());
                    }
                    if (empty($publishedDois)) {
                        $publishedDois = Repo::doi()->getDoisForSubmission($article->getId());
                    }
                    foreach ($publishedDois as $pDoiId) {
                        $pDoi = Repo::doi()->get($pDoiId);
                        $msg = $pDoi?->getData($this->getFailedMsgSettingName());
                        if (!empty($msg)) {
                            $failedMsg = $msg;
                            break;
                        }
                    }
                }
            }
        }

        if (empty($failedMsg)) {
            $issueId = $request->getUserVar('issueId');
            if (!empty($issueId)) {
                $issue = Repo::issue()->get((int)$issueId);
                if ($issue) {
                    $issueDois = Repo::doi()->getDoisForIssue($issue->getId(), true);
                    foreach ($issueDois as $iDoiId) {
                        $iDoi = Repo::doi()->get($iDoiId);
                        $msg = $iDoi?->getData($this->getFailedMsgSettingName());
                        if (!empty($msg)) {
                            $failedMsg = $msg;
                            break;
                        }
                    }
                }
            }
        }

        if (!empty($failedMsg)) {
            return $failedMsg;
        }

        $context = $request->getContext();
        $batchId = $request->getUserVar('batchId');
        if (empty($batchId)) {
            return __('plugins.importexport.crossref.status.failed');
        }

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? static::CROSSREF_API_STATUS_URL_DEV : static::CROSSREF_API_STATUS_URL,
                [
                    'form_params' => [
                        'doi_batch_id' => $batchId,
                        'type' => 'result',
                        'usr' => $this->getSetting($context->getId(), 'username'),
                        'pwd' => $this->getSetting($context->getId(), 'password'),
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $returnMessage = $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')';
            }
            error_log('CrossrefPlugin::getStatusMessage error: ' . preg_replace('/([?&](?:usr|pwd)=)[^&]*/', '$1*****', $returnMessage));
            return __('plugins.importexport.common.register.error.mdsError', ['param' => $returnMessage]);
        }

        $body = (string) $response->getBody();
        $details = $this->_parseCrossrefDiagnosticXml($body);
        if (!empty($details['errors'])) {
            error_log("Crossref batch {$batchId} returned diagnostic errors: " . implode('; ', $details['errors']));
            return implode(PHP_EOL, $details['errors']) . PHP_EOL . PHP_EOL . $body;
        }

        return $body;
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     */
    public function getObjectAdditionalSettings(): array
    {
        return array_merge(parent::getObjectAdditionalSettings(), [
            $this->getDepositBatchIdSettingName(),
            $this->getFailedMsgSettingName(),
            $this->getSuccessMsgSettingName(),
        ]);
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix()
    {
        return 'crossrefplugin';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        throw new Exception('DOI settings no longer managed via plugin settings form.');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName()
    {
        return (string) \APP\plugins\generic\crossref\CrossrefExportDeployment::class;
    }

    // Settings and configuration methods

    /**
     * Get request failed message setting name.
     * Note: Since 3.4 the separator is underscore (_) instead of double colon (::).
     */
    public function getFailedMsgSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * Note: Since 3.4 the separator is underscore (_) instead of double colon (::).
     */
    public function getDepositBatchIdSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '_batchId';
    }

    /**
     * Get success message setting name.
     */
    public function getSuccessMsgSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '_successMsg';
    }

    // Main export and deposit operations

    /**
     * Exports and deposits XML
     *
     * @param (Issue|Submission)[] $objects
    */
    public function exportAndDeposit(Journal $context, array $objects, string $filter, string &$responseMessage, ?bool $noValidation = null): bool
    {
        $fileManager = new FileManager();
        $resultErrors = [];

        assert($filter != null);
        // Errors occurred will be accessible via the status link
        // thus do not display all errors notifications (for every article),
        // just one general.
        // Warnings occurred when the registration was successful will however be
        // displayed for each article.
        $errorsOccurred = false;
        // The new Crossref deposit API expects one request per object.
        // On contrary the export supports bulk/batch object export, thus
        // also the filter expects an array of objects.
        // Thus the foreach loop, but every object will be in an one item array for
        // the export and filter to work.
        foreach ($objects as $object) {
            $objectFileNamePart = $this->_getObjectFileNamePart($object);
            try {
                // Get the XML
                // Supply an exportErrors array because otherwise exportXML() will echo out export errors
                $exportErrors = [];
                $exportXml = $this->exportXML([$object], $filter, $context, $noValidation, $exportErrors);
                // Only schema validity errors (libxml XML_SCHEMAV_* codes) mean invalid XML; if the schema could not be loaded, let Crossref validate it
                $validityErrors = array_filter($exportErrors, fn (\LibXMLError $error) => $error->code >= 1800 && $error->code < 1900);
                if (!empty($validityErrors)) {
                    $messages = array_unique(array_map(fn (\LibXMLError $error) => trim($error->message), $validityErrors));
                    $failedMsg = __('plugins.importexport.crossref.export.error.validation') . PHP_EOL . implode(PHP_EOL, $messages);
                    error_log("Crossref XML validation error for object {$objectFileNamePart}: {$failedMsg}");
                    $this->updateDepositStatus($context, $object, Doi::STATUS_ERROR, null, $failedMsg);
                    $resultErrors[] = [['plugins.importexport.crossref.export.error.validation']];
                    $errorsOccurred = true;
                    continue;
                }
                if (empty($exportXml)) {
                    $failedMsg = "Failed to generate Crossref XML for object {$objectFileNamePart}";
                    error_log("Crossref export XML error: {$failedMsg}");
                    $this->updateDepositStatus($context, $object, Doi::STATUS_ERROR, null, $failedMsg);
                    $resultErrors[] = [['plugins.importexport.common.register.error.mdsError', $failedMsg]];
                    $errorsOccurred = true;
                    continue;
                }
                // Write the XML to a file.
                // export file name example: crossref-20160723-160036-articles-1-1.xml
                $exportFileName = $this->getExportFileName($this->getExportPath(), $objectFileNamePart, $context, '.xml');
                $fileManager->writeFile($exportFileName, $exportXml);
                // Deposit the XML file.
                $result = $this->depositXML($object, $context, $exportFileName);
                if (!$result || (is_array($result) && !$this->isWarningResult($result))) {
                    $errorsOccurred = true;
                }
                // Warnings are returned as an array too, but the DOI is registered
                if (is_array($result) && !$this->isWarningResult($result)) {
                    $resultErrors[] = $result;
                }
                // Remove all temporary files.
                $fileManager->deleteByPath($exportFileName);
            } catch (\Throwable $e) {
                $errorMsg = "Unexpected error during Crossref deposit for object {$objectFileNamePart}: " . $e->getMessage();
                error_log($errorMsg . PHP_EOL . $e->getTraceAsString());
                $this->updateDepositStatus($context, $object, Doi::STATUS_ERROR, null, $errorMsg);
                $resultErrors[] = [['plugins.importexport.common.register.error.mdsError', $errorMsg]];
                $errorsOccurred = true;
            }
        }
        // Prepare response message and return status
        if (empty($resultErrors)) {
            if ($errorsOccurred) {
                $responseMessage = 'plugins.importexport.crossref.register.error.mdsError';
                return false;
            } else {
                $responseMessage = $this->getDepositSuccessNotificationMessageKey();
                return true;
            }
        } else {
            $responseMessage = 'api.dois.400.depositFailed';
            return false;
        }
    }

    /**
     * Exports and stores XML as a TemporaryFile
     *
     * @param (Issue|Submission)[] $objects
    */
    public function exportAsDownload(Journal $context, array $objects, string $filter, string $objectsFileNamePart, ?bool $noValidation = null, ?array &$exportErrors = null): ?int
    {
        $fileManager = new TemporaryFileManager();

        $exportErrors = [];
        $exportXml = $this->exportXML($objects, $filter, $context, $noValidation, $exportErrors);

        $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');

        $fileManager->writeFile($exportFileName, $exportXml);

        $user = Application::get()->getRequest()->getUser();

        return $fileManager->createTempFileFromExisting($exportFileName, $user->getId());
    }

    /**
     * @param mixed $objects
     * @param Journal $context
     * @param string $filename Export XML filename
     *
     * @throws GuzzleException
     *
     * @see PubObjectsExportPlugin::depositXML()
     *
     * @hook crossrefexportplugin::deposited [[$this, $response->getBody(), $objects]]
     */
    public function depositXML($objects, $context, $filename)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with crossref external service');
            return false;
        }

        $objectPart = $this->_getObjectFileNamePart($objects);
        $status = null;
        $msgSave = null;

        $httpClient = Application::get()->getHttpClient();
        assert(is_readable($filename));

        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? static::CROSSREF_API_URL_DEV : static::CROSSREF_API_URL,
                [
                    'multipart' => [
                        [
                            'name' => 'usr',
                            'contents' => $this->getSetting($context->getId(), 'username'),
                        ],
                        [
                            'name' => 'pwd',
                            'contents' => $this->getSetting($context->getId(), 'password'),
                        ],
                        [
                            'name' => 'operation',
                            'contents' => 'doMDUpload',
                        ],
                        [
                            'name' => 'mdFile',
                            'contents' => fopen($filename, 'r'),
                        ],
                    ]
                ]
            );
        } catch (TransferException $e) {
            $returnMessage = $e->getMessage();
            if ($e instanceof RequestException && $e->hasResponse()) {
                $eResponseBody = (string) $e->getResponse()->getBody();
                $eStatusCode = $e->getResponse()->getStatusCode();
                if ($eStatusCode == static::CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF) {
                    $xmlDetails = $this->_parseCrossrefDiagnosticXml($eResponseBody);
                    $msg = !empty($xmlDetails['errors']) ? implode(PHP_EOL, $xmlDetails['errors']) : $eResponseBody;
                    $msgSave = $msg . PHP_EOL . $eResponseBody;
                    $status = Doi::STATUS_ERROR;
                    $batchId = $xmlDetails['batchId'] ?? null;
                    $this->updateDepositStatus($context, $objects, $status, $batchId, $msgSave);
                    $returnMessage = $msg . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                    error_log("Crossref deposit error (HTTP {$eStatusCode}) for object {$objectPart}: {$returnMessage}");
                } elseif ($eStatusCode == static::CROSSREF_API_DEPOSIT_ERROR_UNAUTHORIZED) {
                    if ($this->isTestMode($context)) {
                        $errorString = __('plugins.importexport.crossref.settings.form.testModeActive');
                    } else {
                        $errorString = __('plugins.importexport.crossref.export.error.unauthorized');
                    }
                    $returnMessage = $errorString . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                    $this->updateDepositStatus($context, $objects, Doi::STATUS_ERROR, null, $returnMessage);
                    error_log("Crossref deposit unauthorized (HTTP {$eStatusCode}) for object {$objectPart}: {$returnMessage}");
                } else {
                    $returnMessage = $eResponseBody . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                    $this->updateDepositStatus($context, $objects, Doi::STATUS_ERROR, null, $returnMessage);
                    error_log("Crossref deposit HTTP error {$eStatusCode} for object {$objectPart}: {$returnMessage}");
                }
            } else {
                // No answer from Crossref, so the status would stay "Submitted"
                $this->updateDepositStatus($context, $objects, Doi::STATUS_ERROR, null, $returnMessage);
                error_log("Crossref deposit network error for object {$objectPart}: {$returnMessage}");
            }
            return [['plugins.importexport.common.register.error.mdsError', $returnMessage]];
        }

        $body = (string) $response->getBody();
        $xmlDetails = $this->_parseCrossrefDiagnosticXml($body);

        if (!$xmlDetails['isValidXml']) {
            $status = Doi::STATUS_ERROR;
            $errorMsg = 'Invalid XML response from Crossref: ' . substr(strip_tags($body), 0, 500);
            $msgSave = $errorMsg . PHP_EOL . $body;
            error_log("Crossref deposit invalid XML response for object {$objectPart}: {$body}");
            $this->updateDepositStatus($context, $objects, $status, null, $msgSave, null);
            return [['plugins.importexport.common.register.error.mdsError', $msgSave]];
        }

        $batchId = $xmlDetails['batchId'];
        $submissionId = $xmlDetails['submissionId'];
        $failureCount = $xmlDetails['failureCount'];
        $warningCount = $xmlDetails['warningCount'];

        $hasErrors = ($failureCount !== null && $failureCount > 0)
            || !empty($xmlDetails['errors'])
            || in_array(strtolower($xmlDetails['status'] ?? ''), ['failure', 'failed']);

        if ($hasErrors) {
            $status = Doi::STATUS_ERROR;
            $errorSummary = !empty($xmlDetails['errors'])
                ? implode(PHP_EOL, $xmlDetails['errors'])
                : "Crossref deposit failed with failure count: {$failureCount}";
            $msgSave = trim($errorSummary . PHP_EOL . PHP_EOL . $body);
            error_log("Crossref deposit diagnostic failure for object {$objectPart}: {$errorSummary}");
            $this->updateDepositStatus($context, $objects, $status, $batchId, $msgSave, null);
            return [['plugins.importexport.common.register.error.mdsError', $msgSave]];
        }

        $status = Doi::STATUS_REGISTERED;
        $result = true;
        $successMessage = $submissionId
            ? __('plugins.generic.crossref.successMessage', ['submissionId' => $submissionId])
            : __('plugins.importexport.crossref.status.registered');

        if ($warningCount > 0 || !empty($xmlDetails['warnings'])) {
            $result = [['plugins.importexport.crossref.register.success.warning', htmlspecialchars($body)]];
            $successMessage .= PHP_EOL . __('plugins.importexport.crossref.register.success.warnings');
        }

        // A possibility for other plugins to work with the response
        Hook::run('crossrefexportplugin::deposited', [[$this, $body, $objects]]);

        if ($objects instanceof Submission) {
            $this->agencyPlugin->getCitationDoiHandler()?->handleDepositResponse($body, $objects);
        }

        $this->updateDepositStatus($context, $objects, $status, $batchId, null, $successMessage);

        return $result;
    }

    /**
     * Update the local DOI deposit status and related metadata for the given object.
     */
    public function updateDepositStatus(Journal $context, Issue|Submission|ReviewAssignment $object, int $status, ?string $batchId = null, ?string $failedMsg = null, ?string $successMsg = null)
    {
        if ($object instanceof Submission) {
            if (method_exists(Repo::doi(), 'getPublishedDoisForSubmission')) {
                $doiIds = Repo::doi()->getPublishedDoisForSubmission($object->getId());
            } else {
                $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
            }
            if (empty($doiIds)) {
                $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
            }
        } elseif ($object instanceof Issue) {
            $doiIds = Repo::doi()->getDoisForIssue($object->getId(), true);
        } else {
            if (method_exists(Repo::doi(), 'getDoisForReviewAssignment')) {
                $doiIds = Repo::doi()->getDoisForReviewAssignment($object->getId(), true);
            } else {
                $doiId = method_exists($object, 'getData') ? $object->getData('doiId') : null;
                $doiIds = $doiId ? [$doiId] : [];
            }
        }

        foreach ($doiIds as $doiId) {
            $doi = Repo::doi()->get($doiId);
            if (!$doi) {
                continue;
            }

            $editParams = [
                'status' => $status,
                // Sets new failedMsg or resets to null for removal of previous message
                $this->getFailedMsgSettingName() => $failedMsg,
                $this->getDepositBatchIdSettingName() => $batchId,
                $this->getSuccessMsgSettingName() => $successMsg,
            ];

            if ($status === Doi::STATUS_REGISTERED) {
                $editParams['registrationAgency'] = $this->getName();
            }

            Repo::doi()->edit($doi, $editParams);
        }
    }

    /**
     * @copydoc DOIPubIdExportPlugin::markRegistered()
     */
    public function markRegistered($context, $objects)
    {
        foreach ($objects as $object) {
            if ($object instanceof Submission) {
                if (method_exists(Repo::doi(), 'getPublishedDoisForSubmission')) {
                    $doiIds = Repo::doi()->getPublishedDoisForSubmission($object->getId());
                } else {
                    $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
                }
                if (empty($doiIds)) {
                    $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
                }
            } elseif ($object instanceof Issue) {
                $doiIds = Repo::doi()->getDoisForIssue($object->getId(), true);
            } else {
                if (method_exists(Repo::doi(), 'getDoisForReviewAssignment')) {
                    $doiIds = Repo::doi()->getDoisForReviewAssignment($object->getId(), true);
                } else {
                    $doiId = method_exists($object, 'getData') ? $object->getData('doiId') : null;
                    $doiIds = $doiId ? [$doiId] : [];
                }
            }

            foreach ($doiIds as $doiId) {
                Repo::doi()->markRegistered($doiId);
            }
        }
    }

    /**
     * Get the publications that can be exported/deposited.
     * Only the last minor versions are considered.
     */
    public function getLatestMinorPublications($publications): array
    {
        $latestMinorPublications = [];
        foreach ($publications as $publication) {
            if (!$publication->getDoi() ||
                $publication->getData('status') != Submission::STATUS_PUBLISHED) {
                continue;
            }

            $versionStage = $publication->getData('versionStage');
            $versionMajor = $publication->getData('versionMajor');
            $versionMinor = $publication->getData('versionMinor');
            if (!array_key_exists($versionStage, $latestMinorPublications)) {
                $latestMinorPublications[$versionStage] = [];
            }
            if (!array_key_exists($versionMajor, $latestMinorPublications[$versionStage])) {
                $latestMinorPublications[$versionStage][$versionMajor] = $publication;
                continue;
            }
            if ($versionMinor > $latestMinorPublications[$versionStage][$versionMajor]->getData('versionMinor')) {
                $latestMinorPublications[$versionStage][$versionMajor] = $publication;
            }
        }
        return $latestMinorPublications;
    }

    /**
     * Safely parse Crossref deposit diagnostic XML and extract all error and warning details.
     *
     * @return array{
     *     isValidXml: bool,
     *     batchId: ?string,
     *     submissionId: ?string,
     *     status: ?string,
     *     failureCount: ?int,
     *     warningCount: int,
     *     errors: string[],
     *     warnings: string[]
     * }
     */
    public function _parseCrossrefDiagnosticXml(string $xmlString): array
    {
        $result = [
            'isValidXml' => false,
            'batchId' => null,
            'submissionId' => null,
            'status' => null,
            'failureCount' => null,
            'warningCount' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        if (trim($xmlString) === '' || !str_contains($xmlString, '<')) {
            return $result;
        }

        $prevUseErrors = libxml_use_internal_errors(true);
        $xmlDoc = new \DOMDocument('1.0', 'utf-8');
        $loaded = @$xmlDoc->loadXML($xmlString);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if (!$loaded || !$xmlDoc->documentElement) {
            return $result;
        }

        $rootName = strtolower($xmlDoc->documentElement->localName ?? $xmlDoc->documentElement->tagName);
        if ($rootName !== 'doi_batch_diagnostic' && $rootName !== 'crossref_result') {
            return $result;
        }

        $result['isValidXml'] = true;

        if ($xmlDoc->documentElement->hasAttribute('status')) {
            $result['status'] = $xmlDoc->documentElement->getAttribute('status');
        }

        $batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
        if ($batchIdNode) {
            $result['batchId'] = trim($batchIdNode->nodeValue);
        }

        $submissionIdNode = $xmlDoc->getElementsByTagName('submission_id')->item(0);
        if ($submissionIdNode) {
            $result['submissionId'] = trim($submissionIdNode->nodeValue);
        }

        $failureCountNode = $xmlDoc->getElementsByTagName('failure_count')->item(0);
        if ($failureCountNode !== null) {
            $result['failureCount'] = (int) $failureCountNode->nodeValue;
        }

        $warningCountNode = $xmlDoc->getElementsByTagName('warning_count')->item(0);
        if ($warningCountNode !== null) {
            $result['warningCount'] = (int) $warningCountNode->nodeValue;
        }

        $collectedErrors = [];
        $collectedWarnings = [];

        // 1. Process record_diagnostic elements
        $recordDiagnostics = $xmlDoc->getElementsByTagName('record_diagnostic');
        foreach ($recordDiagnostics as $diag) {
            $diagStatus = strtolower($diag->getAttribute('status'));
            $doiNode = $diag->getElementsByTagName('doi')->item(0);
            $doi = $doiNode ? trim($doiNode->nodeValue) : null;

            $diagMsgs = [];
            foreach ($diag->getElementsByTagName('msg') as $m) {
                $val = trim($m->nodeValue);
                if ($val !== '') {
                    $diagMsgs[] = $val;
                }
            }

            if (empty($diagMsgs)) {
                $text = trim($diag->textContent);
                if ($doi && str_starts_with($text, $doi)) {
                    $text = trim(substr($text, strlen($doi)));
                }
                if ($text !== '') {
                    $diagMsgs[] = $text;
                }
            }

            $prefix = $doi ? "[DOI {$doi}] " : '';
            foreach ($diagMsgs as $msgText) {
                $formatted = $prefix . $msgText;
                if ($diagStatus === 'warning') {
                    $collectedWarnings[] = $formatted;
                } else {
                    $collectedErrors[] = $formatted;
                }
            }
        }

        // 2. Process all top-level or other msg elements
        $allMsgs = $xmlDoc->getElementsByTagName('msg');
        foreach ($allMsgs as $m) {
            if ($m->parentNode && $m->parentNode->nodeName === 'record_diagnostic') {
                continue;
            }
            $val = trim($m->nodeValue);
            if ($val !== '' && !in_array($val, $collectedErrors) && !in_array($val, $collectedWarnings)) {
                $collectedErrors[] = $val;
            }
        }

        // 3. Process error elements
        $errorNodes = $xmlDoc->getElementsByTagName('error');
        foreach ($errorNodes as $e) {
            $val = trim($e->nodeValue);
            if ($val !== '' && !in_array($val, $collectedErrors)) {
                $collectedErrors[] = $val;
            }
        }

        // 4. If root status is Failure/failed and no specific errors found
        if (in_array(strtolower($result['status'] ?? ''), ['failure', 'failed']) && empty($collectedErrors)) {
            $collectedErrors[] = 'Crossref rejected deposit with status "Failure"';
        }

        $result['errors'] = array_values(array_unique($collectedErrors));
        $result['warnings'] = array_values(array_unique($collectedWarnings));

        return $result;
    }

    /**
     * Check if the depositXML() result only contains the success-with-warning message
     */
    private function isWarningResult(array $result): bool
    {
        return count($result) === 1 && ($result[0][0] ?? null) === 'plugins.importexport.crossref.register.success.warning';
    }

    /**
     * Get part of the file name based on the object that is being exported
     */
    private function _getObjectFileNamePart(Submission|Issue|ReviewAssignment $object): string
    {
        if ($object instanceof Submission) {
            return 'articles-' . $object->getId();
        }

        if ($object instanceof ReviewAssignment) {
            return 'peerReviews-' . $object->getId();
        }
        return 'issues-' . $object->getId();
    }
}
