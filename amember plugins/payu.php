<?php

/**
 * @am_payment_api 6.0
*/
class Am_Paysystem_Payu extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '6.3.29';

    protected $defaultTitle = 'PayU';
    protected $defaultDescription = 'You can pay by credit card or directly from most Polish banks: /
    Możesz zapłacić kartą kredytową lub bezpośrednio z konta większości polskich banków:
    - Visa, MasterCard, mBank, MultiBank, Bank Zachodni WBK, Bank PEKAO SA, Inteligo, Lukas Bank, Nordea Bank...';

    const ACTION_URL = "https://secure.payu.com/paygw/UTF/NewPayment";

    protected $transaction;

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addInteger('pos_id', ['size' => 20])
            ->setLabel('Id punktu płatności (pos_id)');
        $form->addSecretText('key1', ['size' => 32, 'maxlength' => '32'])
            ->setLabel('Klucz MD5');
        $form->addSecretText('key2', ['size' => 32, 'maxlength' => '32'])
            ->setLabel('Drugi klucz MD5');
        $form->addSecretText('pos_auth_key', ['size' => 20, 'maxlength' => '32'])
            ->setLabel('Parametr pos_auth_key');
    }

    public function isConfigured()
    {
        return $this->getConfig('pos_id') > '';
    }

    public function _process($invoice, $request, $result)
    {
        $action = new Am_Paysystem_Action_Form(self::ACTION_URL);

        $vars = array_filter([
            'pos_id' => $this->getConfig('pos_id'),
            'pos_auth_key' => $this->getConfig('pos_auth_key'),
            'session_id' => $invoice->public_id,
            'amount' => round($invoice->first_total * 100),
            'desc' => $invoice->getLineDescription(),
            'first_name' => $invoice->getFirstName(),
            'last_name' => $invoice->getLastName(),
            'country' => $invoice->getCountry(),
            'street' => $invoice->getStreet(),
            'city' => $invoice->getCity(),
            'post_code' => $invoice->getZip(),
            'email' => $invoice->getEmail(),
            'city' => $this->getConfig('lang'),
            'client_ip' => $_SERVER['REMOTE_ADDR'],
            'ts' => $this->getDi()->time
        ]);
        ksort($vars);
        foreach ($vars as $k => $v)
        {
            $action->addParam($k, $v);
        }
        $action->sig = hash('sha256', http_build_query($vars) . '&' . $this->getConfig('key2'));

        $this->logOther('Payment Request', $action);
        $result->setAction($action);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Payu($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }


    function directAction($request, $response, array $invokeArgs)
    {
        try
        {
            return parent::directAction($request, $response, $invokeArgs);
        } catch (Exception $e) {
            $this->getDi()->logger->error($e);
            print "ERROR";
            exit;
        }
    }

    function isRefundable(InvoicePayment $payment)
    {
        return false;
    }
}

class Am_Paysystem_Transaction_Payu extends Am_Paysystem_Transaction_Incoming
{
    const ACTION_ADD = 'user.add';
    const ACTION_REBILL = 'rebill';
    const ACTION_DELETE = 'user.delete';

    const SERVER = 'www.platnosci.pl';
    const SERVER_SCRIPT = '/paygw/UTF/Payment/get';

    // @todo: admin-cancel twocheckout

    public function findInvoiceId()
    {
        return $this->transaction->session_id;
    }

    public function genereateSignature($pos_id, $session_id, $ts)
    {
        return md5($pos_id . $session_id . $ts . $this->plugin->getConfig("key2"));
    }

    public function getUniqId()
    {
        return $this->transaction->session_id;
    }

    public function validateSource()
    {
        if (!$this->request->get("pos_id") || !$this->request->get("session_id") || !$this->request->get("ts") || !$this->request->get("sig"))
            throw new Am_Exception_Paysystem_TransactionInvalid("Empty Parametrs!");

        if ($this->request->get("pos_id") != $this->plugin->getConfig("pos_id"))
            throw new Am_Exception_Paysystem_TransactionInvalid("WRONG POS ID!");

        if ($this->genereateSignature($this->request->get("pos_id"), $this->request->get("session_id"), $this->request->get("ts")) != $this->request->get("sig"))
            throw new Am_Exception_Paysystem_TransactionInvalid("WRONG SIGNATURE!");

        $this->getOrder($this->request->get("session_id"));

        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->transaction->status)
        {
            case 99 :
                if ($this->invoice->getStatus() == 0)
                    $this->invoice->addPayment($this);
                break;
            case 2 :
                $this->invoice->stopAccess($this);
                break;
        }
        print "OK";
    }

    function xmlToArray(SimpleXMLElement $xml)
    {
        $arr = (array) $xml;

        foreach ($arr as $k => $v)
            if (is_object($v))
            {
                $arr[$k] = $this->xmlToArray($v);
                if (empty($arr[$k]))
                    $arr[$k] = null;
            }

        return $arr;
    }

    function getOrder($session_id)
    {
        $ts = time();
        $sig = md5($this->plugin->getConfig('pos_id') . $session_id . $ts . $this->plugin->getConfig('key1'));

        $r = new Am_HttpRequest('https://' . self::SERVER . self::SERVER_SCRIPT, 'POST');
        $r->addPostParameter([
            'pos_id' => $this->plugin->getConfig('pos_id'),
            'session_id' => $session_id,
            'ts' => $ts,
            'sig' => $sig
        ]);

        $l = $this->plugin->logRequest($r);

        $response = $r->send()->getBody();
        $l->add($response);

        $res = new SimpleXMLElement($response);

        if ($res->status != 'OK')
            throw new Am_Exception_Paysystem_TransactionInvalid("BAD RESPONSE STATUS!");

        if ($res->trans->pos_id != $this->plugin->getConfig('pos_id'))
            throw new Am_Exception_Paysystem_TransactionInvalid("INCORECT POS NUMBER!");

        $sig = md5($res->trans->pos_id . $res->trans->session_id . $res->trans->order_id . $res->trans->status . $res->trans->amount . $res->trans->desc . $res->trans->ts . $this->plugin->getConfig('key2'));
        if ($res->trans->sig != $sig)
            throw new Am_Exception_Paysystem_TransactionInvalid("INCORECT SIGNATURE!");

        $this->transaction = $res->trans;


        /* Status:
         *       1: nowa
         *       2: anulowana
         *       3: odrzucona
         *       4: rozpoczęta
         *       5: oczekuje na odbiór
         *       6: autoryzacja odmowna
         *       7: płatność odrzucona
         *       99: płatność odebrana - zakończona
         *       888: błędny status
         */

        return true;
    }
}