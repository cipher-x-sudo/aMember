<?php
/**
 * @title Cookie Notice
 * @desc allows you to inform visitors that your site uses cookies and to comply with the EU cookie law GDPR regulations
 */

class Am_Plugin_CookieNotice extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '6.3.31';

    const DEFAULT_MSG = 'This site uses cookies to help personalise content and to keep you logged in if you register.
By continuing to use this site, you are consenting to our use of cookies';
    const DEFAULT_BG_COLOR = '#4149f2';
    const DEFAULT_FG_COLOR = '#ffffff';

    protected $_configPrefix = 'misc.';

    protected $done = false;

    function getTitle()
    {
        return 'Cookie Notice';
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addProlog(<<<CUT
<style>
<!--
    .am-row:hover .color-pick {
        opacity: 1;
    }
    .color-pick {
        opacity: 0;
        display: inline-block;
        vertical-align: middle;
        cursor: pointer;
        width: 1em;
        height: 1em;
        border-radius: 50%;
        transition: transform .3s, opacity .3s;
    }
    .color-pick:hover {
        transform: scale(1.8);
    }
-->
</style>
CUT
        );

        $form->addTextarea('msg', ['class' => 'am-el-wide'])
            ->setLabel('Message')
            ->default = self::DEFAULT_MSG;

        $this->addElementColor($form, 'fg_color', ___('Text Color'));

        $this->addElementColor($form, 'bg_color', ___('Background Color'));

        $form->addScript()
            ->setScript(<<<CUT
jQuery(document).on('click', '.color-pick', function(){
    $(this).closest('.am-row').find('input').val($(this).data('color')).change();
});
jQuery(function(){
    function hexToRgb(hex) {
       var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
       return result ? {
           r: parseInt(result[1], 16),
           g: parseInt(result[2], 16),
           b: parseInt(result[3], 16)
       } : null;
    }

    $('.color-input').change(function(){
        var tColor = 'inherit';

        if ((c = hexToRgb($(this).val())) &&
            (1 - (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255 > 0.5)) {
            tColor = '#fff';
        }
        $(this).css({background: $(this).val(), color: tColor, border: 'none'});
    }).change();
});
CUT
            );

        $form->addAdvRadio('position')
            ->setLabel('Position')
            ->loadOptions([
                'top' => 'Top',
                'bottom' => 'Bottom',
            ]);

        $form->setDefault('fg_color', self::DEFAULT_FG_COLOR);
        $form->setDefault('bg_color', self::DEFAULT_BG_COLOR);
        $form->setDefault('position', 'top');
    }

    protected function addElementColor($form, $name, $title)
    {
        $gr = $form->addGroup()
            ->setLabel($title);
        $gr->setSeparator(' ');

        $attr = ['size' => 7, 'placeholder' => '#4149F2', 'class' => 'color-input'];

        $gr->addText($name, $attr);

        foreach ([
                     '#f1f5f9', '#dee7ec', '#cccccc', '#ffebcd', '#ff8a80',
                     '#ea80fc', '#d1c4e9', '#e3f2fd', '#bbdefb', '#0079d1', '#b2dfdb',
                     '#e6ee9c', '#c8e6c9', '#4caf50', '#bcaaa4', '#212121', '#263238'
                 ] as $color) {
            $gr->addHtml()
                ->setHtml("<div class='color-pick' style='background:{$color}' data-color='$color'></div>");
        }
    }

    function directAction($request, $response, $invokeArgs)
    {
        Am_Cookie::set($this->getId(), 1, strtotime('+1year'));
    }

    function onBeforeRender(Am_Event $e)
    {
        if (
            empty($_COOKIE[$this->getId()])
            && !$this->done
            && !defined('AM_ADMIN')
        ) {
            $this->done = true;
            $url = json_encode($this->getDi()->url("misc/{$this->getId()}", false));
            $msg = $this->getConfig('msg', self::DEFAULT_MSG);
            $bg_color = $this->getConfig('bg_color', self::DEFAULT_BG_COLOR);
            $fg_color = $this->getConfig('fg_color', self::DEFAULT_FG_COLOR);
            $css = $this->getConfig('position', 'top') == 'top' ? 'top: 0; bottom: unset;' : 'top: unset; bottom: 0;';

            $l_accept = Am_Html::escape(___('Accept'));

            $this->getDi()->view->placeholder('body-finish')
                ->append(<<<CUT
<style>
<!--
    .am-cookie-notice {
        background: $bg_color;
        $css
    }
    .am-cookie-notice .am-cookie-notice__msg {
        color: $fg_color;
    }
    .am-cookie-notice .am-cookie-notice__link:hover, .am-cookie-notice .am-cookie-notice__link {
        color: $fg_color;
        border-color: $fg_color;
    }
    .am-cookie-notice .am-cookie-notice__link:hover {
        background: {$fg_color}55;
    }
-->
</style>
<div class="am-cookie-notice am-common"><div class="am-cookie-notice__msg">{$msg}</div><div><a href="javascript:;" onclick='jQuery.get({$url}, function(){jQuery(".am-cookie-notice").fadeOut(600);})' class="am-cookie-notice__link local-link">$l_accept</a></div></div>
CUT
);
        }
    }
}