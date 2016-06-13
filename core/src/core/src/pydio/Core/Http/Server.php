<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pydio\Core\Exception\PydioException;

use Pydio\Core\Http\Message\PromptMessage;
use Pydio\Core\Http\Message\UserMessage;
use Pydio\Core\Http\Middleware\ITopLevelMiddleware;
use Pydio\Core\Http\Middleware\SapiMiddleware;
use Pydio\Core\Http\Response\SerializableResponseChunk;
use Pydio\Core\Http\Response\SerializableResponseStream;
use Pydio\Core\Model\Context;
use Pydio\Log\Core\AJXP_Logger;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequestFactory;

defined('AJXP_EXEC') or die('Access not allowed');

class Server
{
    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * @var \SplStack
     */
    protected $middleWares;

    /**
     * @var ITopLevelMiddleware
     */
    protected $topMiddleware;

    /**
     * @var string
     */
    protected $base;

    /**
     * @var \SplStack
     */
    protected static $middleWareInstance;

    public function __construct($base){

        $this->middleWares = new \SplStack();
        $this->middleWares->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);

        $this->base = $base;

        $this->stackMiddleWares();

        self::$middleWareInstance = &$this->middleWares;
        
    }

    protected function stackMiddleWares(){

        $this->middleWares->push(array("Pydio\\Core\\Controller\\Controller", "registryActionMiddleware"));
        $this->middleWares->push(array("Pydio\\Core\\Http\\Middleware\\SessionRepositoryMiddleware", "handleRequest"));
        $this->middleWares->push(array("Pydio\\Core\\Http\\Middleware\\AuthMiddleware", "handleRequest"));
        $this->middleWares->push(array("Pydio\\Core\\Http\\Middleware\\SecureTokenMiddleware", "handleRequest"));
        $this->middleWares->push(array("Pydio\\Core\\Http\\Middleware\\SessionMiddleware", "handleRequest"));

        $topMiddleware = new SapiMiddleware();
        $this->topMiddleware = $topMiddleware;
        $this->middleWares->push(array($topMiddleware, "handleRequest"));

    }

    public function registerCatchAll(){
        if (is_file(TESTS_RESULT_FILE)) {
            set_error_handler(array($this, "catchError"), E_ALL & ~E_NOTICE & ~E_STRICT );
            set_exception_handler(array($this, "catchException"));
        }
    }

    public function getRequest(){
        if(!isSet($this->request)){
            $this->request = $this->initServerRequest();
        }
        return $this->request;
    }

    public function updateRequest(ServerRequestInterface $request){
        $this->request = $request;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function nextCallable(&$request, &$response){
        if($this->middleWares->valid()){
            $callable = $this->middleWares->current();
            $this->middleWares->next();
            list ($request, $response) = call_user_func_array($callable, array($request, $response, function($req, $res){
                return $this->nextCallable($req, $res);
            }));
        }
        return [$request, $response];
    }

    

    /**
     * To be used by middlewares
     * @param ServerRequestInterface $requestInterface
     * @param ResponseInterface $responseInterface
     * @param callable|null $next
     * @return ResponseInterface
     */
    public static function callNextMiddleWare(ServerRequestInterface $requestInterface, ResponseInterface $responseInterface, callable $next = null){
        if($next !== null){
            $responseInterface = call_user_func_array($next, array($requestInterface, $responseInterface));
        }
        return $responseInterface;
    }

    /**
     * @param callable $comparisonFunction
     * @param ServerRequestInterface $requestInterface
     * @param ResponseInterface $responseInterface
     * @param callable|null $next
     * @return ResponseInterface
     */
    public static function callNextMiddleWareAndRewind(callable $comparisonFunction, ServerRequestInterface $requestInterface, ResponseInterface $responseInterface, callable $next = null){
        if($next !== null){
            $responseInterface = call_user_func_array($next, array($requestInterface, $responseInterface));
        }
        self::$middleWareInstance->rewind();
        while(!$comparisonFunction(self::$middleWareInstance->current())){
            self::$middleWareInstance->next();
        }
        self::$middleWareInstance->next();
        return $responseInterface;
    }


    public function addMiddleware(callable $middleWareCallable){
        $this->middleWares->push($middleWareCallable);
        self::$middleWareInstance = $this->middleWares;
    }

    public function listen(){
        $response = new Response();
        $this->middleWares->rewind();
        $request = $this->getRequest();
        list ($request, $response) = $this->nextCallable($request, $response);
        return [$request, $response];
    }

    /**
     * @param bool $rest
     * @return ServerRequestInterface
     */
    protected function initServerRequest($rest = false){

        $request = ServerRequestFactory::fromGlobals();
        $request = $request->withAttribute("ctx", Context::emptyContext());
        return $request;

    }

    /**
     * Error Catcher for PHP errors. Depending on the SERVER_DEBUG config
     * shows the file/line info or not.
     * @static
     * @param $code
     * @param $message
     * @param $fichier
     * @param $ligne
     * @param $context
     */
    public function catchError($code, $message, $fichier, $ligne, $context)
    {
        if(error_reporting() == 0) {
            return ;
        }
        AJXP_Logger::error(basename($fichier), "error l.$ligne", array("message" => $message));
        if(AJXP_SERVER_DEBUG){
            if($context instanceof  \Exception){
                $message .= $context->getTraceAsString();
            }else{
                $message .= PydioException::buildDebugBackTrace();
            }
        }
        $req = $this->getRequest();
        $resp = new Response();
        $x = new SerializableResponseStream();
        $resp = $resp->withBody($x);
        $x->addChunk(new UserMessage($message, LOG_LEVEL_ERROR));
        $this->topMiddleware->emitResponse($req, $resp);
        
    }

    /**
     * Catch exceptions, @see catchError
     * @param \Exception $exception
     */
    public function catchException($exception)
    {
        if($exception instanceof SerializableResponseChunk){

            $req = $this->getRequest();
            $resp = new Response();
            $x = new SerializableResponseStream();
            $resp = $resp->withBody($x);
            $x->addChunk($exception);
            $this->topMiddleware->emitResponse($req, $resp);
            return;
        }

        try {
            $this->catchError($exception->getCode(), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception);
        } catch (\Exception $innerEx) {
            error_log(get_class($innerEx)." thrown within the exception handler!");
            error_log("Original exception was: ".$innerEx->getMessage()." in ".$innerEx->getFile()." on line ".$innerEx->getLine());
            error_log("New exception is: ".$innerEx->getMessage()." in ".$innerEx->getFile()." on line ".$innerEx->getLine()." ".$innerEx->getTraceAsString());
            print("Error");
        }
    }


}