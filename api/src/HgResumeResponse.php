<?php

class HgResumeResponse {
	/* SUCCESS
	 * Operation completed successfully.
	 * In the context of a PushDataChunk, this indicates that the final chunk
	 *      was received successfully and the bundle was successfully applied.
	 * No further information is expected. */
	const SUCCESS = 0;

	/* RECEIVED
	 * Data was received and stored successfully.
	 * In the context of PushDataChunk, further *push* requests are expected. */
	const RECEIVED = 1;

	/* RESEND
	 * Data was received but did not validate with its checksum. */
	const RESEND = 2;

	/* RESET
	 * Data was received but the operation failed.
	 * In the context of PushDataChunk, all chunks were received, but
	 *      applying the bundle to the repo failed.  The operation failed
	 *      and the sender must start over sending the bundle.
	 * Resending the bundle is encouraged. */
	const RESET = 3;

	/* UNAUTHORIZED
	 * Invalid/missing username/password credentials were supplied. */
	const UNAUTHORIZED = 4;

	/* FAIL
	 * The operation failed because one more more parameters are invalid or
	 * were not understood.
	 * The request should NOT be repeated with the same parameter values. */
	const FAIL = 5;

	/* UNKNOWNID
	 * The operation failed because the repoId is unknown among the
	 * repositories on the server. */
	const UNKNOWNID = 6;

	/* NOCHANGE
	 * Request received but no operation was performed on the server
	 * In the context of PullDataChunk, this means that the baseHash provided
	 * resulted in no changesets to bundle for a Pull */
	const NOCHANGE = 7;

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