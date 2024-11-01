<?php
/*
Plugin Name: WP-ZoomImage With CopyProtect
Plugin URI: http://www.techtipsmaster.com/wp-zoomimagecopyprotect.html
Description: Integration of Copy Protect feature(disabling right clicks,mouse selection) with FancyZoom ,image-zooming code to overlay and pop up images on a page without having to load an entirely new page.
Version: 1.0
Author:Johnu George
Author URI: http://www.techtipsmaster.com/

License:

    The javascript file
    "adddomloadevent.js" is taken from
    http://www.thefutureoftheweb.com/blog/adddomloadevent and can be
    copied without restrictions. The javascript files "FancyZoom.js"
    and "FancyZoomHTML.js", hereafter refered to collectively as
    "FancyZoom", are Copyright 2008 Cabel Sasser / Panic Inc. and are
    NOT licensed under the GNU GPL. FancyZoom is free for use on
    non-commercial websites, but requires a one-time license fee per
    domain for commercial (for-profit) websites.

FancyZoom License:

    Redistribution and use of this effect in source form, with or
    without modification, are permitted provided that the following
    conditions are met:
 
    * USE OF SOURCE ON COMMERCIAL (FOR-PROFIT) WEBSITE REQUIRES
    ONE-TIME LICENSE FEE PER DOMAIN.
    Reasonably priced! Visit www.fancyzoom.com for licensing
    instructions. Thanks!

    * Non-commercial (personal) website use is permitted without
    * license/payment!
    
    * Redistribution of source code must retain the above copyright
    notice, this list of conditions and the following disclaimer.

    * Redistribution of source code and derived works cannot be sold
    without specific written prior permission.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND
    CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
    MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS
    BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
    EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
    TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
    DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
    ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
    TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF
    THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
    SUCH DAMAGE.
    
GNU General Public License:

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

define('KEY_FZOOM_DIR', 'fancyzoom_dir', FALSE);
define('KEY_FZOOM_COMPAT', 'popim_compat', FALSE);

add_option(KEY_FZOOM_DIR, fzoom_get_script_dir_url(),
	   'The path to the WP FancyZoom plugin on your website.');
add_option(KEY_FZOOM_COMPAT, 0, 'Is WP FancyZoom maintaining backwards compatibility with the old Simple Popup Images plugin?');

// Header hook
function insert_fancyzoom_javascript($unused) {
  $fancyzoom_path = get_option(KEY_FZOOM_DIR)."/";

  echo "\n<script type='text/javascript'>var zoomImagesBase = '".
    $fancyzoom_path."';</script>\n";
  echo "<script src='{$fancyzoom_path}js-global/FancyZoom.js' type='text/javascript'></script>\n";
  echo "<script src='{$fancyzoom_path}js-global/FancyZoomHTML.js' type='text/javascript'></script>\n";
  echo "<script src='{$fancyzoom_path}adddomloadevent.js' type='text/javascript'></script>\n";
  echo "<script type='text/javascript'>addDOMLoadEvent(setupZoom);</script>\n";
}


////
// The filter itself


// Filter posted text, looking for <popim> tags, and turning their
// contents into the appropriate popup. Originally this just worked
// on "<popim ... />" tags, but the TinyMCE editor auto-converts
// those tags to "<popim ...></popim>", so now the filter works on
// both.
function fzoom_popim_compatibility_filter($text) {
  $output = preg_replace("|<popim(.*?)/?>(\s*</\s*popim\s*>)?|ie",
  			 "fzoom_popim_parse_link_from_attribs('\\1')", $text);
  return $output;
}

// Take a bunch of options from a <popim /> tag and turn them into
// the appropriate <a href> and <img> tags. The <popim /> tag must have
// at least the URL to the image, the image title, and the thumbnail URL
function fzoom_popim_parse_link_from_attribs($str) {
  // Possible tag attributes
  $attrib_pattern = '(imageurl|title|thumbnailurl|'.
    'thumbwidth|thumbheight)';

  // Pull out all attributes and shove them into arrays. Attribute
  // strings are surrounded by quotemarks. We make sure not to
  // match on a quote preceded by a backslash. Since the slash
  // requirements are different for single versus double quotes
  // (i.e. "he's" is allowed w/o a backslash, but not 'he's')
  // we do two matches. Also note that double-quotes are passed
  // from WP/PHP with a backslash in front of them already
  $c1 = preg_match_all('/'.$attrib_pattern.' *= *\\\\"(.*?)(?<!\\\\)\\\\"/i',
		       $str, $attrib_matches1);
  $c2 = preg_match_all('/'.$attrib_pattern." *= *'(.*?)(?<!\\\\)'/i",
		       $str, $attrib_matches2);
  
  if (!$c1 && !$c2) {
    return "";
  }
  if ($c1) {
    $c1_combine = array_combine($attrib_matches1[1],$attrib_matches1[2]);
  }
  else {
    $c1_combine = array();
  }
  if ($c2) {
    $c2_combine = array_combine($attrib_matches2[1],$attrib_matches2[2]);
  }
  else {
    $c2_combine = array();
  }
  $attrib_array = array_merge($c1_combine, $c2_combine);
 
  $attrib_array = array_change_key_case($attrib_array, CASE_LOWER);
  $attrib_array['title'] = stripslashes($attrib_array['title']);

  if (!($attrib_array['imageurl'] && $attrib_array['thumbnailurl'])) {
    return "";
  }

  return fzoom_popim_generate_popup_link($attrib_array['imageurl'],
					 $attrib_array['title'],
					 $attrib_array['thumbnailurl'],
					 $attrib_array['thumbwidth'],
					 $attrib_array['thumbheight']);
}

// Generate the actual HTML for the popup, given certain information
// about the image, thumbnail, and more.
function fzoom_popim_generate_popup_link($url_to_image, $image_title,
					 $url_to_thumbnail, $thumbnail_width,
					 $thumbnail_height)
{
  $image_title_attrib = htmlspecialchars($image_title);

  // Create directory versions of the passed URLs. This assumes
  // that the file exists on the local server. We'll use this
  // as a fallback method for getting the image size
  $dir_to_thumbnail = fzoom_turn_url_to_dir($url_to_thumbnail);
  
  // If there is no server name in the passed URLs, add the
  // server's name.
  $url_to_image = fzoom_add_server_to_url($url_to_image);
  $url_to_thumbnail = fzoom_add_server_to_url($url_to_thumbnail);
  
  // If the thumbnail's size wasn't specified, compute it
  if ($thumbnail_width <= 0 || $thumbnail_height <= 0) {
    if (!($thumbnail_size = @getimagesize($url_to_thumbnail)) &&
	!($thumbnail_size = @getimagesize($dir_to_thumbnail))) {
      return "";
    }
    $thumbnail_width = $thumbnail_size[0];
    $thumbnail_height = $thumbnail_size[1];
  }

  return '<a title="'.$image_title_attrib.'" href="'.
    $url_to_image.'"><img src="'.$url_to_thumbnail.
    '" width="' . $thumbnail_width . 
    '" height="' . $thumbnail_height .
    '" alt="' . $image_title_attrib .
    '" title="' . $image_title_attrib .
    '" /></a>';
}

// Register our filters and actions
add_action('wp_head', 'insert_fancyzoom_javascript');
if (get_option(KEY_FZOOM_COMPAT)) {
  add_filter('the_content', 'fzoom_popim_compatibility_filter', 0);
  add_filter('the_excerpt', 'fzoom_popim_compatibility_filter', 0);
}


////
// Helper Functions


// We can't guarantee that we're running in PHP5 or later, so we can't
// guarantee the existence of array_combine(). So add it in if necessary.
if (!function_exists('array_combine')) {
  function array_combine($a, $b) {
    $c = array();
    if (is_array($a) && is_array($b))
      while (list(, $va) = each($a))
	if (list(, $vb) = each($b))
	  $c[$va] = $vb;
	else
	  break 1;
    return $c;
  }
}

// Convert a path to use Unix '/' directory separators if it doesn't
// already.
function fzoom_unixify_path($path) {
  return ((DIRECTORY_SEPARATOR != '/') ?
	  str_replace(DIRECTORY_SEPARATOR, '/', $path) : $path);
}

// Sometimes we might get URLs that lack the server. If so, prepend the
// current server URL to the passed URL
function fzoom_add_server_to_url($url) {
  // If there is no server name in the passed URLs, add the
  // server's name. But don't add a slash between the server
  // name and the URL if the URL already starts with a slash.
  if (substr($url, 0, 7) != 'http://' &&
      substr($url, 0, 8) != 'https://') {
    return 'http://'.$_SERVER['SERVER_NAME'].
      (substr($url, 0, 1) == '/' ? '' :
       '/') . $url;
  }
  return $url;
}

// Get the relative URL to the directory of the current (included) script.
// We can't use $_SERVER['PHP_SELF'] or anything clever like that, because
// that will point to whatever script called popup-images.php.
// Instead, we do some hackery. __FILE__ contains the local path to
// this (included) file. ABSPATH contains the local path to the
// Wordpress install. The WP option "siteurl" has the URL to the WP
// install.
function fzoom_get_script_dir_url() {
  $fzoom_script_dir = fzoom_unixify_path(dirname(__FILE__));
  $fzoom_abspath = fzoom_unixify_path(ABSPATH);
  $fzoom_url_dir_array = parse_url(get_option('siteurl'));
  $fzoom_url_path = $fzoom_url_dir_array['path'];
  // In theory ABSPATH - siteurl path gives the directory to the site 
  if (empty($fzoom_url_path)) {
    $dir_to_site = $fzoom_abspath;
  }
  else if (($i = strpos($fzoom_abspath, $fzoom_url_path)) === FALSE) {
    return '';
  }
  else {
    $dir_to_site = substr($fzoom_abspath, 0, $i);
  }
  if (empty($fzoom_script_dir) || strpos($fzoom_script_dir, $dir_to_site) === FALSE) {
    return '';
  }
  return str_replace($dir_to_site, '/', $fzoom_script_dir);
}

// Turn a URL into a directory.
function fzoom_turn_url_to_dir($url) {
  // To try to find a file that's specified by URL (for the cases
  // where PHP won't let us get to the file via URL), we try a
  // couple of different approaches. One, we use both the hostname
  // and path from the URL. Two, we use only the path. If the file
  // doesn't exist in the first case, we default to the second case.

  $homedir = get_option('fzoom_home_directory');

  $url_bits = parse_url($url);

  // Try the hostname + path approach
  $dir_from_url = $homedir.ltrim($url_bits['host'].$url_bits['path'], "/");
  if (file_exists($dir_from_url))
    return $dir_from_url;

  $dir_from_url = $homedir.ltrim($url_bits['path'], "/");

  return $dir_from_url;
}


////
// UI Elements

// Make sure the TinyMCE editor is okay with our new-fangled "popim"
// tag

add_filter('mce_valid_elements', 'fzoom_popim_mce_valid_elements', 0);

function fzoom_popim_mce_valid_elements($valid_elements) {
  $valid_elements .= '+popim[imageurl|title|thumbnailurl|imagewidth|'.
    'imageheight|thumbwidth|thumbheight|ispersistent|scrollbars]';
  return $valid_elements;
}

// Hook our submenu up with the admin menu manager
add_action('wp_head','PreventCopyBlogs');
add_action('wp_footer','PreventCopyBlogs_footer');
add_action('admin_menu', 'fzoom_submenu');

function PreventCopyBlogs()
{      
       	$wp_PreventCopyBlogs_nrc = get_option('PreventCopyBlogs_nrc');
	$wp_PreventCopyBlogs_nts = get_option('PreventCopyBlogs_nts');

	$wp_PreventCopyBlogs_nrc_t = get_option('PreventCopyBlogs_nrc_t');
	$wp_PreventCopyBlogs_nrc_text = get_option('PreventCopyBlogs_nrc_text');
	$pos = strpos(strtolower(getenv("REQUEST_URI")), '?preview=true');
	
	if ($pos === false) {
//if($wp_PreventCopyBlogs_nrc_t == true)
{
		if($wp_PreventCopyBlogs_nrc == true) 
		{
			if($wp_PreventCopyBlogs_nrc_t == true)
		{ PreventCopyBlogs_no_right_click_msg($wp_PreventCopyBlogs_nrc_text); }
			else
		{ PreventCopyBlogs_no_right_click($wp_PreventCopyBlogs_nrc_text); }
		}
		if($wp_PreventCopyBlogs_nts == true) { PreventCopyBlogs_no_select(); }
		if($wp_PreventCopyBlogs_ndd == true) { alert("hi");Prevent_Drag_Drop();}
		
	}
}
}

function PreventCopyBlogs_footer()
{
	//$wp_PreventCopyBlogs_nts = get_option('PreventCopyBlogs_nts');

	//if($wp_PreventCopyBlogs_nts == true) 
         { PreventCopyBlogs_no_select_footer(); }

}
// The options submenu, which appears under the Options admin menu
function fzoom_submenu() {
  if (function_exists('add_options_page'))
    add_options_page('WP-ZoomImage', 'WP-ZoomImage', 6, __FILE__, 'fzoom_options_page');
}

function PreventCopyBlogs_no_right_click_msg($PreventCopyBlogs_click_message)
{
if(!$PreventCopyBlogs_click_message){$PreventCopyBlogs_click_message="Please dont copy";}

?>

<script type="text/javascript">
<!--

var message="<?php echo $PreventCopyBlogs_click_message; ?>"+"\n"+"Your ip is recorded as "+"<?php $ip=$_SERVER['REMOTE_ADDR'];echo "$ip";?>"+"\n";

///////////////////////////////////
function clickIE4(){
if (event.button==2){
alert(message);
return false;
}
}

function clickNS4(e){
if (document.layers||document.getElementById&&!document.all){//Mozila if (!document.all && document.getElementById) type="MOzilla"; 
if (e.which==2||e.which==3){
alert(message);
return false;
}
}
}

if (document.layers){
document.captureEvents(Event.MOUSEDOWN);
document.onmousedown=clickNS4;
}
else if (document.all&&!document.getElementById){
document.onmousedown=clickIE4;
}

document.oncontextmenu=new Function("alert(message);return false")

// --> 
</script>

<?php
}
function PreventCopyBlogs_no_right_click($PreventCopyBlogs_click_message)
{
if(!$PreventCopyBlogs_click_message){$PreventCopyBlogs_click_message="Please dont copy";}

?>

<script type="text/javascript">
<!--

var message="<?php echo $PreventCopyBlogs_click_message; ?>"+"\n"+"Your ip is recorded as "+"<?php $ip=$_SERVER['REMOTE_ADDR'];echo "$ip";?>"+"\n";

///////////////////////////////////
function clickIE() {if (document.all) {(message);return false;}}
function clickNS(e) {if 
(document.layers||(document.getElementById&&!document.all)) {
if (e.which==2||e.which==3) {(message);return false;}}}
if (document.layers) 
{document.captureEvents(Event.MOUSEDOWN);document.onmousedown=clickNS;}
else{document.onmouseup=clickNS;document.oncontextmenu=clickIE;}

document.oncontextmenu=new Function("return false")
// --> 
</script>

<?php
}
function PreventCopyBlogs_no_select()
{
?>
<script type="text/javascript">
function disableSelection(target){
if (typeof target.onselectstart!="undefined") //For IE 
	target.onselectstart=function(){return false}
else if (typeof target.style.MozUserSelect!="undefined") //For Firefox
	target.style.MozUserSelect="none"
else //All other route (For Opera)
	target.onmousedown=function(){return false}
target.style.cursor = "default"
}

</script>

<?php
}
function Prevent_Drag_Drop()
{
?>
<script language="Javascript">
<!-- Begin
document.ondragstart = function(){return false}
// End -->
</script>
<?php
}
//Please dont remove link.Please give me a backlink for my work
function PreventCopyBlogs_no_select_footer()
// If you are removing this link please consider adding me in your blogroll or write a post about this plugin.
{
?>
<script type="text/javascript">
disableSelection(document.body)
</script>
<small>Copy Protected by <a href="http://www.techtipsmaster.com/" target="_blank">Tech Tips</a>'s & <a href="http://www.techtipsmaster.com/" target="_blank">Computer Tricks</a>'s<a href="http://www.techtipsmaster.com/wp-zoomimagecopyprotect.html" target="_blank">CopyProtect Wordpress Blogs</a>.</small>
<noscript><small>Copy Protected by <a href="http://www.techtipsmaster.com/" target="_blank">Tech Tips</a> & <a href="http://www.techtipsmaster.com/" target="_blank">Computer Tech Tips</a>'s<a href="http://www.techtipsmaster.com/" target="_blank">Computer Tricks</</noscript>
<?php
}




function fzoom_options_page() {
  // Store options if set in post
  if (isset($_POST['fzoom_update'])) {
    $fzoom_dir = $_POST[KEY_FZOOM_DIR];
    if ($fzoom_dir == '') $fzoom_dir = fzoom_get_script_dir_url();
    $fzoom_dir = trim($fzoom_dir);
    $fzoom_dir = str_replace('\\\\', '\\', $fzoom_dir);
    $fzoom_dir = str_replace('\\', '/', $fzoom_dir);
    $fzoom_dir = rtrim($fzoom_dir, '/');

    update_option(KEY_FZOOM_DIR, $fzoom_dir);

  		update_option('PreventCopyBlogs_nrc',$_POST['PreventCopyBlogs_nrc']);
		update_option('PreventCopyBlogs_nts',$_POST['PreventCopyBlogs_nts']);
                update_option('PreventCopyBlogs_nrc_t',$_POST['PreventCopyBlogs_nrc_t']);
		update_option('PreventCopyBlogs_nrc_text',$_POST['PreventCopyBlogs_nrc_text']);
		
    update_option(KEY_FZOOM_COMPAT, $_POST[KEY_FZOOM_COMPAT]);
    
    // Give an updated message
    echo "<div id='message' class='updated fade'><p><strong>".__('WP FancyZoom options updated')."</strong></p></div>";
  }
 	 $wp_PreventCopyBlogs_nrc = get_option('PreventCopyBlogs_nrc');
	$wp_PreventCopyBlogs_nts = get_option('PreventCopyBlogs_nts');
        $wp_PreventCopyBlogs_nrc_t=get_option('PreventCopyBlogs_nrc_t');
        
  ?>
<div class="wrap">
   <h2>WP-Zoom Image Copy Protect Options</h2>
   <form method="post" id="fzoom_options">
   <?php wp_nonce_field('update-options'); ?>
   <fieldset class="options">
   <legend>Plugin Options</legend>
   <table class="editform" cellspacing="2" cellpadding="5" width="100%">
   
   		<tr valign="top"> 
				<th width="33%" scope="row">Disable right mouse click:</th> 
				<td>
				<input type="checkbox" id="PreventCopyBlogs_nrc" name="PreventCopyBlogs_nrc" value="PreventCopyBlogs_nrc" <?php if($wp_PreventCopyBlogs_nrc == true) { echo('checked="checked"'); } ?> />
				check to activate <br /></td></tr>
                         <tr valign="top"> 
				<th width="33%" scope="row">Enable message to be displayed</th> 
				<td>
<input type="checkbox" id="PreventCopyBlogs_nrc_t" name="PreventCopyBlogs_nrc_t" value="PreventCopyBlogs_nrc_t" <?php if($wp_PreventCopyBlogs_nrc_t == true) { echo('checked="checked"'); } ?> />
				check to activate:If activated,message option below must be enabled. <br />

				<input name="PreventCopyBlogs_nrc_text" type="text" id="PreventCopyBlogs_nrc_text" value="<?php echo get_option('PreventCopyBlogs_nrc_text') ;?>" size="30"/>
				This warning will be given to users who right click .Above option must be checked for this feature.
				</td> 
			</tr>
			<tr valign="top"> 
				<th width="33%" scope="row">Disable text selection:</th> 
				<td>
				<input type="checkbox" id="PreventCopyBlogs_nts" name="PreventCopyBlogs_nts" value="PreventCopyBlogs_nts" <?php if($wp_PreventCopyBlogs_nts == true) { echo('checked="checked"'); } ?> />
				check to activate.
				</td> 
			</tr>
			
   
   </table>
   <input type="hidden" name="action" value="update" />
  
   <p class='submit'><input type='submit' name='fzoom_update' value='<?php _e('Update Options'); ?> &raquo;' /></p>
   </fieldset>
   </form>
   </div>
<?php
}


?>