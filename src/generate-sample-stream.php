<?php

	$input_filename = 'sample-image.jpg'; // Input file
	$output_length = 10000; // The number of times the image should be added to the output file

	$content = file_get_contents($input_filename);
	$f = fopen('sample-image.jpg-x'.$output_length, 'w');

	for ($i=0; $i < $output_length; $i++) {
		fwrite($f, $content);
	}
	fclose($f);
