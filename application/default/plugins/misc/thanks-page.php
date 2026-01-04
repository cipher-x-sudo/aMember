<?php

/**
 * allows to add some custom html content to default thank you
 * page based on purchased product
 * @am_plugin_api 6.0
 */
class Am_Plugin_ThanksPage extends Am_Plugin
{
    protected $_configPrefix = 'misc.';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle(___('Thanks Page'));
        $form->addTextarea('thanks_page', array('class' => 'am-el-wide', 'rows'=>12))
            ->setLabel(___("After Purchase Display this content on thank you page\n" .
                'You can use %invoice.% and %user.% variables in url eg: %user.login%, %user.email%, %invoice.public_id% etc.'));
    }

    function onGridProductInitForm(Am_Event_Grid $e)
    {
        $e->getGrid()->getForm()->getAdditionalFieldSet()->addHtmlEditor('_thanks_page')
            ->setLabel(___("After Purchase Display this content on thank you page\n" .
               'You can use %invoice.% and %user.% variables in url eg: %user.login%, %user.email%, %invoice.public_id% etc.'));
    }

    function onGridProductValuesFromForm(Am_Event_Grid $e)
    {
        $args = $e->getArgs();
        $product = $args[1];
        $product->data()->set('thanks_page', null);
        $product->data()->setBlob('thanks_page', @$args[0]['_thanks_page']);
    }

    function onGridProductValuesToForm(Am_Event_Grid $e)
    {
        $args = $e->getArgs();
        $product = $args[1];
        $args[0]['_thanks_page'] = $this->getContent($product);
    }

    function getContent(Am_Record $product)
    {
        return $product->data()->get('thanks_page') == Am_DataFieldStorage::BLOB_VALUE ?
            $product->data()->getBlob('thanks_page') :
            $product->data()->get('thanks_page');
    }

    function onThanksPage(Am_Event $e)
    {
        if (!$e->getInvoice()) return;

        $content = array();
        foreach ($e->getInvoice()->getProducts() as $pr) {
            if ($c = $this->getContent($pr)) {
                $content[] = sprintf('<div>%s</div>', $c);
            }
        }
        if ($_ = $this->getConfig('thanks_page')) {
            $content[] = $_;
        }

        if (!$content) return;
        $content = implode("\n", $content);

        $invoice = $e->getInvoice();
        $t = new Am_SimpleTemplate();
        $t->assign('invoice', $invoice);
        foreach ($invoice->getPaymentRecords() as $p) {
            $t->assign('payment', $p);
        }
        $t->assign('user', $e->getInvoice()->getUser());
        $content = $t->render($content);

        $this->getDi()->blocks->add('thanks/success', new Am_Block_Base(___('Thanks Success'), 'thanks-page-content', $this,
                function(Am_View $view) use ($content) {
                    return $content;
                }));
    }
}