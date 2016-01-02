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
class hikamarketWarehouseClass extends hikamarketClass {

	protected $tables = array('shop.warehouse');
	protected $pkeys = array('warehouse_id');

	protected $toggle = array('warehouse_published' => 'warehouse_id');
	protected $toggleAcl = array('warehouse_published' => 'warehouse_edit_published');
	protected $deleteToggle = array('shop.warehouse' => 'warehouse_id');

	public function &getNameboxData($typeConfig, &$fullLoad, $mode, $value, $search, $options) {
		$ret = array(
			0 => array(),
			1 => array()
		);

		$query = 'SELECT * FROM ' . hikamarket::table('shop.warehouse') . ' WHERE warehouse_published = 1 ORDER BY warehouse_name';
		$this->db->setQuery($query);
		$warehouses = $this->db->loadObjectList('warehouse_id');
		foreach($warehouses as $warehouse) {
			$ret[0][$warehouse->warehouse_id] = $warehouse->warehouse_name;
		}

		if(!empty($value)) {
			if(!is_array($value))
				$value = array($value);
			foreach($value as $v) {
				if(isset($ret[0][$v]))
					$ret[1][$v] = $ret[0][$v];
			}
		}

		return $ret;
	}

	public function getTreeList($serialized = false, $display = '', $limit = 20) {
		$query = 'SELECT * FROM '.hikamarket::table('shop.warehouse');

		if($limit > 0)
			$this->db->setQuery($query, 0, $limit);
		else
			$this->db->setQuery($query);

		$warehouses = $this->db->loadObjectList();

		if(!$serialized)
			return $warehouses;

		$elements = array();
		foreach($warehouses as $element) {
			$obj = new stdClass();
			$obj->status = 0;
			$obj->name = $element->warehouse_name;
			$obj->value = $element->warehouse_id;

			$elements[] =& $obj;
			unset($obj);
		}
		return $elements;
	}

	public function findTreeList($search = '', $serialized = false, $display = '', $limit = 20) {
		if(HIKASHOP_J30)
			$searchStr = "'%" . $this->db->escape($search, true) . "%'";
		else
			$searchStr = "'%" . $this->db->getEscaped($search, true) . "%'";

		$query = 'SELECT * FROM '.hikamarket::table('shop.warehouse').' WHERE warehouse_name LIKE '.$search.'';

		if($limit > 0)
			$this->db->setQuery($query, 0, $limit);
		else
			$this->db->setQuery($query);
		$warehouses = $this->db->loadObjectList();

		if(!$serialized)
			return $warehouses;

		$elements = array();
		foreach($warehouses as $element) {
			$obj = new stdClass();
			$obj->status = 0;
			$obj->name = $element->warehouse_name;
			$obj->value = $element->warehouse_id;

			$elements[] =& $obj;
			unset($obj);
		}
		return $elements;
	}
}
