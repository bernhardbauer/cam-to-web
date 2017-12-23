<?php

	namespace bbauer\CamToWeb;

	use bbauer\CamToWeb\Server\BroadcastServer;
	use bbauer\CamToWeb\Server\ServerTest;
	use Ratchet\Http\HttpServer;
	use Ratchet\Server\IoServer;
	use Ratchet\WebSocket\WsServer;
	use Ratchet\WebSocket\MessageComponentInterface;
	use React\ChildProcess\Process as ChildProcess;
	use React\EventLoop\Factory as LoopFactory;
	use React\Socket\ConnectionInterface;
	use React\Socket\Server as SocketServer;

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
		private $port = '8100';
		private $ws_app;

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

		/**
		 * Construct the re-streaming service
		 *
		 * @param MessageComponentInterface ws_app the websocket app class
		 * @param stirng port the port on which the websocket server should run on
		 */
		public function __construct(MessageComponentInterface $ws_app, $port = '8100') {
			$this->ws_app = $ws_app;
			$this->port = $port;
			$this->lines_learning = array_pad([], $this->learning_cycles_necessary, 0);
			$this->loop = LoopFactory::create();

			$this->setupWebSocketServer($this->address, $this->port);
		}

		/**
		 * Sets up the ffmpeg stream using input and output parameters
		 *
		 * @param array input parameters for the ffmpeg service
		 * @param array output parameters for the ffmpeg service
		 */
		public function setupFFMPEG(array $input, array $output) {
			$command = 'ffmpeg '.implode(' ', $input).' '.implode(' ', $output);
			echo $command."\n";

			$this->ffmpeg = new ChildProcess($command);
			$this->ffmpeg->start($this->loop);
			$this->benchmark_start = time();

			$this->ffmpeg->stdout->on('data', function ($chunk) {
				$this->process($chunk);
			});

			$this->ffmpeg->on('exit', function($exitCode, $termSignal) {
				echo 'Process exited with code ' . $exitCode . PHP_EOL;
			});
		}

		public function setupWebSocketServer($address, $port) {
			$socket = new SocketServer($address.':'.$port, $this->loop);

			// /** @var ConnectionInterface $conn */
			// $socket->on('connection', function ($conn) {
			// 	echo "=> New connection (".$conn->getRemoteAddress().") ;)\n";
			// });
			$socket->on('error', 'printf');

			$server = new ServerTest($socket, $this->loop);

			// $this->ioserver = new IoServer(
			// 	new HttpServer(
			// 		new WsServer(
			// 			$this->ws_app
			// 		)
			// 	),
			// 	$socket,
			// 	$this->loop
			// );

			// try {
			// 	$this->ws_app = new BroadcastServer($address, $port);
			// 	$this->loop->addPeriodicTimer(0, function ($timer) {
			// 		try {
			// 			$this->ws_app->run();
			// 		} catch (\Exception $e) {
			// 			$this->ws_app->stdout($e->getMessage());
			// 		}
			// 	});
			// } catch (\Exception $e) {
			// 	echo "Exception:".PHP_EOL;
			// 	echo $e->getMessage().PHP_EOL;
			// }



			// $this->ffmpeg = new ChildProcess($command);
			// $this->ffmpeg->start($this->loop);
			// $this->benchmark_start = time();
			//
			// $this->ffmpeg->stdout->on('data', function ($chunk) {
			// 	$this->process($chunk);
			// });
			//
			// $this->ffmpeg->on('exit', function($exitCode, $termSignal) {
			// 	echo 'Process exited with code ' . $exitCode . PHP_EOL;
			// });

		}

		public function setupMonitoring() {
			$this->memory_timer = $this->loop->addPeriodicTimer(10, function () {
				$memory_usage = number_format(((memory_get_usage(true) / 1024) / 1024), 2); // MB
				$memory_peak_usage = number_format(((memory_get_peak_usage(true) / 1024) / 1024), 2); // MB

				echo "=====   Memory usage   =====".PHP_EOL;
				echo "> Now:  ".$memory_usage." MB".PHP_EOL;
				echo "> Peak: ".$memory_peak_usage." MB".PHP_EOL;


				echo "=====   Benchmark Results   =====".PHP_EOL;
				$benchmark_end = time();
				$time_elapsed = (intval($benchmark_end) - intval($this->benchmark_start));
				echo "> Images: ".$this->image_count.PHP_EOL;
				echo "> Start: ".$this->benchmark_start.PHP_EOL;
				echo "> End: ".$benchmark_end.PHP_EOL;
				echo "> Elapsed: ".$time_elapsed." seconds".PHP_EOL;
				echo "> FPS: ".floatval(floatval($this->image_count) / floatval($time_elapsed)).PHP_EOL;
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
			// $this->ws_app->broadcastImage($this->image_buffer);
			// $this->ws_app->broadcast('hello');
			// $this->ws_app->broadcastBinary($this->image_buffer);

			// Reset image buffer and start the next image
			$this->image_buffer = '';
			if ($this->learning) {
				$this->learning_cycle++;
			}
			$this->lines_read = 0;

			if ($this->learning && $this->learning_cycle >= $this->learning_cycles_necessary) {
				$this->learning = false;
				$this->lines_to_skip = max((min($this->lines_learning) - 15), 0);
				echo "Learning finished with learning cycle $this->learning_cycle. Learned skip value is $this->lines_to_skip\n";
			}
		}

		public function run() {
			if ($this->loop !== null) {
				$this->loop->run();
			} else {
				echo "[Critical] Could not start event loop!".PHP_EOL;
			}
			// $this->ioserver->run(); // Runs the react event loop
		}

	}



	$restream = new ReStream(
		new WebSocketServer(),
		'8100'
	);
	$restream->setupMonitoring();
	$restream->setupFFMPEG([
		'-y',
		'-rtsp_transport tcp',
		'-i rtsp://192.168.1.50:7447/5a2923899008e84d9919205f_1' // rtsp://184.72.239.149/vod/mp4:BigBuckBunny_175k.mov
	], [
		'-an',
		'-q:v 1',
		'-b:v 1000k',
		'-vsync 0',
		'-vf fps=fps=10',
		'-hide_banner',
		'-f image2',
		'-updatefirst 1',
		'pipe:1'
	]);
	$restream->run();
