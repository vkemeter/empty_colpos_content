<?php declare(strict_types = 1);

namespace Supseven\EmptyColposContent\Hooks\Backend;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class PageLayoutView
 *
 * Use this Hook as an Example. Move it to Your Extension and modify if by Your needs.
 * Simply register it via ext_localconf.php
 *
 * Example:
 * $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['drawColPos'][1542873608]
 *      = \YourNameSpace\YourExtension\Hooks\Backend\PageLayoutView::class .'->render';
 *
 * @package Supseven\EmptyColposContent\Hooks\Backend
 */
class PageLayoutView implements SingletonInterface
{
    /**
     * @var PageRenderer
     */
    protected $pageRenderer;

    /**
     * Initialize the page renderer
     */
    public function __construct()
    {
        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
    }

    /**
     * @param $params
     * @return string
     */
    public function render($params): string
    {
        if (!isset($params['columnConfig']['colPos'])) {
            /** @var StandaloneView $view */
            $view = GeneralUtility::makeInstance(StandaloneView::class);
            $view->setTemplatePathAndFilename('EXT:empty_colpos_content/Resources/Private/Backend/Templates/EmptyColposContent.html');

            $view->assignMultiple([
                'headline' => 'Example Headline',
                'text' => 'Hac phasellus eget sapien massa aenean nec euismod justo, dui nam aliquam sociosqu tortor class mi odio id, sit leo quam venenatis facilisi purus auctor. Ullamcorper egestas et ipsum maximus duis, hac sit eu elementum vivamus, mus risus lobortis nunc. Donec condimentum est malesuada quis hac penatibus mi aptent fringilla, quam vitae himenaeos scelerisque fermentum lobortis dictumst hendrerit, sagittis amet sem velit dignissim nec imperdiet ligula. Lacus auctor libero habitasse ante placerat tincidunt mi, congue luctus platea cras dignissim inceptos non pharetra, fringilla mollis id augue elementum pulvinar. Scelerisque praesent sit malesuada volutpat aliquam netus libero ex arcu urna torquent amet, sapien proin magna faucibus eget dapibus nisl ad pretium sed nascetur diam, luctus finibus laoreet quis class accumsan ac neque phasellus lacinia cursus. Eu maecenas blandit efficitur interdum suscipit urna scelerisque leo hac, pellentesque bibendum venenatis vestibulum fringilla porta parturient rhoncus convallis donec, porttitor dapibus duis sem facilisis augue habitasse montes. Tincidunt velit fames turpis montes porta dictum dolor, viverra dui justo ipsum cursus habitasse vehicula, laoreet a feugiat fermentum ullamcorper tristique. Amet varius ex dignissim metus potenti nam nunc consequat, vulputate eget pharetra neque placerat pulvinar quam magna, inceptos dolor mattis sagittis vel eu etiam.',
                'footer' => 'Example Footer'
            ]);

            return $view->render();
        }

        return '';
    }
}
