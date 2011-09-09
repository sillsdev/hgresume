<?php

class HgResumeResponse {
	const SUCCESS = 0;
	const RECEIVED = 1;
	const RESEND = 2;
	const RESET = 3;
	const UNAUTHORIZED = 4;
	const FAIL = 5;

	var $code;
	var $returnValues;
	var $content;

	function __construct($code, $values = array(), $content = "") {
		$this->code = $code;
		$this->returnValues = $values;
		$this->content = $content;
	}
}

?>