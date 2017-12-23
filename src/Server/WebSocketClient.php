<?php

	/*********************************************/
	/* Copyright (c) 2017 bbmk IT solutions gmbh */
	/*            All rights reserved            */
	/*                                           */
	/* URL: http://bbmk.at                       */
	/* Email: office@bbmk.at                     */
	/*                                           */
	/* @author Bernhard Bauer                    */
	/*********************************************/

	namespace bbauer\CamToWeb\Server;

	class WebSocketClient {

		private $host = null;
		private $port = null;
		private $_socket = null;
		private $connected = false;

		public function __construct($host, $port) {
			$this->host = $host;
			$this->port = $port;

			$this->connected = $this->_connect($this->host, $this->port);
		}

		public function __destruct() {
			$this->_disconnect();
			$this->connected = false;
		}

		/**
		 * Tries to send a message to the websocket server
		 *
		 * @param string $data the data s´which should be sent to the websocket server
		 * @return string|false returns false in case of an error, otherwise it returns the data which has been sent by the websocket server as reply
		 */
		public function sendData($data) {
			if ($this->_write($data)) {
				stream_set_timeout($this->_socket, 2); // Set a read timeout of 2 seconds
				$read = $this->_read();

				// If there is some data return it
				if ($read !== null) {
					return $read;
				}
			}

			return false;
		}

		/**
		 * Tries to connect to a websocket server
		 *
		 * @param string $host the host of the websocket server
		 * @param int $port the port on which the websocket server is hosted
		 * @return bool true if the connection has been established, otherwise false
		 */
		private function _connect($host, $port) {
			// If no host or port has been given abort connection request
			if ($host === null || $port === null) {
				return false;
			}

			$header = "GET / HTTP/1.1\r\n";
			$header .= "Connection: Upgrade\r\n";
			$header .= "Upgrade: websocket\r\n";
			$header .= "Host: ".$host.":".$port."\r\n";
			$header .= "Origin: http://localhost\r\n";
			$header .= "Sec-WebSocket-Key: ".$this->generateKey()."\r\n";
			$header .= "Sec-WebSocket-Version: 13\r\n";
			$header .= "User-Agent: bbmk-php-client\r\n";
			$header .= "\r\n";

			// Try to open the socket and send the upgrade header
			$this->_socket = @fsockopen($host, $port, $errno, $errstr, 2);
			if ($this->_socket !== false) {
				if (@fwrite($this->_socket, $header) !== false) {
					return true;

					// stream_set_timeout($this->_socket, 2); // Set a read timeout of 2 seconds
					// $response_string = fread($this->_socket, 2000);
					// $response_array = explode("\r\n", $response_string);
					//
					// $status_code = 500;
					// $headers = array();
					// $content = null;
					// $version = '1.1';
					//
					// // Parse the response headers
					// foreach ($response_array as $key => $value) {
					// 	// Retreive http status code and protocol
					// 	if (strpos($value, 'HTTP') === 0) {
					// 		$http_info = explode(' ', $value);
					// 		$status_code = $http_info[1];
					// 		$version = explode('/', $http_info[0], 2)[1];
					// 	} else if ($value != "") {
					// 		$header_parts = explode(': ', $value, 2);
					// 		$headers[$header_parts[0]] = $header_parts[1];
					// 	} else {
					// 		break;
					// 	}
					// }
					//
					// // Retreive the content
					// $content = substr($response_array[count($response_array)-1], 4);
					// // Create a response object
					// $response = new Response($status_code, $headers, $content, $version);
					//
					// // Verifiy the request and response
					// // If successfully verified return true, as the connection has been established
					// $verifier = new ResponseVerifier();
					// if ($verifier->verifyAll($request, $response)) {
					// 	return true;
					// } else {
					// 	// If the connection could not be verified disconnect and return false
					// 	$this->_disconnect();
					// 	return false;
					// }
				}
			}

			return false;
		}

		/**
		 * Write a chunk of data through the websocket, using hybi10 frame encoding
		 *
		 * @param string data the data to transport to the server
		 * @param boolean final indicate if this block is the final data block of this request (default true)
		 * @return int|false returns an integer with the number of bytes written or false in case of an error
		 */
		function _write($data, $final = true) {
			if (!$this->connected) {
				$this->connected = $this->_connect($this->host, $this->port);
			}

			if ($this->connected) {
				// Assamble header: FINal 0x80 | Opcode 0x02
				$header=chr(($final?0x80:0) | 0x02); // 0x02 binary
				// Mask 0x80 | payload length (0-125)
				if(strlen($data)<126) $header.=chr(0x80 | strlen($data));
				elseif (strlen($data)<0xFFFF) $header.=chr(0x80 | 126) . pack("n",strlen($data));
				else $header.=chr(0x80 | 127) . pack("N",0) . pack("N",strlen($data));
				// Add mask
				$mask=pack("N",rand(1,0x7FFFFFFF));
				$header.=$mask;

				// Mask application data.
				for($i = 0; $i < strlen($data); $i++) {
					$data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));
				}

				try {
					return fwrite($this->_socket, $header.$data);
				} catch (\Exception $e) {
					$this->_disconnect();
					return false;
				}

			}

			return false;
		}




		/*============================================================================*\
		  Read from websocket
		  string websocket_read(resource $handle [,string &error_string])

		  read a chunk of data from the server, using hybi10 frame encoding

		  handle
			the resource handle returned by websocket_open, if successful
		  error_string (optional)
			A referenced variable to store error messages, i any
		  Read


		\*============================================================================*/
		/**
		 * Read a chunk of data from the server, using hybi10 frame encoding
		 * Note:
		 *     - This implementation waits for the final chunk of data, before returning
		 *     - Reading data while handling/ignoring other kind of packages
		 *
		 * @param string error_string an optional variable which will return an error message if there has been an error
		 * @return string|null returns the data if sucessfully read or null if the client is not connected
		 */
		function _read(&$error_string = null) {
			if (!$this->connected) {
				$this->connected = $this->_connect($this->host, $this->port);
			}

			if ($this->connected) {
				$data = "";
				do {
					// Read header
					$header=fread($this->_socket,2);
					if(!$header) {
						$error_string = "Reading header from websocket failed.";
						return false;
					}
					$opcode = ord($header[0]) & 0x0F;
					$final = ord($header[0]) & 0x80;
					$masked = ord($header[1]) & 0x80;
					$payload_len = ord($header[1]) & 0x7F;

					// Get payload length extensions
					$ext_len = 0;
					if($payload_len >= 0x7E) {
						$ext_len = 2;
						if($payload_len == 0x7F) $ext_len = 8;
							$header=fread($this->_socket, $ext_len);
						if(!$header) {
							$error_string = "Reading header extension from websocket failed.";
							return false;
						}

						// Set extented paylod length
						$payload_len= 0;
						for($i=0; $i<$ext_len; $i++) {
							$payload_len += ord($header[$i]) << ($ext_len-$i-1)*8;
						}
					}

					// Get Mask key
					if($masked) {
						$mask = fread($this->_socket, 4);
						if(!$mask) {
							$error_string = "Reading header mask from websocket failed.";
							return false;
						}
					}

					// Get payload
					$frame_data='';
					do {
						$frame= fread($this->_socket, $payload_len);
						if(!$frame) {
							$error_string = "Reading from websocket failed.";
							return false;
						}
						$payload_len -= strlen($frame);
						$frame_data .= $frame;
					} while($payload_len > 0);
					// Handle ping requests (sort of) send pong and continue to read
					if($opcode == 9) {
						try {
							// Assamble header: FINal 0x80 | Opcode 0x0A + Mask on 0x80 with zero payload
							fwrite($this->_socket,chr(0x8A) . chr(0x80) . pack("N", rand(1,0x7FFFFFFF)));
						} catch (\Exception $e) {
							// As nothing can be written to the socket assume the connection has been lost and disconnect
							$error_string = "Could not send reply to ping request.";
							$this->_disconnect();
							return null;
						}
						continue;

					// Close
					} elseif($opcode == 8) {
						$this->_disconnect();

					// 0 = continuation frame, 1 = text frame, 2 = binary frame
					} elseif($opcode < 3) {
						// Unmask data
						$data_len=strlen($frame_data);
						if($masked) {
							for ($i = 0; $i < $data_len; $i++)
								$data.= $frame_data[$i] ^ $mask[$i % 4];
						} else {
							$data.= $frame_data;
						}
					} else {
						continue;
					}
				} while(!$final);

				return $data;
			} else {
				// Return null if the client is not connected
				return null;
			}
		}

		private function _disconnect() {
			$closed = fclose($this->_socket);
			$this->connected = false;
		}

		/**
		 * Generates a key for the web socket handshake request
		 * Key to be used in header "Sec-WebSocket-Key"
		 *
		 * @return string base64 encoded key
		 */
		public function generateKey() {
			$chars     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwzyz1234567890+/=';
			$charRange = strlen($chars) - 1;
			$key       = '';
			for ($i = 0; $i < 16; $i++) {
				$key .= $chars[mt_rand(0, $charRange)];
			}

			return base64_encode($key);
		}

	}
