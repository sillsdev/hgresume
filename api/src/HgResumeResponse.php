<?php

class HgResumeResponse {
	const SUCCESS = 0;
	const RECEIVED = 1;
	const RESEND = 2;
	const RESET = 3;
	const UNAUTHORIZED = 4;
	const FAIL = 5;
	const UNKNOWNID = 6;

	var $Code;
	var $Values;
	var $Content;

	function __construct($code, $values = array(), $content = "") {
		$this->Code = $code;
		$this->Values = $values;
		$this->Content = $content;
	}
}

?>