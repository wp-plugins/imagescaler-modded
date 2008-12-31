<?php
/*
Plugin Name: ImageScaler Modded
Plugin URI: http://nguyenthanhcong.com/imagescaler-modded-version/
Description: Properly resamples images resized in the post editor
Author: Fu4ny
Version: 1.0
Author URI: http://nguyenthanhcong.com
*/

class imagescaler {
	/* takes the content of the post, returns a version with changed URL tags '*/
	function filter_post($post){
		// Post comes in with slashes already added, so remove them
		$post = stripslashes($post);
		// Call filter_img on each image tag
		$post = preg_replace_callback('/<img([^>"]|"[^"]*")*>/i',
			array($this, 'filter_img'), $post);
		// Returned string is expected to be slash-escaped
		$post = addslashes($post);
		return $post;
	}
	
	/* Downloads the file at $url locally, and returns the local path */
	function download_file ($url) {
		$url_parts = parse_url($url);
		if(!$url_parts || $url_parts['scheme'] != 'http'){
			return false;
		}
		
		$ext = $this->file_extension($url);
		if(!preg_match('/(jpe?g|gif|png)/', $ext)){
			return false;
		}

		// adds the imagescaler directory if it does not already exist
		if(!file_exists('../wp-content/imagescaler')){
			mkdir('../wp-content/imagescaler');
		}
		
		$filename = '../wp-content/imagescaler/' . md5($url) . ".$ext";
		$outfile = fopen($filename, 'w');
		$port = isset($url_parts['port'])? $url_parts['port'] : 80;
		
		if(!$sock = fsockopen($url_parts['host'], $port, $errno, $errstr, 15)){
			return false;
		}
		
		$send =	"GET $url_parts[path] HTTP/1.1\n" .
			"Host: $url_parts[host]\n" .
			"Connection: Close\n\n";
		
		fwrite($sock, $send);
		
		// Discard received HTTP headers
		while(trim(fgets($sock, 4096)) != '') {}
		
		// Download and save file locally
		while(!feof($sock)){
			fwrite($outfile, fgets($sock, 4096));
		}
		
		fclose($sock);
		fclose($outfile);
		
		return $filename;
	}
	
	/* Parses an XML tag into an array of attributes */
	function simple_xml_attribute_parse ($tag) {
		preg_match_all('/([a-z0-9]+)=(?:\"([^\"]*)\"|\s*([^\s]+))/i', $tag, $matches, PREG_SET_ORDER);
		$attributes = array();
		foreach($matches as $att){
			$attributes[$att[1]] = $att[2];
		}
		return $attributes;
	}
	
	/* Returns the XML code for the given tagname and array of attributes */
	function simple_xml_construct ($tagname, $attributes) {
		$tag = "<$tagname ";
		foreach($attributes as $name => $value) {
			$tag .= "$name=\"$value\" ";
		}
		$tag .= "/>";
		return $tag;
	}
	
	/* takes an array with a single element, the code of the image tag.
	   returns the new image tag after resizing the image (if neccesary) */
	function filter_img ($code) {
		$img = $this->simple_xml_attribute_parse($code[0]);
		
		$max_width = get_option('imagescaler_max_width');
		$max_height = get_option('imagescaler_max_height');
		
		if(empty($img['src'])){
			return $code[0];
		}
		//Modded, If Images' width < max width, don't do anything
		$img_rong = getimagesize($img['src']);
		if ($img_rong[0] < $max_width){
			return $code[0];
		}
		//End mod
		$url_save=$img['src']; //Save the life
		if(isset($img['imagescaler'])){
			// The following if-statement is entirely for backwards-compatibility with imagescaler 0.1 and 1.0
			if(preg_match('#^\d{4}/\d{2}/.+$#', $img['imagescaler'])){
				$img['imagescaler'] = get_option('siteurl') . "/wp-content/wp-uploads/$img[imagescaler]";
			}
			$url = $img['imagescaler'];
			$filename = $this->url_to_local($img['imagescaler']);
		} elseif($url = $img['src'] && $filename = $this->url_to_local($url)) {
		} elseif($filename = $this->download_file($img['src'])) {
			$url = $this->local_to_url($filename);
		} else {
			// Give up on finding the image
			return $code[0];
		}
		
		if(!file_exists($filename)){
			return $code[0];
		}
		
		// Find the actual dimensions of the image
		if(!list($img_width, $img_height) = getimagesize($filename)){
			// Can't find the dimensions of the image, so it is
			// probably corrupt
			return $code[0];
		}
		
		$scale = 1;
		// Find $scale = the new image's size in relation to the old image
		if(isset($img['width'])){
			$scale = $img['width'] / $img_width;
		} else if(isset($img['height'])) {
			$scale = $img['height'] / $img_height;
		}
		
		if($max_width && $img_width * $scale > $max_width){
			$scale = $max_width / $img_width;
		}
		
		if($max_height && $img_height * $scale > $max_height){
			$scale = $max_height / $img_height;
		}
		if($scale == 1){
			// The image is the same as it's original size, so don't resize it
			//Modded, Don't do anything when it's orginal size
			//$img['width'] = $img_width;
			//$img['height'] = $img_height;
			//$img['src'] = $url;
			//$img['imagescaler'] = $url;
			return $this->simple_xml_construct('img', $img);
		}
		
		// Find new image dimensions
		$width = round($scale * $img_width);
		$height = round($scale * $img_height);
		
		if(!$src = $this->resize_image($filename, $width, $height)){
			// Could not resize image
			return $code[0];
		}
		
		$img['width'] = $width;
		$img['height'] = $height;
		$img['src'] = $src;
		$img['imagescaler'] = $url;
		
		//Modded, Add the link to orginal image
		$imgtag = $this->simple_xml_construct('img', $img);
		$imgtag = '<a href=\''.$url_save.'\'>'.$imgtag.'</a>';
		return $imgtag;
	}
	
	/* takes a url of an uploaded image and returns the local file location, or
	   false if the file is not local */
	function url_to_local ($src) {
		$home = get_option('siteurl');
		
		if(preg_match('#' . preg_quote($home, '#') . '/(wp-content/(?:uploads|imagescaler)/.+)#', $src, $match)){
			return ('../' . $match[1]);
		}
	}
	
	/* takes a local uploaded file and returns the url to it, or false if the directory is not known */
	function local_to_url ($filename) {
		$home = get_option('siteurl');
		if(preg_match('#..(/wp-content/(?:uploads|imagescaler)/.+)#i', $filename, $match)){
			return $home . $match[1];
		}
		return $false;
	}
	
	/* prevents ImageScaler from making code xhtml invalid by removing the non-standard attribute
	   used by ImageScaler. */
	function xhtml_post_cleanup ($post) {
		return preg_replace('/(<img(?:[^"]|"[^"]*")*)(?:\\simagescaler="[^"]*")((?:[^"]|"[^"]*")*>)/i', '\\1\\2', $post);
	}
	
	/* returns the file extension of $filename. $filename can be a file name or a URL. */
	function file_extension ($filename) {
		if (preg_match('/\.([^\.]*)$/', $filename, $match)){
			return strtolower($match[1]);
		}
		return false;
	}
	
	/* copies the image at $filename, resized, and returns the url or false if a problem occurs */
	function resize_image ($filename, $width, $height) {
		$home = get_option('siteurl');
		$upload_path = get_option('uploadpath');
		
		// adds the imagescaler directory if it does not already exist
		if(!file_exists('../wp-content/imagescaler')){
			mkdir('../wp-content/imagescaler');
		}
		
		if(!file_exists($filename)){
			return false;
		}
		
		$imagename = md5($filename . ',' . $width . ',' . $height);
		// Determine the file type from the extension
		$ext = $this->file_extension($filename);
		if($ext == 'jpg' || $ext == 'jpeg') {
			$img = imagecreatefromjpeg($filename);
			$save_img = 'imagejpeg';
			$imagename .= '.jpg';
		} else if ($ext == 'gif'){
			$img = imagecreatefromgif($filename);
			$save_img = 'imagegif';
			$imagename .= '.gif';
		} else if ($ext == 'png'){
			$img = imagecreatefrompng($filename);
			$save_img = 'imagepng';
			$imagename .= '.png';
		} else {
			// Unsupported image type
			return false;
		}
		
		if(!file_exists('../wp-content/imagescaler' . $imagename)){
			// make image
			$new_img = imagecreatetruecolor($width, $height);
			imagecopyresampled($new_img, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));
			$save_img($new_img, '../wp-content/imagescaler/' . $imagename);
		}
		
		return $home . '/wp-content/imagescaler/' . $imagename;
	}
	
	/* Display the options page */
	function options_page () {
?>
	<div class="wrap">
	<h2 style="margin: 0 0 20px 0">ImageScaler</h2>
	<?php
		if(isset($_POST['imagescaler_submit'])){
			$errors = array();
			$success = array();
			
			$max_width = $_POST['image_max_width'];
			$max_height = $_POST['image_max_height'];			
			$xhtml_strict = (isset($_POST['xhtml_strict']) && $_POST['xhtml_strict'] == 'on') ? 'true' : 'false';
			
			if ($max_width == '')
				$max_width = 0;
			if (!is_numeric($max_width) && !isset($errors['empty_width']))
				$errors['nan_width'] = 'Value for max width has to be a number';
			
			if ($max_height == '')
				$max_height = 0;
			if (!is_numeric($max_height) && !isset($errors['empty_height']))
				$errors['nan_height'] = 'Value for max height has to be a number';
			
			if (count($errors) == 0) {
				$old_max_width = get_option('imagescaler_max_width');
				$old_max_height = get_option('imagescaler_max_height');
				$xhtml_strict_old = get_option('imagescaler_xhtml_strict');
				
				if ($xhtml_strict_old != $xhtml_strict) {
					update_option('imagescaler_xhtml_strict', $xhtml_strict);
					$success['strict'] = "<strong>Force xhtml strict</strong> set to <strong>$xhtml_strict</strong>";
				}
				
				if ($old_max_width != $max_width) {
					update_option('imagescaler_max_width', $max_width);
					if ($max_width == 0)
						$success['max_width'] = "Maximum width set to <strong>0</strong>. Imagescaler will not limit width";
					else
						$success['max_width'] = "Maximum width for images is now set to: <strong>$max_width px</strong>";
				}
				
				if ($old_max_height != $max_height) {
					update_option('imagescaler_max_height', $max_height);
					if ($max_height == 0)
						$success['max_height'] = "Maximum height set to <strong>0</strong>. Imagescaler will not limit height";
					else
						$success['max_height'] = "Maximum height for images is now set to: <strong>$max_height px</strong>";
				}
				
				if (count($success) != 0) {
			?>
				<div id="message" class="updated fade" style="margin: 0 0 20px 0;">
					<ul>
					<?php foreach($success as $msg) { ?>
						<li><?php echo $msg; ?></li>
					<?php } ?>
					</ul>
				</div>
		<?php
				}
			} else {
		?>
			<div id="message" class="updated fade-ff0000">
				<ul>
				<?php foreach($errors as $error) { ?>
					<li><?php echo $error; ?></li>
				<?php } ?>
				</ul>
			</div>
		<?php
			} 

		}
		
		if (!is_numeric(get_option('imagescaler_max_width')) && !is_numeric(get_option('imagescaler_max_height'))) {
			update_option('imagescaler_max_width', '0');
			update_option('imagescaler_max_height', '0');
		}
		
		if (!extension_loaded("gd")) {
			$loaded = false;
		
		?>
			<div id="message" class="updated fade-ff0000">
				<p>Could not find GD Library extension. Plugin has been deactivated.</p>
			</div>
			
		<?php
		} else {
			$loaded = true;
		}

		?>
	<form method="post" action="options-general.php?page=imagescaler.php">
		<p>
			<label for="image_max_width" style="float: left; width: 135px; margin: 5px 0 0 0;">Images max width:</label>
			<input style="width: 40px;" type="text" name="image_max_width" id="image_max_width" <?php if ($loaded == false) echo 'disabled="true"'; ?> value="<?php echo (get_option('imagescaler_max_width')); ?>" /> px
		</p>
				
		<p>
			<label for="image_max_height" style="float: left; width: 135px; margin: 5px 0 0 0;">Images max height:</label>
			<input style="width: 40px;" type="text" name="image_max_height" id="image_max_height" <?php if ($loaded == false) echo 'disabled="true"'; ?> value="<?php echo (get_option('imagescaler_max_height')); ?>" /> px
		</p>
		<p style="padding: 14px 0 0 0">
			<input type="checkbox" name="xhtml_strict" id="xhtml_strict" <?php echo (get_option('imagescaler_xhtml_strict') == 'true')? 'checked="checked"' : ''; ?> />
			<label for="xhtml_strict">Force strict xhtml</label>
		</p>
		<p>
			ImageScaler adds a non-standard attribute to image tags in order to retain the information needed to recreate the image if its dimensions change.
			The <strong>force strict xhtml</strong> option removes this attribute from the (x)HTML code before it is sent to the browser. If this were not done,
			the page would not be valid xhtml. If you don't know what valid xhtml is, or your html is not valid anyway, feel free to leave this disabled.
		</p>
		<p style="padding: 15px 0 10px 0;">
			<?php if (extension_loaded("gd")) echo "<strong>GD library extension is enabled, so everything should be working fine.</strong>"; ?> Dimensions for max width and height is set in pixels. Values of 0 means that the constraints for images width/height will be disregarded.
		</p>
		<p class="submit">
			<input type="submit" name="imagescaler_submit" value="<?php _e('Update Options »') ?>" />
		</p>
	</form>
	<p>ImageScaler was developed by <a href="http://www.paulbutler.org/">Paul Butler</a> and <a href="http://www.davidkarlsson.info/">David Karlsson</a>. Modded by <a href="http://nguyenthanhcong.com">Fu4ny</a>.</p>
	</div>
<?php
	}
	
	function options_menu () {
		add_options_page('ImageScaler', 'ImageScaler', 'manage_options', 'imagescaler.php', array($this, 'options_page'));
	}
}

$imagescaler = new imagescaler();

add_action('content_save_pre', array($imagescaler, 'filter_post'));
if(get_option('imagescaler_xhtml_strict') == 'true'){
	add_action('the_content', array($imagescaler, 'xhtml_post_cleanup'));
}

add_action('admin_menu', array($imagescaler, 'options_menu'));
