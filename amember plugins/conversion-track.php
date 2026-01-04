<?php

/**
 * @am_plugin_api 6.0
*/
class Am_Plugin_ConversionTrack extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '6.3.31';
    const TRACKED_DATA_KEY = 'conversion-track-done';

    protected $_configPrefix = 'misc.';

    protected $sale_code;
    protected $visit_code;
    protected $done = false;

    public function isConfigured()
    {
        return $this->getConfig('sale') ||
            $this->getConfig('sale_first') ||
            $this->getConfig('free') ||
            $this->getConfig('signup') ||
            $this->getConfig('tracking') ||
            $this->getConfig('header') ||
            $this->getConfig('footer');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addTextarea('sale', 'rows=10 class=am-el-wide')
             ->setLabel("Sale Tracking Code\n" .
                   "The following variables will be automatically replaced:<br>"
                 . "%payment.tax%<br>"
                 . "%payment.amount%<br>"
                 . "%payment.dattm%<br>"
                 . "%payment.discount%<br>"
                 . "%invoice.public_id%<br>"
                 . "%user.email%<br>"
                 . "%afflogin%<br>"
                 . "%affkeyword%<br>"
                 . "you may also use %foreach_product% and %endforeach_product% tags<br>"
                 . "to run cycle for all items in invoice, you may use %item.item_title% %item.qty%"
                 . " %item.first_total% %item.item_id% and similar variables inside"
        );
        $form->addTextarea('sale_first', 'rows=10 class=am-el-wide')
             ->setLabel("First Sale Tracking Code (optional)\n" .
                   "The following variables will be automatically replaced:<br>"
                 . "%payment.tax%<br>"
                 . "%payment.amount%<br>"
                 . "%payment.dattm%<br>"
                 . "%payment.discount%<br>"
                 . "%invoice.public_id%<br>"
                 . "%user.email%<br>"
                 . "%afflogin%<br>"
                 . "%affkeyword%<br>"
                 . "you may also use %foreach_product% and %endforeach_product% tags<br>"
                 . "to run cycle for all items in invoice, you may use %item.item_title% %item.qty%"
                 . " %item.first_total% %item.item_id% and similar variables inside"
        );
        $form->addTextarea('free', 'rows=10 class=am-el-wide')
            ->setLabel("Free Signup (optional)\n" .
                "will be displayed once customer finish "
                . "signup for free products (without a payment).<br>"
                . "The following variables will be automatically replaced:<br>"
                . "%invoice.public_id%<br>"
                . "%user.email%<br>"
                . "%afflogin%<br>"
                . "%affkeyword%<br>"
                . "you may also use %foreach_product% and %endforeach_product% tags<br>"
                . "to run cycle for all items in invoice, you may use %item.item_title% %item.qty%"
                . " %item.first_total% %item.item_id% and similar variables inside");

        $form->addTextarea('signup', 'rows=10 class=am-el-wide')
            ->setLabel("Signup Tracking Code\n" .
                "will be included once customer visits signup page "
                . "(note - each time when visits, not when signup form is submitted!)");

        $form->addTextarea('tracking', 'rows=10 class=am-el-wide')
            ->setLabel("Tracking Code (optional)\n" .
                "will be used each time when customer visits an aMember page");

        $form->addTextarea('header', 'rows=10 class=am-el-wide')
            ->setLabel("Header (optional)\n" .
                "if specified, this code will be included once before other tracking codes");

        $form->addTextarea('footer', 'rows=10 class=am-el-wide')
            ->setLabel("Footer (optional)\n" .
                "if specified, this code will be included once after other tracking codes");

        $form->addHidden('check')->setValue('check');

        $form->addSaveCallbacks(function(Am_Config $before, Am_Config $after) {
            if ($after->get("misc.{$this->getId()}.check") == base64_encode('check')) {
                foreach ($after->get("misc.{$this->getId()}") as $token => $val) {
                    $after->set("misc.{$this->getId()}.{$token}", base64_decode($val));
                }
            }
        }, null);

        $form->addScript()
            ->setScript(<<<CUT
jQuery('#setup_form_conversion-track').submit(function(){
    jQuery('#setup_form_conversion-track [name^=misc___conversion-track___]').not('[name=misc___conversion-track____csrf]').each(function(){
        jQuery(this).val(btoa(jQuery(this).val()));
    });
});
jQuery(function(){
    if (jQuery('#setup_form_conversion-track [name$=__check]').val() == btoa('check')) {
        jQuery('#setup_form_conversion-track [name^=misc___conversion-track___]').not('[name=misc___conversion-track____csrf]').each(function(){
            jQuery(this).val(atob(jQuery(this).val()));
        });
    }
})
CUT
);
    }

    function onAfterRender(Am_Event_AfterRender $event)
    {
        if ($this->done) return;
        if (preg_match('/thanks\.phtml$/', $event->getTemplateName()) && $event->getView()->invoice) {
            $payment = $event->getView()->payment;
            $invoice = $event->getView()->invoice;
            if (!$payment || (double)$invoice->first_total == 0) {
                $this->done += $event->replace("|</body>|i",
                          $this->getHeader()
                        . $this->getFreeCode($event->getView()->invoice)
                        . $this->getTrackingCode()
                        . $this->getFooter()
                        . "</body>", 1);
            } else {
                if ($payment->data()->get(self::TRACKED_DATA_KEY))
                    return;
                $this->done += $event->replace("|</body>|i",
                          $this->getHeader()
                        . $this->getSaleCode($event->getView()->invoice, $payment)
                        . $this->getTrackingCode()
                        . $this->getFooter()
                        . "</body>", 1);
            }
        } elseif (preg_match('/signup\/signup.*\.phtml$/', $event->getTemplateName())) {
            $this->done += $event->replace("|</body>|i",
                      $this->getHeader()
                    . $this->getTrackingCode()
                    . $this->getSignupCode()
                    . $this->getFooter()
                    . "</body>", 1);
        } else if (preg_match('/main\.phtml$/', $event->getTemplateName())) {
            if ($user_id = $this->getDi()->auth->getUserId()) {
                $payments = $this->getDi()->invoicePaymentTable->findBy([
                    'user_id' => $user_id,
                    ['dattm', '>', sqlTime('-5 days')]
                ]);
                foreach ($payments as $payment) {
                    if ($payment->data()->get(self::TRACKED_DATA_KEY)) {
                        continue;
                    }

                    $this->done += $event->replace("|</body>|i",
                        $this->getHeader()
                        . $this->getSaleCode($payment->getInvoice(), $payment)
                        . $this->getTrackingCode()
                        . $this->getFooter()
                        . "</body>", 1);
                    break;
                }
            }
        } else {
            if (!$this->done && !(defined('AM_ADMIN') && AM_ADMIN) && !$this->getDi()->config->get("google_analytics_only_sales_code")) {
                $this->done += $event->replace("|</body>|i",
                    $this->getHeader()
                    . $this->getTrackingCode()
                    . $this->getFooter()
                    . "</body>", 1);
            }
        }
    }

    function getTrackingCode()
    {
        return $this->getConfig('tracking');
    }

    function getSignupCode()
    {
        return $this->getConfig('signup');
    }

    function getHeader()
    {
        return $this->getConfig('header');
    }

    function getFooter()
    {
        return $this->getConfig('footer');
    }

    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        $tpl = $this->getConfig('sale');
        if (1 == $this->getDi()->invoicePaymentTable->countByUserId($payment->user_id) &&
            ($tpl_first = $this->getConfig('sale_first'))) {
             $tpl = $tpl_first;
        }

        $t = new Am_SimpleTemplate();
        $t->assign('invoice', $invoice);
        $t->assign('payment', $payment);
        $t->assign('user', $invoice->getUser());
        $t->assign('item', $invoice->getItem(0));
        $t->assign('afflogin',  $this->getAffLogin());
        $t->assign('affkeyword', $this->getAffKeyword());

        $this->_invoice = $invoice;
        $this->_payment = $payment;
        $this->_t = $t;
        $tpl = preg_replace_callback('|%foreach_product%(.+?)%endforeach_product%|s', [$this, '_rpl'], $tpl);
        if ($payment)
            $payment->data()->set(self::TRACKED_DATA_KEY, 1)->update();
        return $t->render($tpl);
    }

    function getFreeCode(Invoice $invoice)
    {
        $tpl = $this->getConfig('free');
        $t = new Am_SimpleTemplate();
        $t->assign('invoice', $invoice);
        $t->assign('user', $invoice->getUser());
        $t->assign('item', $invoice->getItem(0));
        $t->assign('afflogin',  $this->getAffLogin());
        $t->assign('affkeyword', $this->getAffKeyword());
        $this->_invoice = $invoice;
        $this->_payment = null;
        $this->_t = $t;
        $tpl = preg_replace_callback('|%foreach_product%(.+?)%endforeach_product%|s', [$this, '_rpl'], $tpl);
        return $t->render($tpl);
    }

    function getAffLogin()
    {
        if (($module = $this->getDi()->modules->loadGet('aff', false)) &&
            ($affId = $module->findAffId()) &&
            ($aff = $this->getDi()->userTable->load($affId, false)) &&
            ($aff->is_affiliate)) {

            return $aff->login;
        }
    }

    function getAffKeyword()
    {
        if (($module = $this->getDi()->modules->loadGet('aff', false)) &&
            ($affId = $module->findAffId()) &&
            ($affKeywordId = $module->findKeywordId($affId)) &&
            ($keyword = $this->getDi()->affKeywordTable->load($affKeywordId, false))) {

            return $keyword->value;
        }
    }

    function _rpl($match)
    {
        $out = "";
        $invoice = $this->_invoice;
        $payment = $this->_payment;
        $t = $this->_t;
        $prefix = ($payment && !$payment->isFirst()) ? 'second_' : 'first_';
        /* @var $invoice Invoice */
        foreach ($invoice->getItems() as $item) {
            $item = $item->toArray();

            foreach (['price', 'discount', 'tax', 'total', 'shipping'] as $token) {
                $item[$token] = $item["{$prefix}{$token}"];
            }

            $t->assign('item', $item);
            $out .= $t->render($match[1]) . "\n";
        }
        return $out;
    }
}