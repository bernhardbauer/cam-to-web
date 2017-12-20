<?php

	// Metadata structure definition
	define("SOI", "\xFF\xD8"); // Start Of Image
	define("APPn", "ffe"); // Application specific (Metadata) -> n omitted on purpose!
	define("SOS", "\xFF\xDA"); // Start Of Scan
	define("EOI", "\xFF\xD9"); // End of Image

	// Some variables to speed up the checking process
	$learning = true;
	$learning_cycles_necessary = 20;
	$learning_cycle = 0;
	$lines_learning = array_pad([], $learning_cycles_necessary, 0);
	$lines_to_skip = 0;
	$lines_read = 0;

	// Buffer for output images
	$image_buffer = '';
	$is_first_image = true;

	// Benchmark data
	$benchmark_start = time();
	$image_count = 0;

	while($line = fgets(STDIN)) {
		// $lines_to_skip = 0;
		// Check for start of image -> SOI
		if ($is_first_image && strpos($line, SOI) === 0 && empty($image_buffer)) {
			$is_first_image = false;
			$image_buffer .= $line;
			$lines_learning[$learning_cycle] = 0;

		// Check for end of an image and start of next one -> EOI + SOI
		} else if (($learning && strpos($line, EOI.SOI) !== false)
			|| (!$learning && $lines_read >= $lines_to_skip && strpos($line, EOI.SOI) !== false)) {

			// Split the buffered data by an EOI and SOI -> append splitted data to the correct image buffer
			$image_seperation = explode(EOI.SOI, $line, 2);

			// Append an EOI to the image buffer
			$image_buffer .= $image_seperation[0].EOI;

			// Assume that the image is valid (because of performance considerations)
			// output image
			$image_count++;

			// Reset image buffer and start the next image
			$image_buffer = SOI.$image_seperation[1];
			if ($learning) {
				$learning_cycle++;
			}
			$lines_read = 0;

			if ($learning && $learning_cycle >= $learning_cycles_necessary) {
				$learning = false;
				$lines_to_skip = max((min($lines_learning) - 15), 0);
				echo "Learning finished with learning cycle $learning_cycle. Learned skip value is $lines_to_skip\n";
			}

		// If neither a start nor an end of an image has been detected append the content of the input buffer to the image buffer
		} else {
			if ($learning) { $lines_learning[$learning_cycle]++; }
			$lines_read++;

			// Only append data if an image has been started
			if (!empty($image_buffer) !== '') {
				$image_buffer .= $line;
			}

			// If the buffer grows greater than 32 Megabyte clear it
			if (strlen($image_buffer) > 33554432) { // buffer > 32 MB
				$image_buffer = '';
			}
		}
	}

	$benchmark_end = time();
	$time_elapsed = (intval($benchmark_end) - intval($benchmark_start));
	echo "=== Benchmark Results ===\n";
	echo "Images: $image_count\n";
	echo "Start: $benchmark_start\n";
	echo "End: $benchmark_end\n";
	echo "Elapsed: $time_elapsed seconds\n";
	echo "FPS: ".floatval(floatval($image_count) / floatval($time_elapsed))."\n";
