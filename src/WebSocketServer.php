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

	namespace bbauer\CamToWeb;

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
			$conn->close();
		}

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
				$conn->send(base64_encode($image));
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
