<?php

/**
* @copyright Copyright (c) 2013-2014 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/

class s9e_MediaBBCodes
{
	/**
	* Path to a cache dir, used to cache scraped pages
	*/
	public static $cacheDir;

	public static function install($old, $new, $addon)
	{
		self::filterExcludedSites($addon);
		self::injectCustomRenderers($addon);
	}

	public static function injectCustomRenderers($addon)
	{
		$custom  = class_exists('s9e_Custom');
		if (!$custom)
		{
			return;
		}

		foreach ($addon->bb_code_media_sites->site as $site)
		{
			$id = (string) $site['media_site_id'];
			$callback = 's9e_Custom::' . $id;

			if (is_callable($callback))
			{
				$site->embed_html = '<!-- ' . $callback . "() -->\n" . $site->embed_html;

				$site['embed_html_callback_class']  = 's9e_MediaBBCodes';
				$site['embed_html_callback_method'] = 'embed';
			}
		}
	}

	protected static function filterExcludedSites($addon)
	{
		$exclude = XenForo_Application::get('options')->s9e_EXCLUDE_SITES;
		if (!$exclude)
		{
			return;
		}

		$exclude = array_flip(preg_split('/\\W+/', $exclude, -1, PREG_SPLIT_NO_EMPTY));
		$nodes   = array();

		foreach ($addon->bb_code_media_sites->site as $site)
		{
			$id = (string) $site['media_site_id'];

			if (isset($exclude[$id]))
			{
				$nodes[] = dom_import_simplexml($site);
			}
		}

		foreach ($nodes as $node)
		{
			$node->parentNode->removeChild($node);
		}
	}

	public static function updateTags($tags)
	{
		return $tags;
	}

	protected static function reinstall()
	{
		$model = XenForo_Model::create('XenForo_Model_BbCode');
		$model->deleteBbCodeMediaSitesForAddOn('s9e');
		$model->rebuildBbCodeCache();
	}

	public static function match($url, $regexps, $scrapes, $filters = array())
	{
		$vars = array();

		if (!empty($regexps))
		{
			$vars = self::getNamedCaptures($url, $regexps);
		}

		foreach ($scrapes as $scrape)
		{
			$scrapeVars = array();

			$skip = true;
			foreach ($scrape['match'] as $regexp)
			{
				if (preg_match($regexp, $url, $m))
				{
					// Add the named captures to the available vars
					$scrapeVars += $m;

					$skip = false;
				}
			}

			if ($skip)
			{
				continue;
			}

			if (isset($scrape['url']))
			{
				// Add the vars from non-scrape "extract" regexps
				$scrapeVars += $vars;

				// Add the original URL
				if (!isset($scrapeVars['url']))
				{
					$scrapeVars['url'] = $url;
				}

				// Replace {@var} tokens in the URL
				$scrapeUrl = preg_replace_callback(
					'#\\{@(\\w+)\\}#',
					function ($m) use ($scrapeVars)
					{
						return (isset($scrapeVars[$m[1]])) ? $scrapeVars[$m[1]] : '';
					},
					$scrape['url']
				);
			}
			else
			{
				// Use the same URL for scraping
				$scrapeUrl = $url;
			}

			// Overwrite vars extracted from URL with vars extracted from content
			$vars = array_merge($vars, self::scrape($scrapeUrl, $scrape['extract']));
		}

		// No vars = no match
		if (empty($vars))
		{
			return false;
		}

		// Apply filters
		foreach ($filters as $varName => $callbacks)
		{
			if (!isset($vars[$varName]))
			{
				continue;
			}

			foreach ($callbacks as $callback)
			{
				$vars[$varName] = $callback($vars[$varName]);
			}
		}

		// If there's only one capture named "id" we store its value as-is
		$keys = array_keys($vars);
		if ($keys === array('id'))
		{
			return $vars['id'];
		}

		// If there's only one capture named "url" and it looks like a URL, we store its value as-is
		if ($keys === array('url') && preg_match('#^\\w+://#', $vars['url']))
		{
			return $vars['url'];
		}

		// If there are more than one capture, or it's not named "id", we store it as a series of
		// URL-encoded key=value pairs
		$pairs = array();
		ksort($vars);
		foreach ($vars as $k => $v)
		{
			if ($v !== '')
			{
				$pairs[] = urlencode($k) . '=' . urlencode($v);
			}
		}

		// NOTE: XenForo silently nukes the mediaKey if it contains any HTML special characters,
		//       that's why we use ; rather than the standard &
		return implode(';', $pairs);
	}

	public static function embed($mediaKey, $site)
	{
		// If the value looks like a URL, we copy its value to the "url" var
		if (preg_match('#^\\w+://#', $mediaKey))
		{
			$vars['url'] = $mediaKey;
		}

		// If the value looks like a series of key=value pairs, add them to $vars
		if (preg_match('(^(\\w+=[^;]*)(?>;(?1))*$)', $mediaKey))
		{
			$vars = array();
			foreach (explode(';', $mediaKey) as $pair)
			{
				list($k, $v) = explode('=', $pair);
				$vars[urldecode($k)] = urldecode($v);
			}
		}

		// The value is used as the "id" var if it hasn't been defined already
		if (!isset($vars['id']))
		{
			$vars['id'] = $mediaKey;
		}

		// No vars = no match, return a link to the content, or the BBCode as text
		if (empty($vars))
		{
			$mediaKey = htmlspecialchars($mediaKey);

			return (preg_match('(^https?://)', $mediaKey))
				? "<a href=\"$mediaKey\">$mediaKey</a>"
				: "[media={$site['media_site_id']}]{$mediaKey}[/media]";
		}

		// Prepare the HTML
		$html = $site['embed_html'];

		// Test whether this particular site has its own renderer
		$html = preg_replace_callback(
			'(<!-- (' . __CLASS__ . '::render\\w+)\\((?:(\\d+), *(\\d+))?\\) -->)',
			function ($m) use ($vars)
			{
				$callback = $m[1];

				if (!is_callable($callback))
				{
					return $m[0];
				}

				$html = call_user_func($callback, $vars);

				if (isset($m[2], $m[3]))
				{
					$html = preg_replace('/( width=")[^"]*/',  '${1}' . $m[2], $html);
					$html = preg_replace('/( height=")[^"]*/', '${1}' . $m[3], $html);
				}

				return $html;
			},
			$html,
			-1,
			$cnt
		);

		// Otherwise use the configured template
		if (!$cnt)
		{
			$html = preg_replace_callback(
				// Interpolate {$id} and other {$vars}
				'(\\{\\$([a-z]+)\\})',
				function ($m) use ($vars)
				{
					return (isset($vars[$m[1]])) ? htmlspecialchars($vars[$m[1]]) : '';
				},
				$site['embed_html']
			);
		}

		// Test for custom modifications
		if (preg_match('(^<!-- (s9e_Custom::\w+)\\(\\) -->\\s*)', $html, $m))
		{
			$html = substr($html, strlen($m[0]));

			if (is_callable($m[1]))
			{
				$html = call_user_func($m[1], $html, $vars);
			}
		}

		return $html;
	}

	public static function wget($url)
	{
		// Return the content from the cache if applicable
		if (isset(self::$cacheDir) && file_exists(self::$cacheDir))
		{
			$cacheFile = self::$cacheDir . '/http.' . crc32($url) . '.gz';

			if (file_exists($cacheFile))
			{
				return file_get_contents('compress.zlib://' . $cacheFile);
			}
		}

		$page = @file_get_contents(
			'compress.zlib://' . $url,
			false,
			stream_context_create(array(
				'http' => array(
					'header' => 'Accept-Encoding: gzip'
				)
			))
		);

		if ($page && isset($cacheFile))
		{
			file_put_contents($cacheFile, gzencode($page, 9));
		}

		return $page;
	}

	protected static function scrape($url, $regexps)
	{
		return self::getNamedCaptures(self::wget($url), $regexps);
	}

	protected static function getNamedCaptures($string, $regexps)
	{
		$vars = array();

		foreach ($regexps as $regexp)
		{
			if (preg_match($regexp, $string, $m))
			{
				foreach ($m as $k => $v)
				{
					// Add named captures to the vars without overwriting existing vars
					if (!is_numeric($k) && !isset($vars[$k]))
					{
						$vars[$k] = $v;
					}
				}
			}
		}

		return $vars;
	}

	public static function renderAmazon($vars)
	{
		$vars += array('id' => null, 'tld' => null);

		$html='<iframe width="120" height="240" allowfullscreen="" frameborder="0" scrolling="no" src="//rcm';if(isset($vars['tld'])&&(strpos('cadefritjpuk',$vars['tld'])!==false))$html.='-'.htmlspecialchars($vars['tld'],2);$html.='.amazon.';if($vars['tld']==='jp'||$vars['tld']==='uk')$html.='co.'.htmlspecialchars($vars['tld'],2);elseif(isset($vars['tld'])&&(strpos('cadefrit',$vars['tld'])!==false))$html.=htmlspecialchars($vars['tld'],2);else$html.='com';$html.='/e/cm?lt1=_blank&amp;bc1=FFFFFF&amp;bg1=FFFFFF&amp;fc1=000000&amp;lc1=0000FF&amp;p=8&amp;l=as1&amp;f=ifr&amp;asins='.htmlspecialchars($vars['id'],2).'&amp;o=';if($vars['tld']==='ca')$html.='15';elseif($vars['tld']==='de')$html.='3';elseif($vars['tld']==='fr')$html.='8';elseif($vars['tld']==='it')$html.='29';elseif($vars['tld']==='jp')$html.='9';elseif($vars['tld']==='uk')$html.='2';else$html.='1';$html.='&amp;t=';if(!empty(XenForo_Application::get('options')->s9e_AMAZON_ASSOCIATE_TAG))$html.=htmlspecialchars(XenForo_Application::get('options')->s9e_AMAZON_ASSOCIATE_TAG,2);else$html.='_';$html.='"></iframe>';

		return $html;
	}

	public static function matchAmazon($url)
	{
		$regexps = array('#(?=.*?[./]amazon\\.(?>c(?>a|o(?>m|\\.(?>jp|uk)))|de|fr|it)[:/]).*?/(?:dp|gp/product)/(?\'id\'[A-Z0-9]+)#', '#(?=.*?[./]amazon\\.(?>c(?>a|o(?>m|\\.(?>jp|uk)))|de|fr|it)[:/]).*?amazon\\.(?:co\\.)?(?\'tld\'ca|de|fr|it|jp|uk)#');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderAudiomack($vars)
	{
		$vars += array('id' => null, 'mode' => null);

		$html='<iframe width="100%" style="max-width:900px" allowfullscreen="" frameborder="0" scrolling="no" height="';if($vars['mode']==='album')$html.='352';else$html.='144';$html.='" src="//www.audiomack.com/embed3';if($vars['mode']==='album')$html.='-album';$html.='/'.htmlspecialchars($vars['id'],2).'"></iframe>';

		return $html;
	}

	public static function matchAudiomack($url)
	{
		$regexps = array('!audiomack\\.com/(?\'mode\'album|song)/(?\'id\'[-\\w]+/[-\\w]+)!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderBandcamp($vars)
	{
		$vars += array('album_id' => null, 'track_id' => null, 'track_num' => null);

		$html='<iframe width="400" height="400" allowfullscreen="" frameborder="0" scrolling="no" src="//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/';if(isset($vars['album_id'])){$html.='album='.htmlspecialchars($vars['album_id'],2);if(isset($vars['track_num']))$html.='/t='.htmlspecialchars($vars['track_num'],2);}else$html.='track='.htmlspecialchars($vars['track_id'],2);$html.='"></iframe>';

		return $html;
	}

	public static function matchBandcamp($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!bandcamp\\.com/album/.!'),
				'extract' => array('!/album=(?\'album_id\'\\d+)!')
			),
			array(
				'match'   => array('!bandcamp\\.com/track/.!'),
				'extract' => array('!"album_id":(?\'album_id\'\\d+)!', '!"track_num":(?\'track_num\'\\d+)!', '!/track=(?\'track_id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchBbcnews($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!bbc\\.com/news/\\w+!'),
				'extract' => array('!meta name="twitter:player".*?playlist=(?\'playlist\'[-/\\w]+)(?:&poster=(?\'poster\'[-/.\\w]+))?(?:&ad_site=(?\'ad_site\'[/\\w]+))?!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchBlip($url)
	{
		$regexps = array('!blip\\.tv/play/(?\'id\'[\\w+%/_]+)!');
		$scrapes = array(
			array(
				'match'   => array('!blip\\.tv/[^/]+/[^/]+-\\d+$!'),
				'extract' => array('!blip\\.tv/play/(?\'id\'[\\w%+/_]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderCbsnews($vars)
	{
		$vars += array('id' => null, 'pid' => null);

		$html='<object type="application/x-shockwave-flash" typemustmatch="" width="425" height="279" data="';if(isset($vars['pid']))$html.='http://www.cbsnews.com/common/video/cbsnews_player.swf';else$html.='http://i.i.cbsi.com/cnwk.1d/av/video/cbsnews/atlantis2/cbsnews_player_embed.swf';$html.='"><param name="allowfullscreen" value="true"><param name="flashvars" value="';if(isset($vars['pid']))$html.='pType=embed&amp;si=254&amp;pid='.htmlspecialchars($vars['pid'],2);else$html.='si=254&amp;contentValue='.htmlspecialchars($vars['id'],2);$html.='"><embed type="application/x-shockwave-flash" width="425" height="279" allowfullscreen="" src="';if(isset($vars['pid']))$html.='http://www.cbsnews.com/common/video/cbsnews_player.swf';else$html.='http://i.i.cbsi.com/cnwk.1d/av/video/cbsnews/atlantis2/cbsnews_player_embed.swf';$html.='" flashvars="';if(isset($vars['pid']))$html.='pType=embed&amp;si=254&amp;pid='.htmlspecialchars($vars['pid'],2);else$html.='si=254&amp;contentValue='.htmlspecialchars($vars['id'],2);$html.='"></object>';

		return $html;
	}

	public static function matchCbsnews($url)
	{
		$regexps = array('#cbsnews\\.com/video/watch/\\?id=(?\'id\'\\d+)#');
		$scrapes = array(
			array(
				'match'   => array('#cbsnews\\.com/videos/(?!watch/)#'),
				'extract' => array('#"pid":"(?\'pid\'\\w+)"#')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchColbertnation($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!thecolbertreport\\.cc\\.com/videos/!'),
				'extract' => array('!(?\'id\'mgid:arc:video:colbertnation\\.com:[-0-9a-f]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchComedycentral($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!c(?:c|omedycentral)\\.com/video-clips/!'),
				'extract' => array('!(?\'id\'mgid:arc:video:[.\\w]+:[-\\w]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchDailyshow($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!thedailyshow\\.c(?:c\\.c)?om/(?:collection|extended-interviews|videos|watch)/!'),
				'extract' => array('!(?\'id\'mgid:arc:(?:playlist|video):thedailyshow\\.com:[-0-9a-f]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderEbay($vars)
	{
		$vars += array('id' => null, 'itemid' => null, 'lang' => null);

		$html='<a href="http://www.ebay.';if($vars['lang']==='de_AT')$html.='at';elseif($vars['lang']==='en_GB')$html.='co.uk';elseif($vars['lang']==='de_DE')$html.='de';elseif($vars['lang']==='fr_FR')$html.='fr';elseif($vars['lang']==='it_IT')$html.='it';else$html.='com';$html.='/itm/';if(isset($vars['itemid']))$html.=htmlspecialchars($vars['itemid'],2);else$html.=htmlspecialchars($vars['id'],2);$html.='">eBay item #';if(isset($vars['itemid']))$html.=htmlspecialchars($vars['itemid'],0);else$html.=htmlspecialchars($vars['id'],0);$html.='</a>';

		return $html;
	}

	public static function matchEbay($url)
	{
		$regexps = array('#(?=.*?[./]ebay\\.(?>co(?>m|\\.uk)|de|fr|[ai]t)[:/]).*?ebay.[\\w.]+/itm/(?:[-\\w]+/)?(?\'id\'\\d+)#', '#(?=.*?[./]ebay\\.(?>co(?>m|\\.uk)|de|fr|[ai]t)[:/]).*?[?&]item=(?\'id\'\\d+)#');
		$scrapes = array(
			array(
				'match'   => array('#ebay\\.(?!com/)#'),
				'extract' => array('#"locale":"(?\'lang\'\\w+)"#')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchEighttracks($url)
	{
		$regexps = array('!8tracks\\.com/[-\\w]+/(?\'id\'\\d+)(?=#|$)!');
		$scrapes = array(
			array(
				'match'   => array('!8tracks\\.com/[-\\w]+/[-\\w]+!'),
				'extract' => array('!eighttracks://mix/(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchEspn($url)
	{
		$regexps = array('#(?=.*?[./]espn\\.go\\.com[:/]).*?(?\'cms\'deportes|espn(?!d)).*(?:clip\\?|video\\?v|clipDeportes\\?)id=(?:\\w+:)?(?\'id\'\\d+)#');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchGametrailers($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!gametrailers\\.com/(?:full-episode|review|video)s/!'),
				'extract' => array('!(?\'id\'mgid:arc:(?:episode|video):gametrailers\\.com:[-\\w]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderGetty($vars)
	{
		$vars += array('et' => null, 'height' => null, 'id' => null, 'sig' => null, 'width' => null);

		$html='<iframe width="'.htmlspecialchars($vars['width'],2).'" height="'.htmlspecialchars(49+$vars['height'],2).'" src="//embed.gettyimages.com/embed/'.htmlspecialchars($vars['id'],2).'?et='.htmlspecialchars($vars['et'],2).'&amp;similar=on&amp;sig='.htmlspecialchars($vars['sig'],2).'" allowfullscreen="" frameborder="0" scrolling="no"></iframe>';

		return $html;
	}

	public static function matchGetty($url)
	{
		$regexps = array('!gty\\.im/(?\'id\'\\d+)!', '!(?=.*?[./]g(?:ettyimages\\.(?:c(?:n|o(?:\\.(?>jp|uk)|m(?>\\.au)?))|d[ek]|es|fr|i[et]|nl|pt|[bs]e)|ty\\.im)[:/]).*?gettyimages\\.[.\\w]+/detail(?=/).*?/(?\'id\'\\d+)!');
		$scrapes = array(
			array(
				'url'     => 'http://embed.gettyimages.com/preview/{@id}',
				'match'   => array('//'),
				'extract' => array('!"height":[ "]*(?\'height\'\\d+)!', '!"width":[ "]*(?\'width\'\\d+)!', '!et=(?\'et\'[-=\\w]+)!', '!sig=(?\'sig\'[-=\\w]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchGfycat($url)
	{
		$regexps = array('!gfycat\\.com/(?\'id\'\\w+)!');
		$scrapes = array(
			array(
				'url'     => 'http://gfycat.com/{@id}',
				'match'   => array('//'),
				'extract' => array('!gfyHeight[ ="]+(?\'height\'\\d+)!', '!gfyWidth[ ="]+(?\'width\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchGoogleplus($url)
	{
		$regexps = array('!//plus\\.google\\.com/(?:\\+\\w+|(?\'oid\'\\d+))/posts/(?\'pid\'\\w+)!');
		$scrapes = array(
			array(
				'match'   => array('!//plus\\.google\\.com/\\+[^/]+/posts/\\w!'),
				'extract' => array('!oid="?(?\'oid\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchGooglesheets($url)
	{
		$regexps = array('!docs\\.google\\.com/spreadsheet(?:/ccc\\?key=|s/d/)(?\'id\'\\w+)[^#]*(?:#gid=(?\'gid\'\\d+))?!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderGrooveshark($vars)
	{
		$vars += array('playlistid' => null, 'songid' => null);

		$html='<object type="application/x-shockwave-flash" typemustmatch="" width="400" height="'.htmlspecialchars((isset($vars['songid'])?40:400),2).'" data="//grooveshark.com/'.htmlspecialchars((isset($vars['songid'])?'songW':'w'),2).'idget.swf"><param name="allowfullscreen" value="true"><param name="flashvars" value="playlistID='.htmlspecialchars($vars['playlistid'],2).'&amp;songID='.htmlspecialchars($vars['songid'],2).'"><embed type="application/x-shockwave-flash" src="//grooveshark.com/'.htmlspecialchars((isset($vars['songid'])?'songW':'w'),2).'idget.swf" width="400" height="'.htmlspecialchars((isset($vars['songid'])?40:400),2).'" allowfullscreen="" flashvars="playlistID='.htmlspecialchars($vars['playlistid'],2).'&amp;songID='.htmlspecialchars($vars['songid'],2).'"></object>';

		return $html;
	}

	public static function matchGrooveshark($url)
	{
		$regexps = array('%grooveshark\\.com(?:/#!?)?/playlist/[^/]+/(?\'playlistid\'\\d+)%');
		$scrapes = array(
			array(
				'url'     => 'http://grooveshark.com/s/{@path}',
				'match'   => array('%grooveshark\\.com(?:/#!?)?/s/(?\'path\'[^/]+/.+)%'),
				'extract' => array('%songID=(?\'songid\'\\d+)%')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchHulu($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!hulu\\.com/watch/!'),
				'extract' => array('!eid=(?\'id\'[-\\w]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderImgur($vars)
	{
		$vars += array('height' => null, 'id' => null, 'type' => null, 'width' => null);

		$html='<iframe allowfullscreen="" frameborder="0" scrolling="no" width="';if($vars['type']==='gifv'&&isset($vars['width']))$html.=htmlspecialchars($vars['width'],2);else$html.='100%';$html.='" height="';if($vars['type']==='gifv'&&isset($vars['height']))$html.=htmlspecialchars($vars['height'],2);else$html.='550';$html.='" src="';if($vars['type']==='gifv')$html.='//i.imgur.com/'.htmlspecialchars($vars['id'],2).'.gifv#embed';else$html.='//imgur.com/a/'.htmlspecialchars($vars['id'],2).'/embed';$html.='"></iframe>';

		return $html;
	}

	public static function matchImgur($url)
	{
		$regexps = array('!imgur\\.com/a/(?\'id\'\\w+)!', '!imgur\\.com/(?\'id\'\\w+)\\.(?:gifv|mp4)!');
		$scrapes = array(
			array(
				'match'   => array('!imgur\\.com/a/\\w!'),
				'extract' => array('!<a class="(?\'type\'album)-embed-link!')
			),
			array(
				'url'     => 'http://i.imgur.com/{@id}.gifv',
				'match'   => array('!imgur\\.com/\\w+\\.(?:gifv|mp4)!'),
				'extract' => array('!width:\\s*(?\'width\'\\d+)!', '!height:\\s*(?\'height\'\\d+)!', '!(?\'type\'gifv)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchIndiegogo($url)
	{
		$regexps = array('!indiegogo\\.com/projects/(?\'id\'\\d+)$!');
		$scrapes = array(
			array(
				'match'   => array('!indiegogo\\.com/projects/.!'),
				'extract' => array('!indiegogo\\.com/projects/(?\'id\'\\d+)/!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchInternetarchive($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('!archive\\.org/details/!'),
				'extract' => array('!meta property="twitter:player" content="https://archive.org/embed/(?\'id\'[^/"]+)!', '!meta property="og:video:width" content="(?\'width\'\\d+)!', '!meta property="og:video:height" content="(?\'height\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderKickstarter($vars)
	{
		$vars += array('id' => null, 'video' => null);

		$html='';if(isset($vars['video']))$html.='<iframe width="480" height="360" src="//www.kickstarter.com/projects/'.htmlspecialchars($vars['id'],2).'/widget/video.html" allowfullscreen="" frameborder="0" scrolling="no"></iframe>';else$html.='<iframe width="220" height="380" src="//www.kickstarter.com/projects/'.htmlspecialchars($vars['id'],2).'/widget/card.html" allowfullscreen="" frameborder="0" scrolling="no"></iframe>';

		return $html;
	}

	public static function matchKickstarter($url)
	{
		$regexps = array('!kickstarter\\.com/projects/(?\'id\'[^/]+/[^/?]+)(?:/widget/(?:(?\'card\'card)|(?\'video\'video)))?!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchMixcloud($url)
	{
		$regexps = array('@mixcloud\\.com/(?!categories|tag)(?\'id\'[-\\w]+/[^/&]+)/@');
		$scrapes = array(
			array(
				'match'   => array('@//i\\.mixcloud\\.com/\\w+$@'),
				'extract' => array('@link rel="canonical" href="https?://[^/]+/(?\'id\'[-\\w]+/[^/&]+)/@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchMsnbc($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('@msnbc\\.com/[-\\w]+/watch/@'),
				'extract' => array('@guid="?(?\'id\'\\w+)@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchNatgeovideo($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('@video\\.nationalgeographic\\.com/(?:tv|video)/\\w@'),
				'extract' => array('@guid="(?\'id\'[-\\w]+)"@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchPodbean($url)
	{
		$regexps = array('!podbean\\.com/site/player/index/pid/\\d+/eid/(?\'id\'\\d+)!');
		$scrapes = array(
			array(
				'match'   => array('!podbean\\.com/e/!'),
				'extract' => array('!embed/postId/(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchRdio($url)
	{
		$regexps = array('!rd\\.io/./(?\'id\'\\w+)!');
		$scrapes = array(
			array(
				'url'     => 'http://www.rdio.com/api/oembed/?url={@url}',
				'match'   => array('!rdio\\.com/.*?(?:playlist|track)!'),
				'extract' => array('!rd\\.io/./(?\'id\'\\w+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchRutube($url)
	{
		$regexps = array('!rutube\\.ru/tracks/(?\'id\'\\d+)!');
		$scrapes = array(
			array(
				'match'   => array('!rutube\\.ru/video/[0-9a-f]{32}!'),
				'extract' => array('!rutube\\.ru/play/embed/(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchSlideshare($url)
	{
		$regexps = array('!slideshare\\.net/[^/]+/[-\\w]+-(?\'id\'\\d{6,})$!');
		$scrapes = array(
			array(
				'match'   => array('!slideshare\\.net/[^/]+/\\w!'),
				'extract' => array('!"presentationId":(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderSoundcloud($vars)
	{
		$vars += array('id' => null, 'playlist_id' => null, 'secret_token' => null, 'track_id' => null);

		$html='<iframe width="100%" height="166" style="max-width:900px" allowfullscreen="" frameborder="0" scrolling="no" src="https://w.soundcloud.com/player/?url=';if(isset($vars['secret_token'])&&isset($vars['playlist_id']))$html.='https://api.soundcloud.com/playlists/'.htmlspecialchars($vars['playlist_id'],2).'&amp;secret_token='.htmlspecialchars($vars['secret_token'],2);elseif(isset($vars['secret_token'])&&isset($vars['track_id']))$html.='https://api.soundcloud.com/tracks/'.htmlspecialchars($vars['track_id'],2).'&amp;secret_token='.htmlspecialchars($vars['secret_token'],2);else{if((strpos($vars['id'],'://')===false))$html.='https://soundcloud.com/';$html.=htmlspecialchars($vars['id'],2);if(isset($vars['secret_token']))$html.='&amp;secret_token='.htmlspecialchars($vars['secret_token'],2);}$html.='"></iframe>';

		return $html;
	}

	public static function matchSoundcloud($url)
	{
		$regexps = array('@(?\'id\'https?://(?:(?:api\\.soundcloud\\.com/(?:playlist|track)s/\\d+)|soundcloud\\.com/[^/]+/(?:sets/)?[^/]+)(?:(?:\\?secret_token=|/(?=s-))(?\'secret_token\'[-\\w]+))?|^[^/]+/[^/]+$)@');
		$scrapes = array(
			array(
				'url'     => 'https://api.soundcloud.com/resolve?url={@id}&_status_code_map%5B302%5D=200&_status_format=json&client_id=b45b1aa10f1ac2941910a7f0d10f8e28&app_version=7a35847b',
				'match'   => array('@soundcloud\\.com/(?!playlists/|tracks/)[^/]+/(?:sets/)?[^/]+/s-@'),
				'extract' => array('@playlists/(?\'playlist_id\'\\d+)@', '@tracks/(?\'track_id\'\\d+)@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchSportsnet($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('//'),
				'extract' => array('@vid(?:eoId)?=(?\'id\'\\d+)@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderSpotify($vars)
	{
		$vars += array('path' => null, 'uri' => null);

		$html='<iframe width="400" height="480" allowfullscreen="" frameborder="0" scrolling="no" src="https://embed.spotify.com/?view=coverart&amp;uri=';if(isset($vars['uri']))$html.=htmlspecialchars($vars['uri'],2);else$html.='spotify:'.htmlspecialchars(strtr($vars['path'],'/',':'),2);$html.='"></iframe>';

		return $html;
	}

	public static function matchSpotify($url)
	{
		$regexps = array('!(?\'uri\'spotify:(?:album|artist|user|track(?:set)?):[,:\\w]+)!', '!(?:open|play)\\.spotify\\.com/(?\'path\'(?:album|artist|track|user)/[/\\w]+)!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchTeamcoco($url)
	{
		$regexps = array('!teamcoco\\.com/video/(?\'id\'\\d+)!');
		$scrapes = array(
			array(
				'match'   => array('!teamcoco\\.com/video/.!'),
				'extract' => array('!teamcoco\\.com/embed/v/(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderTed($vars)
	{
		$vars += array('id' => null);

		$html='<iframe width="560" height="315" allowfullscreen="" frameborder="0" scrolling="no" src="http://embed.ted.com/'.htmlspecialchars($vars['id'],2);if((strpos($vars['id'],'.html')===false))$html.='.html';$html.='"></iframe>';

		return $html;
	}

	public static function matchTinypic($url)
	{
		$regexps = array('!tinypic\\.com/player\\.php\\?v=(?\'id\'\\w+)&s=(?\'s\'\\d+)!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchTraileraddict($url)
	{
		$regexps = array();
		$scrapes = array(
			array(
				'match'   => array('@traileraddict\\.com/(?!tags/)[^/]+/.@'),
				'extract' => array('@v\\.traileraddict\\.com/(?\'id\'\\d+)@')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderTwitch($vars)
	{
		$vars += array('archive_id' => null, 'channel' => null, 'chapter_id' => null);

		$html='<object type="application/x-shockwave-flash" typemustmatch="" width="620" height="378" data="//www.twitch.tv/widgets/';if(isset($vars['archive_id'])||isset($vars['chapter_id']))$html.='arch';else$html.='l';$html.='ive_embed_player.swf"><param name="allowfullscreen" value="true"><param name="flashvars" value="channel='.htmlspecialchars($vars['channel'],2);if(isset($vars['archive_id']))$html.='&amp;archive_id='.htmlspecialchars($vars['archive_id'],2);if(isset($vars['chapter_id']))$html.='&amp;chapter_id='.htmlspecialchars($vars['chapter_id'],2);$html.='&amp;auto_play=false"><embed type="application/x-shockwave-flash" width="620" height="378" allowfullscreen="" src="//www.twitch.tv/widgets/';if(isset($vars['archive_id'])||isset($vars['chapter_id']))$html.='arch';else$html.='l';$html.='ive_embed_player.swf" flashvars="channel='.htmlspecialchars($vars['channel'],2);if(isset($vars['archive_id']))$html.='&amp;archive_id='.htmlspecialchars($vars['archive_id'],2);if(isset($vars['chapter_id']))$html.='&amp;chapter_id='.htmlspecialchars($vars['chapter_id'],2);$html.='&amp;auto_play=false"></object>';

		return $html;
	}

	public static function matchTwitch($url)
	{
		$regexps = array('#twitch\\.tv/(?\'channel\'(?!m/)\\w+)(?:/b/(?\'archive_id\'\\d+)|/c/(?\'chapter_id\'\\d+))?#');
		$scrapes = array(
			array(
				'match'   => array('!twitch\\.tv/m/\\d+!'),
				'extract' => array('!channel=(?\'channel\'\\w+)&.*?archive_id=(?\'archive_id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderUstream($vars)
	{
		$vars += array('cid' => null, 'vid' => null);

		$html='<iframe width="480" height="302" allowfullscreen="" frameborder="0" scrolling="no" src="//www.ustream.tv/embed/';if(isset($vars['vid']))$html.='recorded/'.htmlspecialchars($vars['vid'],2);else$html.=htmlspecialchars($vars['cid'],2);$html.='"></iframe>';

		return $html;
	}

	public static function matchUstream($url)
	{
		$regexps = array('!ustream\\.tv/recorded/(?\'vid\'\\d+)!');
		$scrapes = array(
			array(
				'match'   => array('#ustream\\.tv/(?!explore/|platform/|recorded/|search\\?|upcoming$|user/)(?:channel/)?[-\\w]+#'),
				'extract' => array('!embed/(?\'cid\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchVidme($url)
	{
		$regexps = array('!vid\\.me/(?\'id\'\\w+)!');
		$scrapes = array(
			array(
				'match'   => array('//'),
				'extract' => array('!meta property="og:video:type" content="video/\\w+">\\s*<meta property="og:video:height" content="(?\'height\'\\d+)">\\s*<meta property="og:video:width" content="(?\'width\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchVk($url)
	{
		$regexps = array('!vk(?:\\.com|ontakte\\.ru)/(?:[\\w.]+\\?z=)?video(?\'oid\'-?\\d+)_(?\'vid\'\\d+)!', '!vk(?:\\.com|ontakte\\.ru)/video_ext\\.php\\?oid=(?\'oid\'-?\\d+)&id=(?\'vid\'\\d+)&hash=(?\'hash\'[0-9a-f]+)!');
		$scrapes = array(
			array(
				'url'     => 'http://vk.com/video{@oid}_{@vid}',
				'match'   => array('!vk.*?video-?\\d+_\\d+!'),
				'extract' => array('!embed_hash=(?\'hash\'[0-9a-f]+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchVube($url)
	{
		$regexps = array('!vube\\.com/[^/]+/[^/]+/(?\'id\'\\w+)!');
		$scrapes = array(
			array(
				'match'   => array('!vube\\.com/s/\\w+!'),
				'extract' => array('!vube\\.com/[^/]+/[^/]+/(?\'id\'\\w+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function matchWshh($url)
	{
		$regexps = array('!worldstarhiphop\\.com/featured/(?\'id\'\\d+)!');
		$scrapes = array(
			array(
				'match'   => array('!worldstarhiphop\\.com/(?:\\w+/)?video\\.php\\?v=\\w+!'),
				'extract' => array('!disqus_identifier[ =\']+(?\'id\'\\d+)!')
			)
		);

		return self::match($url, $regexps, $scrapes);
	}

	public static function renderWsj($vars)
	{
		$vars += array('id' => null);

		$html='<iframe width="512" height="288" src="http://live.wsj.com/public/page/embed-'.htmlspecialchars(strtr($vars['id'],'-','_'),2).'.html" allowfullscreen="" frameborder="0" scrolling="no"></iframe>';

		return $html;
	}

	public static function renderYoutube($vars)
	{
		$vars += array('h' => null, 'id' => null, 'list' => null, 'm' => null, 's' => null, 't' => null);

		$html='<iframe width="560" height="315" allowfullscreen="" frameborder="0" scrolling="no" src="//www.youtube.com/embed/'.htmlspecialchars($vars['id'],2);if(isset($vars['list']))$html.='?list='.htmlspecialchars($vars['list'],2);if(isset($vars['t'])||isset($vars['m'])){if(isset($vars['list']))$html.='&amp;';else$html.='?';$html.='start=';if(isset($vars['t']))$html.=htmlspecialchars($vars['t'],2);elseif(isset($vars['h']))$html.=htmlspecialchars($vars['h']*3600+$vars['m']*60+$vars['s'],2);else$html.=htmlspecialchars($vars['m']*60+$vars['s'],2);}$html.='"></iframe>';

		return $html;
	}

	public static function matchYoutube($url)
	{
		$regexps = array('!youtube\\.com/(?:watch.*?v=|v/)(?\'id\'[-\\w]+)!', '!youtu\\.be/(?\'id\'[-\\w]+)!', '!(?=.*?[./]youtu(?>\\.be|be\\.com)[:/]).*?[#&?]t=(?:(?:(?\'h\'\\d+)h)?(?\'m\'\\d+)m(?\'s\'\\d+)|(?\'t\'\\d+))!', '!(?=.*?[./]youtu(?>\\.be|be\\.com)[:/]).*?&list=(?\'list\'[-\\w]+)!');
		$scrapes = array();

		return self::match($url, $regexps, $scrapes);
	}
}