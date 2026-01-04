<?php

/**
 * Single-point entry point for all api module requests
 * Class ApiController
 */
class Api_ApiController extends Am_Mvc_Controller
{
    function routeAction()
    {
        $path = strtolower(trim($this->_request->getPathInfo(), '/ '));

        $request = clone $this->_request;

        if (!$controller = $this->match($request, $path))
        {
            throw new Am_Exception_NotFound("404 not found");
        }

        if (method_exists($controller, 'setNestedCallback'))
            $controller->setNestedCallback(function($alias) use ($request) {
                // it will be called when a Table controller wants access to nested Table controller $alias
                $c = $this->getModule()->createByDefinition($alias, ['di' => $this->getDi()]);
                $this->getModule()->checkPermissions($request, $alias,
                    strtolower($request->getMethod()));
                return $c;
            });

        $method = toCamelCase(strtolower($request->getActionName()));

        $response = call_user_func([$controller, $method], $request, $this->getResponse(), [ 'di' => $this->getDi() ]);
        if (!$response)
            throw new Am_Exception_InternalError("No return from an API method [$method]");
        $this->setResponse($response);
    }

    /**
     * Find controller for request
     *
     * @param Am_Mvc_Request $request
     * @param string $path
     * @return Am_ApiController_Base|bool
     * @throws Am_Exception_InputError
     * @throws Am_Exception_InternalError
     * @throws Am_Exception_Security
     */
    public function match(Am_Mvc_Request & $request, $path)
    {
        $path   = trim($path, '/');

        $result = false;

        $vars = explode('/', $path);

        $module = array_shift($vars);
        if ($module !== 'api') return false; // that is not about me
        if (empty($vars[0]) || ($vars[0] === 'admin')) return false; // that is my regular admin page

        $controller = array_shift($vars);

        $record = $this->getModule()->getDefinition($controller);
        if (!$record)
            throw new Am_Exception_Security("No API Action set: looks like controller [$controller] is not configured in API module");

        // detect REST request method
        $method = null;

        if ($request->getParam('_method'))
            $method = strtoupper(preg_replace('/[^a-zA-Z0-9-]/', '', $request->getParam('_method')));
        else {
            $method = $request->getMethod();
            if ($request->isPut()) {
                $putParams = [];
                parse_str($request->getRawBody(), $putParams);
                $request->setParams($putParams);
            }
        }

        switch ($method)
        {
            case 'POST'   :
                $method = 'post';
                break;
            case 'DELETE' :
            case 'PUT':
                if (empty($vars[0]) || !filter_var($vars[0], FILTER_VALIDATE_INT)) // not int id passed
                    throw new Am_Exception_InputError("No id passed for ".$method." method");
                $request->setParam('_id', (int)array_shift($vars));
                break;
            case 'OPTIONS': $method = 'options'; break;
            case 'HEAD'   : $method = 'head'; break;
            case 'TRACE'  : $method = 'trace'; break;
            case 'GET'    :
            default       :
                if (!empty($vars[0]) && filter_var($vars[0], FILTER_VALIDATE_INT)) // if int id passed
                {
                    $request->setParam('_id', (int)array_shift($vars));
                    $method = 'get';
                } else {
                    if ($method == 'GET')
                        $method = null;
                    if (empty($method))
                        $method = array_shift($vars);
                    if (empty($method))
                        $method = 'index';
                }
        }
        $method = strtolower($method);

        $version = (int)$request->getParam('_version');
        if (!$version) $version = 1;

        $this->getModule()->checkPermissions($request, $controller, $method);

        $request
            ->setControllerName($controller)
            ->setActionName($method);

        return $this->getModule()->createByDefinition($controller);
    }
}
