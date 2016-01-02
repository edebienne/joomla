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
class hikamarketColorType {
	protected $values = null;
	protected $othervalues = array();

	protected function load() {
		if($this->values !== null)
			return;

		$this->values = array();
		for($red = 0; $red < 6; $red++) {
			$rhex = dechex($red * 0x33);
			$rhex = (strlen($rhex) < 2) ? "0".$rhex : $rhex;
			for($blue = 0; $blue < 6; $blue++) {
				$bhex = dechex($blue * 0x33);
				$bhex = (strlen($bhex) < 2) ? "0".$bhex : $bhex;
				for($green = 0; $green < 6; $green++) {
					$ghex = dechex($green * 0x33);
					$ghex = (strlen($ghex) < 2) ? "0".$ghex : $ghex;

					$this->values[$red][] = '#'.$rhex.$ghex.$bhex;
				}
			}
		}
		$this->othervalues = array(
			'#000000', '#111111', '#222222', '#333333', '#444444', '#555555', '#666666', '#777777', '#888888', '#999999',
			'#AAAAAA', '#BBBBBB', '#CCCCCC', '#DDDDDD', '#EEEEEE', '#FFFFFF', '#FF0000', '#00FFFF', '#0000FF', '#0000A0',
			'#FF0080', '#800080', '#FFFF00', '#00FF00', '#FF00FF', '#FF8040', '#804000', '#800000', '#808000', '#408080',
		);
	}

	public function display($id, $map, $color) {
		if(HIKASHOP_J25) {
			$xmlConf = new SimpleXMLElement('<field name="'.$map.'" type="color" label=""></field>');
			JFormHelper::loadFieldClass('color');
			$jform = new JForm('hikashop');
			$fieldTag = new JFormFieldColor();
			$fieldTag->setForm($jform);
			$fieldTag->setup($xmlConf, $color);
			return $fieldTag->input;
		}

		$code = '<input type="text" name="'.$map.'" id="color'.$id.'" onchange=\'applyColorExample'.$id.'()\' class="inputbox" size="10" value="'.$color.'" />'.
			' <input size="10" maxlength="0" style=\'cursor:pointer;background-color:'.$color.'\' onclick="if(document.getElementById(\'colordiv'.$id.'\').style.display == \'block\'){document.getElementById(\'colordiv'.$id.'\').style.display = \'none\';}else{document.getElementById(\'colordiv'.$id.'\').style.display = \'block\';}" id=\'colorexample'.$id.'\' />'.
			'<div id=\'colordiv'.$id.'\' style=\'display:none;position:absolute;background-color:white;border:1px solid grey;z-index:999\'>'.$this->displayGrid($id).'</div>';
		return $code;
	}

	public function displayAll($id, $map, $color) {
		return $this->display($id, $map, $color);
	}

	public function displayGrid($id = '') {
		$js = '
function applyColor'.$id.'(newcolor) {
	document.getElementById(\'color'.$id.'\').value = newcolor;
	document.getElementById("colordiv'.$id.'").style.display = "none";
	applyColorExample'.$id.'();
}
function applyColorExample'.$id.'() {
	document.getElementById(\'colorexample'.$id.'\').style.backgroundColor = document.getElementById(\'color'.$id.'\').value;
	document.getElementById("colordiv'.$id.'").style.display = "none";
}';
		$doc = JFactory::getDocument();
		$doc->addScriptDeclaration($js);

		$this->load();

		$text = '<table><tr>';
		foreach($this->othervalues as $oneColor) {
			$text .= '<td style="cursor:pointer" width="10" height="10" bgcolor="'.$oneColor.'" onclick="applyColor'.$id.'(\''.$oneColor.'\')"></td>';
		}
		$text .= '</tr></table>';
		$text .= '<table>';
		foreach($this->values as $line) {
			$text .= '<tr>';
			foreach($line as $oneColor) {
				$text .= '<td style="cursor:pointer" width="10" height="10" bgcolor="'.$oneColor.'" onclick="applyColor'.$id.'(\''.$oneColor.'\')"></td>';
			}
			$text .= '</tr>';
		}
		$text .= '</table>';
		return $text;
	}
}
