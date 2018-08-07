<?php

namespace streaky\sag;

/**
 * Uses the PHP cURL bindings for HTTP communication with CouchDB. This gives
 * you more advanced features, like SSL supports, with the cost of an
 * additional dependency that your shared hosting environment might now have.
 *
 * @version %VERSION%
 * @package HTTP
 */
class curl {

	public $decodeResp = true;

	protected $host;

	protected $port;

	protected $proto = 'http'; //http or https

	protected $sslCertPath;

	protected $socketOpenTimeout;                 //The seconds until socket connection timeout

	protected $socketRWTimeoutSeconds;            //The seconds for socket I/O timeout

	protected $socketRWTimeoutMicroseconds;       //The microseconds for socket I/O timeout

	private $ch;

	private $followLocation; //whether cURL is allowed to follow redirects

	public function __construct($host = "127.0.0.1", $port = 5984) {

		$this->host = $host;
		$this->port = (int) $port;

		/*
		  * PHP doesn't like it if you tell cURL to follow location headers when
		  * open_basedir is set in PHP's configuration. Only check to see if it's
		  * set once so we don't ini_get() on every request.
		  */
		$this->followLocation = !ini_get('open_basedir');


	}

	/**
	 * Used by the concrete HTTP adapters, this abstracts out the generic task of
	 * turning strings from the net into response objects.
	 *
	 * @param string $response The body of the HTTP packet.
	 * @param string $method The request's HTTP method ("HEAD", etc.).
	 *
	 * @returns \stdClass The response object.
	 */
	protected function makeResult($response, $method) {
		//Make sure we got the complete response.
		if($method != 'HEAD' && isset($response->headers->{'content-length'}) && strlen($response->body) != $response->headers->{'content-length'}) {
			throw new exception\sag('Unexpected end of packet.');
		}

		/*
		  * HEAD requests can return an HTTP response code >=400, meaning that there
		  * was a CouchDB error, but we don't get a $response->body->error because
		  * HEAD responses don't have bodies.
		  *
		  * We do this before the json_decode() because even running json_decode()
		  * on undefined can take longer than calling it on a JSON string. So no
		  * need to run any of the $json code.
		  */
		if($method == 'HEAD') {
			if($response->status >= 400) {
				throw new exception\couch('HTTP/CouchDB error without message body', $response->headers->_HTTP->status);
			}

			return $response;
		}

		// Decode whether they ask for a raw response or not for error messages.
		if(!empty($response->headers->{'content-type'}) && $response->headers->{'content-type'} == 'application/json') {
			$json = json_decode($response->body);

			if(isset($json)) {
				if(!empty($json->error)) {
					$json->error = ucfirst($json->error);
					throw new exception\couch("{$json->error} (".trim($json->reason, "\t\n\r\0\x0B.").")", $response->headers->_HTTP->status);
				}

				if($this->decodeResp) {
					$response->body = $json;
				}
			}
		}

		return $response;
	}

	/**
	 * Whether to use HTTPS or not.
	 *
	 * @param bool $use Whether to use HTTPS or not.
	 */
	public function useSSL($use) {
		$this->proto = 'http' . (($use) ? 's' : '');
	}

	/**
	 * Sets the location of the CA file.
	 *
	 * @param mixed $path The absolute path to the CA file, or null to unset.
	 */
	public function setSSLCert($path) {
		$this->sslCertPath = $path;
	}

	/**
	 * Returns whether Sag is using SSL.
	 *
	 * @returns bool Returns true if the adapter is using SSL, else false.
	 */
	public function usingSSL() {
		return $this->proto === 'https';
	}

	/**
	 * Sets how long Sag should wait to establish a connection to CouchDB.
	 *
	 * @param int $seconds The number of seconds.
	 */
	public function setOpenTimeout($seconds) {
		if(!is_int($seconds) || $seconds < 1) {
			throw new exception\sag('setOpenTimeout() expects a positive integer.');
		}

		$this->socketOpenTimeout = $seconds;
	}

	/**
	 * Set how long we should wait for an HTTP request to be executed.
	 *
	 * @param int $seconds The number of seconds.
	 * @param int $microseconds The number of microseconds.
	 */
	public function setRWTimeout($seconds, $microseconds) {
		if(!is_int($microseconds) || $microseconds < 0) {
			throw new exception\sag('setRWTimeout() expects $microseconds to be an integer >= 0.');
		}

		//TODO make this better, including checking $microseconds
		//$seconds can be 0 if $microseconds > 0
		if(!is_int($seconds) || ((!$microseconds && $seconds < 1) || ($microseconds && $seconds < 0))
		) {
			throw new exception\sag('setRWTimeout() expects $seconds to be a positive integer.');
		}

		$this->socketRWTimeoutSeconds = $seconds;
		$this->socketRWTimeoutMicroseconds = $microseconds;
	}

	/**
	 * Returns an associative array of the currently set timeout values.
	 *
	 * @return array An associative array with the keys 'open', 'rwSeconds', and
	 * 'rwMicroseconds'.
	 *
	 * @see setTimeoutsFromArray()
	 */
	public function getTimeouts() {
		return array('open' => $this->socketOpenTimeout, 'rwSeconds' => $this->socketRWTimeoutSeconds, 'rwMicroseconds' => $this->socketRWTimeoutMicroseconds);
	}

	/**
	 * A utility function that sets the different timeout values based on an
	 * associative array.
	 *
	 * @param array $arr An associative array with the keys 'open', 'rwSeconds', and 'rwMicroseconds'.
	 *
	 * @see getTimeouts()
	 */
	public function setTimeoutsFromArray($arr) {
		/*
		  * Validation is lax in here because this should only ever be used with
		  * getTimeouts() return values. If people are using it by hand then there
		  * might be something wrong with the API.
		  */
		if(!is_array($arr)) {
			throw new exception\sag('Expected an array and got something else.');
		}

		if(is_int($arr['open'])) {
			$this->setOpenTimeout($arr['open']);
		}

		if(is_int($arr['rwSeconds'])) {
			if(is_int($arr['rwMicroseconds'])) {
				$this->setRWTimeout($arr['rwSeconds'], $arr['rwMicroseconds']);
			} else {
				$this->setRWTimeout($arr['rwSeconds'], 0);
			}
		}
	}

	/**
	 * A utility function for the concrete adapters to turn the HTTP Cookie
	 * header's value into an object (map).
	 *
	 * @param string $cookieStr The HTTP Cookie header value (not including the
	 * "Cookie: " key.
	 *
	 * @returns \stdClass An object mapping cookie name to cookie value.
	 */
	protected function parseCookieString($cookieStr) {
		$cookies = new \stdClass();

		foreach(explode('; ', $cookieStr) as $cookie) {
			$crumbs = explode('=', $cookie);
			if(!isset($crumbs[1])) {
				$crumbs[1] = '';
			}
			$cookies->{trim($crumbs[0])} = trim($crumbs[1]);
		}

		return $cookies;
	}

	/**
	 * Processes the packet, returning the server's response.
	 *
	 * @param string $method The HTTP method for the request (ex., "HEAD").
	 * @param string $url The URL to hit, not including the host info (ex.,
	 * "/_all_docs").
	 * @param string $data A serialized version of any data that needs to be sent
	 * in the packet's body.
	 * @param array $reqHeaders An associative array of headers where the keys
	 * are the header names.
	 * @param mixed $specialHost Uses the provided host for this packet only -
	 * does not change the adapter's global host setting.
	 * @param mixed $specialPort Uses the provided port for this packet only -
	 * does not change the adapter's global port setting.
	 *
	 * @returns \stdClass The response object created by makeResponse().
	 * @see makeResponse()
	 */
	public function procPacket($method, $url, $data = null, $reqHeaders = array(), $specialHost = null, $specialPort = null) {

		$this->ch = curl_init();

		// the base cURL options
		$opts = array(
			CURLOPT_URL => "{$this->proto}://{$this->host}:{$this->port}{$url}",
			CURLOPT_PORT => $this->port,
			CURLOPT_FOLLOWLOCATION => $this->followLocation,
			CURLOPT_HEADER => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY => false,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method
		);

		$opts[CURLOPT_HTTPHEADER] = array();
		$opts[CURLOPT_HTTPHEADER][] = "Connection: close";

		// cURL wants the headers as an array of strings, not an assoc array
		if(is_array($reqHeaders) && sizeof($reqHeaders) > 0) {

			foreach($reqHeaders as $k => $v) {
				$opts[CURLOPT_HTTPHEADER][] = "$k: $v";
			}
		}

		// send data through cURL's poorly named opt
		if($data) {
			$opts[CURLOPT_POSTFIELDS] = $data;
		}

		// special considerations for HEAD requests
		if($method == 'HEAD') {
			$opts[CURLOPT_NOBODY] = true;
		}

		// connect timeout
		if(is_int($this->socketOpenTimeout)) {
			$opts[CURLOPT_CONNECTTIMEOUT] = $this->socketOpenTimeout;
		}

		// exec timeout (seconds)
		if(is_int($this->socketRWTimeoutSeconds)) {
			$opts[CURLOPT_TIMEOUT] = $this->socketRWTimeoutSeconds;
		}

		// exec timeout (ms)
		if(is_int($this->socketRWTimeoutMicroseconds)) {
			$opts[CURLOPT_TIMEOUT_MS] = $this->socketRWTimeoutMicroseconds;
		}

		// SSL support: don't verify unless we have a cert set
		if($this->proto === 'https') {
			if(!$this->sslCertPath) {
				$opts[CURLOPT_SSL_VERIFYPEER] = false;
			} else {
				$opts[CURLOPT_SSL_VERIFYPEER] = true;
				$opts[CURLOPT_SSL_VERIFYHOST] = 2;
				$opts[CURLOPT_CAINFO] = $this->sslCertPath;
			}
		}

		curl_setopt_array($this->ch, $opts);

		$chResponse = curl_exec($this->ch);

		if($chResponse !== false) {
			// prepare the response object
			$response = new \stdClass();
			$response->headers = new \stdClass();
			$response->headers->_HTTP = new \stdClass();
			$response->body = '';

			// split headers and body
			list($respHeaders, $response->body) = explode("\r\n\r\n", $chResponse, 2);

			// split up the headers
			$respHeaders = explode("\r\n", $respHeaders);

			for($i = 0; $i < sizeof($respHeaders); $i++) {
				// first element will always be the HTTP status line
				if($i === 0) {
					$response->headers->_HTTP->raw = $respHeaders[$i];

					preg_match('(^HTTP/(?P<version>\d+\.\d+)\s+(?P<status>\d+))S', $respHeaders[$i], $match);

					$response->headers->_HTTP->version = $match['version'];
					$response->headers->_HTTP->status = $match['status'];
					$response->status = $match['status'];
				} else {
					$line = explode(':', $respHeaders[$i], 2);
					$line[0] = strtolower($line[0]);
					$response->headers->{$line[0]} = ltrim($line[1]);

					if($line[0] == 'set-cookie') {
						$response->cookies = $this->parseCookieString($line[1]);
					}
				}
			}
		} else if(curl_errno($this->ch)) {

			$enum = curl_errno($this->ch);
			$emes = curl_error($this->ch);
			curl_close($this->ch);

			switch($enum) {
				case 7:
					throw new exception\sag('cURL Error: Connection Refused', 7);
				break;
				default :
					throw new exception\sag("cURL error: {$emes}", $enum);
			}

		} else {
			curl_close($this->ch);
			throw new exception\sag('cURL returned false without providing an error.');
		}

		// in the event cURL can't follow and we got a Location header w/ a 3xx
		if(!$this->followLocation && isset($response->headers->location) && $response->status >= 300 && $response->status < 400) {
			$parts = parse_url($response->headers->location);

			if(empty($parts['path'])) {
				$parts['path'] = '/';
			}

			$adapter = $this->makeFollowAdapter($parts);

			// we want the old headers (ex., Auth), but might need a new Host
			if(isset($parts['host'])) {
				$reqHeaders['Host'] = $parts['host'];

				if(isset($parts['port'])) {
					$reqHeaders['Host'] .= ':' . $parts['port'];
				}
			}

			return $adapter->procPacket($method, $parts['path'], $data, $reqHeaders);
		}

		curl_close($this->ch);
		return self::makeResult($response, $method);
	}

	/**
	 * Used when we need to create a new adapter to follow a redirect because
	 * cURL can't.
	 *
	 * @param array $parts Return value from url_parts() for the location header.
	 *
	 * @return curl Returns $this if talking to the same server
	 * with the same protocol, otherwise creates a new instance.
	 */
	private function makeFollowAdapter($parts) {
		// re-use $this if we just got a path or the host/proto info matches
		if(empty($parts['host']) || ($parts['host'] == $this->host && $parts['port'] == $this->port && $parts['scheme'] == $this->proto)
		) {
			return $this;
		}

		if(empty($parts['port'])) {
			$parts['port'] = ($parts['scheme'] == 'https') ? 443 : 5984;
		}

		$adapter = new self($parts['host'], $parts['port']);
		$adapter->useSSL($parts['scheme'] == 'https');
		$adapter->setTimeoutsFromArray($this->getTimeouts());

		return $adapter;
	}
}
