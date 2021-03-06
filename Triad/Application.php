<?php
/**
 * Triad - Lightweight MVP / HMVP Framework
 * @link http://
 * @author Marek Vavrecan, vavrecan@gmail.com
 * @copyright 2013 Marek Vavrecan
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3
 * @version 1.0.0
 */

namespace Triad;

use Triad\Exceptions\NotFoundException;
use Triad\Exceptions\TriadException;
use Triad\Router;
use Triad\Request;
use Triad\Config;

class ApplicationEnvironment
{
    const DEVELOPMENT = "development";
    const PRODUCTION = "production";
}

interface IApplication
{
    public function execute(Request $request);
    public function handleException(\Exception $e, Request $request);
    public function handleResponseException(\Exception $e, \Triad\Response $response);
}

/**
 * Class Application
 * Application represents all agents (triads) that handle request and executes presenter or other handlers
 * Application requires request to execute and set response
 * @package Triad
 */
abstract class Application implements IApplication
{
    /**
     * deadlock protection - define how much request can be nested
     */
    const MAX_NESTING_LEVEL = 10;

    /**
     * Make this available everywhere
     * @var Configuration
     */
    public $configuration;
    /**
     * @var ApplicationEnvironment
     */
    protected $environment;
    /**
     * @var Router
     */
    protected $router;

    private $initialized = false;

    public final function __construct(Config $configuration) {
        $this->configuration = $configuration;
        $this->environment = ApplicationEnvironment::PRODUCTION;

        if (isset($this->configuration["environment"]))
            $this->setEnvironment($this->configuration["environment"]);
    }

    /**
     * Init function is called once per class instance in the execute method as required
     * therefore we might catch exceptions from database declaration
     *
     * @return mixed
     */
    public abstract function init();

    public function setEnvironment($environment) {
        if ($environment != ApplicationEnvironment::PRODUCTION &&
            $environment != ApplicationEnvironment::DEVELOPMENT)
            throw new \Triad\Exceptions\TriadException("Unsupported environment");

        $this->environment = $environment;
    }

    public function getEnvironment() {
        return $this->environment;
    }

    public function setRouter(Router $route) {
        $this->router = $route;
    }

    public function getRouter() {
        return $this->router;
    }

    /**
     * Handle route to presenter
     * @param Request $request
     * @param array $mvpParams Parameters parsed from request path
     * @throws \Triad\Exceptions\NotFoundException
     */
    protected function executePresenter(Request $request, $mvpParams) {
        $namespace = $mvpParams["namespace"];
        $presenterName = Utils::getObjectName($mvpParams["presenter"]);
        $className = "{$namespace}\\{$presenterName}";

        if (class_exists($className, true)) {
            $presenter = new $className($this, $request, $mvpParams);
            $presenter->execute();
        }
        else {
            throw new NotFoundException("Alias you requested do not exist: " . $request->path);
        }
    }


    /**
     * Execute custom handler
     * @param Request $request
     * @param $handlerParams
     * @throws Exceptions\TriadException
     */
    protected function executeHandler(Request $request, $handlerParams) {
        $callback = $handlerParams["callback"];
        if (!is_callable($callback))
            throw new TriadException("Callback is not a function");

        $return = call_user_func_array($callback, array($this, $request, $handlerParams));

        if (!is_null($return))
            $request->response->set($return);
    }

    /**
     * Route request and execute presenter or handler
     * @param Request $request
     * @throws Exceptions\TriadException
     * @throws Exceptions\NotFoundException
     */
    public function execute(Request $request) {
        try {
            if (!$this->initialized) {

                // set base path
                if (isset($this->configuration["base_path"]) && $request instanceof \Triad\Requests\HttpRequest)
                    $request->setBasePath($this->configuration["base_path"]);

                // check access
                if (isset($this->configuration["client_secret"]))
                    $request->validateRequest($this->configuration["client_secret"]);

                // initialize router
                $this->router = new \Triad\Router();

                $this->init($this->configuration);
                $this->initialized = true;
            }

            // check if maximum nested level is not reached
            $nestingLevel = $request->getNestingLevel();
            if ($nestingLevel > self::MAX_NESTING_LEVEL)
                throw new \Triad\Exceptions\TriadException("Maximum request nesting level reached");

            if (!($this->router instanceof \Triad\Router))
                throw new \Triad\Exceptions\TriadException("Route is missing");

            $routeParams = array();
            $routeMatch = $this->router->match($request, $routeParams);

            switch ($routeMatch) {
                case RouteType::MVP:
                    $this->executePresenter($request, $routeParams);
                    break;
                case RouteType::SIMPLE:
                case RouteType::CALLBACK:
                    $this->executeHandler($request, $routeParams);
                    break;
                default:
                    throw new \Triad\Exceptions\NotFoundException("Unable to route: " . $request->path);
            }
        }
        catch(\Exception $e) {
            $this->handleException($e, $request);
        }
    }

    /**
     * Returns error data as array
     * @param \Exception $e
     * @param \Triad\Request|null $request
     * @return array
     */
    private function formatError(\Exception $e, $request = null) {
        // get some useful debugging info
        $debugInfo = array();
        if ($this->environment == ApplicationEnvironment::DEVELOPMENT) {
            $debugInfo = array("debug" => array(
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "code" => $e->getCode(),
                "trace" => array_map(function($item) {
                    // remove arguments from trace (they are causing recursion errors)
                    if (isset($item["args"]))
                        unset($item["args"]);
                    return $item;
                }, $e->getTrace()),
                "request" => ($request != null ? array(
                    "method" => $request->getMethod(),
                    "path" => $request->getPath(),
                    "params" => $request->getParams(),
                ) : array()),
            ));
        }

        // exception class name
        $class = get_class($e);
        $class = Utils::extractClassName($class);

        // return error data
        return array(
            "message" => $e->getMessage(),
            "type" => $class
        ) + $debugInfo;
    }

    /**
     * Get frienty exception details into request response
     * @param \Exception $e
     * @param Request $request
     */
    public function handleException(\Exception $e, Request $request) {
        $response = $request->getResponse();
        // make sure response is empty
        $response->clear();

        // default error code
        $httpErrorCode = \Triad\Requests\HttpStatusCode::INTERNAL_SERVER_ERROR;

        // check for http code override
        if ($e instanceof Exceptions\TriadException) {
            $httpErrorCode = $e->getHttpCode();
        }

        // only set http error code onlt if HttpResponse present
        if ($response instanceof \Triad\Responses\HttpResponse) {
            $response->setResponseCode($httpErrorCode);
        }

        // print user friendly error
        $response["error"] = $this->formatError($e, $request);
    }

    /**
     * Handle Exception occured during processing response
     * @param \Exception $e
     * @param Response $response
     */
    public function handleResponseException(\Exception $e, \Triad\Response $response) {
        $response->clear();

        if ($response instanceof \Triad\Responses\HttpResponse) {
            // make sure we output http error code so this will not get indexed
            $httpErrorCode = \Triad\Requests\HttpStatusCode::INTERNAL_SERVER_ERROR;
            $response->setResponseCode($httpErrorCode);
            $response->outputHeaders();

            // display mini error information
            print "<pre>";
            print str_repeat("-", 80) . "<br />";
            print "<strong>Error during processing response</strong><br />";
            print_r($this->formatError($e));
            print "</pre>";
        }
    }
}