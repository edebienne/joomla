<?php
/**
 * @package    HikaMarket for Joomla!
 * @version    1.6.7
 * @author     Obsidev S.A.R.L.
 * @copyright  (C) 2011-2015 OBSIDEV. All rights reserved.
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php

class hikamarketCssType {

	private function load($type = '') {
		$this->values = array(
			JHTML::_('select.option', '',JText::_('HIKA_NONE'))
		);

		jimport('joomla.filesystem.folder');
		$regex = '^'.$type.'_([-_A-Za-z0-9]*)\.css$';
		$allCSSFiles = JFolder::files(HIKAMARKET_MEDIA.'css', $regex);
		foreach($allCSSFiles as $oneFile) {
			preg_match('#'.$regex.'#i',$oneFile,$results);
			$this->values[] = JHTML::_('select.option', $results[1],$results[1]);
		}
	}

	public function display($map, $type, $value) {
		$this->load($type);

		$shopConfig = hikamarket::config(false);

		$aStyle = (empty($value) || $value == 'default') ? ' style="display:none"' : '';
		$html = JHTML::_('select.genericlist', $this->values, $map, 'class="inputbox" size="1" onchange="var e=document.getElementById(\'hikamarket_css_'.$type.'_edit\');if(this.value==\'\'||this.value==\'default\'){e.style.display=\'none\';}else{e.style.display=\'\';}"', 'value', 'text', $value, 'css_'.$type.'_choice' );
		$manage = hikashop_isAllowed($shopConfig->get('acl_config_manage','all'));
		if($manage) {
			$popup = hikamarket::get('shop.helper.popup');
			$html .= $popup->display(
				'<img src="'. HIKAMARKET_IMAGES.'icon-16/edit.png" style="vertical-align:middle;" alt="'.JText::_('HIKA_EDIT').'"/>',
				'CSS',
				'\''.'index.php?option=com_hikamarket&amp;tmpl=component&amp;ctrl=config&amp;task=css&amp;file='.$type.'_\'+document.getElementById(\'css_'.$type.'_choice'.'\').value+\'&amp;var='.$type.'\'',
				'hikamarket_css_'.$type.'_edit',
				760,480, $aStyle, '', 'link',true
			);
			$html .= $popup->display(
				'<img src="'. HIKAMARKET_IMAGES.'icon-16/plus.png" style="vertical-align:middle;" alt="'.JText::_('HIKA_NEW').'"/>',
				'CSS',
				hikamarket::completeLink('config&task=css&var='.$type, true),
				'hikamarket_css_'.$type.'_new',
				760,480, '', '', 'link'
			);
		}
		return $html;
	}
}
