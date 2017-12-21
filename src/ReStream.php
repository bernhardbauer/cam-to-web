<?php

	namespace bbauer\CamToApp;

	use Ratchet\Server\IoServer;
	use Ratchet\MessageComponentInterface;
	use React\ChildProcess\Process as ChildProcess;
	use React\EventLoop\Factory as LoopFactory;
	use React\Socket\Server as Reactor;

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
			$socket = new Reactor($this->address . ':' . $port, $this->loop);
			$this->ioserver = new IoServer(
				$this->ws_app,
				$socket,
				$this->loop
			);
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
			$this->ws_app->broadcastImage($this->image_buffer);

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

			if (($this->image_count % 100) === 0) {
				$benchmark_end = time();
				$time_elapsed = (intval($benchmark_end) - intval($this->benchmark_start));
				echo "=== Benchmark Results ===\n";
				echo "Images: $this->image_count\n";
				echo "Start: $this->benchmark_start\n";
				echo "End: $benchmark_end\n";
				echo "Elapsed: $time_elapsed seconds\n";
				echo "FPS: ".floatval(floatval($this->image_count) / floatval($time_elapsed))."\n";
			}
		}

		public function run() {
			$this->ioserver->run(); // Runs the react event loop
		}

	}



	$restream = new ReStream(
		new WebSocketServer(),
		'8100'
	);
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
