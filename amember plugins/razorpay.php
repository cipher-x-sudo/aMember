<?php

/**
 * @table paysystems
 * @id razorpay
 * @title Razorpay
 * @visible_link https://razorpay.com/
 * @recurring no
 * @am_payment_api 6.0
 */
class Am_Paysystem_Razorpay extends Am_Paysystem_Abstract
{

    const
        PLUGIN_STATUS = self::STATUS_DEV,
        PLUGIN_REVISION = '6.3.5',
        API_URL = 'https://api.razorpay.com/v1';

    protected
        $defaultTitle = "RazorPay",
        $defaultDescription = "Next Generation of Digital Payments",
        $_canAutoCreate = false;

    function _initSetupForm(\Am_Form_Setup $form)
    {
        $form->addText('key')->setLabel(___('Merchant Key'));
        $form->addText('secret')->setLabel(___('Key Secret'));
        $form->addText('name')->setLabel(___("Merchant Name to be shown in Checkout Form"));
    }

    public function init()
    {
        parent::init();
        $this->getDi()->billingPlanTable->customFields()
            ->add(new Am_CustomFieldText('razorpay_id', "Razorpay Billing Plan #"));
    }

    function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getSupportedCurrencies()
    {
        return ['INR','USD'];
    }

    function getTotalCount(Invoice $invoice)
    {
        if($invoice->rebill_times != IProduct::RECURRING_REBILLS)
            return $invoice->rebill_times;
        else
        {
            $p = new Am_Period($invoice->second_period);
            switch($p->getUnit())
            {
                //We support subscriptions for a maximum duration of 100 years.
                case 'd':
                    return 50 * 365 / $p->getCount();
                case 'm':
                    return 50 * 12 / $p->getCount();
                case 'y':
                    return 50 / $p->getCount();
            }
        }
    }
    /**
     *
     * @param Invoice $invoice
     * @param Am_Mvc_Request $request
     * @param Am_Paysystem_Result $result
     */
    function _process($invoice, $request, $result)
    {
        if($invoice->rebill_times)
        {
            $req = new Am_HttpRequest(self::API_URL . '/subscriptions', Am_HttpRequest::METHOD_POST);
            $req->setAuth($this->getConfig('key'), $this->getConfig('secret'));
            $req->setHeader('Content-Type', 'application/json');
            $req->setBody(json_encode([
                'plan_id' => $invoice->getItem(0)->getBillingPlanData('razorpay_id'),
                'total_count' => floor($this->getTotalCount($invoice)),
                'notes' => [
                    'public_id' => $invoice->public_id
                    ]
            ]));
            $res = $req->send();
            $log = Am_Di::getInstance()->invoiceLogRecord;
            $log->setInvoice($invoice);
            $log->paysys_id = $this->getId();
            $log->add($req);
            $log->add($res);

            $vars = json_decode($res->getBody(), true);
            if($vars['status'] == 'created')
            {
                $a = new Am_Paysystem_Action_Redirect($vars['short_url']);
                $result->setAction($a);
            }
            else
            {
                throw new Am_Exception_InternalError('Wrong response received!');
            }
        }
        else {
            $a = new Am_Paysystem_Action_HtmlTemplate_Razorpay(dirname(__FILE__), 'form.phtml');
            $a->vars = [
                'key' => $this->getConfig('key'),
                'amount' => $invoice->first_total * 100,
                'currency' => $invoice->currency,
                'name' => $this->getConfig('name', $this->getDi()->config->get('site_title')),
                'description' => $invoice->getLineDescription(),
                'prefill.name' => $invoice->getName(),
                'prefill.email' => $invoice->getEmail(),
            ];
            $a->invoice = $invoice;
            $a->url = $this->getPluginUrl('thanks');
            $a->id = $invoice->getSecureId("RZTHANKS");
            $result->setAction($a);
        }
    }

    function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Thanks_Razorpay($this, $request, $response, $invokeArgs);
    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Incoming_Razorpay($this, $request, $response, $invokeArgs);
    }

    function processRefund(\InvoicePayment $payment, \Am_Paysystem_Result $result, $amount)
    {
        $req = $this->getApiReq($uri = '/payments/' . $payment->receipt_id . '/refund', Am_HttpRequest::METHOD_POST);
        $req->addPostParameter('amount', $payment->amount * 100);
        $resp = $req->send();
        $log = $this->getDi()->invoiceLogRecord;
        $log->setInvoice($payment->getInvoice());
        $log->add([$uri => $resp->getStatus() . ' : ' . $resp->getBody(), true]);

        if ($resp->getStatus() != 200)
            return false;
        $refund = json_decode($resp->getBody(), true);
        $trans = new Am_Paysystem_Transaction_Manual($this);
        $trans->setAmount($amount);
        $trans->setReceiptId($refund->id);
        $result->setSuccess($trans);
    }

    function getApiReq($uri, $method = Am_HttpRequest::METHOD_GET)
    {
        $req = new Am_HttpRequest(self::API_URL . $uri, $method);
        $req->setAuth($this->getConfig('key'), $this->getConfig('secret'));
        return $req;
    }

}

class Am_Paysystem_Action_HtmlTemplate_Razorpay extends Am_Paysystem_Action_HtmlTemplate
{

    protected
        $_template;
    protected
        $_path;

    public
        function __construct($path, $template)
    {
        $this->_template = $template;
        $this->_path = $path;
    }

    public
        function process($action = null)
    {
        $action->view->addBasePath($this->_path);

        $action->view->assign($this->getVars());
        $action->renderScript($this->_template);

        throw new Am_Exception_Redirect;
    }

}

class Am_Paysystem_Transaction_Incoming_Thanks_Razorpay extends Am_Paysystem_Transaction_Incoming_Thanks
{

    public
        function getUniqId()
    {
        return $this->payment['id'];
    }

    public
        function findInvoiceId()
    {
        $invoice = $this->getPlugin()->getDi()->invoiceTable->findBySecureId($this->request->get('id'), 'RZTHANKS');
        return $invoice->public_id;
    }

    public
        function validateSource()
    {
        if (!$this->request->get('razorpay_payment_id'))
            return false;
        $req = $this->getPlugin()->getApiReq('/payments/' . $this->request->get('razorpay_payment_id'));
        $resp = $req->send();
        if ($resp->getStatus() != 200)
            return false;
        $this->payment = json_decode($resp->getBody(), true);
        $this->log->add($this->payment, true);
        return true;
    }

    public
        function validateStatus()
    {
        switch($this->payment['status'])
        {
            case 'authorized' :
                $req = $this->getPlugin()->getApiReq($uri = '/payments/' . $this->payment['id'] . '/capture', Am_HttpRequest::METHOD_POST);
                $req->addPostParameter('amount', $this->invoice->first_total * 100);
                $resp = $req->send();
    
                $this->log->add([$uri => $resp->getStatus() . ' : ' . $resp->getBody()]);
                
                if ($resp->getStatus() != '200')
                    return false;
                
                return true;
            case 'captured' :
                return true;
                
            default:
                return false;
                
        }

    }

    public
        function validateTerms()
    {
        return ($this->invoice->first_total * 100 == $this->payment['amount']);
    }

}

class Am_Paysystem_Transaction_Incoming_Razorpay extends Am_Paysystem_Transaction_Incoming
{

    function __construct($plugin, $request, $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->vars = json_decode($request->getRawBody(), true);
    }

    public function validateSource()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    function getUniqId()
    {
        return $this->vars['payload']['payment']['entity']['id'];
    }

    function findInvoiceId()
    {
        return @$this->vars['payload']['subscription']['entity']['notes']['public_id'];
    }

    function processValidated()
    {
        switch($this->vars['event']){
            case 'subscription.charged':
                $this->invoice->addPayment($this);
                break;
        }
    }
}
