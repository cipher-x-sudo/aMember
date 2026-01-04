<?php
/**
 * @table paysystems
 * @id kuickpay
 * @title Kuickpay
 * @decsription Pay from your bank's. Mobile banking, Internet banking Or ATM
 * @visible_link https://www.kuickpay.com/
 * @recurring none
 * @logo_url kuickpay.png
 * @country PK
 * @am_payment_api 6.0
 */


class Am_Paysystem_Kuickpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_DEV;
    const PLUGIN_REVISION = '6.3.31';

    protected $defaultTitle = 'Kuickpay';
    protected $defaultDescription = "Pay from your bank's. Mobile banking, Internet banking Or ATM";

    const CONSUMER_NUMBER = 'kuickpay-consumer-number';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('prefix')->setLabel('Kuickpay Prefix
        assigned by Kuickpay to Institution');

        $form->addText('username')->setLabel('WebService username
        To secure the Web Service access ');

        $form->addSecretText('password')->setLabel('WebService Password
        To secure the Web Service access
        ');

        $form->addHtmlEditor("html")->setLabel(
            ___("Payment Instructions for customer\n" .
                "you can enter any HTML here, it will be displayed to " .
                "customer when he chooses to pay using this payment system " .
                "you can use the following tags: " .
                "%s - Receipt HTML, " .
                "%s - Invoice Title, " .
                "%s - Invoice Id, " .
                "%s - Invoice Total, " .
                "%s - Consumer Number/Voucher Code", '%receipt_html%', '%invoice_title%', '%invoice.public_id%',
                '%invoice.first_total%', '%consumer_number%'))
            ->setMceOptions([
                'placeholder_items' => [
                    ['Receipt HTML', '%receipt_html%'],
                    ['Invoice Title', '%invoice_title%'],
                    ['Invoice Id', '%invoice.public_id%'],
                    ['Invoice Total', '%invoice.first_total%'],
                    ['Consumer Number/Voucher Code', '%consumer_number%']
                ]
            ]);
        $label = Am_Html::escape(___('preview'));
        $url = Am_Html::escape($this->getPluginUrl('preview'));
        $text = ___('Please save your settings before use preview link');
        $form->addHtml()
            ->setHtml(<<<CUT
<a href="$url" class="link">$label</a> $text
CUT
            );
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList()); // support any
    }


    function getConsumerNumber(Invoice $invoice): string
    {
        $consumerNumber = $invoice->data()->get(self::CONSUMER_NUMBER);
        if (!empty($consumerNumber)) {
            return $consumerNumber;
        }
        $number = $this->getConfig('prefix') . $this->getDi()->security->randomString(13, '0123456789');
        $invoice->data()->set(self::CONSUMER_NUMBER, $number)->update();
        return $number;
    }

    function getInvoiceByConsumerNumber(string $number): ?Invoice
    {
        return ($invoice = $this->getDi()->invoiceTable->findFirstByData(self::CONSUMER_NUMBER, $number)) ? $invoice: null;
    }

    function init()
    {
        $this->getDi()->router->addRoute('kuickpay-inquiry', new Am_Mvc_Router_Route(
            'payment/kuickpay/inquiry/:consumer',
            [
                'module' => 'default',
                'controller' => 'direct',
                'action' => 'index',
                'type' => 'payment',
                'plugin_id' => 'kuickpay',
                'action' => 'inquiry'
            ]
        ));
        $this->getDi()->router->addRoute('kuickpay-bill', new Am_Mvc_Router_Route(
            'payment/kuickpay/bill/:consumer',
            [
                'module' => 'default',
                'controller' => 'direct',
                'action' => 'index',
                'type' => 'payment',
                'plugin_id' => 'kuickpay',
                'action' => 'bill'
            ]
        ));


    }

    public function _process($invoice, $request, $result)
    {
        if ($this->getDi()->modules->isEnabled('cart')) {
            $this->getDi()->modules->loadGet('cart')->destroyCart();
        }
        if ((float)$invoice->first_total == 0) {
            $invoice->addAccessPeriod(new Am_Paysystem_Transaction_Free($this));
        }
        if (empty($invoice->due_date)) {
            $invoice->due_date = $this->getDi()->dateTime->modify('+5 days')->format('Y-m-d');
            $invoice->updateSelectedFields('due_date');
        }


        $result->setAction(
            new Am_Paysystem_Action_Redirect(
                $this->getDi()->url("payment/" . $this->getId() . "/instructions",
                    ['id' => $invoice->getSecureId($this->getId())], false)
            )
        );
    }

    function validateRequest($request)
    {
        $body = $request->getRawBody();
        $this->getDi()->logger->info('Kuickpay incoming request: ' . $body);
        $params = @json_decode($body, true);
        if (empty($params)) {
            throw new Am_Exception_InternalError("Params are empty");
        }

        if (empty($params['userName']) || empty($params['password'])) {
            throw new Am_Exception_InputError("Missing username/password in request");
        } else {
            if ($params['userName'] != $this->getConfig('username') || $params['password'] != $this->getConfig('password')) {
                throw new Am_Exception_InternalError("Unable to validate request. Credentials are incorrect");
            }
        }


        $consumerNumber = $params['consumerNumber'] ?? null;

        if (is_null($consumerNumber)) {
            throw new Am_Exception_InternalError("No consumer number received");
        }
        return $consumerNumber;

    }

    public function directAction($request, $response, $invokeArgs)
    {
        $actionName = $request->getActionName();
        switch ($actionName) {
            case 'inquiry' :
                try {
                    $consumerNumber = $this->validateRequest($request);
                } catch (Exception $ex) {
                    $this->getDi()->logger->info("KuickPay Exception", ['exception' => $ex]);
                    return $response->withJson([
                        'Response_Code' => '03',
                    ]);
                }
                $invoice = $this->getInvoiceByConsumerNumber($consumerNumber);

                if (!$invoice) {
                    return $response->withJson([
                        'Response_Code' => '01'
                    ]);
                }

                $data = [
                    'Response_Code' => '00',
                    'Consumer_Detail' => $invoice->getUser()->getName(),
                    'Bill_Status' => $invoice->isCompleted() ? 'P' : 'U',
                    'Due_Date' => $_d = date('Ymd', amstrtotime($invoice->due_date)),
                    'Amount_Within_DueDate' => $_ = "+" . str_pad((string)($invoice->first_total * 100), 13, "0",
                            STR_PAD_LEFT),
                    'Amount_After_DueDate' => $_,
                    'Billing_Month' => substr($_d, 2, 4),
                    'Reserved' => str_pad($invoice->getUser()->phone . "|" . $invoice->getUser()->getEmail(), 200, " "),
                    'Date_Paid' => str_pad($invoice->tm_started ? date("Ymd", amstrtotime($invoice->tm_started)): " ", 8, " "),
                    'Amount_Paid' => str_pad((string)($invoice->first_total * 100), 12, "0",
                        STR_PAD_LEFT),
                    'Tran_Auth_Id' => str_pad($invoice->isCompleted() ? $invoice->getPaymentRecords()[0]->receipt_id : " ", 6, " ")
                ];
                return $response->withJson($data);
                break;
            case 'bill' :
                try {
                    $consumerNumber = $this->validateRequest($request);
                } catch (Exception $ex) {
                    $this->getDi()->logger->info("KuickPay Exception", ['exception' => $ex]);
                    return $response->withJson([
                        'Response_Code' => '03',
                    ]);
                }
                $invoice = $this->getInvoiceByConsumerNumber($consumerNumber);

                if (!$invoice) {
                    return $response->withJson([
                        'Response_Code' => '01'
                    ]);
                }

                try{
                    $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);
                    $transaction = $this->createTransaction($request, $response, $invokeArgs);
                    if (!$transaction)
                    {
                        throw new Am_Exception_InputError("Request not handled - createTransaction() returned null");
                    }
                    $transaction->setInvoiceLog($invoiceLog);
                    try {
                        $transaction->process();
                    } catch (Exception $e) {
                        if ($invoiceLog)
                            $invoiceLog->add($e);
                        throw $e;
                    }
                    if ($invoiceLog)
                        $invoiceLog->setProcessed();

                    return $response->withJson([
                        'Response_Code' => '00'
                    ]);

                }catch(Am_Exception $ex){
                    return $response->withJson([
                        'Response_Code' => '03'
                    ]);

                }
                break;

            case 'instructions' :
                $invoice = $this->getDi()->invoiceTable->findBySecureId($request->getFiltered('id'), $this->getId());
                if (!$invoice) {
                    throw new Am_Exception_InputError(___("Sorry, seems you have used wrong link"));
                }
                $view = new Am_View;
                $html = $this->getConfig('html', 'SITE OWNER DID NOT PROVIDE INSTRUCTIONS FOR OFFLINE PAYMENT YET');

                $tpl = new Am_SimpleTemplate;
                $tpl->receipt_html = $view->partial('_receipt.phtml', ['invoice' => $invoice, 'di' => $this->getDi()]);
                $tpl->invoice = $invoice;
                $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
                $tpl->invoice_id = $invoice->invoice_id;
                $tpl->cancel_url = $this->getDi()->url('cancel', ['id' => $invoice->getSecureId('CANCEL')], false);
                $tpl->invoice_title = $invoice->getLineDescription();
                $tpl->product = $invoice->getItem(0)->tryLoadProduct();
                $tpl->consumer_number = $this->getConsumerNumber($invoice);

                $view->invoice = $invoice;
                $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom', '%s',
                        ['invoice' => $invoice]);
                $view->title = $this->getTitle();
                $response->setBody($view->render("layout.phtml"));
                break;
            case 'preview' :
                $this->previewAction($request, $response, $invokeArgs);
                break;
            default:
                return parent::directAction($request, $response, $invokeArgs);
        }
    }

    function getReadme()
    {
        $inquiry = $this->getPluginUrl('inquiry');
        $bill = $this->getPluginUrl('bill');
        return <<<DOC
Plugin accept REST requests at these two endpoints:
Bill Inquiry: {$inquiry}
Bill Payment: {$bill}
DOC;;
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Kuickpay($this, $request, $response, $invokeArgs);
    }

    public function previewAction($request, $response, $invokeArgs)
    {
        if (!$this->getDi()->authAdmin->getUserId()) {
            throw new Am_Exception_AccessDenied;
        }

        $view = new Am_View;
        $form = $this->createPreviewForm();
        $form->setDataSources([$request]);
        do {
            if ($form->isSubmitted() /*&& $f->validate()*/) {
                $v = $form->getValue();
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->toggleFrozen(true);

                $u = $this->getDi()->userTable->findFirstByLogin($v['user']);
                if (!$u) {
                    [$el] = $form->getElementsByName('user');
                    $el->setError(___('User %s not found', $v['user']));
                    break;
                }
                $invoice->setUser($u);
                if ($v['coupon']) {
                    $invoice->setCouponCode($v['coupon']);
                    $error = $invoice->validateCoupon();
                    if ($error) {
                        [$el] = $form->getElementsByName('coupon');
                        $el->setError($error);
                        break;
                    }
                }
                foreach ($v['product_id'] as $plan_id => $qty) {
                    $p = $this->getDi()->billingPlanTable->load($plan_id);
                    $pr = $p->getProduct();
                    try {
                        $invoice->add($pr, $qty);
                    } catch (Am_Exception_InputError $e) {
                        $form->setError($e->getMessage());
                        break;
                    }
                }
                $invoice->calculate();
                $invoice->setPaysystem($this->getId());
                $invoice->invoice_id = 'ID';
                $invoice->public_id = 'PUBLIC_ID';

                $html = $this->getConfig('html', 'SITE OWNER DID NOT PROVIDE INSTRUCTIONS FOR OFFLINE PAYMENT YET');

                $tpl = new Am_SimpleTemplate;
                $tpl->receipt_html = $view->partial('_receipt.phtml', ['invoice' => $invoice, 'di' => $this->getDi()]);
                $tpl->invoice = $invoice;
                $tpl->user = $this->getDi()->userTable->load($invoice->user_id);
                $tpl->invoice_id = $invoice->invoice_id;
                $tpl->cancel_url = $this->getDi()->url('cancel', ['id' => $invoice->getSecureId('CANCEL')], false);
                $tpl->invoice_title = $invoice->getLineDescription();
                $tpl->product = $invoice->getItem(0)->tryLoadProduct();
                $tpl->consumer_number = $this->getConsumerNumber($invoice);

                $view->invoice = $invoice;
                $view->content = $tpl->render($html) . $view->blocks('payment/offline/bottom');
                $view->title = $this->getTitle();
                $response->setBody($view->render("layout.phtml"));
                return;
            }
        } while (false);

        $view->title = $this->getTitle() . ' &middot; ' . ___("Preview");
        $view->content = (string)$form;
        $view->display('admin/layout.phtml');
    }

    protected function createPreviewForm()
    {
        $form = new Am_Form_Admin;
        $form->addText('user')
            ->setLabel(___('Enter username of existing user'))
            ->addRule('required');
        $form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#user-0" ).autocomplete({
        minLength: 2,
        source: amUrl("/admin-users/autocomplete")
    });
});
CUT
        );
        $form->addElement(new Am_Form_Element_ProductsWithQty('product_id'))
            ->setLabel(___('Products'))
            ->loadOptions($this->getDi()->billingPlanTable->selectAllSorted())
            ->addRule('required');
        $form->addText('coupon')->setLabel(___('Coupon'))->setId('p-coupon');
        $form->addScript('script')->setScript(<<<CUT
jQuery("input#p-coupon").autocomplete({
    minLength: 2,
    source: amUrl("/admin-coupons/autocomplete")
});
CUT
        );
        $form->addSaveButton(___('Preview'));
        return $form;
    }


}

class Am_Paysystem_Transaction_Incoming_Kuickpay extends Am_Paysystem_Transaction_Incoming{

    protected $parsedBody;
    function __construct($plugin, $request, $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->parsedBody = @json_decode($request->getRawBody(), true);

    }

    public function validateSource()
    {
        return
            !empty($this->parsedBody) &&
            ($this->parsedBody['userName'] == $this->getPlugin()->getConfig('username')) &&
            ($this->parsedBody['password'] == $this->getPlugin()->getConfig('password'));
    }

    public function validateTerms()
    {
        return floatval($this->parsedBody['amount']/100) == floatval($this->invoice->first_total);
    }

    public function validateStatus()
    {
        return true;
    }

    function getUniqId()
    {
        return $this->parsedBody['authId'];
    }

    function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getInvoiceByConsumerNumber($this->parsedBody['consumerNumber']);
        return $invoice ? $invoice->public_id : null;

    }
}