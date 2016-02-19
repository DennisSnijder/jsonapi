<?php

namespace JSONAPI\Controllers;

use JSONAPI\Config\Config;
use JSONAPI\Helpers\APIHelper;
use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class MenuController extends APIController implements ControllerProviderInterface
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var Config
     */
    private $config;
    /**
     * @var APIHelper
     */
    private $APIHelper;


    /**
     * MenuController constructor.
     * @param Config $config
     * @param APIHelper $APIHelper
     * @param Application $app
     */
    public function __construct(Config $config, APIHelper $APIHelper, Application $app)
    {
        $this->app = $app;
        $this->config = $config;
        $this->APIHelper = $APIHelper;
    }

    /**
     * Returns routes to connect to the given application.
     *
     * @param Application $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     */
    public function connect(Application $app)
    {
        /**
         * @var $ctr \Silex\ControllerCollection
         */
        $ctr = $app['controllers_factory'];

        $app->get("", [$this, "listMenus"]);

        return $ctr;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function listMenus(Request $request)
    {
        $this->config->setCurrentRequest($request);

        $name = '';
        if ($q = $request->get('q')) {
            $name = "/$q";
        }

        $menu = $this->app['config']->get('menu'.$name, false);
        if ($menu) {
            return new JsonResponse([
                'data' => $menu
            ]);
        }
        return new JsonResponse([
            'detail' => "Menu with name [$q] not found."
        ]);
    }

}