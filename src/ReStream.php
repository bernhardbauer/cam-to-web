#!/usr/bin/env php
<?php

	namespace bbauer\CamToWeb;

	use bbauer\CamToWeb\Server\BroadcastServer;
	use bbauer\CamToWeb\Server\WebSocketServer;
	use Ratchet\Http\HttpServer;
	use Ratchet\Server\IoServer;
	use Ratchet\WebSocket\WsServer;
	use Ratchet\WebSocket\MessageComponentInterface;
	use React\ChildProcess\Process as ChildProcess;
	use React\EventLoop\Factory as LoopFactory;
	use React\Socket\ConnectionInterface;

	require dirname(__DIR__).'/vendor/autoload.php';

	// Metadata structure definition
	define("SOI", "\xFF\xD8"); // Start Of Image
	define("SOS", "\xFF\xDA"); // Start Of Scan
	define("EOI", "\xFF\xD9"); // End of Image


	class ReStream {

		private $ioserver;
		private $ffmpeg;
		private $loop;
		private $address = '0.0.0.0';
		private $port = '8090';
		private $ws_server;
		private $shutdown = false; // If true prevents start of the loop and processes

		// Some variables to speed up the checking process
		private $learning = true;
		private $learning_cycles_necessary = 20;
		private $learning_cycle = 0;
		private $lines_learning = array();
		private $lines_to_skip = 0;
		private $lines_read = 0;

		// Buffer for output images
		private $image_buffer = '';
		private $is_first_image = true;

		// Benchmark variables
		private $benchmark_start = 0;
		private $image_count = 0;

		// Memory monitoring
		private $memory_timer;

		// Watchdog
		private $wdt = 0;
		private $react_wdt_timer; // Watchdog timer from React

		/**
		 * Construct the re-streaming service
		 *
		 * @param string $address the ip address on which the server should run on
		 * @param string $port the port on which the websocket server should run on
		 */
		public function __construct($address = '0.0.0.0', $port = '8090') {
			$this->address = $address;
			$this->port = $port;
			$this->lines_learning = array_pad([], $this->learning_cycles_necessary, 0);
			$this->loop = LoopFactory::create();

			$this->setupWebSocketServer($this->address, $this->port);

			$this->react_wdt_timer = $this->loop->addPeriodicTimer(1, function () {
				if ($this->wdt > 0) {
					// Shutdown script if the watchdog timer has not been updated in the last 10 seconds
					if ($this->wdt < (time() - 10)) {
						$this->stderr("==> WATCHDOG <==  called! Shutting down process because the timer has not been updated.");
						$this->shutdown();
					}
				}
			});
			$this->wdt = time();
		}

		/**
		 * Sets up the ffmpeg stream using input and output parameters
		 *
		 * @param array input parameters for the ffmpeg service
		 * @param array output parameters for the ffmpeg service
		 */
		public function setupFFMPEG(array $input, array $output) {
			if ($this->shutdown === true) {
				$this->stderr("Could not start FFMPEG process as the service is in shutdown mode!");
				return;
			}

			$command = 'ffmpeg '.implode(' ', $input).' '.implode(' ', $output);
			$this->stdout("Command: ".$command);

			$this->ffmpeg = new ChildProcess($command);
			$this->ffmpeg->start($this->loop);
			$this->benchmark_start = time();

			$this->ffmpeg->stdout->on('data', function ($chunk) {
				$this->process($chunk);
			});

			$this->ffmpeg->stderr->on('data', function ($chunk) {
				$this->stderr($chunk.PHP_EOL);
			});

			$this->ffmpeg->on('exit', function($exitCode, $termSignal) {
				if ($exitCode !== null) {
					$this->stderr('FFMPEG Process exited with code "'.strval($exitCode).'"');
				} else {
					$this->stderr('FFMPEG Process has been terminated with code "'.strval($termSignal).'"');
				}


				// Shutdown all processes and end the event loop
				$this->shutdown();
			});
		}

		public function setupWebSocketServer($address, $port) {
			if ($this->shutdown === true) {
				$this->stderr("Could not start the WebSocket server as the service is in shutdown mode!");
				return;
			}

			try {
				$this->ws_server = new BroadcastServer($this->loop, $address, $port);
			} catch (\Exception $e) {
				$this->stderr("Websocket server could not be started! (Message: ".$e->getMessage()."; Stacktrace: ".$e->getTraceAsString().")");

				// Shutdown all processes and end the event loop
				$this->shutdown();
			}
		}

		public function setupMonitoring($period = 60) {
			if ($this->shutdown === true) {
				$this->stderr("Could not setup monitoring as the service is in shutdown mode!");
				return;
			}

			$this->memory_timer = $this->loop->addPeriodicTimer($period, function () {
				$memory_usage = number_format(((memory_get_usage(true) / 1024) / 1024), 2); // MB
				$memory_peak_usage = number_format(((memory_get_peak_usage(true) / 1024) / 1024), 2); // MB

				$this->stdout("=====   Memory usage   =====");
				$this->stdout("Now:  ".$memory_usage." MB");
				$this->stdout("Peak: ".$memory_peak_usage." MB");

				$benchmark_end = time();
				$time_elapsed = (intval($benchmark_end) - intval($this->benchmark_start));
				$this->stdout("=====   Benchmark Results   =====");
				$this->stdout("Images: ".$this->image_count);
				$this->stdout("Start: ".$this->benchmark_start);
				$this->stdout("End: ".$benchmark_end);
				$this->stdout("Elapsed: ".$time_elapsed." seconds");
				$this->stdout("FPS: ".floatval(floatval($this->image_count) / floatval($time_elapsed)));

				// Print websocket server statistics
				if ($this->ws_server !== null) {
					$this->ws_server->printStatistics();
				}

				$this->stdout("=====   End Monitoring   =====");
			});
		}

		/**
		 * Processes an input stream of concatenated jpeg images
		 * Images get extracted out of the stream o be sent to multiple clients
		 *
		 * @param mixed line the input data (binary string)
		 */
		public function process($line) {
			// $this->lines_to_skip = 0;
			// Check for start of image -> SOI
			if (strpos($line, SOI) === 0) {
				$this->image_buffer = $line;

				if (strpos(substr($line, -2), EOI) === 0) { // Check if the last byte is an image end
					$this->image_buffer .= $line;
					$this->sendImageAndResetBuffer();
				}

				// Check for end of an image and start of next one -> EOI + SOI
			} else if (($this->learning && strpos($line, EOI.SOI) !== false)
				|| (!$this->learning && $this->lines_read >= $this->lines_to_skip && strpos($line, EOI.SOI) !== false)) {

				// Split the buffered data by an EOI and SOI -> append splitted data to the correct image buffer
				$image_seperation = explode(EOI.SOI, $line, 2);

				// Append an EOI to the image buffer
				$this->image_buffer .= $image_seperation[0].EOI;

				// Assume that the image is valid (because of performance considerations)
				$this->sendImageAndResetBuffer();

				// Add image start
				$this->image_buffer = SOI.$image_seperation[1];

			// If neither a start nor an end of an image has been detected append the content of the input buffer to the image buffer
			} else {
				if ($this->learning) { $this->lines_learning[$this->learning_cycle]++; }
				$this->lines_read++;

				// Only append data if an image has been started
				if (!empty($this->image_buffer) !== '') {
					$this->image_buffer .= $line;
				}

				// If the buffer grows greater than 32 Megabyte clear it
				if (strlen($this->image_buffer) > 67108864) { // buffer > 32 MB (calculation using multiple of 4 bit!)
					$this->image_buffer = '';
				}
			}
		}

		private function sendImageAndResetBuffer() {
			// Assume that the image is valid (because of performance considerations)
			$this->image_count++;
			// Broadcast image over websocket server
			if ($this->ws_server !== null) {
				$this->wdt = time(); // Update the watchdog time
				$this->ws_server->broadcastBinary($this->image_buffer);
			}

			// Reset image buffer and start the next image
			$this->image_buffer = '';
			if ($this->learning) {
				$this->learning_cycle++;
			}
			$this->lines_read = 0;

			if ($this->learning && $this->learning_cycle >= $this->learning_cycles_necessary) {
				$this->learning = false;
				$this->lines_to_skip = max((min($this->lines_learning) - 15), 0);
				$this->stdout("Learning finished with learning cycle $this->learning_cycle. Learned skip value is $this->lines_to_skip");
			}
		}

		private function shutdown() {
			$this->shutdown = true;

			// Terminate the ffmpeg process
			if ($this->ffmpeg !== null) {
				$this->ffmpeg->terminate();
			}

			// Stop the event loop
			if ($this->loop !== null) {
				$this->loop->stop();
			}

			$this->stdout("Shutdown completed");
			exit();
		}

		public function stdout($message) {
			fwrite(STDOUT, "[ReStream][Info] ".$message.PHP_EOL);
		}

		public function stderr($message) {
			fwrite(STDERR, "[ReStream][Error] ".$message.PHP_EOL);
		}

		private function isShutdown() {
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

	// Start the re-streaming service from the command line with the given feed number
	if (isset($argc) && isset($argv) && $argc >= 3) { // min 2 arguments -> argc starts with 1 (first argument = filename)
		// Check if the argument is between (incl.) 0 and 9
		if (!is_numeric($argv[2]) || intval($argv[2]) < 0 || intval($argv[2]) >= 10) {
			echo ("Invalid argument for 'feed_number' given! Must be an integer between (incl.) 0 and 9".PHP_EOL);
			echo ("Usage: ./ReStream.php <input_rtsp_url> <feed_number>".PHP_EOL);
		} else if (empty($argv[1]) || strpos($argv[1], "rtsp://") !== 0) {
			echo ("Invalid argument for 'input_rtsp_url' given! Must be an rtsp url and start with 'rtsp://'!".PHP_EOL);
			echo ("Usage: ./ReStream.php <input_rtsp_url> <feed_number>".PHP_EOL);
		} else {
			$restream = new ReStream(
				'0.0.0.0',
				'809'.strval($argv[2])
			);
			$restream->setupMonitoring();
			$restream->setupFFMPEG([
				'-loglevel repeat+warning', // Sets the log level to warning + the repeat option specifies that no output should be overwritten
				'-y',
				'-rtsp_transport tcp', // tcp necessary for unifi cameras!
				'-i '.$argv[1] // '-i rtsp://184.72.239.149/vod/mp4:BigBuckBunny_175k.mov'
			], [
				'-an', // No audio
				'-q:v 2', // Video quality (best = 1)
				'-b:v 1000k', // Video bitrate
				'-vsync 0',
				'-vf fps=fps=10', // 10 fps
				'-hide_banner',
				'-f image2',
				'-updatefirst 1',
				'pipe:1'
			]);
			$restream->run();

			if (!$restream->isShutdown()) {
				echo "Re-streaming service started on port ".'809'.strval($argv[2]).".".PHP_EOL;
			} else {
				echo "Re-streaming service is in shutdown mode!".PHP_EOL;
			}
		}
	} else {
		echo ("Invalid command usage!".PHP_EOL);
		echo ("Usage: ./ReStream.php <input_rtsp_url> <feed_number>".PHP_EOL);
	}
