<?php
/*
	Plugin Name: Brafton API Article Loader
	Plugin URI: http://www.brafton.com/support/wordpress
	Description: A Wordpress 2.9+ plugin designed to download articles from Brafton's API and store them locally, along with attached media.
	Version: 1.3.6
	Author: Brafton, Inc.
	Author URI: http://brafton.com/support/wordpress
*/

/* options are deleted in case of plugin deactivation */
require_once(ABSPATH . 'wp-admin/includes/admin.php');
require_once(ABSPATH . 'wp-includes/post.php');
include_once 'SampleAPIClientLibrary/ApiHandler.php';
include_once 'sitemap.php';

add_action('deactivate_BraftonWordpressPlugin/BraftonWordpressPlugin.php', 'braftonxml_sched_deactivate');
add_action('delete_term', "brafton_category_delete");
add_action('delete_term', "brafton_tag_delete");

// allow script & iframe tag within posts
function allow_post_tags( $allowedposttags ){
    $allowedposttags['script'] = array(
        'type' => true,
        'src' => true,
        'height' => true,
        'width' => true,
    );
    $allowedposttags['iframe'] = array(
        'src' => true,
        'width' => true,
        'height' => true,
        'class' => true,
        'frameborder' => true,
        'webkitAllowFullScreen' => true,
        'mozallowfullscreen' => true,
        'allowFullScreen' => true
    );
$allowedposttags["object"] = array(
 "height" => array(),
 "width" => array()
);
 
$allowedposttags["param"] = array(
 "name" => array(),
 "value" => array()
);
 
$allowedposttags["embed"] = array(
 "src" => array(),
 "type" => array(),
 "allowfullscreen" => array(),
 "allowscriptaccess" => array(),
 "height" => array(),
 "width" => array()
);
$allowedposttags['a'] = array(
        'data-br-form-id' => true,
            'href' => true,
            'rel' => true,
            'rev' => true,
            'name' => true,
            'target' => true,
            'class' => true
    );
$allowedposttags['video'] = array(
        'id' => true,
            'class' => true,
            'controls' => true,
            'preload' => true,
            'height' => true,
            'width' => true,
            'poster' => true
    );
$allowedposttags['source'] = array(
        'src' => true,
            'type' => true,
            'data-resolution' => true
    );
$allowedposttags['link'] = array(
        'href' => true,
            'type' => true,
            'rel' => true
    );
    
$allowedposttags['style'] = array(
        
            'type' => true,
            'scoped' => true,
	    'media' => true
    );

$allowedposttags['input'] = array(

        'id' => true,
        'type' => true,
        'name' => true, 
		'value' => true
    );

    return $allowedposttags;
}

function js_protocols($protocols) {
   $protocols[] = 'javascript';
   return $protocols;
}

add_filter('wp_kses_allowed_html','allow_post_tags', 1);
add_filter( 'kses_allowed_protocols', 'js_protocols' );


function debugTimer($msg = "DebugTimer")
{
	global $starttime;
	global $lasttime;
	$mtime = microtime();
	$mtime = explode(" ", $mtime);
	$mtime = $mtime[1] + $mtime[0];
	$endtime = $mtime;
	$totaltime = ($endtime - $starttime);
	$sinceLasttime = ($lasttime - $endtime);
	$sinceLasttime = substr($sinceLasttime, 0, 5);
	$totaltime = substr($totaltime, 0, 5);
	$_SESSION['debugTimer'] .= $msg . "   " . $totaltime . " sec (" . $sinceLasttime . ")<br/>";
	
	$lasttime = $endtime;
}

function logMsg($msg)
{
	$msg = date("m/d/Y h:i:s A") . " - " . $msg . "\n";
	$logLoc = logLoc();
	if ($logLoc == false)
		return;
	
	if (file_put_contents($logLoc, $msg, FILE_APPEND) == false)
		echo "<span style='color:red'>There was a problem writing to the log at " . $logLoc . ", it is likely a file permissions issue.</span>";
}

function logLoc()
{
	if (get_option("braftonxml_log_loc") == "none")
		return false;
	
	$loc = plugin_dir_path(__FILE__) . "/log.txt";
	$loc2 = plugin_dir_path(__FILE__) . "log.txt";
	
	if (get_option("braftonxml_log_loc") == "loc")
		return $loc;
	
	if (get_option("braftonxml_log_loc") == "loc2")
		return $loc2;
	
	$msg = "Establishing log.txt location\n";
	if (file_put_contents($loc, $msg, FILE_APPEND) == false)
	{
		if (file_put_contents($loc2, $msg, FILE_APPEND) == false)
			update_option("braftonxml_log_loc", "none");
		else
			update_option("braftonxml_log_loc", "loc2");
	}
	else
		update_option("braftonxml_log_loc", "loc");
	
	return $loc;
}

function clearLog()
{
	if (get_option("braftonxml_log_loc") == "loc")
		return plugin_dir_path(__FILE__) . "/log" . date("_m_d_Y__h_i_s") . ".txt";
	
	if (get_option("braftonxml_log_loc") == "loc2")
		return plugin_dir_path(__FILE__) . "log" . date("_m_d_Y__h_i_s") . ".txt";
	
	return false;
}

function braftonCurPageURL()
{
	$pageURL = 'http';
	
	if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER["HTTPS"]) == "on")
		$pageURL .= "s";
	
	$pageURL .= "://";
	
	if ($_SERVER["SERVER_PORT"] != "80")
		$pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
	else
		$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
	
	return $pageURL;
}

function brafton_category_delete()
{
	delete_cat_tag("cat");
}

function brafton_tag_delete()
{
	delete_cat_tag("tag");
}

function delete_cat_tag($catortag)
{
	global $wpdb;
	$db;
	$input;
	
	if ($catortag == "cat")
	{
		$db = "category";
		$input = "braftonxml_sched_cats_input";
	}
	else
	{
		$db = "post_tag";
		$input = "braftonxml_sched_tags_input";
	}
	
	$tname []= $wpdb->get_results("select wp.name from wp_terms wp, wp_term_taxonomy wpt where wp.term_id=wpt.term_id and wpt.taxonomy='$db'");
	$brafton_table = explode(",", get_option($input));
	$haystack = array();
	for ($x = 0; $x < count($tname); $x++)
		for ($z = 0; $z < count($tname[$x]); $z++)
			$haystack[] = $tname[$x][$z]->name;
	
	$count = count($brafton_table);
	for ($i = 0; $i < $count; $i++)
	{
		$brafton_table[$i] = trim($brafton_table[$i]);
		if (!(in_array($brafton_table[$i], $haystack)))
			unset($brafton_table[$i]);
	}
	
	$string = implode(",", $brafton_table);
	update_option($input, $string);
}

function braftonxml_sched_deactivate()
{
	delete_option("braftonxml_sched_url");
	delete_option("braftonxml_sched_recc");
	delete_option("braftonxml_sched_triggercount");
	delete_option("braftonxml_sched_API_KEY");
	delete_option("braftonxml_domain");
}

add_action("init", "clear_crons_left");
function clear_crons_left()
{
	wp_clear_scheduled_hook("braftonxml_sched_hook");
}

/* Admin options page display function is called */
add_action('admin_menu', 'braftonxml_sched_add_admin_pages');
function braftonxml_sched_add_admin_pages()
{
	add_options_page('Brafton Article Loader', 'Brafton Article Loader', 'update_plugins', __FILE__, 'braftonxml_sched_options_page');
}

/* Options sent by the options form are set here */
/* Schedules are activated and deactivated */
add_action('init', 'braftonxml_sched_setoptions');
function braftonxml_sched_setoptions()
{
	global $feedSettings;
	
	if (!empty($_POST['braftonxml_default_author']))
		update_option("braftonxml_default_author", $_POST['braftonxml_default_author']);
	
	if (!empty($_POST['braftonxml_sched_API_KEY']))
		update_option("braftonxml_sched_API_KEY", $_POST['braftonxml_sched_API_KEY']);
	
	if (!empty($_POST['braftonxml_domain']))
	{
		update_option("braftonxml_domain", $_POST['braftonxml_domain']);
		update_option("braftonxml_sched_url", 'http://' . $_POST['braftonxml_domain']);
	}
	
	//update_option("braftonxml_sched_url", 'http://api.brafton.com');
	
	if (!empty($_POST['braftonxml_sched_tags']))
		update_option("braftonxml_sched_tags", $_POST['braftonxml_sched_tags']);
	
	if (!empty($_POST['braftonxml_sched_tags_input']))
		update_option("braftonxml_sched_tags_input", $_POST['braftonxml_sched_tags_input']);
	
	if (!empty($_POST['braftonxml_sched_cats']))
		update_option("braftonxml_sched_cats", $_POST['braftonxml_sched_cats']);
	
	if (!empty($_POST['braftonxml_sched_cats_input']))
		update_option("braftonxml_sched_cats_input", $_POST['braftonxml_sched_cats_input']);
	
	if (!empty($_POST['braftonxml_sched_photo']))
		update_option("braftonxml_sched_photo", $_POST['braftonxml_sched_photo']);
	
	if (!empty($_POST['braftonxml_sched_status']))
		update_option("braftonxml_sched_status", $_POST['braftonxml_sched_status']);
	
	if (!empty($_POST['braftonxml_overwrite']))
		update_option("braftonxml_overwrite", $_POST['braftonxml_overwrite']);
	
	if (!empty($_POST['braftonxml_publishdate']))
		update_option("braftonxml_publishdate", $_POST['braftonxml_publishdate']);
	
	if (!empty($_POST['braftonxml_video']))
		update_option("braftonxml_video", $_POST['braftonxml_video']);
	
	if (!empty($_POST['braftonxml_videoPublic']))
		update_option("braftonxml_videoPublic", $_POST['braftonxml_videoPublic']);
	
	if (!empty($_POST['braftonxml_videoSecret']))
		update_option("braftonxml_videoSecret", $_POST['braftonxml_videoSecret']);
	
	if (isset($_POST['braftonxml_videoFeedNum'])) 
		update_option("braftonxml_videoFeedNum", $_POST['braftonxml_videoFeedNum']);

	if (!empty($_POST['brafton_video_embed']))
		update_option("brafton_video_embed", $_POST['brafton_video_embed']);

	if (!empty($_POST['brafton_atlantis_jquery']))
		update_option("brafton_atlantis_jquery", $_POST['brafton_atlantis_jquery']);
		
	if (!empty($_POST['brafton_atlantis_extra_css']))
		update_option("brafton_atlantis_extra_css", $_POST['brafton_atlantis_extra_css']);
    
    if (!empty($_POST['braftonxml_dynamic_author']))
		update_option("braftonxml_dynamic_author", $_POST['braftonxml_dynamic_author']);

	//Video CTA options
	if (!empty($_POST['brafton_pause_txt']))
		update_option("brafton_pause_txt", $_POST['brafton_pause_txt']);

	if (!empty($_POST['brafton_pause_link']))
		update_option("brafton_pause_link", $_POST['brafton_pause_link']);

	if (!empty($_POST['brafton_endcta_title']))
		update_option("brafton_endcta_title", $_POST['brafton_endcta_title']);

	if (!empty($_POST['brafton_endcta_subtitle']))
		update_option("brafton_endcta_subtitle", $_POST['brafton_endcta_subtitle']);

	if (!empty($_POST['brafton_endcta_btnlink']))
		update_option("brafton_endcta_btnlink", $_POST['brafton_endcta_btnlink']);

	if (!empty($_POST['brafton_endcta_btntxt']))
		update_option("brafton_endcta_btntxt", $_POST['brafton_endcta_btntxt']);

	$feedSettings = array(
		"url" => get_option("braftonxml_sched_url"),
		"API_Key" => get_option("braftonxml_sched_API_KEY")
	);
	
	if (!empty($_POST['braftonxml_sched_save']))
	{
		braftonxml_clear_all_crons('braftonxml_sched_hook');
		wp_schedule_event(time() + 3600, "hourly", "braftonxml_sched_hook", $feedSettings);
		braftonxml_sched_trigger_schedule($feedSettings['url'], $feedSettings['API_Key']);
		
	} else if (!empty($_POST['braftonxml_sched_stop']))
	{
		$timestamp = wp_next_scheduled('braftonxml_sched_hook', $feedSettings);
		/* This is where the event gets unscheduled */
		wp_unschedule_event($timestamp, "braftonxml_sched_hook", $feedSettings);
		
	}else if (!empty($_POST['braftonxml_sched_submit']))
	{
		/* This is where the actual recurring event is scheduled */
		if (!wp_next_scheduled('braftonxml_sched_hook', $feedSettings))
		{
			braftonxml_clear_all_crons('braftonxml_sched_hook');
			wp_schedule_event(time() + 3600, "hourly", "braftonxml_sched_hook", $feedSettings);
			braftonxml_sched_trigger_schedule($feedSettings['url'], $feedSettings['API_Key']);
		}
	}
}

function braftonxml_admin_notice()
{
	$feedSettings = array(
		"url" => get_option("braftonxml_sched_url"),
		"API_Key" => get_option("braftonxml_sched_API_KEY")
	);
	
	if (!wp_next_scheduled('braftonxml_sched_hook', $feedSettings))
	{
		echo '<div class="error">
				<p>Article Importer not enabled.</p>
				</div>';
	}
}

add_action('wp_head', 'brafton_videojs_scripts');

function brafton_videojs_scripts(){
	$embed = get_option("brafton_video_embed");
	
	if($embed=="videojs"){
		echo '<link href="//vjs.zencdn.net/4.3/video-js.css" rel="stylesheet"><script src="//vjs.zencdn.net/4.3/video.js"></script>';

	}else if($embed=="atlantis"){
        if(get_option("brafton_atlantis_jquery")=="on") echo '<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>';
        
		echo '<link rel="stylesheet" href="//atlantisjs.brafton.com/v1/atlantisjsv1.3.css" type="text/css" /><script src="//atlantisjs.brafton.com/v1/atlantis.min.v1.3.js" type="text/javascript"></script>';
		
		
		if(get_option("brafton_atlantis_extra_css")=="on") {
		$css=<<<EOT
		<style type="text/css">
		.vjs-menu{
		width:10em!important;
		left:-4em!important;
		}

		.ajs-default-skin div.vjs-big-play-button span{
		top:70%!important;
		}

		.ajs-default-skin{
		-moz-box-shadow: 2px 2px 4px 3px #ccc;
		-webkit-box-shadow: 2px 2px 4px 3px #ccc;
		box-shadow: 2px 2px 4px 3px #ccc;
		}

		.ajs-call-to-action-button{
		width:200px!important;
		color: #58795B!important;
		margin-left:0px!important;
		}

		.ajs-call-to-action-button a{
		color:darkslateblue!important;
		}

		.ajs-call-to-action-button a:visited{
		color:darkslateblue!important;
		}
		</style>
EOT;
		echo $css;
		}	
		
	}

}

add_action('wp_head', 'braftonxml_inject_opengraph_tags');
function braftonxml_inject_opengraph_tags()
{
	if (!is_single())
		return;
	
	global $post;
	$tags = array(
		'og:type' => 'article',
		'og:site_name' => get_bloginfo('name'),
		'og:url' => braftonCurPageURL(),
		'og:title' => preg_replace('/<.*?>/', '', get_the_title()),
		'og:description' => htmlspecialchars(preg_replace('/<.*?>/', '', get_the_excerpt())),
		'og:image' => wp_get_attachment_url(get_post_thumbnail_id($post->ID)),
		'article:published_time' => date('c', strtotime($post->post_date))
	);
	
	$tagsHtml = '';
	foreach ($tags as $tag => $content)
		$tagsHtml .= sprintf('<meta property="%s" content="%s" />', $tag, $content) . "\n";
	
	echo trim($tagsHtml);
}

// this runs last (or late) to minimize plugin conflicts
add_filter('language_attributes', 'braftonxml_inject_opengraph_namespaces', 100);
function braftonxml_inject_opengraph_namespaces($content)
{
	$namespaces = array(
		'xmlns:og="http://ogp.me/ns#"',
		'xmlns:article="http://ogp.me/ns/article#"'
	);
	
	foreach ($namespaces as $ns)
		if (strpos($content, $ns) === false) // don't add attributes twice
			$content .= ' ' . $ns;
	
	return trim($content);
}


add_action('admin_notices', 'braftonxml_admin_notice');



function braftonxml_clear_all_crons($hook)
{
	$crons = _get_cron_array();
	if (empty($crons))
		return;
	
	foreach ($crons as $timestamp => $cron)
		if (!empty($cron[$hook]))
			unset($crons[$timestamp][$hook]);
	_set_cron_array($crons);
}

/* This is the scheduling hook for our plugin that is triggered by cron */
add_action('braftonxml_sched_hook', 'braftonxml_sched_trigger_schedule', 10, 2);
function braftonxml_sched_trigger_schedule($url, $API_Key)
{
	braftonxml_sched_load_articles($url, $API_Key);
	update_option("braftonxml_sched_triggercount", get_option("braftonxml_sched_triggercount") + 1);
	
	// HACK: posts are duplicated due to a lack of cron lock resolution (see http://core.trac.wordpress.org/ticket/19700)
	// this is fixed in wp versions >= 3.4.
	$wpVersion = get_bloginfo('version');
	
	if (version_compare($wpVersion, '3.4', '<'))
		duplicateKiller();
}

/* The options page display */
function braftonxml_sched_options_page()
{
	add_option("braftonxml_sched_cats", "categories");
	
	add_option("braftonxml_sched_API_KEY", "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx");
	add_option("braftonxml_domain", "api.brafton.com");
	add_option("braftonxml_sched_photo", "large");
	add_option("braftonxml_sched_status", "publish");
	add_option("braftonxml_sched_tags", "none_tags");
	add_option("braftonxml_overwrite", "off");
	add_option("braftonxml_publishdate", "published");
    add_option("braftonxml_dynamic_author", "n");
	
	add_option("braftonxml_video", "off");
	add_option("braftonxml_videoPublic", "xxxxx");
	add_option("braftonxml_videoSecret", "xxxxx");
	add_option("braftonxml_videoFeedNum", "0");
	add_option("brafton_atlantis_jquery", "on");
	add_option("brafton_atlantis_extra_css", "off");
	add_option("brafton_video_embed","videojs");
	add_option("brafton_pause_link","");
	add_option("brafton_pause_txt","");
	add_option("brafton_endcta_title","");
	add_option("brafton_endcta_subtitle","");
	add_option("brafton_endcta_btnlink","");
	add_option("brafton_endcta_btntxt","");
?>

			<script type="text/javascript">
			function hideshow(which){
				if (!document.getElementById)
					return;
				if (which.style.display=="block")
					which.style.display="none";
				else
					which.style.display="block";
			}
			
			//function keyCheck(){
			//	if($('input[id=just_articles]:checked')){
			//		if(document.getElementById('brafton_api_key').value=='xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
			//		{
			//			return false;
			//		} else return true;
			//	}else if($('input[id=just_video]:checked')){
			//		if($('input[id=brafton_video_public]:checked').val=='xxxxx' || $('input[id=brafton_video_secret]:checked').val=='xxxxx')
			//		{
			//			return false;
					// } else return true;
				// }else{
			//		both, check all of them
					// if(document.getElementById('brafton_video_secret').value=='xxxxx' || 
						// document.getElementById('brafton_video_public').value=='xxxxx' || 
						// document.getElementById('brafton_api_key').value=='xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
					// {
						// return false;
					// } else return true;
				// }
			// }
			</script>

			<style>
			.awesomeButton {
				background: #222 url(http://www.zurb.com/blog_uploads/0000/0617/alert-overlay.png) repeat-x;
				display: inline-block;
				padding: 5px 10px 6px;
				color: #fff;
				text-decoration: none;
				font-weight: bold;
				line-height: 1;
				-moz-border-radius: 5px;
				-webkit-border-radius: 5px;
				-moz-box-shadow: 0 1px 3px rgba(0,0,0,0.5);
				-webkit-box-shadow: 0 1px 3px rgba(0,0,0,0.5);
				text-shadow: 0 -1px 1px rgba(0,0,0,0.25);
				border-bottom: 1px solid #222;
				position: relative;
				cursor: pointer;
				font-size: 14px;
				padding: 8px 14px 9px;
				border:none;
			}

			.awesomeButton:hover							{ background-color: #111; color: #fff; }
			.awesomeButton:active							{ top: 1px; }

			.redAwesomeButton{
				background-color: #e33100;
			}

			.greenAwesomeButton{
				background-color: #00BF32;
			}
			.blueAwesomeButton{
				background-color: blue;
			}
			
			#video-settings {
				border: solid #E6E6E6 1px;
				background-color: #F5F5F5;
				display:inline-block;
				padding:10px;
			}
			
			#video-settings h3 {
				color: red;
			}
			</style>

			<div class="wrap">
				<h1>Content Importer</h1>



<?php
	if (!function_exists('curl_init'))
		echo "<li>WARNING: <b>cURL</b> is disabled or not installed on your server. cURL is required for this plugin's operation.</li>";
	
	$video_option=get_option("braftonxml_video");
	
	if (get_option("braftonxml_sched_API_KEY")=='xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' && $video_option!="on") 
		echo "<span style='color:red'>Please check your API Key.</span><br/><br/>"; 
		
	//if (get_option("braftonxml_videoSecret")=='xxxxx' || get_option("braftonxml_videoPublic")=='xxxxx' && $video_option!="off") 
	//	echo "<span style='color:red'>Please check your API Key.</span><br/><br/>"; 
?>              

				<?php $wp_version=get_bloginfo('version');
				if(version_compare($wp_version, '2.9') >= 0) {
					if(!current_theme_supports( 'post-thumbnails' )) {
				?>
					<div style="padding: 10px; border: 1px solid #cccccc;">
						<?php echo "<br/><span style='color:red;'> Post Thumbnails (Featured Images) are not enabled!</span><br/>"; ?>
					</div>
				<?php
					}
				}
				?>
<?php
	global $feedSettings;
	if (wp_next_scheduled('braftonxml_sched_hook', $feedSettings))
	{
?>
						<p><b>Content importer is scheduled!</b></p>
						<pre>
<?php
		$crons = _get_cron_array();
		$countCron = count($crons);
		
		foreach ($crons as $timestamp => $cron)
		{
			if (isset($cron['braftonxml_sched_hook']))
			{
				echo 'Time now:' . " \t\t\t" . date(get_option('date_format')) . " " . date("H:i:s") . "<br />";
				echo 'Schedule will be triggered:' . " \t" . date(get_option('date_format'), $timestamp) . " " . date("H:i:s", $timestamp) . "<br />";
				$timestamp += 60;
				if ($timestamp < time())
				{
					echo '<p style="color:red;">Warning: Importer\'s last import run did not complete.  Please contact your Content Marketing Specialist for support.</p>';
					//echo $timestamp."<".time();
				}
			}
		}
?>
					</pre>
					<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
						<input type="submit" name="braftonxml_sched_stop" id="braftonxml_sched_stop" class="awesomeButton redAwesomeButton" value="Disable Importer" />
					</form>
					<br/>
					<form method="post" enctype="multipart/form-data" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
						<input type="submit" name="braftonxml_sched_save" id="braftonxml_sched_save" class="awesomeButton blueAwesomeButton" value="Save Importer" />
<?php
		if (get_option("braftonxml_sched_triggercount") > 0)
		{
?>
						<p>Import schedule was triggered <?php echo get_option("braftonxml_sched_triggercount"); ?> times.</p>
<?php
		}
	}else { ?>
		<b style="color:red">Content importer is Not scheduled!</b>
		
		<form method="post" enctype="multipart/form-data" action="<?php echo $_SERVER["REQUEST_URI"]; ?>"><br/><br/>

		<input type="submit" name="braftonxml_sched_submit" id="braftonxml_sched_submit" class="awesomeButton greenAwesomeButton" value="Enable Importer" /><br/><br/>
		
	<?php }
?>

<!-- Remove references to the logging functionality -->
<!--<?php
	if (!isset($_GET['showLog']) || $_GET['showLog'] == 0)
	{
		$logURL = braftonCurPageURL() . '&showLog=1';
?>
						<a href="<?php echo $logURL; ?>">Display Log</a>
<?php
	}
	else
	{
		$filename = logLoc();
		$handle = fopen($filename, "r");
		if ($handle == false)
			"<span style='color:red'>There was a problem opening the log file, this is likely due to a file permission issue.</span>";
		$contents = fread($handle, filesize($filename));
		echo "<pre>" . $contents . "</pre>";
		fclose($handle);
	}
?>


<?php
	if (!isset($_GET['clearLog']) || $_GET['clearLog'] == 0)
	{
		$logURL = braftonCurPageURL() . '&clearLog=1';
?>
							<a href="<?php echo $logURL; ?>">Clear Log</a>
<?php
	}
	else
	{
		$filename = logLoc();
		$newName = clearLog();
		if (rename($filename, $newName) == false)
			echo "<span style='color:red;'>Error clearing log file, likely permissions error.</span><br><br>";
	}
?>-->
<?php
		$domain = get_option("braftonxml_domain");
?>

		<br><br>
								<b><u>API Domain</u></b><br />
								<select name='braftonxml_domain'>
									<option value="api.brafton.com" <?php
		if ($domain == 'api.brafton.com')
			echo 'SELECTED';
?>>Brafton</option>
									<option value="api.contentlead.com" <?php
		if ($domain == 'api.contentlead.com')
			echo 'SELECTED';
?>>ContentLEAD</option>
									<option value="api.castleford.com.au" <?php
		if ($domain == 'api.castleford.com.au')
			echo 'SELECTED';
?>>Castleford</option>

								</select><br/>http://<?php echo get_option("braftonxml_domain"); ?>/<br/><br/>

								<b><u>API Key</u></b><br /> 



								<input type="text" name="braftonxml_sched_API_KEY" id="brafton_api_key" value="<?php echo get_option("braftonxml_sched_API_KEY"); ?>" /><br />
								Example: 2de93ffd-280f-4d4b-9ace-be55db9ad4b7<br/>
								<br/>Importer will run every hour<br />
								
                                <br/>
                                <b><u>Dynamic Author</u></b><br/>
                                <span><font size="-2"><i>Sets Author to "byLine" From the feed.  If the Author does not exsist they will be added.<br/> Default auhor is returned if no author is set int he field or if new author cannot be created.</i></font></span><br/>
                                <input type="radio" name="braftonxml_dynamic_author" value="y" <?php
		if (get_option("braftonxml_dynamic_author") == 'y')
		{
			print 'checked';
		}
?> />Use Dynamic Authors<br />                
									<input type="radio" name="braftonxml_dynamic_author" value="n" <?php
		if (get_option("braftonxml_dynamic_author") == 'n')
		{
			print 'checked';
		}
?> />Do Not Use Dynamic Authors<br />
            
								<br />
                                <div id="brafton_show_author" style="display:block">
								<b><u>Default Post Author</u></b><br />                                       
<?php
		wp_dropdown_users(array(
			'name' => 'braftonxml_default_author',
			'hide_if_only_one_author' => true,
			'selected' => get_option("braftonxml_default_author", false)
		));
?>
									<br />
									<br />                
									<b><u>Categories</u></b><br />                                     
									<input type="radio" name="braftonxml_sched_cats" value="categories" <?php
		if (get_option("braftonxml_sched_cats") == 'categories')
		{
			print 'checked';
		}
?> /> Brafton Categories<br />                
									<input type="radio" name="braftonxml_sched_cats" value="none_cat" <?php
		if (get_option("braftonxml_sched_cats") == 'none_cat')
		{
			print 'checked';
		}
?> /> None<br />
            </div>
									<table>
										<tr><td>Enter custom <b>categories</b>: <input type="text" name="braftonxml_sched_cats_input" value="<?php
		echo get_option("braftonxml_sched_cats_input", "");
?>"/></td></tr>             
										<tr><td><font size="-2"><i>Each category separated by a comma(first, second, third)</i></font></td></tr>
					<!--  				<tr><td style="text-indent: 20px;"><i>Applied to all articles: </i><input type="radio" name="braftonxml_sched_cus_cat" value="all" <?php //if (get_option("braftonxml_sched_cus_cat") == 'all') { print 'checked'; }
?> /></td></tr> 
						<tr><td style="text-indent: 20px;"><i>Applied to no articles: </i> <input type="radio" name="braftonxml_sched_cus_cat" value="no" <?php //if (get_option("braftonxml_sched_cus_cat") == 'no') { print 'checked'; }
?> /></td></tr> 
					-->				 
				</table>                                            
				<br />             
				<br />

				<b><u>Default post status</u></b><br />                     
				<input type="radio" name="braftonxml_sched_status" value="publish" <?php
		if (get_option("braftonxml_sched_status") == 'publish')
		{
			print 'checked';
		}
?> /> Published<br />
				<input type="radio" name="braftonxml_sched_status" value="draft" <?php
		if (get_option("braftonxml_sched_status") == 'draft')
		{
			print 'checked';
		}
?>/> Draft<br />

				<br />             
				
				<br />

				<a href="javascript:hideshow(document.getElementById('advancedOptions'))" id='advancedOptionsButton'>Display Advanced Options</a>
				<div id='advancedOptions' style='display:none;border:thin solid #DFDFDF;padding:5px;'>

					<b><u>Tags</u></b><br />                
					<input type="radio" name="braftonxml_sched_tags" value="tags" <?php
		if (get_option("braftonxml_sched_tags") == 'tags')
		{
			print 'checked';
		}
?> /> Brafton Tags as tags<br />                    
					<input type="radio" name="braftonxml_sched_tags" value="keywords" <?php
		if (get_option("braftonxml_sched_tags") == 'keywords')
		{
			print 'checked';
		}
?> /> Brafton Keywords as tags<br />
					<input type="radio" name="braftonxml_sched_tags" value="cats" <?php
		if (get_option("braftonxml_sched_tags") == 'cats')
		{
			print 'checked';
		}
?> /> Brafton Categories as tags <br />  
					<input type="radio" name="braftonxml_sched_tags" value="none_tags" <?php
		if (get_option("braftonxml_sched_tags") == 'none_tags')
		{
			print 'checked';
		}
?> /> None <br />
					<table>
						<tr><td> Enter custom <b>tags</b>: <input type="text" name="braftonxml_sched_tags_input" value="<?php
		echo get_option("braftonxml_sched_tags_input", "");
?>"/><br /></td></tr>
						<tr><td><font size="-2"><i>Each tag separated by a comma(first, second, third)</i></font></td></tr>             			
					<!--  				<tr><td style="text-indent: 20px;"><i>Applied to all articles: </i><input type="radio" name="braftonxml_sched_cus_tags" value="all" <?php //if (get_option("braftonxml_sched_cus_tags") == 'all') { print 'checked'; }
?> /></td></tr> 
						<tr><td style="text-indent: 20px;"><i>Applied to no articles: </i> <input type="radio" name="braftonxml_sched_cus_tags" value="no" <?php //if (get_option("braftonxml_sched_cus_tags") == 'no') { print 'checked'; }
?> /></td></tr> 
					-->
				</table>              
				<br />

				<b><u>Upload a specific Archive Feed</b></u><br>
				<input type="file" name="archive" size="40">
				<br />
				<br />

				<b><u>Include Updated Feed Content</u></b><br />        
				<font size="-2"><i>If option set to "On," any edits made to posts will be overwritten.</i></font><br />
				<input type="radio" name="braftonxml_overwrite" value="on" <?php
		if (get_option("braftonxml_overwrite") == 'on')
		{
			print 'checked';
		}
?> /> On<br />
				<input type="radio" name="braftonxml_overwrite" value="off" <?php
		if (get_option("braftonxml_overwrite") == 'off')
		{
			print 'checked';
		}
?>/> Off<br />

				<br />

				<b><u>Set date to: Publish, Last Modified or Created Date</u></b><br />        
				<font size="-2"><i></i></font><br />
				<input type="radio" name="braftonxml_publishdate" value="published" <?php
		if (get_option("braftonxml_publishdate") == 'published')
		{
			print 'checked';
		}
?> /> Publish Date<br />
				<input type="radio" name="braftonxml_publishdate" value="modified" <?php
		if (get_option("braftonxml_publishdate") == 'modified')
		{
			print 'checked';
		}
?>/> Last Modified Date<br />
				<input type="radio" name="braftonxml_publishdate" value="created" <?php
		if (get_option("braftonxml_publishdate") == 'created')
		{
			print 'checked';
		}
?>/> Created Date<br />

				<br /> 
				<div id="video-settings">
				<h3>**Please do not adjust these settings unless you are importing video.**</h3>
				<b><u>Brafton Video Integration</u></b><br />        

				<input type="radio" id="just_video" name="braftonxml_video" value="on" <?php
		if ($video_option == 'on')
		{
			print 'checked';
		}
?> /> Just Video<br />
				<input type="radio" id="just_articles" name="braftonxml_video" value="off" <?php
		if ($video_option == 'off')
		{
			print 'checked';
		}
?>/> Just Articles<br />
				<input type="radio" id="both_articles_video" name="braftonxml_video" value="both" <?php
		if ($video_option == 'both')
		{
			print 'checked';
		}
?>/> Both Articles and Video<br />
				<br /> 
				<b><u>Public Key</u></b><br />   
				<input type="text" name="braftonxml_videoPublic" id="brafton_video_public" value="<?php
		echo get_option("braftonxml_videoPublic");
?>" /><br />
				<br /> 
				<b><u>Private Key</u></b><br />   
				<input type="text" name="braftonxml_videoSecret" id="brafton_video_secret" value="<?php
		echo get_option("braftonxml_videoSecret");
?>" /><br />
				<br /> 
				<b><u>Feed Number</u></b><br />   
				<input type="text" name="braftonxml_videoFeedNum" value="<?php
		echo get_option("braftonxml_videoFeedNum");
?>" /><br /><br />

<?php $video_player = get_option('brafton_video_embed','videojs');?>
				<b><u>Embed Player Header Script</u></b><br />   
				<font size="-2"><i>Selecting 'Neither' will still import videojs embed code, this is just the script imports.  Turn if off for sites that already have video js script in the header.</i></font><br />
				<input type="radio" id="embed_type" name="brafton_video_embed" value="videojs" <?php
						if ($video_player == 'videojs')
						{
							print 'checked';
						}
				?> /> VideoJS<br />
								<input type="radio" id="atlantis" name="brafton_video_embed" value="atlantis" <?php
						if ($video_player == 'atlantis')
						{
							print 'checked';
						}
				?>/> Atlantis<br />
								<input type="radio" id="neither" name="brafton_video_embed" value="off" <?php
						if ($video_player == 'off')
						{
							print 'checked';
						}
				?>/> Neither<br />
				
				<br /> 
	<?php $video_jquery = get_option('brafton_atlantis_jquery','on');?>
				<b><u>Import Jquery Script?</u></b><br />        
				<font size="-2"><i>Some sites already have jquery, set this to off if additional jquery script included with atlantisjs is causing issues.</i></font><br />
				<input type="radio" name="brafton_atlantis_jquery" value="on" <?php
					if ($video_jquery == 'on')
					{
						print 'checked';
					}
			?> /> On<br />
							<input type="radio" name="brafton_atlantis_jquery" value="off" <?php
					if ($video_jquery == 'off')
					{
						print 'checked';
					}
			?>/> Off<br />

				<?php $video_css = get_option('brafton_atlantis_extra_css','on');?>
				<b><u>Import Extra CSS for Atlantis?</u></b><br />        
				<font size="-2"><i>Extra CSS to fix a common issue where atlantisJS looks wonky.</i></font><br />
				<input type="radio" name="brafton_atlantis_extra_css" value="on" <?php
					if ($video_css == 'on')
					{
						print 'checked';
					}
			?> /> On<br />
							<input type="radio" name="brafton_atlantis_extra_css" value="off" <?php
					if ($video_css == 'off')
					{
						print 'checked';
					}
			?>/> Off<br />

				<b><u>Pause CTA Text</u></b><br />
				<font size="-2"><i>Text to display when video is paused.</i></font><br />
				<input type="text" name="brafton_pause_txt" value="<?php echo get_option("brafton_pause_txt", ""); ?>" /><br />

				<b><u>Pause CTA Link</u></b><br />
				<font size="-2"><i>URL pause CTA points to (include http://).</i></font><br />
				<input type="text" name="brafton_pause_link" value="<?php echo get_option("brafton_pause_link", ""); ?>" /><br />

				<b><u>End CTA Title</u></b><br />
				<font size="-2"><i>Title text to display at end of video.</i></font><br />
				<input type="text" name="brafton_endcta_title" value="<?php echo get_option("brafton_endcta_title", ""); ?>" /><br />

				<b><u>End CTA Sub-Title</u></b><br />
				<font size="-2"><i>Subtitle text to display at end of video.</i></font><br />
				<input type="text" name="brafton_endcta_subtitle" value="<?php echo get_option("brafton_endcta_subtitle", ""); ?>" /><br />

				<b><u>End CTA Button Link</u></b><br />
				<font size="-2"><i>What end-video CTA button links to.</i></font><br />
				<input type="text" name="brafton_endcta_btnlink" value="<?php echo get_option("brafton_endcta_btnlink", ""); ?>" /><br />

				<b><u>End CTA Button Text</u></b><br />
				<font size="-2"><i>Text of end-video CTA button.</i></font><br />
				<input type="text" name="brafton_endcta_btntxt" value="<?php echo get_option("brafton_endcta_btntxt", ""); ?>" /><br />

				</div><!--/video-settings-->
				<br /> 


			</div><!--Advanced Options-->
			<br>
			<br>
			
		</form>
</div>
<?php
}

function braftonxml_sched_load_videos()
{
	// Load Brafton Videos
	require_once 'RCClientLibrary/AdferoArticlesVideoExtensions/AdferoVideoClient.php';
	require_once 'RCClientLibrary/AdferoArticles/AdferoClient.php';
	require_once 'RCClientLibrary/AdferoPhotos/AdferoPhotoClient.php';
	
	// Access Keys
	$publicKey = get_option("braftonxml_videoPublic");
	$secretKey = get_option("braftonxml_videoSecret");
	
	$domain = get_option( "braftonxml_domain");

	switch ($domain) {
		case 'api.brafton.com':
			$baseURL = 'http://livevideo.api.brafton.com/v2/';
			$photoURI = "http://pictures.brafton.com/v2/";
			break;

		case 'api.contentlead.com':
			$baseURL = 'http://livevideo.api.contentlead.com/v2/';
			$photoURI = "http://pictures.contentlead.com/v2/";
			break;

		 case 'api.castleford.com.au':
		 	$baseURL = 'http://livevideo.api.castleford.com.au/v2/';
			$photoURI = "http://pictures.castleford.com.au/v2/";
			break;

		default:
			$baseURL = 'http://livevideo.api.brafton.com/v2/';
			$photoURI = "http://pictures.brafton.com/v2/";
			break;
	}

	$videoClient = new AdferoVideoClient($baseURL, $publicKey, $secretKey);
	$client = new AdferoClient($baseURL, $publicKey, $secretKey);
	$photoClient = new AdferoPhotoClient($photoURI);
	
	$videoOutClient = $videoClient->videoOutputs();
	
	$feedNum = get_option("braftonxml_videoFeedNum");
	
	$photos = $client->ArticlePhotos();
	$scale_axis = 500;
	$scale = 500;
	
	$feeds = $client->Feeds();
	$feedList = $feeds->ListFeeds(0, 10);
	
	$articles = $client->Articles();
	$articleList = $articles->ListForFeed($feedList->items[$feedNum]->id, 'live', 0, 100);
	
	$article_count = count($articleList->items);
	
	//set_magic_quotes_runtime(0);
	$counter = 0;
	
	$categories = $client->Categories();
	
	$sitemap=array();
	
	// Article Import Loop
	foreach ($articleList->items as $article)
	{
		if ($counter >= 30)
			break; //load 30 articles 
		
		//Extend PHP timeout limit by X seconds per article
		set_time_limit(20);
		$brafton_id = $article->id;
		
		if (brafton_post_exists($brafton_id))
			continue;
		
		$counter++;
		$ch = curl_init();
		$post_id = brafton_post_exists($brafton_id);
		$thisArticle = $client->Articles()->Get($brafton_id);
		
		if ($categories->ListForArticle($brafton_id, 0, 100)->items['totalCount'])
		{
			$categoryId = $categories->ListForArticle($brafton_id, 0, 100)->items[0]->id;
			$category = $categories->Get($categoryId);
		}
		
		$presplash = $thisArticle->fields['preSplash'];
		$postsplash = $thisArticle->fields['postSplash'];
				
		$videoList=$videoOutClient->ListForArticle($brafton_id,0,10);
		$list=$videoList->items;
		$player = get_option("brafton_video_embed");
		if ($player == "atlantis")
			$embedCode = sprintf( "<video id='video-%s' class=\"ajs-default-skin atlantis-js\" controls preload=\"auto\" width='512' height='288' poster='%s' >", $brafton_id, $presplash ); 
		
		else
			$embedCode = sprintf( "<video id='video-%s' class='video-js vjs-default-skin' controls preload='auto' width='512' height='288' poster='%s' data-setup src='%s' >", $brafton_id, $presplash, $path ); 

		foreach($list as $listItem){
			$output=$videoOutClient->Get($listItem->id);
			//logMsg($output->path);
			$type = $output->type;
			$path = $output->path; 
			$resolution = $output->height; 
			$source = generate_source_tag( $path, $resolution );
			$embedCode .= $source; 
		}		
		$embedCode .= '</video>';
		//old code
		//$embedCode = $videoClient->VideoPlayers()->GetWithFallback($brafton_id, 'redbean', 1, 'rcflashplayer', 1);
		
		if ($player == "atlantis"){
			$script = '<script type="text/javascript">';
            $script .=  'var atlantisVideo = AtlantisJS.Init({';
            $script .=  'videos: [{';
            $script .='id: "video-' . $brafton_id . '"';

            //video CTA section

            //pull five potential CTA related variables
			$pause_txt = get_option("brafton_pause_txt","");
			$pause_link = get_option("brafton_pause_link","");
			$end_title = get_option("brafton_endcta_title","");
			$end_sub = get_option("brafton_endcta_subtitle","");
			$end_link = get_option("brafton_endcta_btnlink","");
			$end_txt = get_option("brafton_endcta_btntxt","");

			//check if Pause CTA details are set, add to embed code if so
            if ( ($pause_txt != "") && ($pause_link != "") ) {
            	$script .= ',';
            	$script .= 'pauseCallToAction: {';
            	$script .= 'text: "<a href=\'' . $pause_link . '\'>' . $pause_txt . '</a>"';
            	$script .= '}';
            }

            //check if End of video CTA fields are filled out, append if so
            if ( ( $end_title && $end_sub && $end_link && $end_txt ) != "" ) {
            	$script .= ',';
            	$script .= 'endOfVideoOptions: {';
            	$script .= 'callToAction: {';
            	$script .= 'title: "' . $end_title . '",';
            	$script .= 'subtitle: "' . $end_sub . '",';
            	$script .= 'button: {';
            	$script .= 'link: "' . $end_link . '",';
            	$script .= 'text: "' . $end_txt . '"';
            	$script .= '}';
            	$script .= '}';
            	$script .= '}';
            }

            $script .= '}]';
            $script .= '});';
            $script .=  '</script>';
			$embedCode .= $script; 
		}
		
		//if (strpos($embedCode->embedCode, "adobe") < 30)
		//continue;
		
		//echo $embedCode->embedCode."<br><br><br>";
		
		$post_author = get_option("braftonxml_default_author", 1);
		
		//$post_content = "<div id='singlePostVideo'>".$embedCode->embedCode."</div>".$thisArticle->fields['content'];
		$post_content = $thisArticle->fields['content'];
		
		$post_title = $thisArticle->fields['title'];
		
		$post_excerpt = $thisArticle->fields['extract'];
		
		$post_status = get_option("braftonxml_sched_status", "publish");
		
		$post_date = $thisArticle->fields['date'];

		$post_date_gmt;

		// Do some formatting
		$post_date_gmt = strtotime($post_date);
		$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
		
		$article = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'post_excerpt');
		
		if (isset($categories->ListForArticle($brafton_id, 0, 100)->items[0]->id))
		{
			$categoryId = $categories->ListForArticle($brafton_id, 0, 100)->items[0]->id;
			$category = $categories->Get($categoryId);
			$article['post_category'] = array(
				wp_create_category($category->name)
			);
		}
		
		$article['ID'] = $post_id;
		$post_id = wp_insert_post($article);
		

		
		if (is_wp_error($post_id))
			return $post_id;
		else{
			$sitemapaddition = array(
				"url" => get_permalink($post_id),
				"location" => $mp4,
				"title" => $post_title,
				"thumbnail" => $presplash,
				"description" =>$post_content,
				"publication" =>$post_date,
			);
			$sitemap[]=$sitemapaddition;
		}
		
		if (!$post_id)
			return;
		
		add_post_meta($post_id, 'brafton_video', "<div id='singlePostVideo'>" . $embedCode . "</div>", true);
		add_post_meta($post_id, 'brafton_id', $brafton_id, true);
		
		// All-in-One SEO Plugin integration
		if (function_exists('aioseop_get_version'))
		{
			add_post_meta($post_id, '_aioseop_description', $post_excerpt, true);
			add_post_meta($post_id, '_aioseop_keywords', $keywords, true);
		}
		
		// Check if Yoast's Wordpress SEO plugin is active...if so, add relevant meta fields, populated by post info
		if (is_plugin_active('wordpress-seo/wp-seo.php'))
		{
			add_post_meta($post_id, '_yoast_wpseo_title', $post_title, true);
			add_post_meta($post_id, '_yoast_wpseo_metadesc', $post_excerpt, true);
			add_post_meta($post_id, '_yoast_wpseo_metakeywords', $keywords, true);
		}
		
		$thisPhotos = $photos->ListForArticle($brafton_id, 0, 100);
		if (isset($thisPhotos->items[0]->id))
		{
			$photoId = $photos->Get($thisPhotos->items[0]->id)->sourcePhotoId;
			$photoURL = $photoClient->Photos()->GetScaleLocationUrl($photoId, $scale_axis, $scale)->locationUri;
			$photoURL = strtok($photoURL, '?');
			$photoCaption = $photos->Get($thisPhotos->items[0]->id)->fields['caption'];
			
			$photoId = $thisPhotos->items[0]->id;
			$upload_array = wp_upload_dir();
			$master_image = image_download($upload_array, $photoURL, $post_date, $ch);
			$local_image_path = $master_image[0];
			
			if ($local_image_path)
			{
				$wp_filetype = wp_check_filetype(basename($local_image_path), NULL);
				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title' => $photoCaption,
					'post_excerpt' => $photoCaption,
					'post_content' => $photoCaption,
					'post_status' => 'inherit'
				);
				
				// Generate attachment information & set as "Featured image" (Wordpress 2.9+ feature, support must be enabled in your theme)
				$attach_id = wp_insert_attachment($attachment, $local_image_path, $post_id);
				$attach_data = wp_generate_attachment_metadata($attach_id, $local_image_path);
				wp_update_attachment_metadata($attach_id, $attach_data);
				add_post_meta($post_id, '_thumbnail_id', $attach_id, true);
				add_post_meta($post_id, 'pic_id', $image_id, true);
			}
		}
		
		//logMsg("vid:" . $brafton_id . "->" . $post_id . " success");
	}
	addURLs($sitemap);
}
function checkAuthor($author, $byLine){
    if($author == 'n' ){
        $author = get_option("braftonxml_default_author");
    }
    else{
        if(empty($byLine)){$author = get_option("braftonxml_default_author");return $author; }
        if(!(username_exists($byLine))){
            $pass = wp_generate_password(12,false);
            $author = wp_create_user($byLine, $pass, $byLine.rand().'@example.com');
        }
        else{
            $user = get_user_by('login',$byLine);
            $author = $user->ID;
        }
    }
    return $author;
}
function braftonxml_sched_load_articles($url, $API_Key)
{
	//logMsg("Start Run");
	
	if (get_option("braftonxml_video") == 'on')
	{
		braftonxml_sched_load_videos();
		return;
	}
	else if (get_option("braftonxml_video") == 'both')
		braftonxml_sched_load_videos();
	
	
	global $wpdb, $post;
	
	//start cURL
	$ch = curl_init();
	
	//Archive upload check
	if ($_FILES['archive']['tmp_name'])
	{
		echo "Archive Option Selected<br/>";
		$articles = NewsItem::getNewsList($_FILES['archive']['tmp_name'], "html");
	}
	else
	{
		if($API_Key=='xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx') die();
		if (preg_match("/\.xml$/", $API_Key))
			$articles = NewsItem::getNewsList($API_Key, 'news');
		else
		{
			$fh = new ApiHandler($API_Key, $url);
			$articles = $fh->getNewsHTML();
		}
	}
	
	/*	$catDefsObj = $fh->getCategoryDefinitions();
	
	foreach($catDefsObj as $catDef){
	$catDefs[] = $wpdb->escape($catDef->getName());
	
	}
	wp_create_categories($catDefs);*/
	
	$article_count = count($articles);
	$counter = 0;
	
	//set_magic_quotes_runtime(0);
	
	//Article Import Loop
	foreach ($articles as $a)
	{
		if ($counter >= 30)
			break; // load 30 articles 
		// Extend PHP timeout limit by X seconds per article
		set_time_limit(20);
		
		$counter++;
		$brafton_id = $a->getId();
		$articleStatus = "Imported";
		
		if (brafton_post_exists($brafton_id))
		{
			//if the post exists and article edits will automatically overwrite 
			if (get_option("braftonxml_sched_triggercount") % 10 != 0)
			{
				//Every ten importer runs do not skip anything
				$articleStatus = "Updated";
				continue;
			}
		}
		
		switch (get_option('braftonxml_publishdate'))
		{
			case 'modified':
				$date = $a->getLastModifiedDate();
				break;
			
			case 'created':
				$date = $a->getCreatedDate();
				break;
			
			default:
				$date = $a->getPublishDate();
				break;
		}
		
		
		$post_title = $a->getHeadline();
		$post_content = $a->getText();
		$photos = $a->getPhotos();
		
		if (get_option("braftonxml_domain") == 'api.castleford.com.au')
			$post_excerpt = $a->getHtmlMetaDescription();
		else
			$post_excerpt = $a->getExtract();
		
		$keywords = $a->getKeywords();
		$photo_option = 'large';
		$post_image = null;
		$post_image_caption = null;
		
		// Download main image to Wordpress uploads directory (faster page load times)
		// [citation needed] -brian 2013.05.03
		$upload_array = wp_upload_dir();
		
		//Check if picture exists
		if (!empty($photos))
		{
			if ($photo_option == 'large') //Large photo
				$image = $photos[0]->getLarge();
			
			if (!empty($image))
			{
				$post_image = $image->getUrl();
				$post_image_caption = $photos[0]->getCaption();
				$image_id = $photos[0]->getId();
			}
		}
		
		//$img_exists = brafton_img_exists($image_id);
		/*if($img_exists) {
		$local_image_path = $upload_array['baseurl'].brafton_img_location($img_exists);
		}*/
		
		if ($post_image)
		{
			$master_image = image_download($upload_array, $post_image, $date, $ch);
			$local_image_path = $master_image[0];
		}
		else
			$local_image_path = null;
		
		$post_id = brafton_post_exists($brafton_id);
		$post_date;
		$post_date_gmt;
		$post_author = checkAuthor(get_option("braftonxml_dynamic_author"), $a->getByLine());
        //$post_author = get_option("braftonxml_default_author", 1);
		if ($post_id)
			$post_status = get_post_status($post_id);
		else
			$post_status = get_option("braftonxml_sched_status", "publish");
		
		$guid = $API_Key;
		$categories = array();
		$tags_input = array();
		
		// Do some formatting
		$post_date_gmt = strtotime($date);
		$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
		$post_date = get_date_from_gmt($post_date_gmt);
		$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
		$post_content = str_replace('<br>', '<br />', $post_content);
		$post_content = str_replace('<hr>', '<hr />', $post_content);
		
		// Save the article to the articles array
		$article = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'post_excerpt');
		
		// Category handling
		// TODO: tag/category switching based on GUI
		$tag_option = get_option("braftonxml_sched_tags", 'cats');
		$cat_option = get_option("braftonxml_sched_cats");
		$custom_cat = explode(",", get_option("braftonxml_sched_cats_input"));
		$custom_tags = explode(",", get_option("braftonxml_sched_tags_input"));
		$CatColl = $a->getCategories();
		$TagColl = $a->getTags();
		
		// categories
		if ($cat_option == 'categories' && $custom_cat[0] != "") // 'category' option is selected and custom tags inputed
		{
			foreach ($CatColl as $c)
				$categories[] = $wpdb->escape($c->getName());
			
			for ($j = 0; $j < count($custom_cat); $j++)
				$categories[] = $custom_cat[$j];
			$article['post_category'] = wp_create_categories($categories);
		}
		else if ($cat_option == 'none_cat' && $custom_cat[0] != "")
		{
			$cat_name = array();
			$name = array();
			
			$cat_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
			WHERE terms.term_id=tax.term_id AND 
			tax.taxonomy='category'";
			$cat_name []= $wpdb->get_results($cat_query);
			
			for ($j = 0; $j < count($custom_cat); $j++)
				$categories[] = $custom_cat[$j];
			
			for ($x = 0; $x < count($cat_name); $x++)
				for ($z = 0; $z < count($cat_name[$x]); $z++)
					$name[] = $cat_name[$x][$z]->name;
			
			foreach ($CatColl as $c)
				if ((in_array($c->getName(), $name)))
					$categories[] = $wpdb->escape($c->getName());
			$article['post_category'] = wp_create_categories($categories);
		}
		else if ($cat_option == 'categories' && $custom_cat[0] == "")
		{
			foreach ($CatColl as $c)
				$categories[] = $wpdb->escape($c->getName());
			$article['post_category'] = wp_create_categories($categories);
		}
		
		// tags
		if ($tag_option == 'cats' && $custom_tags[0] != "")
		{
			foreach ($CatColl as $c)
				$tags_input[] = $wpdb->escape($c->getName());
			
			for ($j = 0; $j < count($custom_tags); $j++)
				$tags_input[] = $custom_tags[$j];
			$article['tags_input'] = $tags_input;
		}
		else if ($tag_option == 'none_tags' && $custom_tags[0] != "")
		{
			$tname = array();
			$name = array();
			
			$tax_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
			WHERE terms.term_id=tax.term_id AND 
			tax.taxonomy='post_tag'";
			$tname []= $wpdb->get_results($tax_query);
			
			for ($j = 0; $j < count($custom_tags); $j++)
				$tags_input[] = $custom_tags[$j];
			
			for ($x = 0; $x < count($tname); $x++)
				for ($z = 0; $z < count($tname[$x]); $z++)
					$name[] = $tname[$x][$z]->name;
			
			foreach ($CatColl as $c)
				if ((in_array($c->getName(), $name)))
					$tags_input[] = $wpdb->escape($c->getName());
			$article['tags_input'] = $tags_input;
		}
		else if ($tag_option == 'cats' && $custom_tags[0] == "")
		{
			foreach ($CatColl as $c)
				$tags_input[] = $wpdb->escape($c->getName());
			$article['tags_input'] = $tags_input;
		}
		else if ($tag_option == 'keywords' && ($custom_tags[0] == ""))
		{
			if (!empty($keywords))
			{
				$keyword_arr = explode(',', $keywords);
				foreach ($keyword_arr as $keyword)
					$article['tags_input'][] = trim($keyword);
			}
		}
		else if ($tag_option == 'keywords' && $custom_tags[0] != "")
		{
			if (!empty($keywords))
			{
				$tname = array();
				$name = array();
				
				$tax_query = "SELECT terms.name FROM " . $wpdb->terms . " terms, " . $wpdb->term_taxonomy . " tax 
				WHERE terms.term_id=tax.term_id AND 
				tax.taxonomy='post_tag'";
				$tname []= $wpdb->get_results($tax_query);
				
				for ($j = 0; $j < count($custom_tags); $j++)
					$tags_input[] = $custom_tags[$j];
				
				for ($x = 0; $x < count($tname); $x++)
					for ($z = 0; $z < count($tname[$x]); $z++)
						$name[] = $tname[$x][$z]->name;
				$keyword_arr = explode(',', $keywords);
				
				foreach ($keyword_arr as $keyword)
					$tags_input[] = trim($keyword);
				
				foreach ($CatColl as $c)
					if ((in_array($c->getName(), $name)))
						$tags_input[] = $wpdb->escape($c->getName());
				$article['tags_input'] = $tags_input;
			}
		}
		else if ($tag_option == 'tags' && $custom_tags[0] == "")
		{
			$TagCollArray = explode(',', $TagColl);
			foreach ($TagCollArray as $c)
				$tags_input[] = $wpdb->escape($c);
			
			for ($j = 0; $j < count($custom_tags); $j++)
				$tags_input[] = $custom_tags[$j];
			$article['tags_input'] = $tags_input;
		}
		else if ($tag_option == 'tags' && $custom_tags[0] == "")
		{
			$TagCollArray = explode(',', $TagColl);
			foreach ($TagCollArray as $c)
				$tags_input[] = $wpdb->escape($c);
			$article['tags_input'] = $tags_input;
		}
		
		if ($post_id)
		{
			$article['ID'] = $post_id;
			if (get_option("braftonxml_overwrite", "on") == 'on')
				wp_update_post($article);
				
			
			if (populate_postmeta($article_count, $post_id, $image_id))
			{
				$update_image = image_update($post_id, $image_id);
				if (empty($update_image))
				{
					if ($local_image_path)
					{
						$wp_filetype = wp_check_filetype(basename($local_image_path), NULL);
						$attachment = array(
							'post_mime_type' => $wp_filetype['type'],
							'post_title' => $post_image_caption,
							'post_excerpt' => $post_image_caption,
							'post_content' => $post_image_caption,
							'post_status' => 'inherit'
						);
						
						// Generate attachment information & set as "Featured image" (Wordpress 2.9+ feature, support must be enabled in your theme)
						$attach_id = wp_insert_attachment($attachment, $local_image_path, $post_id);
						$attach_data = wp_generate_attachment_metadata($attach_id, $local_image_path);
						wp_update_attachment_metadata($attach_id, $attach_data);
						update_post_meta($post_id, '_thumbnail_id', $attach_id);
						update_post_meta($post_id, 'pic_id', $image_id);
					}
				}
			}
		}
		else
		{
			// insert new story
			$post_id = wp_insert_post($article);

			if (is_wp_error($post_id))
				return $post_id;
			
			if (!$post_id)
				return;
			
			add_post_meta($post_id, 'brafton_id', $brafton_id, true);
			
			// castleford uses a secondary title for keyword quotas
			// this is a stopgap. -brian 06.06.2013
			$seoTitle = $post_title;
			$htmlTitle = $a->getHtmlTitle();
			if (get_option("braftonxml_domain") == 'api.castleford.com.au' && !empty($htmlTitle))
				$seoTitle = $htmlTitle;
			
			// All-in-One SEO Plugin integration
			if (function_exists('aioseop_get_version'))
			{
				add_post_meta($post_id, '_aioseop_description', $post_excerpt, true);
				add_post_meta($post_id, '_aioseop_keywords', $keywords, true);
			}
			
			// Check if Yoast's Wordpress SEO plugin is active...if so, add relevant meta fields, populated by post info
			if (is_plugin_active('wordpress-seo/wp-seo.php'))
			{
				add_post_meta($post_id, '_yoast_wpseo_title', $seoTitle, true);
				add_post_meta($post_id, '_yoast_wpseo_metadesc', $post_excerpt, true);
				add_post_meta($post_id, '_yoast_wpseo_metakeywords', $keywords, true);
			}
			
			if ($local_image_path)
			{
				$wp_filetype = wp_check_filetype(basename($local_image_path), NULL);
				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title' => $post_image_caption,
					'post_excerpt' => $post_image_caption,
					'post_content' => $post_image_caption
				);
				
				// Generate attachment information & set as "Featured image" (Wordpress 2.9+ feature, support must be enabled in your theme)
				$attach_id = wp_insert_attachment($attachment, $local_image_path, $post_id);
				$attach_data = wp_generate_attachment_metadata($attach_id, $local_image_path);
				wp_update_attachment_metadata($attach_id, $attach_data);
				
				add_post_meta($post_id, '_thumbnail_id', $attach_id, true);
				add_post_meta($post_id, 'pic_id', $image_id, true);
			}
		}
		
		//logMsg($articleStatus . " " . $brafton_id . "->" . $post_id . " : " . $post_title);
	}
}

function duplicateKiller()
{
	global $wpdb;
	//grab post_id for all posts with a brafton ID associated with them
	$braftonPosts = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'brafton_id'");

	foreach( $braftonPosts as $postID )
	{
		//grab brafton_id of post to check for copies of
		$braftonID = get_post_meta( $postID, 'brafton_id', true );
		
		$i = 0;
		foreach( $braftonPosts as $innerPost )
		{
			$toCompare = get_post_meta($innerPost, 'brafton_id', true);
			
			//if a post is found with matching "brafton_id"s but different "post_id"s, we have a dupe!
			if( $braftonID == $toCompare && $postID != $innerPost )
			{
				//delete $innerPost from WP database
				wp_delete_post( $innerPost, true );
				//...and remove from array of posts to be compared (since it no longer exists)
				unset( $braftonPosts[$i] );
			}
			$i++;
		}
	}
}

function populate_postmeta($article_count, $post_id, $image_id)
{
	global $wpdb;
	$value = get_option("braftonxml_pic_id_count");
	
	if (!empty($value) && $value < $article_count && $value != "completed" && !empty($image_id))
	{
		add_post_meta($post_id, 'pic_id', $image_id, true);
		$value++;
		update_option("braftonxml_pic_id_count", $value);
		
		if ($value == $article_count || $value == 31)
			update_option("braftonxml_pic_id_count", "completed");
		
		return false;
	}
	else if (empty($value) && !empty($image_id))
	{
		update_option("braftonxml_pic_id_count", 1);
		add_post_meta($post_id, 'pic_id', $image_id, true);
		return false;
	}
	else
		return true;
}

function image_update($id, $image_id)
{
	global $wpdb;
	$query = $wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE 
		meta_key = 'pic_id' AND meta_value = '%d'", $image_id);
	$meta_id = $wpdb->get_var($query);
	
	return $meta_id;
}

/* 
 * Search for existing post by Brafton article ID in postmeta table 
 */
function brafton_post_exists($brafton_id)
{
	global $wpdb;
	$query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE 
				meta_key = 'brafton_id' AND  meta_value = '%d'", $brafton_id);
	$post_id = $wpdb->get_var($query);
	
	$query = $wpdb->prepare("SELECT id FROM $wpdb->posts WHERE 
				id = '%d'", $post_id);
	$exists = $wpdb->get_var($query);
	
	/*if(!isset($exists)) {
	$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_value = '".$brafton_id."'");
	}
	
	//Delete all revisions on Brafton content - the plugin tends to bloat the DB with unneeded revisions
	if($post_id != null) {
	$wpdb->query("DELETE FROM $wpdb->posts WHERE post_type = 'revision' AND ID=".$post_id);
	}*/
	
	return $post_id;
}

function brafton_post_modified($post_id)
{
	global $wpdb;
	$query = $wpdb->prepare("SELECT post_modified FROM $wpdb->posts WHERE 
				post_id = '%d'", $post_id);
	$post_modified = $wpdb->get_var($query);
	return $post_modified;
}

function brafton_img_exists($brafton_img_id)
{
	global $wpdb;
	$query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE 
				meta_key = 'pic_id' AND meta_value = '%d'", $brafton_img_id);
	$post_id = $wpdb->get_var($query);
	return $post_id;
}

function brafton_img_location($brafton_img_post_id)
{
	global $wpdb;
	$query = $wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE 
				meta_key = '_wp_attached_file' AND post_id = '%d'", $brafton_img_post_id);
	$post_id = $wpdb->get_var($query);
	return $post_id;
}

/* 
 * Download image file to upload directory using cURL
 */
function image_download($upload_array, $original_image_url, $date, $ch)
{
	$year = substr($date, 0, 4);
	$month = substr($date, 5, 2);
	$upload_date_dir = $upload_array['basedir']; //."/".$year."/".$month;
	
	/* TODO: determine if this works in majority of cases and reinstate if possible
	if (!is_dir($upload_date_dir)) { // Makes sure an uploads directory for the current year/month exists - if not, create one
	if (!mkdir($upload_date_dir, 0755, true)) {
	die('Failed to create folders...');
	}
	}*/
	
	$original_image_url = strtolower($original_image_url);
	$original_image_url_split = explode('_', $original_image_url, 2);
	$original_image_url_split[0] = substr($original_image_url_split[0], 0, 100);
	$original_image_url_shorter = implode('_', $original_image_url_split);
	
	$raw_image_path = preg_replace("/.*(\/)/", "", $original_image_url_shorter);
	$raw_image_path = preg_replace("/\+/", "_", $raw_image_path);
	$local_image_path = ($upload_date_dir . "/" . $raw_image_path);
	$local_image_url = ($upload_array['baseurl'] . "/" . $raw_image_path); //$date_array['2']."/".$date_array['1']."/".
	
	if (!file_exists($local_image_url))
	{
		$fp = fopen($local_image_path, 'w');
		curl_setopt($ch, CURLOPT_URL, $original_image_url_shorter);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_exec($ch);
		fclose($fp);
	}
	
	return array(
		$local_image_path,
		$local_image_url
	);
}


/* video updates*/
function generate_source_tag($src, $resolution)
{
    $tag = ''; 
    $ext = pathinfo($src, PATHINFO_EXTENSION); 

    return sprintf('<source src="%s" type="video/%s" data-resolution="%s" />', $src, $ext, $resolution );
}

?>
