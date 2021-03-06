<?php namespace Bkwld\Croppa;

// Dependencies
use PhpThumbFactory;

class Croppa {
	
	/**
	 * Constants
	 */
	const PATTERN = '^(.*)-([0-9_]+)x([0-9_]+)?(-[0-9a-z(),\-._]+)*\.(jpg|jpeg|png|gif)$';
	
	/**
	 * Persist the config
	 * @param array $data The config data array
	 * @return void
	 */
	static private $config;
	static public function config($data) {
		self::$config = $data;
	}
	
	/**
	 * Create a URL in the Croppa syntax given different parameters.  This is a helper
	 * designed to be used from view files.	
	 * @param string $src The path to the source
	 * @param integer $width Target width
	 * @param integer $height Target height
	 * @param array $options Addtional Croppa options, passed as key/value pairs.  Like array('resize')
	 * @return string The new path to your thumbnail
	 */
	static public function url($src, $width = null, $height = null, $options = null) {
		
		// Defaults
		if (empty($src)) return; // Don't allow empty strings
		if (empty($width)) $width = '_';
		if (empty($height)) $height = '_';
		
		// Produce the croppa syntax
		$suffix = '-'.$width.'x'.$height;
		
		// Add options.  If the key has no arguments (like resize), the key will be like [1]
		if ($options && is_array($options)) {
			foreach($options as $key => $val) {
				if (is_numeric($key)) $suffix .= '-'.$val;
				elseif (is_array($val)) $suffix .= '-'.$key.'('.implode(',',$val).')';
				else $suffix .= '-'.$key.'('.$val.')';
			}
		}
		
		// Break the path apart and put back together again
		$parts = pathinfo($src);
		$url = self::$config['host'].$parts['dirname'].'/'.$parts['filename'].$suffix;
		if (!empty($parts['extension'])) $url .= '.'.$parts['extension'];
		return $url;
	}
	
	/**
	 * Take the provided URL and, if it matches the Croppa URL schema, create
	 * the thumnail as defined in the URL schema.  If no source image can be found
	 * the function returns false.  If the URL exists, that image is outputted.  If
	 * a thumbnail can be produced, it is, and then it is outputted to the browser.
	 * @param string $url - This is actually the path, like /uploads/image.jpg
	 * @return boolean
	 */
	static public function generate($url) {
		
		// Make sure this file doesn't exist.  There's no reason it should if the 404
		// capturing is working right, but just in case
		if ($src = self::checkForFile($url)) {
			self::show($src);
		}
				
		// Check if the current url looks like a croppa URL.  Btw, this is a good
		// resource: http://regexpal.com/.
		if (!preg_match('#'.self::PATTERN.'#i', $url, $matches)) return false;
		$path = $matches[1].'.'.$matches[5];
		$width = $matches[2];
		$height = $matches[3];
		$options = $matches[4]; // These are not parsed, all options are grouped together raw

		// Increase memory limit, cause some images require a lot to resize
		ini_set('memory_limit', '128M');
		
		// Break apart options
		$options = self::makeOptions($options);
		
		// See if the referenced file exists and is an image
		if (!($src = self::checkForFile($path))) throw new Exception('Croppa: Referenced file missing');
		
		// Make the destination the same path
		$dst = dirname($src).'/'.basename($url);
		
		// Make sure destination is writeable
		if (!is_writable(dirname($dst))) throw new Exception('Croppa: Destination is not writeable');
		
		// If width and height are both wildcarded, just copy the file and be done with it
		if ($width == '_' && $height == '_') {
			copy($src, $dst);
			self::show($dst);
		}
		
		// Make sure that we won't exceed the the max number of crops for this image
		if (self::tooManyCrops($src)) throw new Exception('Croppa: Max crops reached');

		// Create the PHPThumb instance
		$thumb = PhpThumbFactory::create($src);
		
		// Auto rotate the image based on exif data (like from phones)
		// Uses: https://github.com/nik-kor/PHPThumb/blob/master/src/thumb_plugins/jpg_rotate.inc.php
		$thumb->rotateJpg();
		
		// Trim the source before applying the crop.  This is designed to be used in conjunction
		// with a cropping UI tool.
		if (array_key_exists('trim', $options) && array_key_exists('trim_perc', $options)) throw new Exception('Specify a trim OR a trip_perc option, not both');
		else if (array_key_exists('trim', $options)) self::trim($thumb, $options['trim']);
		else if (array_key_exists('trim_perc', $options)) self::trimPerc($thumb, $options['trim_perc']);

		// Do a quadrant adaptive resize.  Supported quadrant values are:
		// +---+---+---+
		// |   | T |   |
		// +---+---+---+
		// | L | C | R |
		// +---+---+---+
		// |   | B |   |
		// +---+---+---+
		if (array_key_exists('quadrant', $options)) {
			if ($height == '_' || $width == '_') throw new Exception('Croppa: Qudrant option needs width and height');
			if (empty($options['quadrant'][0])) throw new Exception('Croppa:: No quadrant specified');
			$quadrant = strtoupper($options['quadrant'][0]);
			if (!in_array($quadrant, array('T','L','C','R','B'))) throw new Exception('Croppa:: Invalid quadrant');
			$thumb->adaptiveResizeQuadrant($width, $height, $quadrant);
		
		// Force to 'resize'
		} elseif (array_key_exists('resize', $options)) {
			if ($height == '_' || $width == '_') throw new Exception('Croppa: Resize option needs width and height');
			$thumb->resize($width, $height);
		
		// Produce a standard crop
		} else {
			if ($height == '_') $thumb->resize($width, 99999);            // If no height, resize by width
			elseif ($width == '_') $thumb->resize(99999, $height);        // If no width, resize by height
			else $thumb->adaptiveResize($width, $height);                 // There is width and height, so crop
		}
		
		// Save it to disk
		$thumb->save($dst);
		
		// Display it
		self::show($thumb, $dst);
	}
	
	/**
	 * Delete the source image and all the crops
	 * @param string $url Relative path to the original source image
	 * @return type
	 */
	static public function delete($url) {
		// Need to decode the url so that we can handle things like space characters
		$url = urldecode($url);
	
		// Delete the source image		
		if (!($src = self::checkForFile($url))) {
			return false;
		}
		unlink($src);
		
		// Loop through the contents of the source directory and delete
		// any images that contain the source directories filename
		$parts = pathinfo($src);
		$files = scandir($parts['dirname']);
		foreach($files as $file) {
			if (strpos($file, $parts['filename']) !== false) {
				if (!unlink($parts['dirname'].'/'.$file)) throw new Exception('Croppa: Unlink failed');
			}
		}
	}
	
	/**
	 * Return width and height values for putting in an img tag.  Uses the same arguments as Croppa::url().
	 * Used in cases where you are resizing an image along one dimension and don't know what the wildcarded
	 * image size is.  They are formatted for putting in a style() attribute.  This seems to have better support
	 * that using the old school width and height attributes for setting the initial height.
	 * @param string $src The path to the source
	 * @param integer $width Target width
	 * @param integer $height Target height
	 * @param array $options Addtional Croppa options, passed as key/value pairs.  Like array('resize')
	 * @return string i.e. "width='200px' height='200px'"
	 */
	static public function sizes($src, $width = null, $height = null, $options = null) {
		
		// Get the URL to the file
		$url = self::url($src, $width, $height, $options);
		
		// Find the local path to this file by removing the URL base and then adding the
		// path to the public directory
		$path = path('public').substr($url, strlen(URL::base())+1);
		
		// Get the sizes
		if (!file_exists($path)) return null; // It may not exist if this is the first request for the img
		if (!($size = getimagesize($path))) throw new Exception('Dimensions could not be read');
		return "width:{$size[0]}px; height:{$size[1]}px;";
		
	}
	
	/**
	 * Create an image tag rather than just the URL.  Accepts the same params as Croppa::url()
	 * @param string $src The path to the source
	 * @param integer $width Target width
	 * @param integer $height Target height
	 * @param array $options Addtional Croppa options, passed as key/value pairs.  Like array('resize')
	 * @return string i.e. <img src="path/to/img.jpg" />
	 */
	static public function tag($src, $width = null, $height = null, $options = null) {
		return '<img src="'.self::url($src, $width, $height, $options).'" />';
	}
	
	
	// ------------------------------------------------------------------
	// Private methods only to follow
	// ------------------------------------------------------------------
	
	// See if there is an existing image file that matches the request
	static private function checkForFile($path) {

		// Loop through all the directories files may be uploaded to
		$src_dirs = self::$config['src_dirs'];
		foreach($src_dirs as $dir) {
			
			// Check that directory exists
			if (!is_dir($dir)) continue;
			if (substr($dir, -1, 1) != '/') $dir .= '/';
			
			// Look for the image in the directory
			$src = realpath($dir.$path);
			if (is_file($src) && getimagesize($src) !== false) {
				return $src;
			}
		}
		
		// None found
		return false;
	}
	
	// See count up the number of crops that have already been created
	// and return true if they are at the max number.
	// For: https://github.com/BKWLD/croppa/issues/1
	static private function tooManyCrops($src) {
		
		// If there is no max set, we are applying no limit
		if (empty(self::$config['max_crops'])) return false;
		
		// Count up the crops
		$found = 0;
		$parts = pathinfo($src);
		$files = scandir($parts['dirname']);
		foreach($files as $file) {
			if (strpos($file, $parts['filename']) !== false) $found++;
			
			// We're matching against the max + 1 because the source file
			// will match but doesn't count against the crop limit
			if ($found > self::$config['max_crops']) return true;
		}
		
		// There aren't too many crops, so return false
		return false;
	}
	
	// Output an image to the browser.  Accepts a string path
	// or a PhpThumb instance
	static private function show($src, $path = null) {
		
		// Handle string paths
		if (is_string($src)) {
			$path = $src;
			$src = PhpThumbFactory::create($src);
		
		// Handle PhpThumb instances
		} else if (empty($path)) {
			throw new Exception('$path is required by Croppa');
		}
		
		// Set the header for the filesize and a bunch of other stuff
		header("Content-Transfer-Encoding: binary");
		header("Accept-Ranges: bytes");
    header("Content-Length: ".filesize($path));
		
		// Display it
		$src->show();
		die;
	}
	
	// Create options array where each key is an option name
	// and the value if an array of the passed arguments
	static private function makeOptions($option_params) {
		$options = array();
		
		// These will look like: "-quadrant(T)-resize"
		$option_params = explode('-', $option_params);
		
		// Loop through the params and make the options key value pairs
		foreach($option_params as $option) {
			if (!preg_match('#(\w+)(?:\(([\w,.]+)\))?#i', $option, $matches)) continue;
			if (isset($matches[2])) $options[$matches[1]] = explode(',', $matches[2]);
			else $options[$matches[1]] = null;
		}

		// Return new options array
		return $options;
	}
	
	// Trim the source before applying the crop where the input is given as
	// offset pixels
	static private function trim($thumb, $options) {
		list($x1, $y1, $x2, $y2) = $options;
					
		// Apply crop to the thumb before resizing happens
		$thumb->crop($x1, $y1, $x2 - $x1, $y2 - $y1);
	}
	
	// Trim the source before applying the crop where the input is given as
	// offset percentages
	static private function trimPerc($thumb, $options) {
		list($x1, $y1, $x2, $y2) = $options;
			
		// Get the current dimensions
		$size = (object) $thumb->getCurrentDimensions();
		
		// Convert percentage values to what GdThumb expects
		$x = round($x1 * $size->width);
		$y = round($y1 * $size->height);
		$width = round($x2 * $size->width - $x);
		$height = round($y2 * $size->height - $y);
		
		// Apply crop to the thumb before resizing happens
		$thumb->crop($x, $y, $width, $height);
	}
	
}
