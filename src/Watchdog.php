#!/usr/bin/env php
<?php

	namespace bbauer\CamToWeb;

	use bbauer\CamToWeb\WebSocketClient;
	use React\ChildProcess\Process as ChildProcess;
	use React\EventLoop\Factory as LoopFactory;
	use React\Socket\ConnectionInterface;

	require dirname(__DIR__).'/vendor/autoload.php';

	class Watchdog {

		private $loop;
		private $address = "127.0.0.1";
		private $monitored_ports = [
			8090 => null,
			8091 => null,
			8092 => null,
			8093 => null,
			8094 => null,
			8095 => null,
			8096 => null,
			8097 => null
		];
		private $reactConnector;
		private $shutdown = false; // If true prevents start of the loop and processes

		// Periodic services
		private $wdt;
		private $reconnect;

		/**
		 * Construct the watchdog service
		 */
		public function __construct() {
			$this->loop = LoopFactory::create();
			// Setup websocket connector
			$this->reactConnector = new \React\Socket\Connector($this->loop, [
				'dns' => '8.8.8.8',
				'timeout' => 10
			]);

			// Initially open websocket connections
			$this->connectAll();

			// Setup watchdog
			$this->setupWatchdog();
		}

		/**
		 * Setup watchdog
		 */
		public function setupWatchdog() {
			if ($this->shutdown === true) {
				$this->stderr("Could not setup watchdog as the service is in shutdown mode!");
				return;
			}

			// Add watchdog timer
			$this->wdt = $this->loop->addPeriodicTimer(1, function () {
				foreach ($this->monitored_ports as $port => $connection_array) {
					if (is_array($connection_array) && isset($connection_array['last_update'])) {
						// Watchdog timer should be greater than reconnect time
						if ($connection_array['last_update'] < time() - 15) {
							shell_exec("lsof -n -i4TCP:".$port." | grep LISTEN | awk '{ print $2 }' | xargs kill -9");
							$this->stdout('Killed process on port '.$port.'!');
							$this->monitored_ports[$port] = null;
						}
					}
				}
			});

			// Add a timer to reconnect all services
			$this->reconnect = $this->loop->addPeriodicTimer(10, function () {
				$this->connectAll();
			});
		}

		/**
		 * Tries to get a connection to all specified ports on which currently no connection has been opened
		 */
		private function connectAll() {
			foreach ($this->monitored_ports as $port => $connection_array) {
				if ($connection_array === null) {
					$this->connectWebsocketService($port);
				}
			}
		}

		/**
		 * Connect to the websocket server
		 */
		private function connectWebsocketService($port) {
			$connector = new \Ratchet\Client\Connector($this->loop, $this->reactConnector);
			$connection = $connector("ws://".$this->address.":".$port)->then(function(\Ratchet\Client\WebSocket $conn) use ($port) {
				$conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $port) {
					if (!empty($this->monitored_ports[$port]) && is_array($this->monitored_ports[$port]) && isset($this->monitored_ports[$port]['last_update'])) {
						$this->monitored_ports[$port]['last_update'] = time();
					}
				});

				$conn->on('close', function($code = null, $reason = null) use ($port) {
					$this->stdout("Connection closed on port $port ({$code} - {$reason})");
				});
			}, function(\Exception $e) use ($port) {
				// Could not connect to service (offline)
				$this->monitored_ports[$port] = null;
			});

			$this->monitored_ports[$port] = [
				'last_update' => time(),
				'connection' => $connection
			];
		}

		/**
		 * Shutdown the service
		 */
		private function shutdown() {
			$this->shutdown = true;

			// Stop the event loop
			if ($this->loop !== null) {
				$this->loop->stop();
			}

			$this->stdout("Shutdown completed");
			exit();
		}

		public function stdout($message) {
			fwrite(STDOUT, "[".(new \DateTime('now'))->format('Y-m-d H:i:s')."][Watchdog][Info] ".$message.PHP_EOL);
		}

		public function stderr($message) {
			fwrite(STDERR, "[".(new \DateTime('now'))->format('Y-m-d H:i:s')."][Watchdog][Error] ".$message.PHP_EOL);
		}

		public function isShutdown() {
			return $this->shutdown;
		}

		/**
		 * Run the event loop if it has been set up
		 */
		public function run() {
			if ($this->loop !== null) {
				$this->loop->run();
			} else {
				$this->stderr("Could not start event loop!");
			}
		}

	}

	$watchdog = new Watchdog();
	$watchdog->run();

	if (!$watchdog->isShutdown()) {
		echo "Watchdog service started.".PHP_EOL;
	} else {
		echo "Watchdog service could not be started as it is in shutdown mode".PHP_EOL;
	}
