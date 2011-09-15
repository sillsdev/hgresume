<?php

require_once("HgResumeResponse.php");

class RestServer {
	public $url;
	public $args;
	public $postData;
	public $debug;

	var $api;

	function __construct($apiInstance, $debug = false) {
		$this->api = $apiInstance;
		$this->debug = $debug;
	}

	function handle() {

		// get method name
		$urlparsed = parse_url($this->url);
		$path = pathinfo($urlparsed['path']);
		$methodName = $path['basename'];

		// add postData to $args
		$args['postData'] = $this->postData;

		if (!method_exists($this->api, $methodName)) {
			RestServer::serverError("Unknown method '$methodName'");
		}

		$orderedParams = RestServer::buildOrderedParamArray($methodName, get_class($this->api), $this->args);
		$response = call_user_func_array(array($this->api, $methodName), $orderedParams);

		$this->_sendResponse($response);
	}

	static function buildOrderedParamArray($method, $className, $args) {
		$classReflection = new ReflectionClass($className);
		$parameters = $classReflection->getMethod($method)->getParameters();
		$argsToReturn = array();

		// loop over each required parameter in the API function
		// add web args in the order that the API function requires
		foreach ($parameters as $p) {
			$pName = $p->name;
			if ($pName == 'chunkData') {
				$pName = 'postData'; // special case to map postData -> chunkData param
			} elseif (!array_key_exists($pName, $args)) {
				RestServer::serverError("param $pName is required for method $method");
			}
			array_push($argsToReturn, $args[$pName]);
		}
		return $argsToReturn;
	}

	static function serverError($msg) {
		header("HTTP/1.1 200 OK");
		header("Content-type: text/plain");
		print($msg);
		exit();
	}

	function _sendResponse($response) {
		list($httpCode, $hgrStatus) = $this->_mapHgResponse($response->Code);

		$headers = array();

		// send HTTP status code
		array_push($headers, "HTTP/1.1 " . $httpCode);

		// send Version
		array_push($headers, "X-HgR-Version: " . $response->Version);

		// send an X-HgR-Status header
		array_push($headers, "X-HgR-Status: $hgrStatus");

		// send Key/Value headers
		if (!is_null($response->Values)) {
			foreach ($response->Values as $key => $value) {
				array_push($headers, "X-HgR-" . ucfirst($key) . ": $value");
			}
		}

		if (!$this->debug) {
			// regular mode
			foreach ($headers as $header) {
				header($header);
			}
			header("Content-type: text/plain\n\n");
		} else {
			// debug mode
			header("Content-type: text/plain\n\n");
			foreach ($headers as $header) {
				print($header . "\n");
			}

		}
		print($response->Content);
	}


	function _mapHgResponse($hgrCode) {
		$httpcode = 0;
		$codeString = '';
		// map resume responsecode to http status code
		switch ($hgrCode) {
			case HgResumeResponse::SUCCESS:
				$httpcode = "200 OK";
				$codeString = 'Success';
				break;
			case HgResumeResponse::RECEIVED:
				$httpcode = "202 Accepted";
				$codeString = 'Received';
				break;
			case HgResumeResponse::RESEND:
				$httpcode = "412 Precondition Failed";
				$codeString = 'Resend';
				break;
			case HgResumeResponse::RESET:
				$httpcode = "400 Bad Request";
				$codeString = 'Reset';
				break;
			case HgResumeResponse::UNAUTHORIZED:
				$httpcode = "401 Unauthorized";
				$codeString = 'Unauthorized';
				break;
			case HgResumeResponse::FAIL:
				$httpcode = "400 Bad Request";
				$codeString = 'Fail';
				break;
			case HgResumeResponse::UNKNOWNID:
				$httpcode = "400 Bad Request";
				$codeString = 'UnknownID';
				break;
			case HgResumeResponse::NOCHANGE:
				$httpcode = "304 Not Modified";
				$codeString = 'NoChange';
				break;
			default:
				throw new Exception("Unknown response code {$response->Code}");
				break;
		}
		return array($httpcode, $codeString);
	}


}

?>