<?php
/** Image Version Component 
 * 
 * A custom component for automagically creating thumbnail versions of any image within your app.
 * Example controller use:
 * $images = $this->{$this->modelClass}->find('first'); 	
 * $this->set('clear', $this->ImageVersion->flushVersion($images['Piece']['file'], array(150, 75), true));
 * $this->set('thumbnail', $this->ImageVersion->version(array('source' => $images['Piece']['file'], 'thumbSize' => array(150, 75))));
 * 	(that would clean out the entire folder 150x75 and then make a thumbnail again and return a path to $thumbnail for the view)
 *
 * @link			http://www.shift8creative.com
 * @author			Tom Maiaroto
 * @modifiedby		Tom
 * @lastmodified	2010-05-05 01:29:32 
 * @created			2008-09-25 01:00:00
 * @license			http://www.opensource.org/licenses/mit-license.php The MIT License
 */
class ImageVersionComponent extends Object {
	/**
	 * Components
	 *
	 * @return void
	 */
	//var $components = array('Session');
	var $controller;
	
	/**
	 * Startup
	 *
	 * @param object $controller
	 * @return void
	 */
	function initialize(&$controller) {
		$this->controller = $controller;			
	}
	
	/**
	 * Returns a path to the generated thumbnail.
	 * It will only generate a thumbnail for an image if the source is newer than the thumbnail,
	 * or if the thumbnail doesn't exist yet.
	 * 
	 * Note: Changing the quality later on after a thumbnail is already generated would have 
	 * no effect. Original source images would have to be updated (re-uploaded or modified via
	 * "touch" command or some other means). Or the existing thumbnail would have to be destroyed
	 * manually or with the flushVersions() method below.
	 * 
	 * @modified 2009-11-10 by Kevin DeCapite (www.decapite.net)
	 * 		- Changed 2 return lines to use ImageVersionComponent::formatPath() method
	 * 		- See that method's comment block for details
	 *  
	 * @modified 2010-05-03 by Tom Maiaroto
	 *		- Added "letterbox" support so resized images don't need to stretch (when not cropping), changed up some resizing math
	 *		- Changed version() method so it takes an array which makes it easier to add more options in the future, consolidated code a lot
	 *		- Added sharpening support
	 *
	 * @param $options Array[required] Options that change the size and cropping method of the image
	 * 		- image String[required] Location of the source image.
	 * 		- size Array[optional] Size of the thumbnail. Default: 75x75
	 * 		- quality Int[optional] Quality of the thumbnail. Default: 85%
	 * 		- crop Boolean[optional] Whether to crop the image (when one dimension is larger than specified $size)
	 * 		- letterbox Mixed[optional] If defined, it needs to be an array that defines the RGB background color to use. So when crop is set to false, this will fill in the rest of the image with a background color. Note: Transparent images will have a transparent letterbox unless forced.
	 *		- force_letterbox_color Boolean[optional] Whether or not to force the letterbox color on images with transparency (gif and png images). Default: false (false meaning their letterboxes will be transparent, true meaning they get a colored letterbox which also floods behind any transparent/translucent areas of the image)
	 *		- sharpen Boolean[optional] Whether to sharpen the image version or not. Default: true (note: png and gif images are not sharpened because of possible problems with transparency)	 
	 *
	 * @return String path to thumbnail image.
	 */
	function version($options = array('image'=>null, 'size'=>array(75, 75), 'quality'=>85, 'crop'=>false, 'letterbox'=>null, 'force_letterbox_color'=>false, 'sharpen'=>true)) {
		if(isset($options['image'])) { $source = $options['image']; } else { $source = null; }
		if(isset($options['size'])) { $thumbSize = $options['size']; } else {	$thumbSize == array(75,75);	}
		if(isset($options['quality'])) { $thumbQuality = $options['quality'];	} else { $thumbQuality = 85; }
		if(isset($options['crop'])) { $crop = $options['crop'];	} else { $crop = false;	}
		if(isset($options['letterbox'])) { $letterbox = $options['letterbox']; } else {	$letterbox = null; }
		if(is_string($letterbox)) { $letterbox = $this->_html2rgb($options['letterbox']); }
		if(isset($options['sharpen'])) { $sharpen = $options['sharpen']; } else { $sharpen = true; }		
		if(isset($options['force_letterbox_color'])) { $force_letterbox_color = $options['force_letterbox_color']; } else { $force_letterbox_color = false; }
		
		// if no source provided, don't do anything
		if(empty($source)): return false; endif;	
				
		// set defaults if null passed for any values
		if($thumbSize == null) { $thumbSize = array(75,75); }
		if($thumbQuality == null) { $thumbQuality = 85; }
		if($crop == null) { $crop = false; }
		
		$webroot = new Folder(WWW_ROOT);
		$this->webRoot = $webroot->path;
		
		// set the size
		$thumb_size_x = $original_thumb_size_x = $thumbSize[0];
		$thumb_size_y = $original_thumb_size_y = $thumbSize[1];		 
						
		// round the thumbnail quality in case someone provided a decimal
		$thumbQuality = ceil($thumbQuality);
		// or if a value was entered beyond the extremes
		if($thumbQuality > 100): $thumbQuality = 100; endif;
		if($thumbQuality < 0): $thumbQuality = 0; endif;
		
		// get full path of source file	(note: a beginning slash doesn't matter, the File class handles that I believe)
		$originalFile = new File($this->webRoot . $source);
		$source = $originalFile->Folder->path.DS.$originalFile->name().'.'.$originalFile->ext();
		// if the source file doesn't exist, don't do anything
		if(!file_exists($source)): return false; endif;
		
		// get the destination where the new file will be saved (including file name)
		$pathToSave = $this->createPath($originalFile->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1]);				
		$dest = $originalFile->Folder->path.DS.$thumb_size_x.'x'.$thumb_size_y.DS.$originalFile->name().'.'.$originalFile->ext();										
		
	        // First make sure it's an image that we can use (bmp support isn't added, but could be)
		switch(strtolower($originalFile->ext())):
			case 'jpg':
			case 'jpeg':
			case 'gif':
			case 'png':
			break;
			default:
				return false;
			break;
		endswitch;

		// Then see if the size version already exists and if so, is it older than our source image?
		if(file_exists($originalFile->Folder->path.DS.$thumb_size_x.'x'.$thumb_size_y.DS.$originalFile->name().'.'.$originalFile->ext())):
			$existingFile = new File($dest);
			if( date('YmdHis', $existingFile->lastChange()) > date('YmdHis', $originalFile->lastChange()) ):
				// if it's newer than the source, return the path. the source hasn't updated, so we don't need a new thumbnail.
				return $this->formatPath(substr(strstr($existingFile->Folder->path.DS.$existingFile->name().'.'.$existingFile->ext(), 'webroot'), 7));				
			endif;
		endif;
			
		// Get source image dimensions
		$size = getimagesize($source);
		$width = $size[0];
		$height = $size[1];
		// $x and $y here are the image source offsets
		$x = NULL;
		$y = NULL;
		$dx = $dy = 0;		
		
		if(($thumb_size_x > $width) && ($thumb_size_y > $height)) {
			$crop = false; // don't need to crop now do we?
		}
		
		// don't allow new width or height to be greater than the original
		if( $thumb_size_x > $width ) { $thumb_size_x = $width; }
		if( $thumb_size_y > $height ) { $thumb_size_y = $height; }		
		// generate new w/h if not provided (cool, idiot proofing)
		if( $thumb_size_x && !$thumb_size_y ) {
			$thumb_size_y = $height * ( $thumb_size_x / $width );
		}
		elseif($thumb_size_y && !$thumb_size_x) {
			$thumb_size_x = $width * ( $thumb_size_y / $height );
		}
		elseif(!$thumb_size_x && !$thumb_size_y) {
			$thumb_size_x = $width;
			$thumb_size_y = $height;
		}
		
		// set some default values for other variables we set differently based on options like letterboxing, etc.
		// TODO: clean this up and consolidate variables so the image creation process is shorter and nicer
		$new_width = $thumb_size_x;
		$new_height = $thumb_size_y;		
		$x_mid = ceil($new_width/2);  //horizontal middle // TODO: possibly add options to change where the crop is from
		$y_mid = ceil($new_height/2); //vertical middle			
				
		// If the thumbnail is square		
		if($thumbSize[0] == $thumbSize[1]) {
			if($width > $height) {
				$x = ceil(($width - $height) / 2 );
				$width = $height;
			} elseif($height > $width) {
				$y = ceil(($height - $width) / 2);
				$height = $width;
			} 	
		// else if the thumbnail is rectangular, don't stretch it
		} else {
			// if we aren't cropping then keep aspect ratio and contain image within the specified size
			if($crop === false) {
				$ratio_orig = $width/$height;
				if ($thumb_size_x/$thumb_size_y > $ratio_orig) {
				   $thumb_size_x = ceil($thumb_size_y*$ratio_orig);
				} else {
				   $thumb_size_y = ceil($thumb_size_x/$ratio_orig);
				}				
			}			
			// if we are cropping...
			if($crop === true) {		        
		        $ratio_orig = $width/$height;				    
			    if ($thumb_size_x/$thumb_size_y > $ratio_orig) {
			       $new_height = ceil($thumb_size_x/$ratio_orig);
			       $new_width = $thumb_size_x;
			    } else {
			       $new_width = ceil($thumb_size_y*$ratio_orig);
			       $new_height = $thumb_size_y;
			    }			    
			    $x_mid = ceil($new_width/2);  //horizontal middle // TODO: possibly add options to change where the crop is from
			    $y_mid = ceil($new_height/2); //vertical middle			    
			}
		}
								
		switch(strtolower($originalFile->ext())):
			case 'png':				
				if($thumbQuality != 0) {		
					$thumbQuality = ($thumbQuality - 100) / 11.111111;
					$thumbQuality = round(abs($thumbQuality));
				}
				$new_im = $this->_generateImage('png',$source, $dx, $dy, $x, $y, $x_mid, $y_mid, $new_width, $new_height, $original_thumb_size_x, $original_thumb_size_y, $thumb_size_x, $thumb_size_y, $height, $width, $letterbox, $crop, $sharpen, $force_letterbox_color);
				imagepng($new_im,$dest,$thumbQuality);	
				imagedestroy($new_im);
			break;		
			case 'gif':		
				$new_im = $this->_generateImage('gif',$source, $dx, $dy, $x, $y, $x_mid, $y_mid, $new_width, $new_height, $original_thumb_size_x, $original_thumb_size_y, $thumb_size_x, $thumb_size_y, $height, $width, $letterbox, $crop, $sharpen, $force_letterbox_color);
				imagegif($new_im,$dest); // no quality setting
				imagedestroy($new_im);
			break;		
			case 'jpg':
			case 'jpeg':			
				$new_im = $this->_generateImage('jpg',$source, $dx, $dy, $x, $y, $x_mid, $y_mid, $new_width, $new_height, $original_thumb_size_x, $original_thumb_size_y, $thumb_size_x, $thumb_size_y, $height, $width, $letterbox, $crop, $sharpen, $force_letterbox_color);
				imagejpeg($new_im,$dest,$thumbQuality);
				imagedestroy($new_im);				
			break;	
			default:
				return false;
			break;	
		endswitch;
		
		$outputPath = new File($dest);			
		$finalPath = substr(strstr($outputPath->Folder->path.DS.$outputPath->name().'.'.$outputPath->ext(), 'webroot'), 7);
		// PHP 5.3.0 would allow for a true flag as the third argument in strstr()... which would take out "webroot" so substr() wasn't required, but for older PHP...		
		
		return $this->formatPath($finalPath);	
	}
	
	// Do all the processing...
	function _generateImage($type=null,$source=null, $dx=null, $dy=null, $x=null, $y=null, $x_mid=null, $y_mid=null, $new_width=null, $new_height=null, $original_thumb_size_x=null, $original_thumb_size_y=null, $thumb_size_x=null, $thumb_size_y=null, $height=null, $width=null, $letterbox=null, $crop=null, $sharpen=null, $force_letterbox_color=null) {		
		switch($type) {
			case 'jpg':
			case 'jpeg':
				$im = imagecreatefromjpeg($source);
			break;
			case 'png':
				$im = imagecreatefrompng($source);
			break;
			case 'gif': 
				$im = imagecreatefromgif($source);
			break;
			default:
			case null:
				return false;
			break;
		}
				
		// CREATE THE NEW IMAGE		
		if(!empty($letterbox)) {
			// if letterbox, use the originally passed dimensions (keeping the final image size to whatever was requested, fitting the other image inside this box)
			$new_im = ImageCreatetruecolor($original_thumb_size_x,$original_thumb_size_y);
			// We want to now set the destination coordinates so we center the image (take overal "box" size and divide in half and subtract by final resized image size divided in half)
			$dx = ceil(($original_thumb_size_x / 2) - ($thumb_size_x / 2));
			$dy = ceil(($original_thumb_size_y / 2) - ($thumb_size_y / 2));				
		} else {
			// otherwise, use adjusted resize dimensions
			$new_im = ImageCreatetruecolor($thumb_size_x,$thumb_size_y);
		}
		// If we're cropping, we need to use a different calculated width and height
		if($crop === true) {
			$cropped_im = imagecreatetruecolor(round($new_width), round($new_height));			
		}
		
		if(($type == 'png') || ($type == 'gif')) {
			$trnprt_indx = imagecolortransparent($im);
			// If we have a specific transparent color that was saved with the image
		      if ($trnprt_indx >= 0) {		   
		        // Get the original image's transparent color's RGB values
		        $trnprt_color = imagecolorsforindex($im, $trnprt_indx);
		        // Allocate the same color in the new image resource
		        $trnprt_indx = imagecolorallocate($new_im, $trnprt_color['red'], $trnprt_color['green'], $trnprt_color['blue']);		   		       
		        // Completely fill the background of the new image with allocated color.
		        imagefill($new_im, 0, 0, $trnprt_indx);		       
		        // Set the background color for new image to transparent
		        imagecolortransparent($new_im, $trnprt_indx);
		        if(isset($cropped_im)) { imagefill($cropped_im, 0, 0, $trnprt_indx); imagecolortransparent($cropped_im, $trnprt_indx); } // do the same for the image if cropped
		      } elseif($type == 'png') {
		    	// ...a png may, instead, have an alpha channel that determines its translucency...
				
				// Fill the (currently empty) new cropped image with a transparent background
				if(isset($cropped_im)) { 
					$transparent_index = imagecolortransparent($cropped_im); // allocate
					//imagepalettecopy($im, $cropped_im); // Don't need to copy the pallette...
					imagefill($cropped_im, 0, 0, $transparent_index);
					//imagecolortransparent($cropped_im, $transparent_index); // we need this and the next line even?? for all the trouble i went through, i'm leaving it in case it needs to be turned back on.		
					//imagetruecolortopalette($cropped_im, true, 256);
				}
			
				// Fill the new image with a transparent background
				imagealphablending($new_im, false); 
		        // Create/allocate a new transparent color for image
		        $trnprt_indx = imagecolorallocatealpha($new_im, 0, 0, 0, 127); // $trnprt_indx = imagecolortransparent($new_im, imagecolorallocatealpha($new_im, 0, 0, 0, 127)); // seems to be no difference, but why call an extra function?
		        imagefill($new_im, 0, 0, $trnprt_indx); // Completely fill the background of the new image with allocated color.		       
		        imagesavealpha($new_im, true);  // Restore transparency blending		        
		        	
		      }
		}
																
		// PNG AND GIF can have transparent letterbox and that area needs to be filled too (it already is though if it's transparent)		
		if(!empty($letterbox)) {
			$background_color = imagecolorallocate($new_im, 255, 255, 255); // default white
			if((is_array($letterbox)) && (count($letterbox) == 3)) {
				$background_color = imagecolorallocate($new_im, $letterbox[0], $letterbox[1], $letterbox[2]);					
			}
			
			// Transparent images like png and gif will show the letterbox color in their transparent areas so it will look weird
			if(($type == 'gif') || ($type == 'png')) {				
				// But we will give the user a choice, forcing letterbox will effectively "flood" the background with that color. 
				if($force_letterbox_color === true) {
					imagealphablending($new_im, true); 
					if(isset($cropped_im)) { imagefill($cropped_im, 0, 0, $background_color); }
				} else {
					// If the user doesn't force letterboxing color on gif and png, make it transaprent ($trnprt_indx from above)
					$background_color = $trnprt_indx;
				}
			}
			
			imagefill($new_im, 0, 0, $background_color);			
		}
							
		// If cropping, we have to set some coordinates
		if($crop === true) {			
			imagecopyresampled($cropped_im, $im, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
			// if letterbox we may have to set some coordinates as well depending on the image dimensions ($dx, $dy) unless its letterbox style
			if(empty($letterbox)) {			
				imagecopyresampled($new_im, $cropped_im, 0, 0, ($x_mid-($thumb_size_x/2)), ($y_mid-($thumb_size_y/2)), $thumb_size_x, $thumb_size_y, $thumb_size_x, $thumb_size_y);
			} else {
				imagecopyresampled($new_im, $cropped_im,$dx,$dy, ($x_mid-($thumb_size_x/2)), ($y_mid-($thumb_size_y/2)), $thumb_size_x, $thumb_size_y, $thumb_size_x, $thumb_size_y);
			}			
		} else {
			imagecopyresampled($new_im,$im,$dx,$dy,$x,$y,$thumb_size_x,$thumb_size_y,$width,$height);
		}
						
		// SHARPEN (optional) -- can't sharpen transparent/translucent PNG		
		if(($sharpen === true) && ($type != 'png') && ($type != 'gif')) {
				$sharpness	= $this->_findSharp($width, $thumb_size_x);					
				$sharpenMatrix	= array(
					array(-1, -2, -1),
					array(-2, $sharpness + 12, -2),
					array(-1, -2, -1)
				);
				$divisor = $sharpness;
				$offset	= 0;
				imageconvolution($new_im, $sharpenMatrix, $divisor, $offset);
		}
		return $new_im;
	}
	
	/**
	* Computes for sharpening the image.
	*
	* function from Ryan Rud (http://adryrun.com)
	*/ 
	function _findSharp($orig, $final) {
		$final	= $final * (750.0 / $orig);
		$a		= 52;
		$b		= -0.27810650887573124;
		$c		= .00047337278106508946;		
		$result = $a + $b * $final + $c * $final * $final;		
		return max(round($result), 0);
	}
	
	/**
	* Deletes a single thumbnail or a directory of thumbnail versions created by the component.
	* Useful during development, or when changing the crop flag or dimensions often to keep tidy.
	* Maybe say a hypothetical CMS has an admin option for a user to change the thumbnail size of
	* a profile photo...well, we might want to run this to clean out the old versions right?
	* Or when a record was deleted containing an image that has a version...afterDelete()...
	*    
	* @param $source String[required] Location of a source image.
	* @param $thumbSize Array[optional] Size of the thumbnail. Default: 75x75
	* @param $clearAll Boolean[optional] Clear all the thumbnails in the same directory. Default: false
	* 
	* @return
	*/
	function flushVersion($source=null, $thumbSize=array(75, 75), $clearAll=false) {
		if((is_null($source)) || (!is_string($source))): return false; endif;
		$webroot = new Folder(WWW_ROOT);
			// take off any beginning slashes (webroot has a trailing one)
			if(substr($source, 0, 1) == '/'):
				$source = substr($source, 1);
			endif;
						
			$pathToFile = $webroot->path . $source;
			$file = new File($pathToFile);
					
			//debug($file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1].DS.$file->name);
			// REMOVE THE FILE (doesn't matter if we remove the directory too later on)
			if(file_exists($file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1])):
				if(unlink($file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1].DS.$file->name)):
					//debug('The file was deleted.');	
				else:
					//debug('The file could not be deleted.');
				endif;
			endif;		
		
		// IF SPECIFIED, REMOVE THE DIRECTORY AND ALL FILES IN IT
		if($clearAll === true):
			if($webroot->delete($file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1])):
				//debug('All files in the folder: '.$file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1].' have been deleted including the folder.');
			else:
				//debug('The folder: '.$file->Folder->path.DS.$thumbSize[0].'x'.$thumbSize[1].' and its files could not be deleted.');
			endif;
		endif;	
		return;	
	}
	
	/**
	 * Pass a full path like /var/www/htdocs/app/webroot/files
	 * Don't include trailing slash.
	 * 
	 * @modified 2009-11-10 by Kevin DeCapite (www.decapite.net)
	 * 		- Now allows for full path like c:\path\to\htdocs\app\webroot\files
	 * 		- Changed explode() function to use DS constant instead of "/"
	 * 		- Modified definition of $root var to be compatible with Windows' environments
	 * 		- Added inline comments where changes were included
	 * 		- Refactored tabbing and spacing for readability & consistency
	 * 
	 * @param $path String[optional]
	 * @return String Path.
	 */
	function createPath($path = null) {
		//$path = $this->webRoot . 'files' . DS . $path;
		$directories = explode(DS, $path);		
		// If on a Windows platform, define root accordingly (assumes <drive letter>: syntax)
		if (substr($directories[0], -1) == ':') {		
			$root = $directories[0];
			array_shift($directories);
		} else {
			// Initialize root to empty string on *nix platforms
			$root = '';
			// looks to see if a slash was included in the path to begin with and if so it removes it
			if ($directories[0] == '') {
				array_shift($directories);
			}
		}		
		foreach ($directories as $directory) {
			if (!file_exists($root.DS.$directory)) { 
				mkdir($root.DS.$directory);	
			}
			$root = $root.DS.$directory;
		}
		// put a trailing slash on
		$root = $root.DS;
		return $root;
	}
	
	/**
	 * Formats a path into a URL-friendly path
	 * Converts '\' to '/' if DS = '\'
	 * Otherwise will do nothing
	 * 
	 * @author Kevin DeCapite (www.decapite.net)
	 * @created 2009-11-10
	 * @param $path
	 * @return unknown_type
	 */
	function formatPath($path) {
		return str_replace(DS, '/', $path);
	} 
			
	/**
	 * Converts web hex value into rgb array.
	 *
	 * @param $color[String] The web hex string (ex. #0000 or 0000)
	 * @return array The rgb array
	 */
    function _html2rgb($color) {
	    if ($color[0] == '#')
	        $color = substr($color, 1);
	    if (strlen($color) == 6)
	        list($r, $g, $b) = array($color[0].$color[1],
	                                 $color[2].$color[3],
	                                 $color[4].$color[5]);
	    elseif (strlen($color) == 3)
	        list($r, $g, $b) = array($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
	    else
	        return false;
	    $r = hexdec($r); $g = hexdec($g); $b = hexdec($b);
	    return array($r, $g, $b);
	}
		
}
?>
