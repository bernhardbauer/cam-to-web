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

	namespace bbauer\CamToApp;

	use Ratchet\ConnectionInterface;
	use Ratchet\RFC6455\Messaging\MessageInterface;
	use Ratchet\WebSocket\MessageComponentInterface;

	class WebSocketServer implements MessageComponentInterface {

		protected $connections = array();

		public function __construct() {}

		/**
		 * A new websocket connection
		 *
		 * @param ConnectionInterface $conn
		 */
		public function onOpen(ConnectionInterface $conn) {
			$this->connections[] = $conn;
			$conn->send('Hello from the "bbmk management" web socket server ;)');
			echo 'New connection'."\n";
		}

		/**
		 * Handle message sending
		 *
		 * @param ConnectionInterface $from
		 * @param MessageInterface $msg
		 */
		public function onMessage(ConnectionInterface $from, MessageInterface $msg) {
			// Send an error to the client (authentication missing)
			$from->send("You're not able to do that!");
		}

		/**
		 * A connection is closed
		 * @param ConnectionInterface $conn
		 */
		public function onClose(ConnectionInterface $conn) {
			$this->connections = array();
			echo "Closing connection\n";
		}

		/**
		 * Error handling
		 *
		 * @param ConnectionInterface $conn
		 * @param \Exception $e
		 */
		public function onError(ConnectionInterface $conn, \Exception $e) {
			echo "An error occurred\n";
			$conn->send('An unexpected error ocurred. The connection will be closed!');
			$conn->close();
		}

		// /**
		//  * Sends a message to all web socket clients of a given channel
		//  *
		//  * @param string $channel the channel to which a message should be sent
		//  * @param string $data_identifier an identifier of the data which should be sent
		//  * @param mixed $data the data which should be sent
		//  * @param bool $server_message true if the message originated from the server, otherwise false
		//  * @return int returns the number of clients to which the message has been sent
		//  */
		// private function broadcast($channel, $data_identifier, $data, $server_message = true) {
		// 	$channel = strtolower($channel);
		// 	$broadcasted_to_count = 0;
		//
		// 	// Send a message to all clients of a channel
		// 	if (isset($this->subscriptions[$channel])) {
		// 		$channel_subscriptions = $this->subscriptions[$channel];
		//
		// 		foreach($channel_subscriptions as $connections) {
		// 			foreach($connections as $conn) {
		// 				$conn->send($this->buildJSONResponse($channel, $data_identifier, $data, $server_message));
		// 				$broadcasted_to_count++;
		// 			}
		// 		}
		// 	}
		//
		// 	return $broadcasted_to_count;
		// }

		// Invokes the garbage collector to free up memory if neccessary
		private function invokeGC() {
			// Invoke the garbage collector if the memory usage gets too high (if not invoked memory leaks would occur if sending a lot of data)
			if (((memory_get_usage(true) / 1024) / 1024) >= 100) {
				$usage_before_cleanup = ((memory_get_usage(true) / 1024) / 1024);
				gc_collect_cycles(); // Collect php garbage
				gc_mem_caches(); // Reclaim zend allocated memory
				$usage_after_cleanup = ((memory_get_usage(true) / 1024) / 1024);
				echo '['.(new \DateTime('now'))->format('Y-m-d H:i:s').'] Freed up '
					.number_format($usage_before_cleanup - $usage_after_cleanup, 2).' MB of RAM'
					.' (before gc: '.number_format($usage_before_cleanup, 2).' MB;'
					.' after gc: '.number_format($usage_after_cleanup, 2).' MB used;'
					.' Peak usage: '.number_format(((memory_get_peak_usage(true) / 1024) / 1024), 2).' MB)'."\n";
			}
		}

		public function broadcastImage($image) {
			foreach ($this->connections as $conn) {
				echo "broadcast\n";
				$conn->send($image, true);
			}
			// $this->clients->rewind();
			// while($this->clients->valid()) {
			// 	echo "broadcasting\n";
			// 	/** @var ConnectionInterface $conn */
			// 	$conn = $this->clients->current(); // similar to current($s)
			// 	$conn->send('demo');
			// 	$this->clients->next();
			// }
		}

	}
