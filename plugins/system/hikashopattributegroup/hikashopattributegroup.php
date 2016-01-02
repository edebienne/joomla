<?php
/*------------------------------------------------------------------------
# plg_hikashopattributegroup - Attribute Group For Hikashop
# ------------------------------------------------------------------------
# author    Eric Debienne
# copyright Copyright (C) 2015. All Rights Reserved.
# @license - http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
# Websites: http://test.debienne.fr
# Technical Support:  Email - dsi@debienne.fr
-------------------------------------------------------------------------*/
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' ); 
jimport('joomla.user.helper');
jimport( 'joomla.user.user');
jimport( 'joomla.html.parameter' );
jimport('joomla.log.log');
jimport('joomla.plugin');
		
class plgSystemHikashopAttributeGroup extends JPlugin
{
	public function onUserAfterSave($user, $isnew, $success, $msg)
	{
		$app = JFactory::getApplication();
		$session = JFactory::getSession();

		if($app->isAdmin()) {
			return;
		}
		
		if(!$success || (isset($user['resetCount']) && (int)$user['resetCount'] > 0)){
			return;
		}
		
		$user = new JUser($user['id']);
		$userid = $user->get('id');
		
		//Now define the parameters like this:
#		JLog::add(JText::_('JTEXT_ERROR_MESSAGE'), JLog::WARNING, 'jerror');
		$fieldname = $this->params->get( 'fieldname', 'status' );
		$reggroup  = $this->params->get( 'reggroup', 2 );
		$removefromdef  = $this->params->get( 'removefromreg', 1 );
		$value = $_POST['data']['user'][$fieldname];
		$automaticLogin = $this->params->get( 'automaticlogin', 1 );
		
		//if it is the same group as registered then exit.
		if($value == $reggroup)
			return;
				
		// Add user to group
		JUserHelper::addUserToGroup($userid, $value); 

		if($removefromdef){
			// Remove user from group
			JUserHelper::removeUserFromGroup($userid, $reggroup);
		}

		// Get the latest groups and view levels
		$authGroups = JAccess::getGroupsByUser($userid);
		$authLevels = JAccess::getAuthorisedViewLevels($userid);

		if($automaticLogin)
		{
			$users_config = JComponentHelper::getParams('com_users');
			if($users_config->get('useractivation') == 0 )
			{
				// Set the _authLevels en _authGroups arguments of the user class
				$user->set('_authLevels', $authLevels);
				$user->set('_authGroups', $authGroups);

				// Get the session and write the user settings to the session				
				$session->set('user', $user);
			}
		}
	}
}
