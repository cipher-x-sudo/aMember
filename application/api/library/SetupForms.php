<?php

/** @help-id 'REST' */
class Am_Form_Setup_Api extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('api');
        $this->setTitle(___('REST API'));
    }

    function initElements()
    {
        $url = Am_Di::getInstance()->url('default/admin-logs/p/invoice');
        $this->addAdvCheckbox('api_debug_mode')
            ->setLabel(___('Enable Debug Mode') . "\n" .
            ___('all requests will be added to %sLogs%s, useful if something is going wrong', '<a href="'.$url.'">', '</a>'));
    }
}