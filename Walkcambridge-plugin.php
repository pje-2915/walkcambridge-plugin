<?php
/*
 * Plugin Name: Site Plugin for Walkcambridge.org
 * Description: Site specific code for Walkcambridge.org
 * Plugin URI:
 * Version: 1.0
 * Author: Paul Edwards
 * Author URI:
 * License: GPL2
 */

// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// add another interval
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
function cron_add_minute( $schedules ) {
	// Adds once every minute to the existing schedules.
	$schedules['everyminute'] = array(
			'interval' => 60,
			'display' => __( 'Once Every Minute' )
	);
	return $schedules;
}
// ---------------------------------------------------------------
add_filter( 'cron_schedules', 'cron_add_minute' );


// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// create a scheduled event (if it does not exist already)
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
function cronstarter_activate()
{
	if( !wp_next_scheduled( 'myCronJob' ) )
	{
		wp_schedule_event( time(), 'twicedaily', 'myCronJob' );
	}
}
// ---------------------------------------------------------------
// and make sure it's called whenever WordPress loads
add_action('wp', 'cronstarter_activate');

// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// unschedule event upon plugin deactivation
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
function cronstarter_deactivate() {
	// find out when the last event was scheduled
	$timestamp = wp_next_scheduled ('myCronJob');
	// unschedule previous event if any
	wp_unschedule_event ($timestamp, 'myCronJob');
}
// ---------------------------------------------------------------
register_deactivation_hook (__FILE__, 'cronstarter_deactivate');

// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// Parse each post from the given URL into the fields we will need
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
function scrape_url($postType, $URL)
{

	if($postType == 'Walk')
	{
		$SKIP_AT_START = 1;
		$SKIP_AT_END = 5;
	}
	else if($postType == 'Social')
	{
		$SKIP_AT_START = 1;
		$SKIP_AT_END = 0;
	}

	$curl = curl_init();
	curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $URL,
			CURLOPT_CONNECTTIMEOUT => 10
	));
	
	$result = curl_exec($curl);
	if($result == false)
	{
		error_log('Failed to reach '.$URL);
		return;
	}

	$scrapedPosts = array();
	
	// Barely any markup so look for bold denoting headings
	$chunks = preg_split('/<b>/', $result);
		
	if(count($chunks) > ($SKIP_AT_START + $SKIP_AT_END))
	{
		for ($i = $SKIP_AT_START; $i < count($chunks)-$SKIP_AT_END; ++$i)
		{
			global $user_ID;
				
			// Split into three sections - bold stuff (title), description, organiser
			$subchunks = preg_split('/<\/b>|([wW]alk )*[lL]eader:|[hH]ost:/', $chunks[$i]);
			if(count($subchunks) < 3)
			{
				error_log('Failed to find three sections in: '.$chunks[$i]);
				return $scrapedPosts;
			}
			$str1 = str_replace('</p>','',$subchunks[2]);
			$str2 = trim($str1,": ");
				
			$content = $subchunks[1];
	
			// Pull out the phone number
			$phoneNum = 'Not given';
			if(preg_match( '/[0-9]+[ ]+[0-9]+/', $str2, $match ) == 1)
			{
				$phoneNum = $match[0];
			}
				
			// Now find the name - first get everything before the number
			$subsubchunks = preg_split('/[\(]*[0-9]/', $str2);
			if(count($subchunks) == 1)
			{
				error_log('Failed to find organiser in: '.$str2);
				return $scrapedPosts;
			}
			$organiser = trim($subsubchunks[0]," ");
				
			// Now try to find the time buried in the post - note we don't
			// use this for ordering anything so just treat as text string
			$eventTime = 'TBD';
			if(preg_match( '/([0-9]+[ ]*([ap]m|[AP]M))|(([0-1][0-9]|2[0-3])|([1-9][0-2]{0,1}))[\.:]([0-5][0-9])/',
					$subchunks[1], $match ) == 1)
			{
				$eventTime = $match[0];
			}
			
			// Now look for any email address buried in the description
			$email = 'Not given';
			if(preg_match( '/[a-zA-Z0-9_]+@[a-zA-Z0-9]+\.[a-zA-Z]{2,}/', $content, $match ) ==1)
			{
				$email = $match[0];
			}
				
			// Now look for a post code
			$postcode = 'TBD';
			if(preg_match( '/[A-Z]{1,2}[0-9]{1,2}[ ]{0,1}[0-9][A-Z]{2}/', $content, $match ) ==1)
			{
				$postcode = $match[0];
			}
	
			// Now look for a landranger grid co-ordinate
			$gridref = 'TBD';
			if(preg_match( '/[NST][A-HJ-Z][ ]{0,1}[0-9]{3}[ ]{0,1}[0-9]{3}/', $content, $match ) ==1)
			{
				$gridref = $match[0];
			}
				
			// Now the Date
			$subsubchunks = preg_split('/-/', $subchunks[0]);
			if(count($subsubchunks) == 1)
			{
				error_log('Failed to find date in: '.$subchunks[0]);
				return $scrapedPosts;
			}
			$eventDate = date('D d M Y', strtotime($subsubchunks[0]));
	
			// Now the title
			$title = trim($subsubchunks[1]," ");
	
			// Now the Grade (if appropriate)
			$grade = 'NONE';
			if(preg_match('/(Easy|Leisurely|Moderate|Strenuous)/', $subchunks[0], $match) == 1)
			{
				$grade = $match[0];
			}

			$debug = true;
			if($debug == true)
			{
				error_log('============================================');
				error_log('grade: '.$grade);
				error_log('title: '.$title);
				error_log('content: '.$content);
				error_log('eventDate: '.$eventDate);
				error_log('eventTime: '.$eventTime);
				error_log('postcode: '.$postcode);
				error_log('gridref: '.$gridref);
				error_log('meetat: '.'');
				error_log('contactName: '.$organiser);
				error_log('contactPhone: '.$phoneNum);
				error_log('contactEmail: '.$email);
			}
	
			// Not interested in posts that have an event date in the past
			if(strtotime($eventDate) > time())
			{
				$scrapedPosts[]=array(
						'grade'=>$grade,
						'title'=>$title,
						'content'=>$content,
						'eventDate'=>$eventDate,
						'eventTime'=>$eventTime,
						'postcode'=>$postcode,
						'gridref'=>$gridref,
						'meetat'=>'',
						'contactName'=>$organiser,
						'contactPhone'=>$phoneNum,
						'contactEmail'=>$email,
						'matched'=>'false',
				);
			}
		}
	}
	else
	{
		error_log('Failed to split posts from: '.$result);
	}
	return $scrapedPosts;
}
// ---------------------------------------------------------------

// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// Now loop through all our posts and check for matches (i.e identical content) :
// if everything matches leave it, if an existing post doesn't match what we just scaped
// then (only if it contains a "scraped" metadata tag) we delete it.
// If something we just scraped doesn't match a post then add it.
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

function reconcile_posts($postType, $scrapedPosts)
{
	global $post;
	$args = array(
			'post_type' => $postType,
			'posts_per_page' => 4,
			'meta_query' => array(
					array(
							'key' => 'dbt_date',
							'value' => time(),
							'compare' => '>=',
					),
					array(
							'key' => 'dbt_scraped',
							'value' => 'true',
							'compare' => '==',
					),
			),
			'orderby' => 'key',
			'order' => 'ASC',);
	
	$query = new WP_Query( $args );

	if ($query->have_posts())
	{
		$localMatched = array();
		while ($query->have_posts())
		{
			// Hoover out all the fields from the post
			$eventdate = get_post_meta($post->ID, 'dbt_date', 'true');
			date('D d M Y', $eventdate);
				
			$grade = get_post_meta($post->ID, 'dbt_grade', 'true');
			$title = wp_get_title();
			$content = wp_get_post();
			$eventTime = get_post_meta($post->ID, 'dbt_time', 'true');
			$postcode = get_post_meta($post->ID, 'dbt_postcode', 'true');
			$gridref = get_post_meta($post->ID, 'dbt_gridref', 'true');
			$meetat = get_post_meta($post->ID, 'dbt_meetat', 'true');
			$contactName = get_post_meta($post->ID, 'dbt_contactname', 'true');
			$contactPhone = get_post_meta($post->ID, 'dbt_contactphone', 'true');
			$matched = false; // $post->ID
				
			// Does this match a newly scraped post?
			foreach ($scrapedPosts as $scrapedPost)
			{
				// Don't bother with email - old site doesn't use them generally
				if($scrapedPost['title'] == $title &&
						$scrapedPost['grade'] == $grade &&
						$scrapedPost['eventTime'] == $eventTime &&
						strtotime($scrapedPost['eventDate']) == $eventDate &&
						$scrapedPost['postcode'] == $postcode &&
						$scrapedPost['gridref'] == $gridref &&
						$scrapedPost['meetat'] == $meetat &&
						$scrapedPost['contactName'] == $contactName &&
						$scrapedPost['contactPhone'] == $contactPhone &&
						$scrapedPost['content'] == $content)
				{
					// set the $localMatched to true and leave this existing wp post alone,
					// also set the matched attribute on the scraped post
					$matched = true;
					$scrapedPosts['matched'] = 'true';
				}
			}
				
			// If we didn't find the wp post in the matches, then delete it
			if($matched == false)
			{
				wp_delete_post($post->ID);
			}
		}
	}

	// Finally, loop through the scraped posts again and create any that haven't been matched
	foreach ($scrapedPosts as $scrapedPost)
	{
		if($scrapedPost['matched']=='false')
		{
			// TODO sort the next statment and add the meta tags
			$new_post = array(
					'post_title' => $scrapedPost['title'],
					'post_content' => $scrapedPost['content'],
					'post_status' => 'publish',
					'post_date' => date('Y-m-d H:i:s'),
					'post_author' => 'scraper',
					'post_type' => $postType,
					'post_category' => '',
					'meta_input' => array(
						'dbt_grade' => $scrapedPost['grade'],
						'dbt_time' => $scrapedPost['eventTime'],
						'dbt_date' => strtotime($scrapedPost['eventDate']),
						'dbt_postcode' => $scrapedPost['postcode'],
						'dbt_gridref' => $scrapedPost['gridref'],
						'dbt_meetat' => $scrapedPost['meetat'],
						'dbt_contactname' => $scrapedPost['contactName'],
						'dbt_contactphone' => $scrapedPost['contactPhone'],
						'dbt_scraped' => 'true',
						'dbt_contactemail' => $scrapedPost['contactEmail']
					)
			);

			$post_id = wp_insert_post($new_post);
		}
	}
}
// ---------------------------------------------------------------

// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
// here's the function we'd like to call with our cron job
// +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
function my_repeat_function()
{
	error_log("timed function called");
	
	$scrapedwalks = scrape_url('Walk','http://www.walkcambridge.org/diary_walk.html');
	reconcile_posts('Walk',$scrapedwalks);

	$scrapedsocials = scrape_url('Social','http://www.walkcambridge.org/diary_social.html');
	reconcile_posts('Social',$scrapedsocials);
}
// ---------------------------------------------------------------

// hook that function onto our scheduled event:
add_action ('myCronJob','my_repeat_function');
?>

