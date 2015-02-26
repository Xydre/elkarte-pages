<?php

/**
 * @package Pages
 *
 * @author Antony Derham
 * @copyright 2015 Antony Derham
 *
 * @version 1.0
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Pages controller.
 */
class Pages_Controller extends Action_Controller
{
	/**
	 * File name to load
	 * @var string
	 */
	protected $_file_name;

	/**
	 * Function or method to call
	 * @var string
	 */
	protected $_function_name;

	/**
	 * Class name, for object oriented controllers
	 * @var string
	 */
	protected $_controller_name;

	public function pre_dispatch() {
		global $settings;

		$settings['custom_template_dirs'] = str_replace('/themes/', '/custom/themes/', $settings['template_dirs']);
		$settings['custom_default_theme_dir'] = str_replace('/themes/', '/custom/themes/', $settings['default_theme_dir']);
		$settings['custom_default_theme_url'] = str_replace('/themes/', '/custom/themes/', $settings['default_theme_url']);
		$settings['custom_theme_dir'] = str_replace('/themes/', '/custom/themes/', $settings['theme_dir']);
		$settings['custom_theme_url'] = str_replace('/themes/', '/custom/themes/', $settings['theme_url']);
		$settings['custom_base_theme_dir'] = str_replace('/themes/', '/custom/themes/', $settings['base_theme_dir']);
		$settings['custom_base_theme_url'] = str_replace('/themes/', '/custom/themes/', $settings['base_theme_url']);
	}

	/*
	 * Call a custom page
	 */
	public function action_index()
	{
		// Get and decode list of pages
		$pages = json_decode(file_get_contents(BOARDDIR . '/custom/pages.json'), true);
		$pa = $_GET['pa'];

		$this->_file_name = $pages[$pa][0];
		$this->_controller_name = $pages[$pa][1];
		$this->_function_name = $pages[$pa][2];

		// Include file and create controller instance
		require_once(BOARDDIR . '/custom/sources/controllers/'.$this->_file_name);
		$page_controller = new $this->_controller_name();

		// Pre-dispatch (load templates and stuff)
		if (method_exists($page_controller, 'pre_dispatch'))
			$page_controller->pre_dispatch();

		// Attempt to dispatch page action call
		if (method_exists($page_controller, $this->_function_name))
			$page_controller->{$this->_function_name}();
		elseif (method_exists($page_controller, 'action_index'))
			$page_controller->action_index();
		// Fall back
		elseif (function_exists($this->_function_name))
		{
			call_user_func($this->_function_name);
		}
		else
		{
			// Things went pretty bad, huh?
			// board index :P
			call_integration_hook('integrate_action_boardindex_before');
			$page_controller = new BoardIndex_Controller();
			$page_controller->action_boardindex();
			call_integration_hook('integrate_action_boardindex_after');
		}

		call_integration_hook('integrate_action_' . $hook . '_after', array($this->_function_name));
	}
}

function loadCustomTemplate($template_name, $style_sheets = array(), $fatal = true)
{
	global $context, $settings;
	static $delay = array();

	// If we don't know yet the default theme directory, let's wait a bit.
	if (empty($settings['custom_template_dirs']))
	{
		$delay[] = array(
			$template_name,
			$style_sheets,
			$fatal
		);
		return;
	}
	// If instead we know the default theme directory and we have delayed something, it's time to process
	elseif (!empty($delay))
	{
		foreach ($delay as $val)
			requireCustomTemplate($val[0], $val[1], $val[2]);

		// Forget about them (load them only once)
		$delay = array();
	}

	requireCustomTemplate($template_name, $style_sheets, $fatal);
}

function requireCustomTemplate($template_name, $style_sheets, $fatal)
{
	global $context, $settings, $txt, $scripturl, $db_show_debug;
	static $default_loaded = false;

	if (!is_array($style_sheets))
		$style_sheets = array($style_sheets);

	if ($default_loaded === false)
	{
		loadCustomCSSFile('index.css');
		$default_loaded = true;
	}

	// Any specific template style sheets to load?
	if (!empty($style_sheets))
	{
		$sheets = array();
		foreach ($style_sheets as $sheet)
			$sheets[] = stripos('.css', $sheet) !== false ? $sheet : $sheet . '.css';
		loadCustomCSSFile($sheets);
	}

	// No template to load?
	if ($template_name === false)
		return true;

	$loaded = false;
	foreach ($settings['custom_template_dirs'] as $template_dir)
	{
		if (file_exists($template_dir . '/' . $template_name . '.template.php'))
		{
			$loaded = true;
			custom_template_include($template_dir . '/' . $template_name . '.template.php', true);
			break;
		}
	}

	if ($loaded)
	{
		if ($db_show_debug === true)
			Debug::get()->add('templates', $template_name . ' (' . basename($template_dir) . ')');

		// If they have specified an initialization function for this template, go ahead and call it now.
		if (function_exists('template_' . $template_name . '_init'))
			call_user_func('template_' . $template_name . '_init');
	}
	// Hmmm... doesn't exist?!  I don't suppose the directory is wrong, is it?
	elseif (!file_exists($settings['custom_default_theme_dir']) && file_exists(BOARDDIR . '/custom/themes/default'))
	{
		$settings['custom_default_theme_dir'] = BOARDDIR . '/custom/themes/default';
		$settings['custom_template_dirs'][] = $settings['custom_default_theme_dir'];

		if (!empty($context['user']['is_admin']) && !isset($_GET['th']))
		{
			loadCustomLanguage('Errors');
			if (!isset($context['security_controls_files']['title']))
				$context['security_controls_files']['title'] = $txt['generic_warning'];
			$context['security_controls_files']['errors']['theme_dir'] = '<a href="' . $scripturl . '?action=admin;area=theme;sa=list;th=1;' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['theme_dir_wrong'] . '</a>';
		}

		loadCustomTemplate($template_name);
	}
	// Cause an error otherwise.
	elseif ($template_name != 'Errors' && $template_name != 'index' && $fatal)
		fatal_lang_error('theme_template_error', 'template', array((string) $template_name));
	elseif ($fatal)
		die(log_error(sprintf(isset($txt['theme_template_error']) ? $txt['theme_template_error'] : 'Unable to load themes/default/%s.template.php!', (string) $template_name), 'template'));
	else
		return false;
}

function loadCustomCSSFile($filenames, $params = array(), $id = '')
{
	if (empty($filenames))
		return;

	$params['subdir'] = 'css';
	$params['extension'] = 'css';
	$params['index_name'] = 'css_files';
	$params['debug_index'] = 'sheets';

	loadCustomAssetFile($filenames, $params, $id);
}

function loadCustomJavascriptFile($filenames, $params = array(), $id = '')
{
	if (empty($filenames))
		return;

	$params['subdir'] = 'scripts';
	$params['extension'] = 'js';
	$params['index_name'] = 'javascript_files';
	$params['debug_index'] = 'javascript';

	loadCustomAssetFile($filenames, $params, $id);
}

function loadCustomAssetFile($filenames, $params = array(), $id = '')
{
	global $settings, $context, $db_show_debug;

	if (empty($filenames))
		return;

	if (!is_array($filenames))
		$filenames = array($filenames);

	// Static values for all these settings
	if (!isset($params['stale']) || $params['stale'] === true)
		$staler_string = CACHE_STALE;
	elseif (is_string($params['stale']))
		$staler_string = ($params['stale'][0] === '?' ? $params['stale'] : '?' . $params['stale']);
	else
		$staler_string = '';

	$fallback = (!empty($params['fallback']) && ($params['fallback'] === false)) ? false : true;
	$dir = '/' . $params['subdir'] . '/';

	// Whoa ... we've done this before yes?
	$cache_name = 'load_' . $params['extension'] . '_' . md5($settings['custom_theme_dir'] . implode('_', $filenames));
	if (($temp = cache_get_data($cache_name, 600)) !== null)
	{
		if (empty($context[$params['index_name']]))
			$context[$params['index_name']] = array();
		$context[$params['index_name']] += $temp;

		if ($db_show_debug === true)
		{
			foreach ($temp as $temp_params)
			{
				$context['debug'][$params['debug_index']][] = $temp_params['options']['basename'] . '(' . (!empty($temp_params['options']['local']) ? (!empty($temp_params['options']['url']) ? basename($temp_params['options']['url']) : basename($temp_params['options']['dir'])) : '') . ')';
			}
		}
	}
	else
	{
		$this_build = array();

		// All the files in this group use the above parameters
		foreach ($filenames as $filename)
		{
			// Account for shorthand like admin.ext?xyz11 filenames
			$has_cache_staler = strpos($filename, '.' . $params['extension'] . '?');
			if ($has_cache_staler)
			{
				$cache_staler = $staler_string;

				$params['basename'] = substr($filename, 0, $has_cache_staler + strlen($params['extension']) + 1);
			}
			else
			{
				$cache_staler = '';
				$params['basename'] = $filename;
			}
			$this_id = empty($id) ? strtr(basename($filename), '?', '_') : $id;

			// Is this a local file?
			if (!empty($params['local']) || (substr($filename, 0, 4) !== 'http' && substr($filename, 0, 2) !== '//'))
			{
				$params['local'] = true;
				$params['dir'] = $settings['custom_theme_dir'] . $dir;
				$params['url'] = $settings['custom_theme_url'];

				// Fallback if we are not already in the default theme
				if ($fallback && ($settings['custom_theme_dir'] !== $settings['custom_default_theme_dir']) && !file_exists($settings['custom_theme_dir'] . $dir . $params['basename']))
				{
					// Can't find it in this theme, how about the default?
					if (file_exists($settings['custom_default_theme_dir'] . $dir . $params['basename']))
					{
						$filename = $settings['custom_default_theme_url'] . $dir . $params['basename'] . $cache_staler;
						$params['dir'] = $settings['custom_default_theme_dir'] . $dir;
						$params['url'] = $settings['custom_default_theme_url'];
					}
					else
						$filename = false;
				}
				else
					$filename = $settings['custom_theme_url'] . $dir . $params['basename'] . $cache_staler;
			}

			// Add it to the array for use in the template
			if (!empty($filename))
			{
				$this_build[$this_id] = $context[$params['index_name']][$this_id] = array('filename' => $filename, 'options' => $params);
				if ($db_show_debug === true)
					Debug::get()->add($params['debug_index'], $params['basename'] . '(' . (!empty($params['local']) ? (!empty($params['url']) ? basename($params['url']) : basename($params['dir'])) : '') . ')');
			}

			// Save it so we don't have to build this so often
			cache_put_data($cache_name, $this_build, 600);
		}
	}
}

function loadCustomLanguage($template_name, $lang = '', $fatal = true, $force_reload = false)
{
	global $user_info, $language, $settings, $context, $modSettings;
	global $db_show_debug, $txt;
	static $already_loaded = array();

	// Default to the user's language.
	if ($lang == '')
		$lang = isset($user_info['language']) ? $user_info['language'] : $language;

	if (!$force_reload && isset($already_loaded[$template_name]) && $already_loaded[$template_name] == $lang)
		return $lang;

	// Do we want the English version of language file as fallback?
	if (empty($modSettings['disable_language_fallback']) && $lang != 'english')
		loadCustomLanguage($template_name, 'english', false);

	// Make sure we have $settings - if not we're in trouble and need to find it!
	if (empty($settings['custom_default_theme_dir']))
		loadEssentialThemeData();

	// What theme are we in?
	$theme_name = basename($settings['custom_theme_url']);
	if (empty($theme_name))
		$theme_name = 'unknown';

	$fix_arrays = false;
	// For each file open it up and write it out!
	foreach (explode('+', $template_name) as $template)
	{
		if ($template === 'index')
			$fix_arrays = true;

		// Obviously, the current theme is most important to check.
		$attempts = array(
			array($settings['custom_theme_dir'], $template, $lang, $settings['custom_theme_url']),
			array($settings['custom_theme_dir'], $template, $language, $settings['custom_theme_url']),
		);

		// Do we have a base theme to worry about?
		if (isset($settings['custom_base_theme_dir']))
		{
			$attempts[] = array($settings['custom_base_theme_dir'], $template, $lang, $settings['custom_base_theme_url']);
			$attempts[] = array($settings['custom_base_theme_dir'], $template, $language, $settings['custom_base_theme_url']);
		}

		// Fall back on the default theme if necessary.
		$attempts[] = array($settings['custom_default_theme_dir'], $template, $lang, $settings['custom_default_theme_url']);
		$attempts[] = array($settings['custom_default_theme_dir'], $template, $language, $settings['custom_default_theme_url']);

		// Fall back on the English language if none of the preferred languages can be found.
		if (!in_array('english', array($lang, $language)))
		{
			$attempts[] = array($settings['custom_theme_dir'], $template, 'english', $settings['custom_theme_url']);
			$attempts[] = array($settings['custom_default_theme_dir'], $template, 'english', $settings['custom_default_theme_url']);
		}

		// Try to find the language file.
		$found = false;
		foreach ($attempts as $k => $file)
		{
			if (file_exists($file[0] . '/languages/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php'))
			{
				// Include it!
				custom_template_include($file[0] . '/languages/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php');

				// Note that we found it.
				$found = true;

				break;
			}
			// @deprecated since 1.0 - old way of archiving language files, all in one directory
			elseif (file_exists($file[0] . '/languages/' . $file[1] . '.' . $file[2] . '.php'))
			{
				// Include it!
				custom_template_include($file[0] . '/languages/' . $file[1] . '.' . $file[2] . '.php');

				// Note that we found it.
				$found = true;

				break;
			}
		}

		// That couldn't be found!  Log the error, but *try* to continue normally.
		if (!$found && $fatal)
		{
			log_error(sprintf($txt['theme_language_error'], $template_name . '.' . $lang, 'template'));
			break;
		}
	}

	if ($fix_arrays)
	{
		$txt['days'] = array(
			$txt['sunday'],
			$txt['monday'],
			$txt['tuesday'],
			$txt['wednesday'],
			$txt['thursday'],
			$txt['friday'],
			$txt['saturday'],
		);
		$txt['days_short'] = array(
			$txt['sunday_short'],
			$txt['monday_short'],
			$txt['tuesday_short'],
			$txt['wednesday_short'],
			$txt['thursday_short'],
			$txt['friday_short'],
			$txt['saturday_short'],
		);
		$txt['months'] = array(
			1 => $txt['january'],
			$txt['february'],
			$txt['march'],
			$txt['april'],
			$txt['may'],
			$txt['june'],
			$txt['july'],
			$txt['august'],
			$txt['september'],
			$txt['october'],
			$txt['november'],
			$txt['december'],
		);
		$txt['months_titles'] = array(
			1 => $txt['january_titles'],
			$txt['february_titles'],
			$txt['march_titles'],
			$txt['april_titles'],
			$txt['may_titles'],
			$txt['june_titles'],
			$txt['july_titles'],
			$txt['august_titles'],
			$txt['september_titles'],
			$txt['october_titles'],
			$txt['november_titles'],
			$txt['december_titles'],
		);
		$txt['months_short'] = array(
			1 => $txt['january_short'],
			$txt['february_short'],
			$txt['march_short'],
			$txt['april_short'],
			$txt['may_short'],
			$txt['june_short'],
			$txt['july_short'],
			$txt['august_short'],
			$txt['september_short'],
			$txt['october_short'],
			$txt['november_short'],
			$txt['december_short'],
		);
	}

	// Keep track of what we're up to soldier.
	if ($db_show_debug === true)
		Debug::get()->add('language_files', $template_name . '.' . $lang . ' (' . $theme_name . ')');

	// Remember what we have loaded, and in which language.
	$already_loaded[$template_name] = $lang;

	// Return the language actually loaded.
	return $lang;
}

function custom_template_include($filename, $once = false)
{
	global $context, $settings, $txt, $scripturl, $modSettings, $boardurl;
	global $maintenance, $mtitle, $mmessage;
	static $templates = array();

	// We want to be able to figure out any errors...
	@ini_set('track_errors', '1');

	// Don't include the file more than once, if $once is true.
	if ($once && in_array($filename, $templates))
		return;
	// Add this file to the include list, whether $once is true or not.
	else
		$templates[] = $filename;

	// Are we going to use eval?
	if (empty($modSettings['disableTemplateEval']))
	{
		$file_found = file_exists($filename) && eval('?' . '>' . rtrim(file_get_contents($filename))) !== false;
		$settings['current_include_filename'] = $filename;
	}
	else
	{
		$file_found = file_exists($filename);

		if ($once && $file_found)
			require_once($filename);
		elseif ($file_found)
			require($filename);
	}

	if ($file_found !== true)
	{
		@ob_end_clean();
		if (!empty($modSettings['enableCompressedOutput']))
			ob_start('ob_gzhandler');
		else
			ob_start();

		if (isset($_GET['debug']))
			header('Content-Type: application/xhtml+xml; charset=UTF-8');

		// Don't cache error pages!!
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-cache');

		if (!isset($txt['template_parse_error']))
		{
			$txt['template_parse_error'] = 'Template Parse Error!';
			$txt['template_parse_error_message'] = 'It seems something has gone sour on the forum with the template system.  This problem should only be temporary, so please come back later and try again.  If you continue to see this message, please contact the administrator.<br /><br />You can also try <a href="javascript:location.reload();">refreshing this page</a>.';
			$txt['template_parse_error_details'] = 'There was a problem loading the <span style="font-family: monospace;"><strong>%1$s</strong></span> template or language file.  Please check the syntax and try again - remember, single quotes (<span style="font-family: monospace;">\'</span>) often have to be escaped with a slash (<span style="font-family: monospace;">\\</span>).  To see more specific error information from PHP, try <a href="%2$s%1$s" class="extern">accessing the file directly</a>.<br /><br />You may want to try to <a href="javascript:location.reload();">refresh this page</a> or <a href="%3$s">use the default theme</a>.';
			$txt['template_parse_undefined'] = 'An undefined error occurred during the parsing of this template';
		}

		// First, let's get the doctype and language information out of the way.
		echo '<!DOCTYPE html>
<html ', !empty($context['right_to_left']) ? 'dir="rtl"' : '', '>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';

		if (!empty($maintenance) && !allowedTo('admin_forum'))
			echo '
		<title>', $mtitle, '</title>
	</head>
	<body>
		<h3>', $mtitle, '</h3>
		', $mmessage, '
	</body>
</html>';
		elseif (!allowedTo('admin_forum'))
			echo '
		<title>', $txt['template_parse_error'], '</title>
	</head>
	<body>
		<h3>', $txt['template_parse_error'], '</h3>
		', $txt['template_parse_error_message'], '
	</body>
</html>';
		else
		{
			require_once(SUBSDIR . '/Package.subs.php');

			$error = fetch_web_data($boardurl . strtr($filename, array(BOARDDIR => '', strtr(BOARDDIR, '\\', '/') => '')));
			if (empty($error) && ini_get('track_errors') && !empty($php_errormsg))
				$error = $php_errormsg;
			elseif (empty($error))
				$error = $txt['template_parse_undefined'];

			$error = strtr($error, array('<b>' => '<strong>', '</b>' => '</strong>'));

			echo '
		<title>', $txt['template_parse_error'], '</title>
	</head>
	<body>
		<h3>', $txt['template_parse_error'], '</h3>
		', sprintf($txt['template_parse_error_details'], strtr($filename, array(BOARDDIR => '', strtr(BOARDDIR, '\\', '/') => '')), $boardurl, $scripturl . '?theme=1');

			if (!empty($error))
				echo '
		<hr />

		<div style="margin: 0 20px;"><span style="font-family: monospace;">', strtr(strtr($error, array('<strong>' . BOARDDIR => '<strong>...', '<strong>' . strtr(BOARDDIR, '\\', '/') => '<strong>...')), '\\', '/'), '</span></div>';

			// I know, I know... this is VERY COMPLICATED.  Still, it's good.
			if (preg_match('~ <strong>(\d+)</strong><br( /)?' . '>$~i', $error, $match) != 0)
			{
				$data = file($filename);
				$data2 = highlight_php_code(implode('', $data));
				$data2 = preg_split('~\<br( /)?\>~', $data2);

				// Fix the PHP code stuff...
				if (!isBrowser('gecko'))
					$data2 = str_replace("\t", '<span style="white-space: pre;">' . "\t" . '</span>', $data2);
				else
					$data2 = str_replace('<pre style="display: inline;">' . "\t" . '</pre>', "\t", $data2);

				// Now we get to work around a bug in PHP where it doesn't escape <br />s!
				$j = -1;
				foreach ($data as $line)
				{
					$j++;

					if (substr_count($line, '<br />') == 0)
						continue;

					$n = substr_count($line, '<br />');
					for ($i = 0; $i < $n; $i++)
					{
						$data2[$j] .= '&lt;br /&gt;' . $data2[$j + $i + 1];
						unset($data2[$j + $i + 1]);
					}
					$j += $n;
				}
				$data2 = array_values($data2);
				array_unshift($data2, '');

				echo '
		<div style="margin: 2ex 20px; width: 96%; overflow: auto;"><pre style="margin: 0;">';

				// Figure out what the color coding was before...
				$line = max($match[1] - 9, 1);
				$last_line = '';
				for ($line2 = $line - 1; $line2 > 1; $line2--)
					if (strpos($data2[$line2], '<') !== false)
					{
						if (preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line2], $color_match) != 0)
							$last_line = $color_match[1];
						break;
					}

				// Show the relevant lines...
				for ($n = min($match[1] + 4, count($data2) + 1); $line <= $n; $line++)
				{
					if ($line == $match[1])
						echo '</pre><div style="background: #ffb0b5;"><pre style="margin: 0;">';

					echo '<span style="color: black;">', sprintf('%' . strlen($n) . 's', $line), ':</span> ';
					if (isset($data2[$line]) && $data2[$line] != '')
						echo substr($data2[$line], 0, 2) == '</' ? preg_replace('~^</[^>]+>~', '', $data2[$line]) : $last_line . $data2[$line];

					if (isset($data2[$line]) && preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line], $color_match) != 0)
					{
						$last_line = $color_match[1];
						echo '</', substr($last_line, 1, 4), '>';
					}
					elseif ($last_line != '' && strpos($data2[$line], '<') !== false)
						$last_line = '';
					elseif ($last_line != '' && $data2[$line] != '')
						echo '</', substr($last_line, 1, 4), '>';

					if ($line == $match[1])
						echo '</pre></div><pre style="margin: 0;">';
					else
						echo "\n";
				}

				echo '</pre></div>';
			}

			echo '
	</body>
</html>';
		}

		die;
	}
}

