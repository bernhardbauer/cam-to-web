<?php

	namespace bbauer\CamToWeb\Server;

	/*
		This websocket server is based on "PHP WebSockets (https://github.com/ghedipunk/PHP-Websockets)"

		Original work: Copyright (c) 2012, Adam Alexander / All rights reserved
		Modified work: Copyright (c) 2017, Bernhard Bauer / All rights reserved

		Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

		* Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
		* Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
		* Neither the name of PHP WebSockets nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

		THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
	*/

	use bbauer\CamToWeb\Server\WebSocketUser;
	use React\EventLoop\LoopInterface;
	use React\Socket\ConnectionInterface;
	use React\Socket\Server;
	use React\Socket\ServerInterface;

	abstract class WebSocketServer {

		/** @var LoopInterface $loop */
		public $loop;
		/** @var ServerInterface $socket */
		public $socket;

		private $address;
		private $port;

		protected $master;
		protected $sockets = array();
		protected $users = array();
		protected $bytesSent = 0; // Total bytes sent from the websocket server
		protected $interactive = true; // more output
		protected $headerOriginRequired = false;
		protected $headerSecWebSocketProtocolRequired = false;
		protected $headerSecWebSocketExtensionsRequired = false;

		/**
		 * @param ServerInterface $socket
		 * @param LoopInterface|null $loop
		 */
		public function __construct(LoopInterface $loop = null, $address = '0.0.0.0', $port = '8080') {
			if (strpos(PHP_VERSION, "hiphop") === false) {
				gc_enable();
			}

			set_time_limit(0);
			ob_implicit_flush();

			$this->loop = $loop;
			$this->address = strval($address);
			$this->port = strval($port);
			$this->socket = new Server($this->address.':'.$this->port, $this->loop); // New socket server

			$this->socket->on('error', 'printf'); // TODO do something with socket errors (i.e. shutdown server -> shutdown loop)
			$this->socket->on('connection', array($this, 'handleConnect'));
			// /** @var ConnectionInterface $conn */
			// $this->socket->on('connection', function ($conn) {
			// 	echo "=> New connection (".$conn->getRemoteAddress().") ;)\n";
			// });

			$this->stdout("Server started -> Listening on: ".$this->socket->getAddress());
		}

		/**
		 * Triggered when a new connection is received from React
		 * @param ConnectionInterface $conn
		 */
		public function handleConnect($conn) {
			$this->stdout("New client with remote address '".$conn->getRemoteAddress()."' connected");
			$this->connect($conn);

			$conn->on('data', function ($data) use ($conn) {
				$user = $this->getUserBySocket($conn);
				if (!$user->handshake) {
					$tmp = str_replace("\r", '', $data);
					if (strpos($tmp, "\n\n") !== false) {
						$this->doHandshake($user, $data);
						$this->stdout("Doing handshake for user with id ".$user->id);
					}
				} else {
					//split packet into frame and send it to deframe
					$this->split_packet(strlen($data), $data, $user);
				}
				$this->process($user, $data);
			});
			$conn->on('close', function () use ($conn) {
				$this->disconnect($this->getUserBySocket($conn));
				$this->stderr("Client disconnected. TCP connection lost: ".$conn->getRemoteAddress());
			});
			$conn->on('error', function (\Exception $e) use ($conn) {
				$this->stderr("Error");
				$this->stderr($e->getMessage());
				$this->stderr($e->getTraceAsString());

				// $this->handleError($e, $conn);
			});
		}

		abstract protected function process($user,$message); // Called immediately when the data is recieved.
		abstract protected function connected($user);        // Called after the handshake response is sent to the client.
		abstract protected function closed($user);           // Called after the connection is closed.

		protected function connecting($user) {
			// Override to handle a connecting user, after the instance of the User is created, but before
			// the handshake has completed.
		}

		public function send($user, $message, $message_type = 'text') {
			if ($user->handshake) {
				if ($user->socket !== null) {
					$message = $this->frame($message, $user, $message_type);
					$this->bytesSent += mb_strlen($message, '8bit');

					$user->socket->write($message);
					return true;
				} else {
					$this->disconnect($user);
					return false;
				}
			}
		}

		public function broadcast($message) {
			foreach ($this->users as $user) {
				$this->send($user, $message);
			}
		}

		public function broadcastBinary($message) {
			foreach ($this->users as $user) {
				$this->send($user, $message, 'binary');
			}
		}

		protected function tick() {
			// Override this for any process that should happen periodically.  Will happen at least once
			// per second, but possibly more often.
		}

		protected function _tick() {
			// Core maintenance processes (e.g. retry sending failed messages)
		}

		protected function connect($socket) {
			$user = new WebSocketUser(uniqid('u'), $socket);
			$this->users[$user->id] = $user;
			$this->sockets[$user->id] = $socket;
			$this->connecting($user);
		}

		protected function disconnect($user, $triggerClosed = true) {
			if ($user !== null) {
				if ($user->socket === null) {
					$this->stderr('Disconnection user with id '.$user->id.' which has a "null" as socket!');
				}

				if ($triggerClosed) {
					$this->stdout("Client with id ".$user->id." disconnected.");
				} else if ($user->socket !== null) {
					$message = $this->frame('', $disconnectedUser, 'close');
					$this->bytesSent += mb_strlen($message, '8bit');
					$user->socket->write($message);
				}

				// Unset user
				if (array_key_exists($user->id, $this->users)) {
					unset($this->users[$user->id]);
				}

				// Unset socket
				if (array_key_exists($user->id, $this->sockets)) {
					unset($this->sockets[$user->id]);
				}

				// Fire user closed event
				$this->closed($user);

				// End socket connection if necessary
				if ($user->socket !== null) {
					$user->socket->end();
					$user->socket = null;
				}
			}
		}

		protected function doHandshake($user, $buffer) {
			$magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
			$headers = array();
			$lines = explode("\n",$buffer);
			foreach ($lines as $line) {
				if (strpos($line,":") !== false) {
					$header = explode(":",$line,2);
					$headers[strtolower(trim($header[0]))] = trim($header[1]);
				} else if (stripos($line,"get ") !== false) {
					preg_match("/GET (.*) HTTP/i", $buffer, $reqResource);
					$headers['get'] = trim($reqResource[1]);
				}
			}
			if (isset($headers['get'])) {
				$user->requestedResource = $headers['get'];
			} else {
				// TODO: fail the connection
				$handshakeResponse = "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
			}
			if (!isset($headers['host']) || !$this->checkHost($headers['host'])
				|| !isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket'
				|| !isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === false
				|| !isset($headers['sec-websocket-key'])
				|| ($this->headerSecWebSocketProtocolRequired && !isset($headers['sec-websocket-protocol'])) || ($this->headerSecWebSocketProtocolRequired && !$this->checkWebsocProtocol($headers['sec-websocket-protocol']))
				|| ($this->headerSecWebSocketExtensionsRequired && !isset($headers['sec-websocket-extensions'])) || ($this->headerSecWebSocketExtensionsRequired && !$this->checkWebsocExtensions($headers['sec-websocket-extensions']))) {

				$handshakeResponse = "HTTP/1.1 400 Bad Request";
			}

			if (!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13) {
				$handshakeResponse = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13";
			}
			if (($this->headerOriginRequired && !isset($headers['origin']) ) || ($this->headerOriginRequired && !$this->checkOrigin($headers['origin']))) {
				$handshakeResponse = "HTTP/1.1 403 Forbidden";
			}

			// Done verifying the _required_ headers and optionally required headers.
			if (isset($handshakeResponse)) {
				if ($user->socket !== null) {
					$this->bytesSent += mb_strlen($handshakeResponse, '8bit');
					$user->socket->write($handshakeResponse);
				}
				$this->disconnect($user);
				return;
			}

			$user->headers = $headers;
			$user->handshake = $buffer;

			$webSocketKeyHash = sha1($headers['sec-websocket-key'] . $magicGUID);

			$rawToken = "";
			for ($i = 0; $i < 20; $i++) {
				$rawToken .= chr(hexdec(substr($webSocketKeyHash,$i*2, 2)));
			}
			$handshakeToken = base64_encode($rawToken) . "\r\n";

			$subProtocol = (isset($headers['sec-websocket-protocol'])) ? $this->processProtocol($headers['sec-websocket-protocol']) : "";
			$extensions = (isset($headers['sec-websocket-extensions'])) ? $this->processExtensions($headers['sec-websocket-extensions']) : "";

			$handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\n";
			$handshakeResponse .= "Upgrade: websocket\r\n";
			$handshakeResponse .= "Connection: Upgrade\r\n";
			$handshakeResponse .= "Sec-WebSocket-Accept: ".$handshakeToken.$subProtocol.$extensions."\r\n";

			// If the socket != null send a handshake response
			if ($user->socket !== null) {
				$this->bytesSent += mb_strlen($handshakeResponse, '8bit');
				$user->socket->write($handshakeResponse);
				$this->connected($user);
			} else {
				$this->disconnect($user);
			}
		}

		protected function checkHost($hostName) {
			return true; // Override and return false if the host is not one that you would expect.
			// Ex: You only want to accept hosts from the my-domain.com domain,
			// but you receive a host from malicious-site.com instead.
		}

		protected function checkOrigin($origin) {
			return true; // Override and return false if the origin is not one that you would expect.
		}

		protected function checkWebsocProtocol($protocol) {
			return true; // Override and return false if a protocol is not found that you would expect.
		}

		protected function checkWebsocExtensions($extensions) {
			return true; // Override and return false if an extension is not found that you would expect.
		}

		protected function processProtocol($protocol) {
			return ""; // return either "Sec-WebSocket-Protocol: SelectedProtocolFromClientList\r\n" or return an empty string.
			// The carriage return/newline combo must appear at the end of a non-empty string, and must not
			// appear at the beginning of the string nor in an otherwise empty string, or it will be considered part of
			// the response body, which will trigger an error in the client as it will not be formatted correctly.
		}

		protected function processExtensions($extensions) {
			return ""; // return either "Sec-WebSocket-Extensions: SelectedExtensions\r\n" or return an empty string.
		}

		protected function getUserBySocket($socket) {
			foreach ($this->users as $user) {
				if ($user->socket == $socket) {
					return $user;
				}
			}
			return null;
		}

		public function stdout($message) {
			if ($this->interactive) {
				echo "[WS][Info] ".$message.PHP_EOL;
			}
		}

		public function stderr($message) {
			if ($this->interactive) {
				echo "[WS][Error] ".$message.PHP_EOL;
			}
		}

		protected function frame($message, $user, $messageType='text', $messageContinues=false) {
			switch ($messageType) {
				case 'continuous':
					$b1 = 0;
					break;
				case 'text':
					$b1 = ($user->sendingContinuous) ? 0 : 1;
					break;
				case 'binary':
					$b1 = ($user->sendingContinuous) ? 0 : 2;
					break;
				case 'close':
					$b1 = 8;
					break;
				case 'ping':
					$b1 = 9;
					break;
				case 'pong':
					$b1 = 10;
					break;
			}
			if ($messageContinues) {
				$user->sendingContinuous = true;
			} else {
				$b1 += 128;
				$user->sendingContinuous = false;
			}

			$length = strlen($message);
			$lengthField = "";
			if ($length < 126) {
				$b2 = $length;
			} else if ($length < 65536) {
				$b2 = 126;
				$hexLength = dechex($length);
				//$this->stdout("Hex Length: $hexLength");
				if (strlen($hexLength)%2 == 1) {
					$hexLength = '0' . $hexLength;
				}
				$n = strlen($hexLength) - 2;

				for ($i = $n; $i >= 0; $i=$i-2) {
					$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
				}
				while (strlen($lengthField) < 2) {
					$lengthField = chr(0) . $lengthField;
				}
			} else {
				$b2 = 127;
				$hexLength = dechex($length);
				if (strlen($hexLength)%2 == 1) {
					$hexLength = '0' . $hexLength;
				}
				$n = strlen($hexLength) - 2;

				for ($i = $n; $i >= 0; $i=$i-2) {
					$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
				}
				while (strlen($lengthField) < 8) {
					$lengthField = chr(0) . $lengthField;
				}
			}

			return chr($b1) . chr($b2) . $lengthField . $message;
		}

		//check packet if he have more than one frame and process each frame individually
		protected function split_packet($length,$packet, $user) {
			//add PartialPacket and calculate the new $length
			if ($user->handlingPartialPacket) {
				$packet = $user->partialBuffer . $packet;
				$user->handlingPartialPacket = false;
				$length = strlen($packet);
			}
			$fullpacket = $packet;
			$frame_pos = 0;
			$frame_id = 1;

			while($frame_pos<$length) {
				$headers = $this->extractHeaders($packet);
				$headers_size = $this->calcoffset($headers);
				$framesize=$headers['length']+$headers_size;

				//split frame from packet and process it
				$frame=substr($fullpacket,$frame_pos,$framesize);

				if (($message = $this->deframe($frame, $user,$headers)) !== FALSE) {
					if ($user->hasSentClose) {
						$this->disconnect($user);
					} else {
						if ((preg_match('//u', $message)) || ($headers['opcode']==2)) {
							//$this->stdout("Text msg encoded UTF-8 or Binary msg\n".$message);
							$this->process($user, $message);
						} else {
							$this->stderr("not UTF-8\n");
						}
					}
				}
				//get the new position also modify packet data
				$frame_pos += $framesize;
				$packet = substr($fullpacket,$frame_pos);
				$frame_id++;
			}
		}

		protected function calcoffset($headers) {
			$offset = 2;
			if ($headers['hasmask']) {
				$offset += 4;
			}
			if ($headers['length'] > 65535) {
				$offset += 8;
			} else if ($headers['length'] > 125) {
				$offset += 2;
			}
			return $offset;
		}

		protected function deframe($message, &$user) {
			//echo $this->strtohex($message);
			$headers = $this->extractHeaders($message);
			$pongReply = false;
			$willClose = false;
			switch($headers['opcode']) {
				case 0:
				case 1:
				case 2:
					break;
				case 8:
					// TODO: close the connection
					$user->hasSentClose = true;
					return "";
				case 9:
					$pongReply = true;
				case 10:
					break;
				default:
					//$this->disconnect($user); // TODO: fail connection
					$willClose = true;
					break;
			}

			/* Deal by split_packet() as now deframe() do only one frame at a time.
			if ($user->handlingPartialPacket) {
			$message = $user->partialBuffer . $message;
			$user->handlingPartialPacket = false;
			return $this->deframe($message, $user);
			}
			*/

			if ($this->checkRSVBits($headers,$user)) {
				return false;
			}

			if ($willClose) {
				// TODO: fail the connection
				return false;
			}

			$payload = $user->partialMessage . $this->extractPayload($message,$headers);

			if ($pongReply) {
				if ($user->socket !== null) {
					$reply = $this->frame($payload, $user, 'pong');
					$this->bytesSent += mb_strlen($reply, '8bit');
					$user->socket->write($reply);
				}
				return false;
			}
			if ($headers['length'] > strlen($this->applyMask($headers,$payload))) {
				$user->handlingPartialPacket = true;
				$user->partialBuffer = $message;
				return false;
			}

			$payload = $this->applyMask($headers,$payload);

			if ($headers['fin']) {
				$user->partialMessage = "";
				return $payload;
			}
			$user->partialMessage = $payload;
			return false;
		}

		protected function extractHeaders($message) {
			$header = array(
				'fin'     => $message[0] & chr(128),
				'rsv1'    => $message[0] & chr(64),
				'rsv2'    => $message[0] & chr(32),
				'rsv3'    => $message[0] & chr(16),
				'opcode'  => ord($message[0]) & 15,
				'hasmask' => $message[1] & chr(128),
				'length'  => 0,
				'mask'    => ""
			);
			$header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);

			if ($header['length'] == 126) {
				if ($header['hasmask']) {
					$header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
				}
				$header['length'] = ord($message[2]) * 256 + ord($message[3]);
			} else if ($header['length'] == 127) {
				if ($header['hasmask']) {
					$header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
				}
				$header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
								  + ord($message[3]) * 65536 * 65536 * 65536
								  + ord($message[4]) * 65536 * 65536 * 256
								  + ord($message[5]) * 65536 * 65536
								  + ord($message[6]) * 65536 * 256
								  + ord($message[7]) * 65536
								  + ord($message[8]) * 256
								  + ord($message[9]);
			} else if ($header['hasmask']) {
				$header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
			}
			//echo $this->strtohex($message);
			//$this->printHeaders($header);
			return $header;
		}

		protected function extractPayload($message,$headers) {
			$offset = 2;
			if ($headers['hasmask']) {
				$offset += 4;
			}
			if ($headers['length'] > 65535) {
				$offset += 8;
			} else if ($headers['length'] > 125) {
				$offset += 2;
			}
			return substr($message,$offset);
		}

		protected function applyMask($headers,$payload) {
			$effectiveMask = "";
			if ($headers['hasmask']) {
				$mask = $headers['mask'];
			} else {
				return $payload;
			}

			while (strlen($effectiveMask) < strlen($payload)) {
				$effectiveMask .= $mask;
			}
			while (strlen($effectiveMask) > strlen($payload)) {
				$effectiveMask = substr($effectiveMask,0,-1);
			}
			return $effectiveMask ^ $payload;
		}

		protected function checkRSVBits($headers,$user) { // override this method if you are using an extension where the RSV bits are used.
		if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
			//$this->disconnect($user); // TODO: fail connection
			return true;
		}
			return false;
		}

		protected function strtohex($str) {
			$strout = "";
			for ($i = 0; $i < strlen($str); $i++) {
				$strout .= (ord($str[$i])<16) ? "0" . dechex(ord($str[$i])) : dechex(ord($str[$i]));
				$strout .= " ";
				if ($i%32 == 7) {
					$strout .= ": ";
				}
				if ($i%32 == 15) {
					$strout .= ": ";
				}
				if ($i%32 == 23) {
					$strout .= ": ";
				}
				if ($i%32 == 31) {
					$strout .= "\n";
				}
			}
			return $strout . "\n";
		}

		protected function printHeaders($headers) {
			echo "Array\n(\n";
			foreach ($headers as $key => $value) {
				if ($key == 'length' || $key == 'opcode') {
					echo "\t[$key] => $value\n\n";
				} else {
					echo "\t[$key] => ".$this->strtohex($value)."\n";
				}
			}
			echo ")\n";
		}

		public function printStatistics() {
			$this->stdout("=====   Websocket server   =====");
			$this->stdout("> Connected users: ".strval(count($this->users)));
			$this->stdout("> Open sockets: ".strval(count($this->sockets)));
			$this->stdout("> Data sent:  ".number_format((($this->bytesSent / 1024) / 1024), 2)." MB");
		}

	}
