<?php

/**
 * This plugin display block with user pending invoices
 * with links to pay it.
 *
 * @am_plugin_api 6.0
 */
class Am_Plugin_BlockPendingInvoice extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '6.3.31';

    const DEFAULT_EXPIRE = 14;
    const DATA_KEY = 'block-pending-invoice-disregard';
    const D_HIDE = 'hide';
    const D_DELET = 'delete';

    protected $_configPrefix = 'misc.';
    protected $invoices = [];

    function onInitFinished(Am_Event $e)
    {
        $this->getDi()->router->addRoute($this->getId(), new Am_Mvc_Router_Route('pending-invoice-pay/:invoice_id', [
            'module' => 'default',
            'controller' => 'pending-invoice-pay',
            'action' => 'index',
        ]));
    }

    function onInitBlocks(Am_Event $e)
    {
        if ($this->getDi()->auth->getUserId() &&
            ($invoices = $this->getPendingInvoices($this->getDi()->auth->getUser()))) {

            $this->invoices = $invoices;
            $e->getBlocks()->add($this->getConfig('target', 'member/main/right'),
                new Am_Block_Base(___('Pending Invoices'), 'block-pending-invoice', $this, [$this, 'renderBlock']),
                $this->getConfig('order') ?: Am_Blocks::MIDDLE);
        }
    }

    function getTitle()
    {
        return ___('Pending Invoices Block');
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $targets = [
            ___('Dashboard') => [
                'member/main/top' => 'Top',
                'member/main/left' => 'Left Column',
                'member/main/right' => 'Right Column',
                'member/main/bottom' => 'Bottom',
            ],
            ___('Profile Form') => [
                'member/profile/top' => 'Top',
                'member/profile/bottom' => 'Bottom',
            ],
            ___('Signup Form') => [
                'signup/form/before' => 'Top',
                'signup/form/after' => 'Bottom',
            ],
            ___('Payments History') => [
                'member/payment-history/top' => 'Top',
                'payment-history/center' => 'Middel',
                'member/payment-history/bottom' => 'Bottom',
            ],
        ];

        $form->addSelect('target')
            ->setLabel(___('Target'))
            ->loadOptions($targets);

        $form->addInteger('order', ['placeholder' => Am_Blocks::MIDDLE])
            ->setLabel(___('Sort Order'));

        $g = $form->addGroup()
            ->setLabel(___('Automatically Hide Invoice after'));
        $g->setSeparator(' ');
        $g->addText('expire', ['size' => 4])
            ->setValue(self::DEFAULT_EXPIRE);
        $g->addHtml()->setHtml(___('days'));

        $form->addAdvRadio('disregard_behavior')
            ->setLabel(___("Disregard Behavior\n" .
                "what to do with invoice once user click Disregard link"))
            ->loadOptions([
                self::D_HIDE => ___('Hide Invoice From Widget'),
                self::D_DELET => ___('Delete Invoice')
            ]);

        $form->addAdvCheckbox('change_paysys')
            ->setlabel(___('Allow to change Payment System'));

        $form->setDefault('disregard_behavior', self::D_HIDE);
        $form->setDefault('target', 'member/main/right');
    }

    function renderBlock(Am_View $view)
    {
        $out = '';
        foreach ($this->invoices as $invoice) {
            /* @var $invoice Invoice */
            $item_titles = [];
            foreach ($invoice->getItems() as $item) {
                array_push($item_titles, $item->item_title);
            }
            $paysys_title = '';
            if ($ps = $invoice->getPaysystem()) {
                $paysys_title = sprintf(' (%s)', $ps->getTitle());
            }
            $out .= sprintf('<li><span class="am-block-pending-invoice-desc"><span class="am-block-pending-invoice-desc_date">%s</span> <span class="am-block-pending-invoice-desc_items">%s</span><span class="am-block-pending-invoice-desc_paysys">%s</span> - <span class="am-block-pending-invoice-desc_total">%s</span></span> <a href="%s" class="am-block-pending-invoice-pay">%s</a> <span class="am-block-pending-invoice-divider">|</span> <a href="%s" class="am-block-pending-invoice-disregard">%s</a></li>',
                    amDate($invoice->tm_added),
                    implode(', ', $item_titles),
                    $paysys_title,
                    $invoice->getCurrency($invoice->first_total),
                    $this->getDi()->url('pending-invoice-pay/' . $invoice->getSecureId('payment-link')),
                    ___('Complete Payment'),
                    $this->getDi()->url("misc/{$this->getId()}/{$invoice->getSecureId('disregard')}"),
                    ___('Disregard')
            );
        }

        return sprintf('<ul class="am-widget-list am-list-pending-invoices">%s</ul>', $out);
    }

    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if (!$invoice = $this->getDi()->invoiceTable->findBySecureId($request->getActionName(), 'disregard')) {
            throw new Am_Exception_InputError();
        }

        if ($invoice->user_id != $this->getDi()->user->pk()) {
            throw new Am_Exception_AccessDenied;
        }

        switch ($this->getConfig('disregard_behavior')) {
            case self::D_DELET:
                $invoice->delete();
                break;
            case self::D_HIDE:
            default:
                $invoice->data()->set(self::DATA_KEY, 1);
                $invoice->save();
        }

        return $response->redirectLocation($this->getDi()->url('member', false));
    }

    /**
     * Retrieve array of invoicese to show in block
     *
     * @param User $user
     * @return Invoice[] $invoices
     */
    protected function getPendingInvoices(User $user)
    {
        $threshold = sprintf('-%d days', $this->getConfig('expire', self::DEFAULT_EXPIRE));

        $invoices = [];

        foreach ($this->getDi()->invoiceTable->findBy([
            'user_id' => $user->pk(),
            'status' => Invoice::PENDING,
            ['tm_added', '>', sqlTime($threshold)]
        ]) as $invoice) {
            /* @var $invoice Invoice */
            if ($invoice->data()->get(self::DATA_KEY))
                continue;
            if ($invoice->due_date && $invoice->due_date < sqlDate('now'))
                continue;

            if (!$invoice->due_date) {
                $invoice->updateQuick('due_date', sqlDate(amstrtotime($invoice->tm_added) + 3600 * 24 * $this->getConfig('expire', self::DEFAULT_EXPIRE)));
            }

            array_push($invoices, $invoice);
        }

        return $invoices;
    }
}

class PendingInvoicePayController extends Am_Mvc_Controller
{
    function indexAction()
    {
        $this->getDi()->auth->requireLogin();

        if (!$invoice = $this->getDi()->invoiceTable->findBySecureId($this->getParam('invoice_id'), 'payment-link')) {
            throw new Am_Exception_InputError();
        }

        if ($invoice->user_id != $this->getDi()->user->pk()) {
            throw new Am_Exception_AccessDenied;
        }

        if ($this->getPlugin()->getConfig('change_paysys')) {
            $invoice->updateQuick('paysys_id', null);
        }

        Am_Mvc_Response::redirectLocation($this->getDi()->url("pay/{$invoice->getSecureId('payment-link')}", false));
    }

    function getPlugin()
    {
        return $this->getDi()->plugins_misc->loadGet('block-pending-invoice');
    }
}