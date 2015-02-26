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

function ia_pages(&$actionArray, &$adminActions)
{
	$actionsArray['page'] = array('Pages.controller.php', 'Pages_Controller', 'action_index');
}
