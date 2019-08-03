<?php

namespace Supseven\EmptyColposContent\Xclass;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\BackendWorkspaceRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

class PageLayoutView extends \TYPO3\CMS\Backend\View\PageLayoutView
{
    public function getTable_tt_content($id)
    {
        switch (TYPO3_branch) {
            case '8.7':
                return $this->getTable_tt_content_v8($id);
                break;

            case '9.5':
                return $this->getTable_tt_content_v9($id);
                break;

            default:
                return parent::getTable_tt_content($id);
                break;
        }
    }

    /**
     * place the new hook in the correct place - for the TYPO3 8.7 branch
     *
     * @param $id
     * @return string
     */
    private function getTable_tt_content_v8($id)
    {
        $backendUser = $this->getBackendUser();
        $expressionBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->getExpressionBuilder();
        $this->pageinfo = BackendUtility::readPageAccess($this->id, '');
        $this->initializeLanguages();
        $this->initializeClipboard();
        $pageTitleParamForAltDoc = '&recTitle=' . rawurlencode(BackendUtility::getRecordTitle('pages', BackendUtility::getRecordWSOL('pages', $id), true));
        /** @var $pageRenderer PageRenderer */
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/LayoutModule/DragDrop');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/Modal');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/LayoutModule/Paste');
        $userCanEditPage = $this->ext_CALC_PERMS & Permission::PAGE_EDIT && !empty($this->id) && ($backendUser->isAdmin() || (int)$this->pageinfo['editlock'] === 0);
        if ($this->tt_contentConfig['languageColsPointer'] > 0) {
            $userCanEditPage = $this->getBackendUser()->check('tables_modify', 'pages_language_overlay');
        }
        if ($userCanEditPage) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('pages_language_overlay');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));

            $queryBuilder->select('uid')
                ->from('pages_language_overlay')
                ->where(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$this->id, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(
                            $this->tt_contentConfig['sys_language_uid'],
                            \PDO::PARAM_INT
                        )
                    )
                )
                ->setMaxResults(1);

            $languageOverlayId = (int)$queryBuilder->execute()->fetchColumn(0);

            $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/PageActions', 'function(PageActions) {
                PageActions.setPageId(' . (int)$this->id . ');
                PageActions.setLanguageOverlayId(' . $languageOverlayId . ');
                PageActions.initializePageTitleRenaming();
            }');
        }
        // Get labels for CTypes and tt_content element fields in general:
        $this->CType_labels = [];
        foreach ($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] as $val) {
            $this->CType_labels[$val[1]] = $this->getLanguageService()->sL($val[0]);
        }
        $this->itemLabels = [];
        foreach ($GLOBALS['TCA']['tt_content']['columns'] as $name => $val) {
            $this->itemLabels[$name] = $this->getLanguageService()->sL($val['label']);
        }
        $languageColumn = [];
        $out = '';

        // Setting language list:
        $langList = $this->tt_contentConfig['sys_language_uid'];
        if ($this->tt_contentConfig['languageMode']) {
            if ($this->tt_contentConfig['languageColsPointer']) {
                $langList = '0,' . $this->tt_contentConfig['languageColsPointer'];
            } else {
                $langList = implode(',', array_keys($this->tt_contentConfig['languageCols']));
            }
            $languageColumn = [];
        }
        $langListArr = GeneralUtility::intExplode(',', $langList);
        $defaultLanguageElementsByColumn = [];
        $defLangBinding = [];
        // For each languages... :
        // If not languageMode, then we'll only be through this once.
        foreach ($langListArr as $lP) {
            $lP = (int)$lP;

            if (!isset($this->contentElementCache[$lP])) {
                $this->contentElementCache[$lP] = [];
            }

            if (count($langListArr) === 1 || $lP === 0) {
                $showLanguage = $expressionBuilder->in('sys_language_uid', [$lP, -1]);
            } else {
                $showLanguage = $expressionBuilder->eq('sys_language_uid', $lP);
            }
            $cList = explode(',', $this->tt_contentConfig['cols']);
            $content = [];
            $head = [];

            // Select content records per column
            $contentRecordsPerColumn = $this->getContentRecordsPerColumn('table', $id, array_values($cList), $showLanguage);
            // For each column, render the content into a variable:
            foreach ($cList as $columnId) {
                if (!isset($this->contentElementCache[$lP][$columnId])) {
                    $this->contentElementCache[$lP][$columnId] = [];
                }

                if (!$lP) {
                    $defaultLanguageElementsByColumn[$columnId] = [];
                }
                // Start wrapping div
                $content[$columnId] .= '<div data-colpos="' . $columnId . '" data-language-uid="' . $lP . '" class="t3js-sortable t3js-sortable-lang t3js-sortable-lang-' . $lP . ' t3-page-ce-wrapper';
                if (empty($contentRecordsPerColumn[$columnId])) {
                    $content[$columnId] .= ' t3-page-ce-empty';
                }
                $content[$columnId] .= '">';
                // Add new content at the top most position
                $link = '';
                if ($this->getPageLayoutController()->contentIsNotLockedForEditors()
                    && (!$this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP))
                ) {
                    if ($this->option_newWizard) {
                        $urlParameters = [
                            'id' => $id,
                            'sys_language_uid' => $lP,
                            'colPos' => $columnId,
                            'uid_pid' => $id,
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $tsConfig = BackendUtility::getModTSconfig($id, 'mod');
                        $moduleName = isset($tsConfig['properties']['newContentElementWizard.']['override'])
                            ? $tsConfig['properties']['newContentElementWizard.']['override']
                            : 'new_content_element';
                        $url = BackendUtility::getModuleUrl($moduleName, $urlParameters);
                    } else {
                        $urlParameters = [
                            'edit' => [
                                'tt_content' => [
                                    $id => 'new'
                                ]
                            ],
                            'defVals' => [
                                'tt_content' => [
                                    'colPos' => $columnId,
                                    'sys_language_uid' => $lP
                                ]
                            ],
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $url = BackendUtility::getModuleUrl('record_edit', $urlParameters);
                    }

                    $link = '<a href="' . htmlspecialchars($url) . '" title="'
                        . htmlspecialchars($this->getLanguageService()->getLL('newContentElement')) . '" class="btn btn-default btn-sm">'
                        . $this->iconFactory->getIcon('actions-document-new', Icon::SIZE_SMALL)->render()
                        . ' '
                        . htmlspecialchars($this->getLanguageService()->getLL('content')) . '</a>';
                }
                if ($this->getBackendUser()->checkLanguageAccess($lP)) {
                    $content[$columnId] .= '
                    <div class="t3-page-ce t3js-page-ce" data-page="' . (int)$id . '" id="' . StringUtility::getUniqueId() . '">
                        <div class="t3js-page-new-ce t3-page-ce-wrapper-new-ce" id="colpos-' . $columnId . '-' . 'page-' . $id . '-' . StringUtility::getUniqueId() . '">'
                        . $link
                        . '</div>
                        <div class="t3-page-ce-dropzone-available t3js-page-ce-dropzone-available"></div>
                    </div>
                    ';
                }
                $editUidList = '';
                if (!isset($contentRecordsPerColumn[$columnId]) || !is_array($contentRecordsPerColumn[$columnId])) {
                    $message = GeneralUtility::makeInstance(
                        FlashMessage::class,
                        $this->getLanguageService()->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:error.invalidBackendLayout'),
                        '',
                        FlashMessage::WARNING
                    );
                    $service = GeneralUtility::makeInstance(FlashMessageService::class);
                    $queue = $service->getMessageQueueByIdentifier();
                    $queue->addMessage($message);
                } else {
                    $rowArr = $contentRecordsPerColumn[$columnId];
                    $this->generateTtContentDataArray($rowArr);

                    foreach ((array)$rowArr as $rKey => $row) {
                        $this->contentElementCache[$lP][$columnId][$row['uid']] = $row;
                        if ($this->tt_contentConfig['languageMode']) {
                            $languageColumn[$columnId][$lP] = $head[$columnId] . $content[$columnId];
                            if (!$this->defLangBinding) {
                                $languageColumn[$columnId][$lP] .= $this->newLanguageButton(
                                    $this->getNonTranslatedTTcontentUids($defaultLanguageElementsByColumn[$columnId], $id, $lP),
                                    $lP,
                                    $columnId
                                );
                            }
                        }
                        if (is_array($row) && !VersionState::cast($row['t3ver_state'])->equals(VersionState::DELETE_PLACEHOLDER)) {
                            $singleElementHTML = '';
                            if (!$lP && ($this->defLangBinding || $row['sys_language_uid'] != -1)) {
                                $defaultLanguageElementsByColumn[$columnId][] = (isset($row['_ORIG_uid']) ? $row['_ORIG_uid'] : $row['uid']);
                            }
                            $editUidList .= $row['uid'] . ',';
                            $disableMoveAndNewButtons = $this->defLangBinding && $lP > 0 && $this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP);
                            if (!$this->tt_contentConfig['languageMode']) {
                                $singleElementHTML .= '<div class="t3-page-ce-dragitem" id="' . StringUtility::getUniqueId() . '">';
                            }
                            $singleElementHTML .= $this->tt_content_drawHeader(
                                $row,
                                $this->tt_contentConfig['showInfo'] ? 15 : 5,
                                $disableMoveAndNewButtons,
                                true,
                                $this->getBackendUser()->doesUserHaveAccess($this->pageinfo, Permission::CONTENT_EDIT)
                            );
                            $innerContent = '<div ' . ($row['_ORIG_uid'] ? ' class="ver-element"' : '') . '>'
                                . $this->tt_content_drawItem($row) . '</div>';
                            $singleElementHTML .= '<div class="t3-page-ce-body-inner">' . $innerContent . '</div>'
                                . $this->tt_content_drawFooter($row);
                            $isDisabled = $this->isDisabled('tt_content', $row);
                            $statusHidden = $isDisabled ? ' t3-page-ce-hidden t3js-hidden-record' : '';
                            $displayNone = !$this->tt_contentConfig['showHidden'] && $isDisabled ? ' style="display: none;"' : '';
                            $highlightHeader = false;
                            if ($this->checkIfTranslationsExistInLanguage([], (int)$row['sys_language_uid']) && (int)$row['l18n_parent'] === 0) {
                                $highlightHeader = true;
                            }
                            $singleElementHTML = '<div class="t3-page-ce ' . ($highlightHeader ? 't3-page-ce-danger' : '') . ' t3js-page-ce t3js-page-ce-sortable ' . $statusHidden . '" id="element-tt_content-'
                                . $row['uid'] . '" data-table="tt_content" data-uid="' . $row['uid'] . '"' . $displayNone . '>' . $singleElementHTML . '</div>';

                            if ($this->tt_contentConfig['languageMode']) {
                                $singleElementHTML .= '<div class="t3-page-ce t3js-page-ce">';
                            }
                            $singleElementHTML .= '<div class="t3js-page-new-ce t3-page-ce-wrapper-new-ce" id="colpos-' . $columnId . '-' . 'page-' . $id .
                                '-' . StringUtility::getUniqueId() . '">';
                            // Add icon "new content element below"
                            if (!$disableMoveAndNewButtons
                                && $this->getPageLayoutController()->contentIsNotLockedForEditors()
                                && $this->getBackendUser()->checkLanguageAccess($lP)
                                && (!$this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP))
                            ) {
                                // New content element:
                                if ($this->option_newWizard) {
                                    $urlParameters = [
                                        'id' => $row['pid'],
                                        'sys_language_uid' => $row['sys_language_uid'],
                                        'colPos' => $row['colPos'],
                                        'uid_pid' => -$row['uid'],
                                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                                    ];
                                    $tsConfig = BackendUtility::getModTSconfig($row['pid'], 'mod');
                                    $moduleName = isset($tsConfig['properties']['newContentElementWizard.']['override'])
                                        ? $tsConfig['properties']['newContentElementWizard.']['override']
                                        : 'new_content_element';
                                    $url = BackendUtility::getModuleUrl($moduleName, $urlParameters);
                                } else {
                                    $urlParameters = [
                                        'edit' => [
                                            'tt_content' => [
                                                -$row['uid'] => 'new'
                                            ]
                                        ],
                                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                                    ];
                                    $url = BackendUtility::getModuleUrl('record_edit', $urlParameters);
                                }
                                $singleElementHTML .= '
								<a href="' . htmlspecialchars($url) . '" title="'
                                    . htmlspecialchars($this->getLanguageService()->getLL('newContentElement')) . '" class="btn btn-default btn-sm">'
                                    . $this->iconFactory->getIcon('actions-document-new', Icon::SIZE_SMALL)->render()
                                    . ' '
                                    . htmlspecialchars($this->getLanguageService()->getLL('content')) . '</a>
							';
                            }
                            $singleElementHTML .= '</div></div><div class="t3-page-ce-dropzone-available t3js-page-ce-dropzone-available"></div></div>';
                            if ($this->defLangBinding && $this->tt_contentConfig['languageMode']) {
                                $defLangBinding[$columnId][$lP][$row[$lP ? 'l18n_parent' : 'uid'] ?: $row['uid']] = $singleElementHTML;
                            } else {
                                $content[$columnId] .= $singleElementHTML;
                            }
                        } else {
                            unset($rowArr[$rKey]);
                        }
                    }
                    $content[$columnId] .= '</div>';
                    $colTitle = BackendUtility::getProcessedValue('tt_content', 'colPos', $columnId);
                    $tcaItems = GeneralUtility::callUserFunction(\TYPO3\CMS\Backend\View\BackendLayoutView::class . '->getColPosListItemsParsed', $id, $this);
                    foreach ($tcaItems as $item) {
                        if ($item[1] == $columnId) {
                            $colTitle = $this->getLanguageService()->sL($item[0]);
                        }
                    }
                    $editParam = $this->doEdit && !empty($rowArr)
                        ? '&edit[tt_content][' . $editUidList . ']=edit' . $pageTitleParamForAltDoc
                        : '';
                    $head[$columnId] .= $this->tt_content_drawColHeader($colTitle, $editParam);
                }
            }
            // For each column, fit the rendered content into a table cell:
            $out = '';
            if ($this->tt_contentConfig['languageMode']) {
                // in language mode process the content elements, but only fill $languageColumn. output will be generated later
                $sortedLanguageColumn = [];
                foreach ($cList as $columnId) {
                    if (GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnId)) {
                        $languageColumn[$columnId][$lP] = $head[$columnId] . $content[$columnId];
                        if (!$this->defLangBinding) {
                            $languageColumn[$columnId][$lP] .= $this->newLanguageButton(
                                $this->getNonTranslatedTTcontentUids($defaultLanguageElementsByColumn[$columnId], $id, $lP),
                                $lP,
                                $columnId
                            );
                        }
                        // We sort $languageColumn again according to $cList as it may contain data already from above.
                        $sortedLanguageColumn[$columnId] = $languageColumn[$columnId];
                    }
                }
                $languageColumn = $sortedLanguageColumn;
            } else {
                $backendLayout = $this->getBackendLayoutView()->getSelectedBackendLayout($this->id);
                // GRID VIEW:
                $grid = '<div class="t3-grid-container"><table border="0" cellspacing="0" cellpadding="0" width="100%" class="t3-page-columns t3-grid-table t3js-page-columns">';
                // Add colgroups
                $colCount = (int)$backendLayout['__config']['backend_layout.']['colCount'];
                $rowCount = (int)$backendLayout['__config']['backend_layout.']['rowCount'];
                $grid .= '<colgroup>';
                for ($i = 0; $i < $colCount; $i++) {
                    $grid .= '<col />';
                }
                $grid .= '</colgroup>';
                // Cycle through rows
                for ($row = 1; $row <= $rowCount; $row++) {
                    $rowConfig = $backendLayout['__config']['backend_layout.']['rows.'][$row . '.'];
                    if (!isset($rowConfig)) {
                        continue;
                    }
                    $grid .= '<tr>';
                    for ($col = 1; $col <= $colCount; $col++) {
                        $columnConfig = $rowConfig['columns.'][$col . '.'];
                        if (!isset($columnConfig)) {
                            continue;
                        }
                        // Which tt_content colPos should be displayed inside this cell
                        $columnKey = (int)$columnConfig['colPos'];
                        // Render the grid cell
                        $colSpan = (int)$columnConfig['colspan'];
                        $rowSpan = (int)$columnConfig['rowspan'];
                        $grid .= '<td valign="top"' .
                            ($colSpan > 0 ? ' colspan="' . $colSpan . '"' : '') .
                            ($rowSpan > 0 ? ' rowspan="' . $rowSpan . '"' : '') .
                            ' data-colpos="' . (int)$columnConfig['colPos'] . '" data-language-uid="' . $lP . '" class="t3js-page-lang-column-' . $lP . ' t3js-page-column t3-grid-cell t3-page-column t3-page-column-' . $columnKey .
                            ((!isset($columnConfig['colPos']) || $columnConfig['colPos'] === '') ? ' t3-grid-cell-unassigned' : '') .
                            ((isset($columnConfig['colPos']) && $columnConfig['colPos'] !== '' && !$head[$columnKey]) || !GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos']) ? ' t3-grid-cell-restricted' : '') .
                            ($colSpan > 0 ? ' t3-gridCell-width' . $colSpan : '') .
                            ($rowSpan > 0 ? ' t3-gridCell-height' . $rowSpan : '') . '">';

                        // Draw the pre-generated header with edit and new buttons if a colPos is assigned.
                        // If not, a new header without any buttons will be generated.
                        if (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== '' && $head[$columnKey]
                            && GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            $grid .= $head[$columnKey] . $content[$columnKey];
                        } elseif (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== ''
                            && GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->getLL('noAccess'));
                        } elseif (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== ''
                            && !GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->sL($columnConfig['name']) .
                                ' (' . $this->getLanguageService()->getLL('noAccess') . ')');
                        } elseif (isset($columnConfig['name']) && $columnConfig['name'] !== '') {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->sL($columnConfig['name'])
                                . ' (' . $this->getLanguageService()->getLL('notAssigned') . ')');
                        } else {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->getLL('notAssigned'));
                        }

                        $grid .= $this->injectEmptyColPosHook($columnConfig);

                        $grid .= '</td>';
                    }
                    $grid .= '</tr>';
                }
                $out .= $grid . '</table></div>';
            }
            // CSH:
            $out .= BackendUtility::cshItem($this->descrTable, 'columns_multi', null, '<span class="btn btn-default btn-sm">|</span>');
        }
        $elFromTable = $this->clipboard->elFromTable('tt_content');
        if (!empty($elFromTable) && $this->getPageLayoutController()->pageIsNotLockedForEditors()) {
            $pasteItem = substr(key($elFromTable), 11);
            $pasteRecord = BackendUtility::getRecord('tt_content', (int)$pasteItem);
            $pasteTitle = $pasteRecord['header'] ? $pasteRecord['header'] : $pasteItem;
            $copyMode = $this->clipboard->clipData['normal']['mode'] ? '-' . $this->clipboard->clipData['normal']['mode'] : '';
            $addExtOnReadyCode = '
                     top.pasteIntoLinkTemplate = '
                . $this->tt_content_drawPasteIcon($pasteItem, $pasteTitle, $copyMode, 't3js-paste-into', 'pasteIntoColumn')
                . ';
                    top.pasteAfterLinkTemplate = '
                . $this->tt_content_drawPasteIcon($pasteItem, $pasteTitle, $copyMode, 't3js-paste-after', 'pasteAfterRecord')
                . ';';
        } else {
            $addExtOnReadyCode = '
                top.pasteIntoLinkTemplate = \'\';
                top.pasteAfterLinkTemplate = \'\';';
        }
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addJsInlineCode('pasteLinkTemplates', $addExtOnReadyCode);
        // If language mode, then make another presentation:
        // Notice that THIS presentation will override the value of $out!
        // But it needs the code above to execute since $languageColumn is filled with content we need!
        if ($this->tt_contentConfig['languageMode']) {
            // Get language selector:
            $languageSelector = $this->languageSelector($id);
            // Reset out - we will make new content here:
            $out = '';
            // Traverse languages found on the page and build up the table displaying them side by side:
            $cCont = [];
            $sCont = [];
            foreach ($langListArr as $lP) {
                $languageMode = '';
                $labelClass = 'info';
                // Header:
                $lP = (int)$lP;
                // Determine language mode
                if ($lP > 0 && isset($this->languageHasTranslationsCache[$lP]['mode'])) {
                    switch ($this->languageHasTranslationsCache[$lP]['mode']) {
                        case 'mixed':
                            $languageMode = $this->getLanguageService()->getLL('languageModeMixed');
                            $labelClass = 'danger';
                            break;
                        case 'connected':
                            $languageMode = $this->getLanguageService()->getLL('languageModeConnected');
                            break;
                        case 'free':
                            $languageMode = $this->getLanguageService()->getLL('languageModeFree');
                            break;
                        default:
                            // we'll let opcode optimize this intentionally empty case
                    }
                }
                $cCont[$lP] = '
					<td valign="top" class="t3-page-column t3-page-column-lang-name" data-language-uid="' . $lP . '">
						<h2>' . htmlspecialchars($this->tt_contentConfig['languageCols'][$lP]) . '</h2>
						' . ($languageMode !== '' ? '<span class="label label-' . $labelClass . '">' . $languageMode . '</span>' : '') . '
					</td>';

                // "View page" icon is added:
                $viewLink = '';
                if (!VersionState::cast($this->getPageLayoutController()->pageinfo['t3ver_state'])->equals(VersionState::DELETE_PLACEHOLDER)) {
                    $onClick = BackendUtility::viewOnClick($this->id, '', BackendUtility::BEgetRootLine($this->id), '', '', ('&L=' . $lP));
                    $viewLink = '<a href="#" class="btn btn-default btn-sm" onclick="' . htmlspecialchars($onClick) . '" title="' . htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.showPage')) . '">' . $this->iconFactory->getIcon('actions-view', Icon::SIZE_SMALL)->render() . '</a>';
                }
                // Language overlay page header:
                if ($lP) {
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('pages_language_overlay');
                    $queryBuilder->getRestrictions()
                        ->removeAll()
                        ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                        ->add(GeneralUtility::makeInstance(BackendWorkspaceRestriction::class));

                    $lpRecord = $queryBuilder->select('*')
                        ->from('pages_language_overlay')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'pid',
                                $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT)
                            ),
                            $queryBuilder->expr()->eq(
                                'sys_language_uid',
                                $queryBuilder->createNamedParameter($lP, \PDO::PARAM_INT)
                            )
                        )
                        ->setMaxResults(1)
                        ->execute()
                        ->fetch();

                    BackendUtility::workspaceOL('pages_language_overlay', $lpRecord);
                    $recordIcon = BackendUtility::wrapClickMenuOnIcon(
                        $this->iconFactory->getIconForRecord('pages_language_overlay', $lpRecord, Icon::SIZE_SMALL)->render(),
                        'pages_language_overlay',
                        $lpRecord['uid']
                    );
                    $urlParameters = [
                        'edit' => [
                            'pages_language_overlay' => [
                                $lpRecord['uid'] => 'edit'
                            ]
                        ],
                        'overrideVals' => [
                            'pages_language_overlay' => [
                                'sys_language_uid' => $lP
                            ]
                        ],
                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                    ];
                    $url = BackendUtility::getModuleUrl('record_edit', $urlParameters);
                    $editLink = (
                    $this->getBackendUser()->check('tables_modify', 'pages_language_overlay')
                        ? '<a href="' . htmlspecialchars($url) . '" class="btn btn-default btn-sm"'
                        . ' title="' . htmlspecialchars($this->getLanguageService()->getLL('edit')) . '">'
                        . $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render() . '</a>'
                        : ''
                    );

                    $lPLabel =
                        '<div class="btn-group">'
                        . $viewLink
                        . $editLink
                        . '</div>'
                        . ' ' . $recordIcon . ' ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($lpRecord['title'], 20));
                } else {
                    $editLink = '';
                    $recordIcon = '';
                    if ($this->getBackendUser()->checkLanguageAccess(0)) {
                        $recordIcon = BackendUtility::wrapClickMenuOnIcon(
                            $this->iconFactory->getIconForRecord('pages', $this->pageRecord, Icon::SIZE_SMALL)->render(),
                            'pages',
                            $this->id
                        );
                        $urlParameters = [
                            'edit' => [
                                'pages' => [
                                    $this->id => 'edit'
                                ]
                            ],
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $url = BackendUtility::getModuleUrl('record_edit', $urlParameters);
                        $editLink = (
                        $this->getBackendUser()->check('tables_modify', 'pages_language_overlay')
                            ? '<a href="' . htmlspecialchars($url) . '" class="btn btn-default btn-sm"'
                            . ' title="' . htmlspecialchars($this->getLanguageService()->getLL('edit')) . '">'
                            . $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render() . '</a>'
                            : ''
                        );
                    }

                    $lPLabel =
                        '<div class="btn-group">'
                        . $viewLink
                        . $editLink
                        . '</div>'
                        . ' ' . $recordIcon . ' ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($this->pageRecord['title'], 20));
                }
                $sCont[$lP] = '
					<td class="t3-page-column t3-page-lang-label nowrap">' . $lPLabel . '</td>';
            }
            // Add headers:
            $out .= '<tr>' . implode($cCont) . '</tr>';
            $out .= '<tr>' . implode($sCont) . '</tr>';
            unset($cCont, $sCont);

            // Traverse previously built content for the columns:
            foreach ($languageColumn as $cKey => $cCont) {
                $out .= '<tr>';
                foreach ($cCont as $languageId => $columnContent) {
                    $out .= '<td valign="top" class="t3-grid-cell t3-page-column t3js-page-column t3js-page-lang-column t3js-page-lang-column-' . $languageId . '">' . $columnContent . '</td>';
                }
                $out .= '</tr>';
                if ($this->defLangBinding && !empty($defLangBinding[$cKey])) {
                    $maxItemsCount = max(array_map('count', $defLangBinding[$cKey]));
                    for ($i = 0; $i < $maxItemsCount; $i++) {
                        $defUid = $defaultLanguageElementsByColumn[$cKey][$i] ?? 0;
                        $cCont = [];
                        foreach ($langListArr as $lP) {
                            if ($lP > 0
                                && is_array($defLangBinding[$cKey][$lP])
                                && !$this->checkIfTranslationsExistInLanguage($defaultLanguageElementsByColumn[$cKey], $lP)
                                && count($defLangBinding[$cKey][$lP]) > $i
                            ) {
                                $slice = array_slice($defLangBinding[$cKey][$lP], $i, 1);
                                $element = $slice[0] ?? '';
                            } else {
                                $element = $defLangBinding[$cKey][$lP][$defUid] ?? '';
                            }
                            $cCont[] = $element . $this->newLanguageButton(
                                    $this->getNonTranslatedTTcontentUids([$defUid], $id, $lP),
                                    $lP,
                                    $cKey
                                );
                        }
                        $out .= '
                        <tr>
							<td valign="top" class="t3-grid-cell">' . implode(('</td>' . '
							<td valign="top" class="t3-grid-cell">'), $cCont) . '</td>
						</tr>';
                    }
                }
            }
            // Finally, wrap it all in a table and add the language selector on top of it:
            $out = $languageSelector . '
                <div class="t3-grid-container">
                    <table cellpadding="0" cellspacing="0" class="t3-page-columns t3-grid-table t3js-page-columns">
						' . $out . '
                    </table>
				</div>';
            // CSH:
            $out .= BackendUtility::cshItem($this->descrTable, 'language_list', null, '<span class="btn btn-default btn-sm">|</span>');
        }

        return $out;
    }

    /**
     * place the new hook in the correct place - for the TYPO3 9.5 branch
     *
     * @param $id
     * @return string
     */
    public function getTable_tt_content_v9($id)
    {
        $expressionBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->getExpressionBuilder();
        $this->pageinfo = BackendUtility::readPageAccess($this->id, '');
        $this->initializeLanguages();
        $this->initializeClipboard();
        $pageTitleParamForAltDoc = '&recTitle=' . rawurlencode(BackendUtility::getRecordTitle('pages', BackendUtility::getRecordWSOL('pages', $id), true));
        /** @var PageRenderer $pageRenderer */
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/LayoutModule/DragDrop');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/Modal');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/LayoutModule/Paste');
        $pageActionsCallback = '';
        if ($this->isPageEditable()) {
            $languageOverlayId = 0;
            $pageLocalizationRecord = BackendUtility::getRecordLocalization('pages', $this->id, (int)$this->tt_contentConfig['sys_language_uid']);
            if (is_array($pageLocalizationRecord)) {
                $pageLocalizationRecord = reset($pageLocalizationRecord);
            }
            if (!empty($pageLocalizationRecord['uid'])) {
                $languageOverlayId = $pageLocalizationRecord['uid'];
            }
            $pageActionsCallback = 'function(PageActions) {
                PageActions.setPageId(' . (int)$this->id . ');
                PageActions.setLanguageOverlayId(' . $languageOverlayId . ');
            }';
        }
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/PageActions', $pageActionsCallback);
        // Get labels for CTypes and tt_content element fields in general:
        $this->CType_labels = [];
        foreach ($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] as $val) {
            $this->CType_labels[$val[1]] = $this->getLanguageService()->sL($val[0]);
        }

        $this->itemLabels = [];
        foreach ($GLOBALS['TCA']['tt_content']['columns'] as $name => $val) {
            $this->itemLabels[$name] = $this->getLanguageService()->sL($val['label']);
        }
        $languageColumn = [];
        $out = '';

        // Setting language list:
        $langList = $this->tt_contentConfig['sys_language_uid'];
        if ($this->tt_contentConfig['languageMode']) {
            if ($this->tt_contentConfig['languageColsPointer']) {
                $langList = '0,' . $this->tt_contentConfig['languageColsPointer'];
            } else {
                $langList = implode(',', array_keys($this->tt_contentConfig['languageCols']));
            }
            $languageColumn = [];
        }
        $langListArr = GeneralUtility::intExplode(',', $langList);
        $defaultLanguageElementsByColumn = [];
        $defLangBinding = [];
        // For each languages... :
        // If not languageMode, then we'll only be through this once.
        foreach ($langListArr as $lP) {
            $lP = (int)$lP;

            if (!isset($this->contentElementCache[$lP])) {
                $this->contentElementCache[$lP] = [];
            }

            if (count($langListArr) === 1 || $lP === 0) {
                $showLanguage = $expressionBuilder->in('sys_language_uid', [$lP, -1]);
            } else {
                $showLanguage = $expressionBuilder->eq('sys_language_uid', $lP);
            }
            $content = [];
            $head = [];

            $backendLayout = $this->getBackendLayoutView()->getSelectedBackendLayout($this->id);
            $columns = $backendLayout['__colPosList'];
            // Select content records per column
            $contentRecordsPerColumn = $this->getContentRecordsPerColumn('table', $id, $columns, $showLanguage);
            $cList = array_keys($contentRecordsPerColumn);
            // For each column, render the content into a variable:
            foreach ($cList as $columnId) {
                if (!isset($this->contentElementCache[$lP])) {
                    $this->contentElementCache[$lP] = [];
                }

                if (!$lP) {
                    $defaultLanguageElementsByColumn[$columnId] = [];
                }

                // Start wrapping div
                $content[$columnId] .= '<div data-colpos="' . $columnId . '" data-language-uid="' . $lP . '" class="t3js-sortable t3js-sortable-lang t3js-sortable-lang-' . $lP . ' t3-page-ce-wrapper';
                if (empty($contentRecordsPerColumn[$columnId])) {
                    $content[$columnId] .= ' t3-page-ce-empty';
                }
                $content[$columnId] .= '">';
                // Add new content at the top most position
                $link = '';
                if ($this->isContentEditable()
                    && (!$this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP))
                ) {
                    if ($this->option_newWizard) {
                        $urlParameters = [
                            'id' => $id,
                            'sys_language_uid' => $lP,
                            'colPos' => $columnId,
                            'uid_pid' => $id,
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $routeName = BackendUtility::getPagesTSconfig($id)['mod.']['newContentElementWizard.']['override']
                            ?? 'new_content_element_wizard';
                        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                        $url = (string)$uriBuilder->buildUriFromRoute($routeName, $urlParameters);
                    } else {
                        $urlParameters = [
                            'edit' => [
                                'tt_content' => [
                                    $id => 'new'
                                ]
                            ],
                            'defVals' => [
                                'tt_content' => [
                                    'colPos' => $columnId,
                                    'sys_language_uid' => $lP
                                ]
                            ],
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                        $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                    }
                    $title = htmlspecialchars($this->getLanguageService()->getLL('newContentElement'));
                    $link = '<a href="' . htmlspecialchars($url) . '" '
                        . 'title="' . $title . '"'
                        . 'data-title="' . $title . '"'
                        . 'class="btn btn-default btn-sm ' . ($this->option_newWizard ? 't3js-toggle-new-content-element-wizard' : '') . '">'
                        . $this->iconFactory->getIcon('actions-add', Icon::SIZE_SMALL)->render()
                        . ' '
                        . htmlspecialchars($this->getLanguageService()->getLL('content')) . '</a>';
                }
                if ($this->getBackendUser()->checkLanguageAccess($lP) && $columnId !== 'unused') {
                    $content[$columnId] .= '
                    <div class="t3-page-ce t3js-page-ce" data-page="' . (int)$id . '" id="' . StringUtility::getUniqueId() . '">
                        <div class="t3js-page-new-ce t3-page-ce-wrapper-new-ce" id="colpos-' . $columnId . '-' . 'page-' . $id . '-' . StringUtility::getUniqueId() . '">'
                        . $link
                        . '</div>
                        <div class="t3-page-ce-dropzone-available t3js-page-ce-dropzone-available"></div>
                    </div>
                    ';
                }
                $editUidList = '';
                if (!isset($contentRecordsPerColumn[$columnId]) || !is_array($contentRecordsPerColumn[$columnId])) {
                    $message = GeneralUtility::makeInstance(
                        FlashMessage::class,
                        $this->getLanguageService()->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:error.invalidBackendLayout'),
                        '',
                        FlashMessage::WARNING
                    );
                    $service = GeneralUtility::makeInstance(FlashMessageService::class);
                    $queue = $service->getMessageQueueByIdentifier();
                    $queue->addMessage($message);
                } else {
                    $rowArr = $contentRecordsPerColumn[$columnId];
                    $this->generateTtContentDataArray($rowArr);

                    foreach ((array)$rowArr as $rKey => $row) {
                        $this->contentElementCache[$lP][$columnId][$row['uid']] = $row;
                        if ($this->tt_contentConfig['languageMode']) {
                            $languageColumn[$columnId][$lP] = $head[$columnId] . $content[$columnId];
                        }
                        if (is_array($row) && !VersionState::cast($row['t3ver_state'])->equals(VersionState::DELETE_PLACEHOLDER)) {
                            $singleElementHTML = '<div class="t3-page-ce-dragitem" id="' . StringUtility::getUniqueId() . '">';
                            if (!$lP && ($this->defLangBinding || $row['sys_language_uid'] != -1)) {
                                $defaultLanguageElementsByColumn[$columnId][] = ($row['_ORIG_uid'] ?? $row['uid']);
                            }
                            $editUidList .= $row['uid'] . ',';
                            $disableMoveAndNewButtons = $this->defLangBinding && $lP > 0 && $this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP);
                            $singleElementHTML .= $this->tt_content_drawHeader(
                                $row,
                                $this->tt_contentConfig['showInfo'] ? 15 : 5,
                                $disableMoveAndNewButtons,
                                true,
                                $this->getBackendUser()->doesUserHaveAccess($this->pageinfo, Permission::CONTENT_EDIT)
                            );
                            $innerContent = '<div ' . ($row['_ORIG_uid'] ? ' class="ver-element"' : '') . '>'
                                . $this->tt_content_drawItem($row) . '</div>';
                            $singleElementHTML .= '<div class="t3-page-ce-body-inner">' . $innerContent . '</div></div>'
                                . $this->tt_content_drawFooter($row);
                            $isDisabled = $this->isDisabled('tt_content', $row);
                            $statusHidden = $isDisabled ? ' t3-page-ce-hidden t3js-hidden-record' : '';
                            $displayNone = !$this->tt_contentConfig['showHidden'] && $isDisabled ? ' style="display: none;"' : '';
                            $highlightHeader = '';
                            if ($this->checkIfTranslationsExistInLanguage([], (int)$row['sys_language_uid']) && (int)$row['l18n_parent'] === 0) {
                                $highlightHeader = ' t3-page-ce-danger';
                            } elseif ($columnId === 'unused') {
                                $highlightHeader = ' t3-page-ce-warning';
                            }
                            $singleElementHTML = '<div class="t3-page-ce' . $highlightHeader . ' t3js-page-ce t3js-page-ce-sortable ' . $statusHidden . '" id="element-tt_content-'
                                . $row['uid'] . '" data-table="tt_content" data-uid="' . $row['uid'] . '"' . $displayNone . '>' . $singleElementHTML . '</div>';

                            $singleElementHTML .= '<div class="t3-page-ce" data-colpos="' . $columnId . '">';
                            $singleElementHTML .= '<div class="t3js-page-new-ce t3-page-ce-wrapper-new-ce" id="colpos-' . $columnId . '-' . 'page-' . $id .
                                '-' . StringUtility::getUniqueId() . '">';
                            // Add icon "new content element below"
                            if (!$disableMoveAndNewButtons
                                && $this->isContentEditable()
                                && $this->getBackendUser()->checkLanguageAccess($lP)
                                && (!$this->checkIfTranslationsExistInLanguage($contentRecordsPerColumn, $lP))
                                && $columnId !== 'unused'
                            ) {
                                // New content element:
                                if ($this->option_newWizard) {
                                    $urlParameters = [
                                        'id' => $row['pid'],
                                        'sys_language_uid' => $row['sys_language_uid'],
                                        'colPos' => $row['colPos'],
                                        'uid_pid' => -$row['uid'],
                                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                                    ];
                                    $routeName = BackendUtility::getPagesTSconfig($row['pid'])['mod.']['newContentElementWizard.']['override']
                                        ?? 'new_content_element_wizard';
                                    $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                                    $url = (string)$uriBuilder->buildUriFromRoute($routeName, $urlParameters);
                                } else {
                                    $urlParameters = [
                                        'edit' => [
                                            'tt_content' => [
                                                -$row['uid'] => 'new'
                                            ]
                                        ],
                                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                                    ];
                                    $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                                    $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                                }
                                $title = htmlspecialchars($this->getLanguageService()->getLL('newContentElement'));
                                $singleElementHTML .= '<a href="' . htmlspecialchars($url) . '" '
                                    . 'title="' . $title . '"'
                                    . 'data-title="' . $title . '"'
                                    . 'class="btn btn-default btn-sm ' . ($this->option_newWizard ? 't3js-toggle-new-content-element-wizard' : '') . '">'
                                    . $this->iconFactory->getIcon('actions-add', Icon::SIZE_SMALL)->render()
                                    . ' '
                                    . htmlspecialchars($this->getLanguageService()->getLL('content')) . '</a>';
                            }
                            $singleElementHTML .= '</div></div><div class="t3-page-ce-dropzone-available t3js-page-ce-dropzone-available"></div></div>';
                            if ($this->defLangBinding && $this->tt_contentConfig['languageMode']) {
                                $defLangBinding[$columnId][$lP][$row[$lP ? 'l18n_parent' : 'uid'] ?: $row['uid']] = $singleElementHTML;
                            } else {
                                $content[$columnId] .= $singleElementHTML;
                            }
                        } else {
                            unset($rowArr[$rKey]);
                        }
                    }
                    $content[$columnId] .= '</div>';
                    $colTitle = BackendUtility::getProcessedValue('tt_content', 'colPos', $columnId);
                    $tcaItems = GeneralUtility::callUserFunction(\TYPO3\CMS\Backend\View\BackendLayoutView::class . '->getColPosListItemsParsed', $id, $this);
                    foreach ($tcaItems as $item) {
                        if ($item[1] == $columnId) {
                            $colTitle = $this->getLanguageService()->sL($item[0]);
                        }
                    }
                    if ($columnId === 'unused') {
                        if (empty($unusedElementsMessage)) {
                            $unusedElementsMessage = GeneralUtility::makeInstance(
                                FlashMessage::class,
                                $this->getLanguageService()->getLL('staleUnusedElementsWarning'),
                                $this->getLanguageService()->getLL('staleUnusedElementsWarningTitle'),
                                FlashMessage::WARNING
                            );
                            $service = GeneralUtility::makeInstance(FlashMessageService::class);
                            $queue = $service->getMessageQueueByIdentifier();
                            $queue->addMessage($unusedElementsMessage);
                        }
                        $colTitle = $this->getLanguageService()->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:colPos.I.unused');
                        $editParam = '';
                    } else {
                        $editParam = $this->doEdit && !empty($rowArr)
                            ? '&edit[tt_content][' . $editUidList . ']=edit' . $pageTitleParamForAltDoc
                            : '';
                    }
                    $head[$columnId] .= $this->tt_content_drawColHeader($colTitle, $editParam);
                }
            }
            // For each column, fit the rendered content into a table cell:
            $out = '';
            if ($this->tt_contentConfig['languageMode']) {
                // in language mode process the content elements, but only fill $languageColumn. output will be generated later
                $sortedLanguageColumn = [];
                foreach ($cList as $columnId) {
                    if (GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnId) || $columnId === 'unused') {
                        $languageColumn[$columnId][$lP] = $head[$columnId] . $content[$columnId];

                        // We sort $languageColumn again according to $cList as it may contain data already from above.
                        $sortedLanguageColumn[$columnId] = $languageColumn[$columnId];
                    }
                }
                if (!empty($languageColumn['unused'])) {
                    $sortedLanguageColumn['unused'] = $languageColumn['unused'];
                }
                $languageColumn = $sortedLanguageColumn;
            } else {
                // GRID VIEW:
                $grid = '<div class="t3-grid-container"><table border="0" cellspacing="0" cellpadding="0" width="100%" class="t3-page-columns t3-grid-table t3js-page-columns">';
                // Add colgroups
                $colCount = (int)$backendLayout['__config']['backend_layout.']['colCount'];
                $rowCount = (int)$backendLayout['__config']['backend_layout.']['rowCount'];
                $grid .= '<colgroup>';
                for ($i = 0; $i < $colCount; $i++) {
                    $grid .= '<col />';
                }
                $grid .= '</colgroup>';

                // Check how to handle restricted columns
                $hideRestrictedCols = (bool)(BackendUtility::getPagesTSconfig($id)['mod.']['web_layout.']['hideRestrictedCols'] ?? false);

                // Cycle through rows
                for ($row = 1; $row <= $rowCount; $row++) {
                    $rowConfig = $backendLayout['__config']['backend_layout.']['rows.'][$row . '.'];
                    if (!isset($rowConfig)) {
                        continue;
                    }
                    $grid .= '<tr>';
                    for ($col = 1; $col <= $colCount; $col++) {
                        $columnConfig = $rowConfig['columns.'][$col . '.'];
                        if (!isset($columnConfig)) {
                            continue;
                        }
                        // Which tt_content colPos should be displayed inside this cell
                        $columnKey = (int)$columnConfig['colPos'];
                        // Render the grid cell
                        $colSpan = (int)$columnConfig['colspan'];
                        $rowSpan = (int)$columnConfig['rowspan'];
                        $grid .= '<td valign="top"' .
                            ($colSpan > 0 ? ' colspan="' . $colSpan . '"' : '') .
                            ($rowSpan > 0 ? ' rowspan="' . $rowSpan . '"' : '') .
                            ' data-colpos="' . (int)$columnConfig['colPos'] . '" data-language-uid="' . $lP . '" class="t3js-page-lang-column-' . $lP . ' t3js-page-column t3-grid-cell t3-page-column t3-page-column-' . $columnKey .
                            ((!isset($columnConfig['colPos']) || $columnConfig['colPos'] === '') ? ' t3-grid-cell-unassigned' : '') .
                            ((isset($columnConfig['colPos']) && $columnConfig['colPos'] !== '' && !$head[$columnKey]) || !GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos']) ? ($hideRestrictedCols ? ' t3-grid-cell-restricted t3-grid-cell-hidden' : ' t3-grid-cell-restricted') : '') .
                            ($colSpan > 0 ? ' t3-gridCell-width' . $colSpan : '') .
                            ($rowSpan > 0 ? ' t3-gridCell-height' . $rowSpan : '') . '">';

                        // Draw the pre-generated header with edit and new buttons if a colPos is assigned.
                        // If not, a new header without any buttons will be generated.
                        if (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== '' && $head[$columnKey]
                            && GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            $grid .= $head[$columnKey] . $content[$columnKey];
                        } elseif (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== ''
                            && GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            if (!$hideRestrictedCols) {
                                $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->getLL('noAccess'));
                            }
                        } elseif (isset($columnConfig['colPos']) && $columnConfig['colPos'] !== ''
                            && !GeneralUtility::inList($this->tt_contentConfig['activeCols'], $columnConfig['colPos'])
                        ) {
                            if (!$hideRestrictedCols) {
                                $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->sL($columnConfig['name']) .
                                    ' (' . $this->getLanguageService()->getLL('noAccess') . ')');
                            }
                        } elseif (isset($columnConfig['name']) && $columnConfig['name'] !== '') {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->sL($columnConfig['name'])
                                . ' (' . $this->getLanguageService()->getLL('notAssigned') . ')');
                        } else {
                            $grid .= $this->tt_content_drawColHeader($this->getLanguageService()->getLL('notAssigned'));
                        }

                        $grid .= $this->injectEmptyColPosHook($columnConfig);

                        $grid .= '</td>';
                    }
                    $grid .= '</tr>';
                }
                if (!empty($content['unused'])) {
                    $grid .= '<tr>';
                    // Which tt_content colPos should be displayed inside this cell
                    $columnKey = 'unused';
                    // Render the grid cell
                    $colSpan = (int)$backendLayout['__config']['backend_layout.']['colCount'];
                    $grid .= '<td valign="top"' .
                        ($colSpan > 0 ? ' colspan="' . $colSpan . '"' : '') .
                        ($rowSpan > 0 ? ' rowspan="' . $rowSpan . '"' : '') .
                        ' data-colpos="unused" data-language-uid="' . $lP . '" class="t3js-page-lang-column-' . $lP . ' t3js-page-column t3-grid-cell t3-page-column t3-page-column-' . $columnKey .
                        ($colSpan > 0 ? ' t3-gridCell-width' . $colSpan : '') . '">';

                    // Draw the pre-generated header with edit and new buttons if a colPos is assigned.
                    // If not, a new header without any buttons will be generated.
                    $grid .= $head[$columnKey] . $content[$columnKey];
                    $grid .= '</td></tr>';
                }
                $out .= $grid . '</table></div>';
            }
        }
        $elFromTable = $this->clipboard->elFromTable('tt_content');
        if (!empty($elFromTable) && $this->isPageEditable()) {
            $pasteItem = substr(key($elFromTable), 11);
            $pasteRecord = BackendUtility::getRecord('tt_content', (int)$pasteItem);
            $pasteTitle = $pasteRecord['header'] ? $pasteRecord['header'] : $pasteItem;
            $copyMode = $this->clipboard->clipData['normal']['mode'] ? '-' . $this->clipboard->clipData['normal']['mode'] : '';
            $addExtOnReadyCode = '
                     top.pasteIntoLinkTemplate = '
                . $this->tt_content_drawPasteIcon($pasteItem, $pasteTitle, $copyMode, 't3js-paste-into', 'pasteIntoColumn')
                . ';
                    top.pasteAfterLinkTemplate = '
                . $this->tt_content_drawPasteIcon($pasteItem, $pasteTitle, $copyMode, 't3js-paste-after', 'pasteAfterRecord')
                . ';';
        } else {
            $addExtOnReadyCode = '
                top.pasteIntoLinkTemplate = \'\';
                top.pasteAfterLinkTemplate = \'\';';
        }
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addJsInlineCode('pasteLinkTemplates', $addExtOnReadyCode);
        // If language mode, then make another presentation:
        // Notice that THIS presentation will override the value of $out!
        // But it needs the code above to execute since $languageColumn is filled with content we need!
        if ($this->tt_contentConfig['languageMode']) {
            // Get language selector:
            $languageSelector = $this->languageSelector($id);
            // Reset out - we will make new content here:
            $out = '';
            // Traverse languages found on the page and build up the table displaying them side by side:
            $cCont = [];
            $sCont = [];
            foreach ($langListArr as $lP) {
                $languageMode = '';
                $labelClass = 'info';
                // Header:
                $lP = (int)$lP;
                // Determine language mode
                if ($lP > 0 && isset($this->languageHasTranslationsCache[$lP]['mode'])) {
                    switch ($this->languageHasTranslationsCache[$lP]['mode']) {
                        case 'mixed':
                            $languageMode = $this->getLanguageService()->getLL('languageModeMixed');
                            $labelClass = 'danger';
                            break;
                        case 'connected':
                            $languageMode = $this->getLanguageService()->getLL('languageModeConnected');
                            break;
                        case 'free':
                            $languageMode = $this->getLanguageService()->getLL('languageModeFree');
                            break;
                        default:
                            // we'll let opcode optimize this intentionally empty case
                    }
                }
                $cCont[$lP] = '
					<td valign="top" class="t3-page-column t3-page-column-lang-name" data-language-uid="' . $lP . '">
						<h2>' . htmlspecialchars($this->tt_contentConfig['languageCols'][$lP]) . '</h2>
						' . ($languageMode !== '' ? '<span class="label label-' . $labelClass . '">' . $languageMode . '</span>' : '') . '
					</td>';

                // "View page" icon is added:
                $viewLink = '';
                if (!VersionState::cast($this->getPageLayoutController()->pageinfo['t3ver_state'])->equals(VersionState::DELETE_PLACEHOLDER)) {
                    $onClick = BackendUtility::viewOnClick(
                        $this->id,
                        '',
                        BackendUtility::BEgetRootLine($this->id),
                        '',
                        '',
                        '&L=' . $lP
                    );
                    $viewLink = '<a href="#" class="btn btn-default btn-sm" onclick="' . htmlspecialchars($onClick) . '" title="' . htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage')) . '">' . $this->iconFactory->getIcon('actions-view', Icon::SIZE_SMALL)->render() . '</a>';
                }
                // Language overlay page header:
                if ($lP) {
                    $pageLocalizationRecord = BackendUtility::getRecordLocalization('pages', $id, $lP);
                    if (is_array($pageLocalizationRecord)) {
                        $pageLocalizationRecord = reset($pageLocalizationRecord);
                    }
                    BackendUtility::workspaceOL('pages', $pageLocalizationRecord);
                    $recordIcon = BackendUtility::wrapClickMenuOnIcon(
                        $this->iconFactory->getIconForRecord('pages', $pageLocalizationRecord, Icon::SIZE_SMALL)->render(),
                        'pages',
                        $pageLocalizationRecord['uid']
                    );
                    $urlParameters = [
                        'edit' => [
                            'pages' => [
                                $pageLocalizationRecord['uid'] => 'edit'
                            ]
                        ],
                        // Disallow manual adjustment of the language field for pages
                        'overrideVals' => [
                            'pages' => [
                                'sys_language_uid' => $lP
                            ]
                        ],
                        'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                    ];
                    $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                    $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                    $editLink = (
                    $this->getBackendUser()->check('tables_modify', 'pages')
                        ? '<a href="' . htmlspecialchars($url) . '" class="btn btn-default btn-sm"'
                        . ' title="' . htmlspecialchars($this->getLanguageService()->getLL('edit')) . '">'
                        . $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render() . '</a>'
                        : ''
                    );

                    $defaultLanguageElements = [];
                    array_walk($defaultLanguageElementsByColumn, function (array $columnContent) use (&$defaultLanguageElements) {
                        $defaultLanguageElements = array_merge($defaultLanguageElements, $columnContent);
                    });

                    $localizationButtons = [];
                    $localizationButtons[] = $this->newLanguageButton(
                        $this->getNonTranslatedTTcontentUids($defaultLanguageElements, $id, $lP),
                        $lP
                    );

                    $lPLabel =
                        '<div class="btn-group">'
                        . $viewLink
                        . $editLink
                        . (!empty($localizationButtons) ? implode(LF, $localizationButtons) : '')
                        . '</div>'
                        . ' ' . $recordIcon . ' ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($pageLocalizationRecord['title'], 20))
                    ;
                } else {
                    $editLink = '';
                    $recordIcon = '';
                    if ($this->getBackendUser()->checkLanguageAccess(0)) {
                        $recordIcon = BackendUtility::wrapClickMenuOnIcon(
                            $this->iconFactory->getIconForRecord('pages', $this->pageRecord, Icon::SIZE_SMALL)->render(),
                            'pages',
                            $this->id
                        );
                        $urlParameters = [
                            'edit' => [
                                'pages' => [
                                    $this->id => 'edit'
                                ]
                            ],
                            'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI')
                        ];
                        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
                        $url = (string)$uriBuilder->buildUriFromRoute('record_edit', $urlParameters);
                        $editLink = (
                        $this->getBackendUser()->check('tables_modify', 'pages')
                            ? '<a href="' . htmlspecialchars($url) . '" class="btn btn-default btn-sm"'
                            . ' title="' . htmlspecialchars($this->getLanguageService()->getLL('edit')) . '">'
                            . $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render() . '</a>'
                            : ''
                        );
                    }

                    $lPLabel =
                        '<div class="btn-group">'
                        . $viewLink
                        . $editLink
                        . '</div>'
                        . ' ' . $recordIcon . ' ' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($this->pageRecord['title'], 20));
                }
                $sCont[$lP] = '
					<td class="t3-page-column t3-page-lang-label nowrap">' . $lPLabel . '</td>';
            }
            // Add headers:
            $out .= '<tr>' . implode($cCont) . '</tr>';
            $out .= '<tr>' . implode($sCont) . '</tr>';
            unset($cCont, $sCont);

            // Traverse previously built content for the columns:
            foreach ($languageColumn as $cKey => $cCont) {
                $out .= '<tr>';
                foreach ($cCont as $languageId => $columnContent) {
                    $out .= '<td valign="top" data-colpos="' . $cKey . '" class="t3-grid-cell t3-page-column t3js-page-column t3js-page-lang-column t3js-page-lang-column-' . $languageId . '">' . $columnContent . '</td>';
                }
                $out .= '</tr>';
                if ($this->defLangBinding && !empty($defLangBinding[$cKey])) {
                    $maxItemsCount = max(array_map('count', $defLangBinding[$cKey]));
                    for ($i = 0; $i < $maxItemsCount; $i++) {
                        $defUid = $defaultLanguageElementsByColumn[$cKey][$i] ?? 0;
                        $cCont = [];
                        foreach ($langListArr as $lP) {
                            if ($lP > 0
                                && is_array($defLangBinding[$cKey][$lP])
                                && !$this->checkIfTranslationsExistInLanguage($defaultLanguageElementsByColumn[$cKey], $lP)
                                && count($defLangBinding[$cKey][$lP]) > $i
                            ) {
                                $slice = array_slice($defLangBinding[$cKey][$lP], $i, 1);
                                $element = $slice[0] ?? '';
                            } else {
                                $element = $defLangBinding[$cKey][$lP][$defUid] ?? '';
                            }
                            $cCont[] = $element;
                        }
                        $out .= '
                        <tr>
							<td valign="top" class="t3-grid-cell">' . implode('</td>' . '
							<td valign="top" class="t3-grid-cell">', $cCont) . '</td>
						</tr>';
                    }
                }
            }
            // Finally, wrap it all in a table and add the language selector on top of it:
            $out = $languageSelector . '
                <div class="t3-grid-container">
                    <table cellpadding="0" cellspacing="0" class="t3-page-columns t3-grid-table t3js-page-columns">
						' . $out . '
                    </table>
				</div>';
        }

        return $out;
    }

    /**
     * custom hook for adding infos to the content column
     * e.g. for columns without a colpos to display infos there
     *
     * @param array $columnConfig
     * @return string
     */
    private function injectEmptyColPosHook(array $columnConfig): string {
        $out = '';
        $drawColPosHook = &$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['drawColPos'];
        if (\is_array($drawColPosHook)) {
            $_params = [
                'columnConfig' => $columnConfig,
                'uid' => $this->id,
            ];

            foreach ($drawColPosHook as $_funcRef) {
                $out .= GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }

        return $out;
    }
}
