<?php
defined('TYPO3_MODE') || die('Access denied.');

call_user_func(
    function () {
        // xclass for adding custom hook into pagelayout view
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Backend\View\PageLayoutView::class] = [
            'className' => \Supseven\EmptyColposContent\Xclass\PageLayoutView::class
        ];

        // the new hook defined in the xclass a few lines before
        // add an own hook in your theme with your needs
        // add this hook only as example, if no other hook is already registered
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['drawColPos'])) {
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['drawColPos'][1542873608]
                = \Supseven\EmptyColposContent\Hooks\Backend\PageLayoutView::class .'->render';
        }
    }
);