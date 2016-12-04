<?php
/**
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @copyright Copyright (c) 2013-2016 Alexander Weissman
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/licenses/UserFrosting.md (MIT License)
 */
namespace UserFrosting\Sprinkle\Core\Handler;

use Interop\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Http\Body;

/**
 * Default UserFrosting application error handler
 *
 * It outputs the error message and diagnostic information in either JSON, XML, or HTML based on the Accept header.
 * @author Alex Weissman (https://alexanderweissman.com) 
 */
class CoreErrorHandler extends \Slim\Handlers\Error
{
    /**
     * @var ContainerInterface The global container object, which holds all your services.
     */
    protected $ci;
    
    /**
     * @var array[string] An array that maps Exception types to callbacks, for special processing of certain types of errors.
     */
    protected $exceptionHandlers = [];
    
    /**
     * Constructor
     *
     * @param ContainerInterface $ci The global container object, which holds all your services.
     * @param boolean $displayErrorDetails Set to true to display full details
     */
    public function __construct(ContainerInterface $ci, $displayErrorDetails = false)
    {
        $this->ci = $ci;
        $this->displayErrorDetails = (bool)$displayErrorDetails;
    }
        
    /**
     * Invoke error handler
     *
     * @param ServerRequestInterface $request   The most recent Request object
     * @param ResponseInterface      $response  The most recent Response object
     * @param Exception              $exception The caught Exception object
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, \Exception $exception)
    {
        // If displayErrorDetails is set to true, and we're not processing an AJAX request with AJAX debug mode turned off, render a debugging error page
        if ($this->displayErrorDetails && (!$request->isXhr() || $this->ci->config['site.debug.ajax'])) {
            return $this->getDebugResponse($request, $response, $exception);
        }
        
        // Default exception handler class
        $handlerClass = '\UserFrosting\Sprinkle\Core\Handler\ExceptionHandler';

        // Get the last matching registered handler class, and instantiate it
        foreach ($this->exceptionHandlers as $exceptionClass => $matchedHandlerClass) {
            if ($exception instanceof $exceptionClass) {
                $handlerClass = $matchedHandlerClass;
            }
        }

        $handler = new $handlerClass($this->ci);

        // Run either the ajaxHandler or standardHandler, depending on the request type
        if (!$request->isXhr() || $this->ci->config['site.debug.ajax']) {
            $response = $handler->standardHandler($request, $response, $exception);
        } else {
            $response = $handler->ajaxHandler($request, $response, $exception);
        }

        // Write exception to log, if enabled by the handler
        if ($handler->getLogFlag()) {
            $this->writeToErrorLog($exception);
        }

        return $response;
    }

    /**
     * Generates an HTML/XML/JSON representation of the error/exception, and appends it to the response.
     *
     * Note that this should only be used for debugging purposes.  In production, it could reveal sensitive information and/or vulnerabilities.
     * @param ServerRequestInterface $request   The most recent Request object
     * @param ResponseInterface      $response  The most recent Response object
     * @param Exception              $exception The caught Exception object
     *
     * @return ResponseInterface
     */
    public function getDebugResponse(ServerRequestInterface $request, ResponseInterface $response, \Exception $exception)
    {
        $contentType = $this->determineContentType($request);
        switch ($contentType) {
            case 'application/json':
                $output = $this->renderJsonErrorMessage($exception);
                break;

            case 'text/xml':
            case 'application/xml':
                $output = $this->renderXmlErrorMessage($exception);
                break;

            case 'text/html':
                $output = $this->renderHtmlErrorMessage($exception);
                break;

            default:
                throw new UnexpectedValueException('Cannot render unknown content type ' . $contentType);
        }

        $body = new Body(fopen('php://temp', 'r+'));
        $body->write($output);

        return $response
                ->withStatus(500)
                ->withHeader('Content-type', $contentType)
                ->withBody($body);
    }

    /**
     * Register an exception handler for a specified exception class.
     *
     * The exception handler must implement \UserFrosting\Sprinkle\Core\Handler\ExceptionHandlerInterface.
     *
     * @param string $exceptionClass The fully qualified class name of the exception to handle.
     * @param string $handlerClass The fully qualified class name of the assigned handler.
     * @throws InvalidArgumentException If the registered handler fails to implement ExceptionHandlerInterface
     */
    public function registerHandler($exceptionClass, $handlerClass)
    {
        if (!is_a($handlerClass, '\UserFrosting\Sprinkle\Core\Handler\ExceptionHandlerInterface', true)) {
            throw new \InvalidArgumentException("Registered exception handler must implement ExceptionHandlerInterface!");
        }

        $this->exceptionHandlers[$exceptionClass] = $handlerClass;
    }    

    /**
     * Alternative logging for errors
     *
     * @param $message
     */
    protected function logError($message)
    {
        $this->ci->errorLogger->error($message);
    }    
}