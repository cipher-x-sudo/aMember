<?php

/**
 * @title REST API Module
 * @setup_url api/admin
 * @desc Allows make aMember API calls from other scripts and even from other servers (for developers)
 */
class Bootstrap_Api extends Am_Module
{
    const ADMIN_PERM_ID = 'api';

    public $gridMethods = [
        'index',
        'get',
        'put',
        'delete',
        'post',
    ];

    /**
     * @var Am_ApiController_Definitions
     */
    protected $definitions;

    public function init()
    {
        $this->definitions = new Am_ApiController_Definitions();
        $this->getDi()->router->addRoute('api_request', new Am_Mvc_Router_Route_Regex('api/(?!admin).+', [
            'module' => 'api',
            'controller' => 'api',
            'action' => 'route',
        ]));
    }

    function onGetPermissionsList(Am_Event $e)
    {
        $e->addReturn("REST API: Can manage API key", self::ADMIN_PERM_ID);
    }

    function onGridAdminInitForm(Am_Event_Grid $e)
    {
        $e->getGrid()
            ->getForm()
            ->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery("[name='perms[api]']").change(function(){
        if (this.checked) {
            jQuery("#perm-api-warn").dialog({
                    autoOpen: true
                    ,width: 400
                    ,height: 200
                    ,closeOnEscape: true
                    ,title: 'Warning'
                    ,modal: true
             });
        }
    });
});
CUT
        );
        $e->getGrid()
            ->getForm()
            ->addEpilog(<<<CUT
<div id="perm-api-warn" style="display:none; font-size: 1.2rem;padding: 1em;line-height: 1.5;">Permission <strong>REST API: Can manage API key</strong> is equivalent to <strong>Super Admin</strong>, admin can create API key with any permission and then use this key to read/update data with API.</div>
CUT
        );
    }

    public function onAdminMenu(Am_Event $event)
    {
        $event->getMenu()->addPage([
            'id'    => 'api',
            'controller' => 'admin',
            'module' => 'api',
            'label' => ___('Remote API Permissions'),
            'resource' => self::ADMIN_PERM_ID,
            'order' => 90,
        ]);
    }

    function addDefinition($alias, $controller, $comment, array $methods)
    {
        // that is temporary, may be changed
        $this->definitions->add($alias, $controller);
        $this->definitions->methods($alias, $methods);
        $this->definitions->comment($alias, $comment);
    }

    function getDefinition($id)
    {
        if (!$this->definitions->count())
            $this->registerControllers();
        return $this->definitions->get($id);
    }

    function getDefinitions()
    {
        if (!$this->definitions->count())
            $this->registerControllers();
        return $this->definitions->getAll();
    }

    function createByDefinition($id) {
        if (!$this->definitions->count())
            $this->registerControllers();
        return $this->definitions->create($id, ['di' => $this->getDi()]);
    }

    /**
     * Throws exception if no permissions added
     * @param Am_Mvc_Request $request
     * @param array $record
     */
    public function checkPermissions(Am_Mvc_Request $request, $alias, $method)
    {
        if ($this->getDi()->config->get('api_debug_mode')) {
            $_= $this->getDi()->invoiceLogRecord;
            $_->paysys_id = $this->getId();
            $_->title = 'REST API';
            $_->add($request);
        }

        $event = $this->getDi()->hook->call(Am_Event::API_CHECK_PERMISSIONS, [
            'request' => $request,
            'alias'   => $alias,
            'method'  => $method,
        ]);
        foreach ($event->getReturn() as $return)
        {
            if ($return === true) return ; // skip checks if allowed by hook
        }

        $s = $request->getHeader('x-api-key') ?: $request->getFiltered('_key');
        if (empty($s) || strlen($s) < 10)
            throw new Am_Exception_InputError("API Error 10001 - no [key] specified or key is too short");
        $apikey = $this->getDi()->apiKeyTable->findFirstByKey($s);
        if (!$apikey || $apikey->is_disabled)
            throw new Am_Exception_InputError("API Error 10002 - [key] is not found or disabled");


        $ipAllowed = function($ip) use ($apikey)
        {
            $res =  array_reduce(array_filter(array_map('trim', explode("\n", $apikey->ip))), function($_, $item) use ($ip){
                return $_ || (strpos($ip, $item)===0);
            }, false);
            return $res;
        };

        if (!empty($apikey->ip) && !$ipAllowed($_SERVER['REMOTE_ADDR'])) {
            throw new Am_Exception_InputError("API Error 10004 - access from this server is not allowed");
        }
        $perms = $apikey->getPerms();

        if (empty($perms[$alias][$method]) || !$perms[$alias][$method])
            throw new Am_Exception_InputError("API Error 10003 - no permissions for $alias-$method API call");
        if (isset($_SERVER['HTTP_ORIGIN']) && !empty($apikey->domain)) {
            $origin = $_SERVER['HTTP_ORIGIN'];
            $host = parse_url($origin, PHP_URL_HOST);
            if (in_array($host, array_map('trim', explode("\n", $apikey->domain)))) {
                header("Access-Control-Allow-Origin: $origin");
            }
        }
    }

    protected function addDefaultControllers()
    {
        $all = $this->gridMethods;

        $this->definitions
            ->add('users', function(Am_Di $di){ return new Am_Api_Users($di, $di->userTable); }, 'Users', $all)
            ->add('access-log', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->accessLogTable); }, 'Access Log', $all)
            ->add('products', function(Am_Di $di) {
                return (new Am_ApiController_Table($di, $di->productTable))
                    ->addNested('billing-plans', true)
                    ->addNested('product-product-category', false);
            }, 'Products', $all)
            ->add('product-category', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->productCategoryTable); }, 'Product Categories', $all)
            ->add('product-product-category', function(Am_Di $di){ return new Am_Api_ProductProductCategory($di, $di->productProductCategoryTable); },
                'Relations Between Products and Categories', $all)
            ->add('billing-plans', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->billingPlanTable); }, 'Product Billing Plans', $all)
            ->add('invoices', function(Am_Di $di){ return new Am_Api_Invoices($di, $di->invoiceTable); }, 'Invoices', $all)
            ->add('invoice-items', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->invoiceItemTable); }, 'Invoice Items', $all)
            ->add('invoice-payments', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->invoicePaymentTable); }, 'Invoice Payments', $all)
            ->add('invoice-refunds', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->invoiceRefundTable); }, 'Invoice Refunds', $all)
            ->add('access', function(Am_Di $di){ return new Am_Api_Access($di, $di->accessTable); }, 'Access', $all)
            ->add('user-consent', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->userConsentTable); },
                'User Consent', ['index', 'get', 'post'])
            ->add('user-notes', function(Am_Di $di){ return new Am_ApiController_Table($di, $di->userNoteTable); }, 'User Note', $all)
            ->add('user-groups', function(Am_Di $di) {return new Am_ApiController_Table($di, $di->userGroupTable);}, 'User Groups', $all)
            ->add('saved-forms', function(Am_Di $di) {return new Am_Api_SavedForm($di, $di->savedFormTable);}, 'Saved Forms', ['index'])
            ->add('check-access', function(Am_Di $di){ return new Am_Api_CheckAccess($di); }, 'Check User Access',
                ['by-login-pass', 'by-login', 'by-email', 'by-login-pass-ip', 'send-pass']);
    }

    protected function registerControllers()
    {
        $this->addDefaultControllers();
        $this->getDi()->hook->call(Am_Event::GET_API_CONTROLLERS, [
            'list' => $this,
        ]);
    }

    function methodComment($method)
    {
        switch($method){
            case 'index' : return ___('List/search for records');
            case 'get' : return ___('Get single record');
            case 'post' : return ___('Insert record');
            case 'put' : return ___('Update record');
            case 'delete' : return ___('Delete record');
        }
        return ___('Run action: %s', $method);
    }

    function onOauthGetScopes(Am_Event $event)
    {
        $scopeRepository = $event->getReturn();
        foreach ($this->getDefinitions() as $alias => $record)
        {
            $url = $this->getDi()->surl("api/$alias");
            foreach ($record['methods'] as $method)
            {
                $id = implode('.', ['rest', $alias, $method]);
                $comment = sprintf('%s.  %s', $record['comment'], $this->methodComment($method));
                $scopeRepository->addScope(OauthScope::create($id, $comment, ['client_credentials']));
            }
        }
        $event->setReturn($scopeRepository);

    }
}