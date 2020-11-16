<?php

namespace Lib;

class RestServer {
    public function __construct($apiInstance, $debug = false) {
        $this->api = $apiInstance;
        $this->debug = $debug;
        $this->args = array();
        $this->url = "";
        $this->postData = "";
    }

    public $url;
    public $args;
    public $postData;
    public $debug;

    private $api;

    public function handle() {

        // get method name
        $urlparsed = parse_url($this->url);
        $path = pathinfo($urlparsed['path']);
        $methodName = $path['basename'];

        // add postData to $args, if not already provided via GET
        if (!array_key_exists("postData", $this->args)) {
            $this->args['postData'] = $this->postData;
        }

        if (!method_exists($this->api, $methodName)) {
            RestServer::serverError("Unknown method '$methodName'");
        }

        $orderedParams = RestServer::buildOrderedParamArray($methodName, get_class($this->api), $this->args);
        $response = call_user_func_array(array($this->api, $methodName), $orderedParams);

        RestServer::sendResponse($response, $this->debug);
    }

    private static function buildOrderedParamArray($method, $className, $args) {
        $classReflection = new \ReflectionClass($className);
        $parameters = $classReflection->getMethod($method)->getParameters();
        $argsToReturn = array();

        // loop over each required parameter in the API function
        // add web args in the order that the API function requires
        foreach ($parameters as $p) {
            $pName = $p->name;
            if ($pName == 'data') {
                $pName = 'postData'; // special case to map postData -> data param
            } elseif (!array_key_exists($pName, $args)) {
                RestServer::serverError("param $pName is required for method $method");
            }
            array_push($argsToReturn, $args[$pName]);
        }
        return $argsToReturn;
    }

    private static function serverError($msg) {
        $response = new HgResumeResponse(HgResumeResponse::FAIL, array('Error' => $msg), $msg);
        RestServer::sendResponse($response, false);
        exit();
    }

    private static function sendResponse($response, $debug) {
        list($httpCode, $hgrStatus) = RestServer::mapHgResponse($response->Code);

        $headers = array();
        array_push($headers, "HTTP/1.1 " . $httpCode);

        $contentSize = mb_strlen($response->Content, "8bit");
        if ($contentSize > 0) {
            array_push($headers, "Content-Length: $contentSize");
        }

        array_push($headers, "X-HgR-Version: " . $response->Version);
        array_push($headers, "X-HgR-Status: $hgrStatus");

        if (!is_null($response->Values)) {
            foreach ($response->Values as $key => $value) {
                array_push($headers, "X-HgR-" . ucfirst($key) . ": $value");
            }
        }


        if (!$debug) {
            // regular mode
            header("Content-Type: application/octet-stream");
            foreach ($headers as $header) {
                header($header);
            }
        } else {
            // debug mode
            header("Content-Type: text/plain");
            foreach ($headers as $header) {
                print($header);
            }

        }
        if ($contentSize > 0) {
            print($response->Content);
        }
    }

    private static function mapHgResponse($hgrCode) {
        // map resume responsecode to http status code
        switch ($hgrCode) {
            case HgResumeResponse::SUCCESS:
                $httpCode = "200 OK";
                $codeString = 'SUCCESS';
                break;
            case HgResumeResponse::RECEIVED:
                $httpCode = "202 Accepted";
                $codeString = 'RECEIVED';
                break;
            case HgResumeResponse::RESET:
                $httpCode = "400 Bad Request";
                $codeString = 'RESET';
                break;
            case HgResumeResponse::UNAUTHORIZED:
                $httpCode = "401 Unauthorized";
                $codeString = 'UNAUTHORIZED';
                break;
            case HgResumeResponse::FAIL:
                $httpCode = "400 Bad Request";
                $codeString = 'FAIL';
                break;
            case HgResumeResponse::UNKNOWNID:
                $httpCode = "400 Bad Request";
                $codeString = 'UNKNOWNID';
                break;
            case HgResumeResponse::NOCHANGE:
                $httpCode = "304 Not Modified";
                $codeString = 'NOCHANGE';
                break;
            case HgResumeResponse::NOTAVAILABLE:
                $httpCode = "503 Service Unavailable";
                $codeString = 'NOTAVAILABLE';
                break;
            case HgResumeResponse::INPROGRESS:
                $httpCode = "202 Accepted";
                $codeString = 'INPROGRESS';
                break;
            default:
                throw new \Exception("Unknown response code $hgrCode");
                break;
        }
        return array($httpCode, $codeString);
    }

}
