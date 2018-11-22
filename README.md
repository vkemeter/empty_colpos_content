# Empty Colpos Content

Whats that?

If You have BackendLayouts with a Column without any Colpos, 
and You want to display information in that Column, simply add the newly added Hook.

Example ext_localconf.php

```
 $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['drawColPos'][1542873608]
 	= \YourNameSpace\YourExtension\Hooks\Backend\PageLayoutView::class .'->render';
```

Use the shipped Hook as an Example and change it for Your needs.

If no own Hook is used, with installation of this Extension, an Example Hook is implemented.

Use Extension Configuration to make this visible to all or just admin Users.

KTHXBYE! Volker.