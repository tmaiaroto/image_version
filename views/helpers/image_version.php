<?php
/**
 * Image Version Helper class to embed thumbnail images on a page.
 * 
 * @link			http://www.shift8creative.com
 * @author			Tom Maiaroto
 * @modifiedby		Tom
 * @lastmodified	2010-05-03 14:55:07 
 * @created			2010-05-04 18:33:55 
 * @license			http://www.opensource.org/licenses/mit-license.php The MIT License
 */
class ImageVersionHelper extends AppHelper {

	var $helpers = array('Html');
	var $component;

	/**
	 * Returns a block of HTML code that embeds a thumbnail image into a page.
	 * It uses the built in CakePHP HTML helper image method for additional options.
	 *  
	 * @param $options Array[required] Options that change the size and cropping method of the image
	 * 		- image String[required] Location of the source image.
	 * 		- size Array[optional] Size of the thumbnail. Default: 75x75
	 * 		- quality Int[optional] Quality of the thumbnail. Default: 85%
	 * 		- crop Boolean [optional] Whether or not to crop the image or fit to max dimension. Default: false
	 * 		- letterbox Mixed[optional] A background color to use if a letterbox effect is desired (rgb array or web hex value). Deafult: null, no letterbox - NOTE: Images won't all "fit" like this, so if you are displaying them in a loop of some sort and setting (expecting) their dimensions to all be the same, they won't be unless they all have the same aspect ratio. Images won't be stretched on resize, but visually may get stretched if you set your html/css options that way.
	 *		- force_letterbox_color Boolean[optional] Whether or not to force the letterbox color on images with transparency (gif and png images). Default: false (false meaning their letterboxes will be transparent, true meaning they get a colored letterbox which also floods behind any transparent/translucent areas of the image)		
	 *		- sharpen Boolean[optional] Whether to sharpen the image version or not. Default: true (note: png and gif images are not sharpened because of possible problems with transparency)	
	 * @param $htmlOptions Object[optional] An array of options, same as Html->image() helper.
	 * @param $html Boolean [optional] Use html image helper to embed the image, or just a path. Default: true (return html code)
	 * 
	 * @return HTML string including image tag and src attribute, along with any additional options.
	 */
	function version($options = array('image'=>null, 'size'=>array(75, 75), 'quality'=>85, 'crop'=>false, 'letterbox'=>null, 'force_letterbox_color'=>false, 'sharpen'=>true), $htmlOptions=array(), $html=true) {		
		if((!isset($options['image'])) || (empty($options['image'])) || (!is_string($options['image']))) { return false; }
		if((!isset($options['size'])) || (!is_array($options['size']))) { return false; }
				
		// remove a slash if one was added accidentally. it doesn't matter either way now.
		// we're always going from the webroot to cover any image in the cake app (typically).
		if(substr($options['image'], 0, 1) == '/'): $options['image'] = substr($options['image'], 1); endif;
		
		// init the component, if it hasn't been initialized	
		if(!$this->component):
			$this->component =& ClassRegistry::init('ImageVersionComponent', 'Component');
		endif;
		
		$outputImage = $this->component->version($options);

		if($html === true):
			$link = $this->Html->image($outputImage, $htmlOptions);		
		else:
			$link = $outputImage;
		endif;

		return $this->output($link);	
	}
	
	/**
	* Deletes a single version thumbnail and/or deletes the entire directory of versions.
	*
	* @param $source String[required] Location of the source image.
	* @param $size Array[optional] Image version.
	* @param $clearAll Boolean[optional] Specify whether or not to remove all versions in a folder.
	* @return
	*/
	function flushVersion($source=null, $size=array(75,75), $clearAll=false) {
		if((is_null($source)) || (!is_string($source))): return false; endif;		
		// init the component, if it hasn't been initialized
		if(!$this->component):
			$this->component =& ClassRegistry::init('ImageVersionComponent', 'Component');
		endif;
		$flush = $this->component->flushVersion($source, $size, $clearAll);
		return;
	}
		
}
?>
