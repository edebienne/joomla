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
class ordermarketViewordermarket extends HikamarketView {

	protected $ctrl = 'order';
	protected $icon = 'order';

	public function display($tpl = null, $params = array()) {
		$this->params =& $params;
		$fct = $this->getLayout();
		if(method_exists($this, $fct))
			$this->$fct();
		parent::display($tpl);
	}

	public function listing($tpl = null) {
		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$ctrl = '';
		$this->paramBase = HIKAMARKET_COMPONENT.'.'.$this->getName().'.listing';

		global $Itemid;
		$url_itemid = '';
		if(!empty($Itemid))
			$url_itemid='&Itemid='.$Itemid;
		$this->assignRef('Itemid', $Itemid);

		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);
		$shopConfig = hikamarket::config(false);
		$this->assignRef('shopConfig', $shopConfig);

		$this->loadRef(array(
			'toggleClass' => 'helper.toggle',
			'currencyHelper' => 'shop.class.currency',
			'paymentType' => 'shop.type.payment',
			'orderStatusType' => 'type.order_status',
			'addressClass' => 'class.address',
			'shopAddressClass' => 'shop.class.address'
		));

		$filterType = $app->getUserStateFromRequest($this->paramBase.'.filter_type', 'filter_type', 0, 'int');

		$cfg = array(
			'table' => 'shop.order',
			'main_key' => 'order_id',
			'order_sql_value' => 'hkorder.order_id'
		);

		$pageInfo = $this->getPageInfo($cfg['order_sql_value'], 'desc');
		$pageInfo->filter->filter_status = $app->getUserStateFromRequest($this->paramBase.'.filter_status', 'filter_status', '', 'string');
		$pageInfo->filter->filter_payment = $app->getUserStateFromRequest($this->paramBase.'.filter_payment', 'filter_payment', '', 'string');
		$pageInfo->filter->filter_user = $app->getUserStateFromRequest($this->paramBase.'.filter_user', 'filter_user', '', 'string');

		$filters = array();
		$searchMap = array(
			'hkorder.order_id',
			'hkorder.order_number',
			'hkuser.user_email'
		);
		$orderingAccept = array('hkorder.','hkuser.');
		$order = '';

		if(!empty($pageInfo->filter->filter_status))
			$filters['order_status'] = 'hkorder.order_status = ' . $db->Quote($pageInfo->filter->filter_status);
		if(!empty($pageInfo->filter->filter_payment))
			$filters['order_payment_method'] = 'hkorder.order_payment_method = ' . $db->Quote($pageInfo->filter->filter_payment);
		if(!empty($pageInfo->filter->filter_user) && (int)$pageInfo->filter->filter_user > 0)
			$filters['order_user_id'] = 'hkorder.order_user_id = ' . (int)$pageInfo->filter->filter_user;

		if($vendor->vendor_id > 1) {
			$filters['order_vendor_id'] = 'hkorder.order_vendor_id = ' . $vendor->vendor_id;
			$filters['order_type'] = 'hkorder.order_type = ' . $db->Quote('subsale');
		} else {
			$filters['order_vendor_id'] = '(hkorder.order_vendor_id = 0 OR hkorder.order_vendor_id = 1)';
			$filters['order_type'] = 'hkorder.order_type = ' . $db->Quote('sale');
		}

		$extrafilters = array();
		$joins = array();
		JPluginHelper::importPlugin('hikashop');
		JPluginHelper::importPlugin('hikamarket');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onBeforeOrderListing', array($this->paramBase, &$extrafilters, &$pageInfo, &$filters, &$joins, &$searchMap));
		$this->assignRef('extrafilters', $extrafilters);

		$this->processFilters($filters, $order, $searchMap, $orderingAccept);

		$query = 'FROM '.hikamarket::table($cfg['table']).' AS hkorder '.
			' LEFT JOIN '.hikamarket::table('shop.user').' AS hkuser ON hkorder.order_user_id = hkuser.user_id '.
			implode(' ', $joins).' '.$filters.$order;
		$db->setQuery('SELECT hkorder.*, hkuser.* '.$query, (int)$pageInfo->limit->start, (int)$pageInfo->limit->value);

		if(empty($pageInfo->search)) {
			$query = 'FROM '.hikamarket::table($cfg['table']).' AS hkorder '.$filters;
		}

		$orders = $db->loadObjectList('order_id');

		$db->setQuery('SELECT COUNT(*) '.$query);
		$pageInfo->elements = new stdClass();
		$pageInfo->elements->total = $db->loadResult();
		$pageInfo->elements->page = count($orders);

		$this->assignRef('orders', $orders);

		$addresses = null;
		$address_fields = null;
		$payments = array();
		if(!empty($orders)) {
			$query = 'SELECT DISTINCT a.* '.
				' FROM ' . hikamarket::table('shop.address') . ' AS a '.
				' INNER JOIN ' . hikamarket::table('shop.order') . ' AS o ON (a.address_id = o.order_billing_address_id OR a.address_id = o.order_shipping_address_id) ' .
				' WHERE o.order_id IN (' . implode(',', array_keys($orders)) . ')';
			$db->setQuery($query);
			$addresses = $db->loadObjectList('address_id');

			if(version_compare($shopConfig->get('version', '1.0'), '2.4.0', '<'))
				$this->loadZone($addresses);
			else
				$this->shopAddressClass->loadZone($addresses);

			$shopPluginClass = hikamarket::get('shop.class.plugins');
			$paymentMethods = $shopPluginClass->getMethods('payment');
			foreach($paymentMethods as $payment) {
				$payments[$payment->payment_id] = $payment;
			}

			foreach($orders as &$order) {
				$order->shipping_name = null;
				if(empty($order->order_shipping_method) && empty($order->order_shipping_id))
					continue;

				if(!empty($order->order_shipping_method)) {
					if(!is_numeric($order->order_shipping_id))
						$order->shipping_name = $this->getShippingName($order->order_shipping_method, $order->order_shipping_id);
					else
						$order->shipping_name = $this->getShippingName(null, $order->order_shipping_id);
				} else {
					$order->shipping_name = array();
					$shipping_ids = explode(';', $order->order_shipping_id);
					foreach($shipping_ids as $shipping_id) {
						$order->shipping_name[] = $this->getShippingName(null, $shipping_id);
					}
					if(count($order->shipping_name) == 1)
						$order->shipping_name = reset($order->shipping_name);
				}
			}
			unset($order);
		}
		$this->assignRef('addresses', $addresses);
		$this->assignRef('address_fields', $address_fields);
		$this->assignRef('payments', $payments);

		$order_stats = null;
		if($config->get('display_order_statistics', 0)) {
			if($vendor->vendor_id > 1) {
				$query = 'SELECT o.order_status, COUNT(o.order_id) as `total` FROM '.hikamarket::table('shop.order').' AS o WHERE o.order_type = \'subsale\' AND o.order_vendor_id = '.(int)$vendor->vendor_id.' GROUP BY o.order_status';
			} else {
				$query = 'SELECT o.order_status, COUNT(o.order_id) as `total` FROM '.hikamarket::table('shop.order').' AS o WHERE o.order_type = \'sale\' GROUP BY o.order_status';
			}
			$db->setQuery($query);
			$order_stats = $db->loadObjectList('order_status');
			ksort($order_stats);
		}
		$this->assignRef('order_stats', $order_stats);

		$this->toolbar = array(
			array(
				'icon' => 'back',
				'name' => JText::_('HIKA_BACK'),
				'url' => hikamarket::completeLink('vendor')
			),
			array(
				'icon' => 'report',
				'name' => JText::_('HIKA_EXPORT'),
				'url' => hikamarket::completeLink('order&task=export'),
				'pos' => 'right',
				'acl' => hikamarket::acl('order/export')
			),
			array(
				'icon' => 'new',
				'name' => JText::_('HIKA_NEW'),
				'url' => hikamarket::completeLink('order&task=add'),
				'pos' => 'right',
				'display' => ($vendor->vendor_id <= 1),
				'acl' => hikamarket::acl('order/add')
			)
		);

		$this->getPagination();

		$this->getOrdering('hkorder.ordering', !$filterType);
	}

	protected function loadZone(&$addresses, $type = 'name', $display = 'frontcomp') {
		if(empty($this->fieldClass))
			$this->fieldClass = hikamarket::get('shop.class.field');
		$fields = $this->fieldClass->getData($display, 'address');
		$this->fields =& $fields;

		if(empty($fields))
			return;

		$namekeys = array();
		foreach($fields as $field) {
			if($field->field_type == 'zone') {
				$namekeys[$field->field_namekey] = $field->field_namekey;
			}
		}

		if(empty($namekeys))
			return;

		$db = JFactory::getDBO();

		$zones = array();
		$quoted_zones = array();
		foreach($addresses as $address) {
			foreach($namekeys as $namekey) {
				if(!empty($address->$namekey)) {
					$zones[$address->$namekey] = $address->$namekey;
					$quoted_zones[$address->$namekey] = $db->Quote($address->$namekey);
				}
			}
		}

		if(empty($zones))
			return;

		if(!in_array($type, array('name', 'object'))) {
			$this->shopAddressClass->_getParents($zones, $addresses, $namekeys);

			return;
		}

		$query = 'SELECT * FROM '.hikamarket::table('shop.zone').' WHERE zone_namekey IN ('.implode(',',$quoted_zones).');';
		$db->setQuery($query);
		$zones = $db->loadObjectList('zone_namekey');
		if(empty($zones))
			return;

		foreach($addresses as $k => $address) {
			foreach($namekeys as $namekey) {
				if(empty($address->$namekey) || empty($zones[$address->$namekey]))
					continue;

				$addresses[$k]->{$namekey.'_orig'} = $addresses[$k]->$namekey;
				if($type == 'name') {
					$addresses[$k]->{$namekey.'_code_2'} = $zones[$address->$namekey]->zone_code_2;
					$addresses[$k]->{$namekey.'_code_3'} = $zones[$address->$namekey]->zone_code_3;
					$addresses[$k]->{$namekey.'_name'} = $zones[$address->$namekey]->zone_name;

					if(is_numeric($zones[$address->$namekey]->zone_name_english)) {
						$addresses[$k]->$namekey = $zones[$address->$namekey]->zone_name;
					} else {
						$addresses[$k]->$namekey = $zones[$address->$namekey]->zone_name_english;
					}

					$addresses[$k]->{$namekey.'_name_english'} = $addresses[$k]->$namekey;
				} else {
					$addresses[$k]->$namekey = $zones[$address->$namekey];
				}
			}
		}
	}

	public function show($tpl = null, $toolbar = true) {
		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$ctrl = '';
		$order_id = hikamarket::getCID('order_id', true);

		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);
		$shopConfig = hikamarket::config(false);
		$this->assignRef('shopConfig', $shopConfig);

		$edit = JRequest::getVar('task','') == 'edit';
		$this->assignRef('edit', $edit);

		global $Itemid;
		$url_itemid = '';
		if(!empty($Itemid))
			$url_itemid='&Itemid='.$Itemid;
		$this->assignRef('Itemid', $Itemid);

		$orderClass = hikamarket::get('shop.class.order');
		$order = $orderClass->loadFullOrder($order_id, true, false);
		if(!empty($order) && $order->order_vendor_id != $vendor->vendor_id ) {
			if( !(($vendor->vendor_id == 1 || $vendor->vendor_id == 0) && ($order->order_vendor_id == 1 || $order->order_vendor_id == 0)) ) {
				$order = null;
				$app->enqueueMessage(JText::_('ORDER_ACCESS_FORBIDDEN'));
				$app->redirect(hikamarket::completeLink('order'));
				return false;
			}
		}
		if(empty($order->customer)) {
			$userClass = hikamarket::get('shop.class.user');
			$order->customer = $userClass->get($order->order_user_id);
		}
		if(!empty($order->products)) {
			$options = false;
			$products = array();
			$product_ids = array();
			foreach($order->products as &$product) {
				if(!empty($product->product_id))
					$product_ids[(int)$product->product_id] = (int)$product->product_id;
				if(!empty($product->order_product_option_parent_id)) {
					if(empty($products[$product->order_product_option_parent_id]))
						$products[$product->order_product_option_parent_id] = array();
					if(empty($products[$product->order_product_option_parent_id]['options']))
						$products[$product->order_product_option_parent_id]['options'] = array();
					$products[$product->order_product_option_parent_id]['options'][] = &$product;

					$options = true;
				} else {
					if(empty($products[$product->order_product_id]))
						$products[$product->order_product_id] = array();
					$products[$product->order_product_id]['product'] = &$product;
				}
			}
			unset($product);

			if($options) {
				foreach($products as &$product) {
					if(!empty($product['product']))
						$order->products[] = $product['product'];
					if(!empty($product['options'])) {
						foreach($product['options'] as &$opt) {
							$order->products[] = $opt;
						}
						unset($opt);
					}
				}
				unset($product);
			}

			if($order->order_type == 'sale') {
				$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.vendor_name, hmv.vendor_id '.
					' FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
					' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
					' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
					' WHERE hko.order_type = \'subsale\' AND hko.order_parent_id = '. (int)$order->order_id .
					' ORDER BY hko.order_id DESC';
				$db->setQuery($query);
				$vendorProducts = $db->loadObjectList();

				foreach($order->products as &$product) {
					$product->vendor_data = null;
					foreach($vendorProducts as $vendorProduct) {
						if((int)$vendorProduct->order_product_parent_id == $product->order_product_id) {
							$product->vendor_data = $vendorProduct;
							break;
						}
					}
				}
				unset($product);
			}
		}
		$this->assignRef('order', $order);

		$this->loadRef(array(
			'toggleClass' => 'helper.toogle',
			'currencyHelper' => 'shop.class.currency',
			'payment' => 'shop.type.plugins',
			'shipping' => 'shop.type.plugins',
			'shippingClass' => 'shop.class.shipping',
			'paymentClass' => 'shop.class.payment',
			'fieldsClass' => 'shop.class.field',
			'addressClass' => 'class.address',
			'shopAddressClass' => 'shop.class.address',
			'popup' => 'shop.helper.popup',
			'order_status' => 'type.order_status',
			'imageHelper' => 'shop.helper.image',
		));
		$this->payment->type = 'payment';
		$this->shipping->type = 'shipping';

		$fields = array();
		if(!empty($order_id)) {

			$order->fields = $this->fieldsClass->getData('backend','address');
			if(hikashop_level(2)) {
				$fields['order'] = $this->fieldsClass->getFields('display:vendor_order_show=1', $order, 'order');
				$null = null;
				$fields['entry'] = $this->fieldsClass->getFields('display:vendor_order_show=1', $null, 'entry');
				$fields['item'] = $this->fieldsClass->getFields('display:vendor_order_show=1', $null, 'item');
			}

			$query = 'SELECT * FROM '.hikamarket::table('shop.history').' WHERE history_order_id='.(int)$order_id.' ORDER BY history_created DESC';
			$db->setQuery($query);
			$order->history = $db->loadObjectList();

			if(!empty($order->order_payment_id)) {
				$order->payment_name = $order->order_payment_method . ' - ' . $order->order_payment_id;

				$paymentMethod = $this->paymentClass->get( (int)$order->order_payment_id );
				if(!empty($paymentMethod->payment_name))
					$order->payment_name = $paymentMethod->payment_name;
			}

			$order->shipping_name = null;
			if(!empty($order->order_shipping_method)) {
				if(!is_numeric($order->order_shipping_id))
					$order->shipping_name = $this->getShippingName($order->order_shipping_method, $order->order_shipping_id);
				else
					$order->shipping_name = $this->getShippingName(null, $order->order_shipping_id);
			} else if(!empty($order->order_shipping_id)) {
				$order->shipping_name = array();
				$shipping_ids = explode(';', $order->order_shipping_id);
				foreach($shipping_ids as $shipping_id) {
					$order->shipping_name[] = $this->getShippingName(null, $shipping_id);
				}
				if(count($order->shipping_name) == 1)
					$order->shipping_name = reset($order->shipping_name);
			}

			if(!empty($order->order_vendor_params) && is_string($order->order_vendor_params))
				$order->order_vendor_params = unserialize($order->order_vendor_params);

			if((int)$order->order_vendor_paid > 0) {
				$query = 'SELECT * '.
					' FROM ' . hikamarket::table('shop.order') .
					' WHERE order_parent_id = ' . $order->order_parent_id . ' AND order_type = ' . $db->Quote('vendorrefund');
				$db->setQuery($query);
				$order->refunds = $db->loadObjectList();
			}

			$query = 'SELECT DISTINCT a.* '.
				' FROM ' . hikamarket::table('shop.address') . ' AS a '.
				' INNER JOIN ' . hikamarket::table('shop.order') . ' AS o ON (a.address_id = o.order_billing_address_id OR a.address_id = o.order_shipping_address_id) ' .
				' WHERE o.order_id = '.(int)$order->order_id;
			$db->setQuery($query);
			$addresses = $db->loadObjectList('address_id');
			$this->assignRef('addresses', $addresses);
			$address_fields = null;
			$this->assignRef('address_fields', $address_fields);

			if(version_compare($shopConfig->get('version', '1.0'), '2.4.0', '<'))
				$this->loadZone($addresses);
			else
				$this->shopAddressClass->loadZone($addresses);
		}
		$this->assignRef('fields',$fields);

		JPluginHelper::importPlugin('hikashop');
		JPluginHelper::importPlugin('hikashoppayment');
		JPluginHelper::importPlugin('hikashopshipping');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onHistoryDisplay', array(&$order->history) );

		if($toolbar) {
			hikamarket::setPageTitle(JText::sprintf('HIKAM_ORDER', $order->order_number));

			$this->toolbar = array(
				'back' => array('icon' => 'back', 'name' => JText::_('HIKA_BACK'), 'url' => hikamarket::completeLink('order')),
				'email' => array(
					'icon' => 'email',
					'name' => JText::_('HIKA_EMAIL'),
					'url' => hikamarket::completeLink('order&task=mail&cid='.$order->order_id, true),
					'popup' => array('id' => 'hikamarket_order_mail_popup', 'width' => 720, 'height' => 480),
					'pos' => 'right',
					'acl' => hikamarket::acl('order/edit/mail')
				),
				'invoice' => array(
					'icon' => 'invoice',
					'name' => JText::_('INVOICE'),
					'url' => hikamarket::completeLink('order&task=invoice&type=full&cid='.$order->order_id, true),
					'popup' => array('id' => 'hikamarket_order_invoice_popup', 'width' => 640, 'height' => 480),
					'pos' => 'right',
					'acl' => hikamarket::acl('order/edit/invoice')
				),
				'shipping-invoice' => array(
					'icon' => 'shipping-invoice',
					'name' => JText::_('SHIPPING_INVOICE'),
					'url' => hikamarket::completeLink('order&task=invoice&type=shipping&cid='.$order->order_id, true),
					'popup' => array('id' => 'hikamarket_order_shippinginvoice_popup', 'width' => 640, 'height' => 480),
					'pos' => 'right',
					'acl' => hikamarket::acl('order/edit/shippinginvoice')
				)
			);
		}
	}

	public function show_vendor($tpl = null) {
		$this->show($tpl, true);

		$this->toolbar['order-status'] = array(
			'icon' => 'order-status',
			'name' => JText::_('HIKAM_EDIT_ORDER_STATUS'),
			'url' => hikamarket::completeLink('order&task=status&cid='.$this->order->order_id, true),
			'popup' => array('id' => 'hikamarket_order_status_popup', 'width' => 640, 'height' => 300),
			'linkattribs' => ' onclick="if(window.orderMgr.editOrderStatus) return window.orderMgr.editOrderStatus(this); window.hikashop.openBox(this); return false;"',
			'pos' => 'right',
			'acl' => hikamarket::acl('order/edit/general')
		);
	}

	public function show_general($tpl = null) {
		$this->show($tpl, false);
	}

	public function show_history($tpl = null) {
		$this->show($tpl, false);
	}

	public function edit_additional($tpl = null) {
		$vendor = hikamarket::loadVendor(true, false);
		if($vendor->vendor_id != 0 && $vendor->vendor_id != 1) {
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));
		}

		$this->show($tpl, false);

		if(hikashop_level(2)) {
			$this->fields['order'] = $this->fieldsClass->getFields('display:vendor_order_edit=1', $order, 'order');
			$null = null;
			$this->fields['entry'] = $this->fieldsClass->getFields('display:vendor_order_edit=1', $null, 'entry');
			$this->fields['item'] = $this->fieldsClass->getFields('display:vendor_order_edit=1', $null, 'item');
		}

		$this->toolbar = array(
			array(
				'url' => '#save',
				'linkattribs' => 'onclick="return window.hikamarket.submitform(\'save\',\'hikamarket_order_additional_form\');"',
				'icon' => 'save',
				'name' => JText::_('HIKA_SAVE'), 'pos' => 'right'
			)
		);

		$ratesType = hikamarket::get('shop.type.rates');
		$this->assignRef('ratesType',$ratesType);

		$pluginsPayment = hikamarket::get('shop.type.plugins');
		$pluginsPayment->type = 'payment';
		$this->assignRef('paymentPlugins', $pluginsPayment);

		$pluginsShipping = hikamarket::get('shop.type.plugins');
		$pluginsShipping->type = 'shipping';
		$this->assignRef('shippingPlugins', $pluginsShipping);
	}

	public function show_additional($tpl = null) {
		$task = JRequest::getCmd('task', '');
		if($task == 'save') {
			$html = '<html><body><script type="text/javascript">'."\r\n".
				'window.parent.hikamarket.submitFct();'."\r\n".
				'</script></body></html>';
			die($html);
		}
		$this->show($tpl, false);
	}

	public function show_shipping_address($tpl = null) {
		$address_type = 'shipping';
		$this->assignRef('type', $address_type);
		$this->show($tpl, false);

		if($this->edit) {
			if(!empty($this->order->order_shipping_address_id)) {
				$addressClass = hikamarket::get('shop.class.address');
				$this->order->shipping_address = $addressClass->get($this->order->order_shipping_address_id);
			}
			$this->fieldsClass->prepareFields($this->order->fields, $this->order->shipping_address, 'address', 'checkout&task=state');
		}

		$this->setLayout('show_address');
	}

	public function show_billing_address($tpl = null) {
		$address_type = 'billing';
		$this->assignRef('type', $address_type);
		$this->show($tpl, false);

		if($this->edit) {
			if(!empty($this->order->order_billing_address_id)) {
				$addressClass = hikamarket::get('shop.class.address');
				$this->order->billing_address = $addressClass->get($this->order->order_billing_address_id);
			}
			$this->fieldsClass->prepareFields($this->order->fields, $this->order->billing_address, 'address', 'checkout&task=state');
		}

		$this->setLayout('show_address');
	}

	public function show_products($tpl = null) {
		$task = JRequest::getCmd('task', '');
		if($task == 'save') {
			$html = '<html><body><script type="text/javascript">'."\r\n".
				'window.parent.hikamarket.submitFct();'."\r\n".
				'</script></body></html>';
			die($html);
		}
		$this->show($tpl, false);
	}

	public function edit_products($tpl = null) {
		$vendor = hikamarket::loadVendor(true, false);
		if($vendor->vendor_id != 0 && $vendor->vendor_id != 1) {
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));
		}
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);
		$shopConfig = hikamarket::config(false);
		$this->assignRef('shopConfig', $shopConfig);
		$db = JFactory::getDBO();

		$productClass = hikamarket::get('shop.class.product');
		$fieldsClass = hikamarket::get('shop.class.field');
		$this->assignRef('fieldsClass', $fieldsClass);

		$order_id = JRequest::getInt('order_id');
		$order_product_id = JRequest::getInt('order_product_id', 0);

		$this->toolbar = array(
			array(
				'url' => '#save',
				'linkattribs' => 'onclick="return window.hikamarket.submitform(\'save\',\'hikamarket_order_product_form\');"',
				'icon' => 'save',
				'name' => JText::_('HIKA_SAVE'), 'pos' => 'right'
			)
		);

		$orderClass = hikamarket::get('shop.class.order');
		$order = $orderClass->get($order_id);
		$originalProduct = new stdClass();

		if(!empty($order_product_id)) {
			$orderProductClass = hikamarket::get('shop.class.order_product');
			$orderProduct = $orderProductClass->get($order_product_id);
			if(empty($orderProduct) || $orderProduct->order_id != $order_id) {
				$orderProduct = new stdClass();
				$orderProduct->order_id = $order_id;
			}
			if(!empty($orderProduct->product_id)) {
				$originalProduct = $productClass->get($orderProduct->product_id);
			}
		} else {
			$orderProduct = new stdClass();
			$orderProduct->order_id = $order_id;

			$product_id = JRequest::getVar('cid', array(), '', 'array');
			if(!empty($product_id) && $productClass->getProducts($product_id)) {
				$products = $productClass->products;
				$product = $products[ (int)$product_id[0] ];
				$product->options = array();

				$originalProduct = $product;

				$orderProduct->product_id = $product->product_id;
				$orderProduct->order_product_name = $product->product_name;
				$orderProduct->order_product_code = $product->product_code;
				$orderProduct->order_product_quantity = 1;

				$currencyClass = hikamarket::get('shop.class.currency');
				$main_currency = (int)$shopConfig->get('main_currency',1);
				$discount_before_tax = (int)$shopConfig->get('discount_before_tax',0);
				$currency_id = $order->order_currency_id;

				if($shopConfig->get('tax_zone_type', 'shipping') == 'billing')
					$zone_id = hikamarket::getZone('billing');
				else
					$zone_id = hikamarket::getZone('shipping');

				$rows = array($product);
				$currencyClass->getPrices($rows, $product_id, $currency_id, $main_currency, $zone_id, $discount_before_tax);
				$currencyClass->pricesSelection($rows[0]->prices, 0);
				if(!empty($rows[0]->prices)) {
					foreach($rows[0]->prices as $price) {
						$orderProduct->order_product_price = $price->price_value;
						$orderProduct->order_product_tax = (@$price->price_value_with_tax - @$price->price_value);
						$orderProduct->order_product_tax_info = @$price->taxes;
					}
				}
			}
		}
		if(!empty($orderProduct->order_product_id) && (int)$orderProduct->order_product_id > 0) {
			if(empty($orderProduct->order_product_parent_id)) {
				$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.* FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
					' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
					' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
					' WHERE hko.order_type = \'subsale\' AND order_product_parent_id = '. (int)$orderProduct->order_product_id .
					' ORDER BY hko.order_id DESC';
				$db->setQuery($query);
				$orderProduct->vendor_data = $db->loadObject();
			}
		} else if(!empty($orderProduct->product_id)) {
			$query = 'SELECT p.product_vendor_id, pp.product_vendor_id AS parent_vendor_id FROM '.hikamarket::table('shop.product').' AS p '.
				' LEFT JOIN '.hikamarket::table('shop.product').' AS pp ON p.product_parent_id = pp.product_id '.
				' WHERE p.product_id = '. (int)$orderProduct->product_id;
			$db->setQuery($query);
			$productVendor = $db->loadObject();
			$orderProduct->vendor_data = $productVendor;

			$vendor_id = 0;
			if(!empty($productVendor->product_vendor_id))
				$vendor_id = (int)$productVendor->product_vendor_id;
			else if(!empty($productVendor->parent_vendor_id))
				$vendor_id = (int)$productVendor->parent_vendor_id;

			$vendorObj = null;
			if(!empty($vendor_id)) {
				$vendorClass = hikamarket::get('class.vendor');
				$vendorObj = $vendorClass->get($vendor_id);
			}
			$orderProduct->vendor = $vendorObj;
		}

		$this->assignRef('orderProduct', $orderProduct);
		$this->assignRef('originalProduct', $originalProduct);

		$ratesType = hikamarket::get('shop.type.rates');
		$this->assignRef('ratesType',$ratesType);

		if(hikashop_level(2)) {
			$null = null;
			$this->fields['item'] = $this->fieldsClass->getFields('display:vendor_order_edit=1', $null, 'item','checkout&task=state');
		}
	}

	public function invoice() {
		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);

		$shopConfig = hikamarket::config(false);
		$this->assignRef('shopConfig', $shopConfig);

		$order_id = hikamarket::getCID('order_id');

		$type = JRequest::getWord('type');
		$this->assignRef('invoice_type', $type);

		$nobutton = true;
		$this->assignRef('nobutton', $nobutton);

		$display_type = 'frontcomp';
		$this->assignRef('display_type', $display_type);

		$currencyClass = hikamarket::get('shop.class.currency');
		$this->assignRef('currencyHelper', $currencyClass);

		$orderClass = hikamarket::get('shop.class.order');
		$order = $orderClass->loadFullOrder($order_id, true, false);
		if(!empty($order) && $order->order_vendor_id != $vendor->vendor_id ) {
			if( !(($vendor->vendor_id == 1 || $vendor->vendor_id == 0) && ($order->order_vendor_id == 1 || $order->order_vendor_id == 0)) ) {
				$order = null;
				$app->enqueueMessage(JText::_('ORDER_ACCESS_FORBIDDEN'));
				$app->redirect(hikamarket::completeLink('order'));
				return false;
			}
		}

		$fieldsClass = hikamarket::get('shop.class.field');
		$this->assignRef('fieldsClass', $fieldsClass);

		$fields = array();
		if(hikashop_level(2)) {
			$null = null;
			if($this->invoice_type == 'shipping')
				$fields['item'] = $fieldsClass->getFields('display:vendor_order_shipping_invoice=1', $null, 'item');
			else
				$fields['item'] = $fieldsClass->getFields('display:vendor_order_invoice=1', $null, 'item');
		}

		$vendorFields = $vendor;
		$extraFields = array(
			'vendor' => $fieldsClass->getFields('frontcomp', $vendorFields, 'plg.hikamarket.vendor')
		);
		$this->assignRef('extraFields', $extraFields);
		$this->assignRef('vendorFields', $vendorFields);

		$store = str_replace(
			array("\r\n","\n","\r"),
			array('<br/>','<br/>','<br/>'),
			$shopConfig->get('store_address','')
		);
		$this->assignRef('store_address', $store);
		$this->assignRef('element', $order);
		$this->assignRef('order', $order);
		$this->assignRef('fields', $fields);

		if(substr($order->order_shipping_method, 0, 7) == 'market-')
			$order->order_shipping_method = substr($order->order_shipping_method, 7);
		if(substr($order->order_payment_method, 0, 7) == 'market-')
			$order->order_payment_method = substr($order->order_payment_method, 7);

		if(!empty($order->order_payment_id)) {
			$pluginsPayment = hikamarket::get('shop.type.plugins');
			$pluginsPayment->type = 'payment';
			$this->assignRef('payment', $pluginsPayment);
		}
		if(!empty($order->order_shipping_id)) {
			$pluginsShipping = hikamarket::get('shop.type.plugins');
			$pluginsShipping->type = 'shipping';
			$this->assignRef('shipping', $pluginsShipping);

			if(empty($order->order_shipping_method)) {
				$shippingClass = hikamarket::get('shop.class.shipping');
				$this->assignRef('shippingClass', $shippingClass);

				$shippings_data = array();
				$shipping_ids = explode(';', $order->order_shipping_id);
				foreach($shipping_ids as $key) {
					$shipping_data = '';
					list($k, $w) = explode('@', $key);
					$shipping_id = $k;
					if(isset($order->shippings[$shipping_id])) {
						$shipping = $order->shippings[$shipping_id];
						$shipping_data = $shipping->shipping_name;
					} else {
						foreach($order->products as $order_product) {
							if($order_product->order_product_shipping_id == $key) {
								if(!is_numeric($order_product->order_product_shipping_id)) {
									$shipping_name = $this->getShippingName($order_product->order_product_shipping_method, $shipping_id);
									$shipping_data = $shipping_name;
								} else {
									$shipping_method_data = $this->shippingClass->get($shipping_id);
									$shipping_data = $shipping_method_data->shipping_name;
								}
								break;
							}
						}
						if(empty($shipping_data))
							$shipping_data = '[ ' . $key . ' ]';
					}
					$shippings_data[] = $shipping_data;
				}
				$order->order_shipping_method = $shippings_data;
			}
		}
	}

	public function customer_set() {
		$users = JRequest::getVar('cid', array(), '', 'array');
		$closePopup = JRequest::getInt('finalstep', 0);

		if($closePopup) {
			$formData = JRequest::getVar('data', array(), '', 'array');
			$users = array( (int)$formData['order']['order_user_id'] );
		}
		$rows = array();
		$data = '';
		$singleSelection = true; //JRequest::getVar('single', false);
		$order_id = JRequest::getInt('order_id', 0);

		$elemStruct = array(
			'user_email',
			'user_cms_id',
			'name',
			'username',
			'email'
		);

		$set_address = JRequest::getInt('set_user_address', 0);

		if(!empty($users)) {
			JArrayHelper::toInteger($users);
			$db = JFactory::getDBO();
			$query = 'SELECT a.*, b.* FROM '.hikamarket::table('user','shop').' AS a INNER JOIN '.hikamarket::table('users', false).' AS b ON a.user_cms_id = b.id WHERE a.user_id IN ('.implode(',',$users).')';
			$db->setQuery($query);
			$rows = $db->loadObjectList();

			if(!empty($rows)) {
				$data = array();
				foreach($rows as $v) {
					$d = '{id:'.$v->user_id;
					foreach($elemStruct as $s) {
						if($s == 'id')
							continue;
						$d .= ','.$s.':\''. str_replace('"','\'',$v->$s).'\'';
					}
					if($set_address && $singleSelection)
						$d .= ',updates:[\'billing\',\'history\']';
					$data[] = $d.'}';
				}
				if(!$singleSelection)
					$data = '['.implode(',',$data).']';
				else {
					$data = $data[0];
					$rows = $rows[0];
				}
			}
		}
		$this->assignRef('rows', $rows);
		$this->assignRef('data', $data);
		$this->assignRef('confirm', $confirm);
		$this->assignRef('singleSelection', $singleSelection);
		$this->assignRef('order_id', $order_id);

		if($closePopup) {
			$js = 'window.hikashop.ready(function(){window.top.hikamarket.submitBox('.$data.');});';
			$doc = JFactory::getDocument();
			$doc->addScriptDeclaration($js);
		}
	}

	public function status() {
		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);

		$shopConfig = hikamarket::config(false);
		$this->assignRef('shopConfig', $shopConfig);

		$this->loadRef(array(
			'orderClass' => 'shop.class.order',
			'order_status' => 'type.order_status',
		));

		$order_id = hikamarket::getCID('order_id');
		$order = $this->orderClass->loadFullOrder($order_id, true, false);
		$this->assignRef('order', $order);

		$order_status_filters = array();
		if($order->order_type == 'subsale' && (int)$order->order_vendor_paid > 0) {
			$valid_order_statuses = explode(',', $config->get('valid_order_statuses', 'confirmed,shipped'));
			if(in_array($order->order_status, $valid_order_statuses)) {
				$order_status_filters = $valid_order_statuses;
			} else {
				$order_status_filters = array($order->order_status);
			}
		}
		$this->assignRef('order_status_filters', $order_status_filters);
	}

	public function export_show() {
		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$ctrl = '';
		$this->paramBase = HIKAMARKET_COMPONENT.'.'.$this->getName().'.listing';

		global $Itemid;
		$url_itemid = '';
		if(!empty($Itemid))
			$url_itemid='&Itemid='.$Itemid;
		$this->assignRef('Itemid', $Itemid);

		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		$config = hikamarket::config();
		$this->assignRef('config', $config);

		$this->loadRef(array(
			'toggleClass' => 'helper.toggle',
			'currencyHelper' => 'shop.class.currency',
			'paymentType' => 'shop.type.payment',
			'orderStatusType' => 'type.order_status'
		));

		$pageInfo = new stdClass();
		$pageInfo->search = JString::strtolower($app->getUserStateFromRequest($this->paramBase.'.search', 'search', '', 'string'));
		$pageInfo->filter = new stdClass();
		$pageInfo->filter->filter_status = $app->getUserStateFromRequest($this->paramBase.'.filter_status', 'filter_status', '', 'string');
		$pageInfo->filter->filter_payment = $app->getUserStateFromRequest($this->paramBase.'.filter_payment', 'filter_payment', '', 'string');
		$pageInfo->filter->filter_startdate = $app->getUserStateFromRequest($this->paramBase.'.filter_startdate', 'filter_startdate', '', 'string');
		$pageInfo->filter->filter_enddate = $app->getUserStateFromRequest($this->paramBase.'.filter_enddate', 'filter_enddate', '', 'string');
		$this->assignRef('pageInfo', $pageInfo);

		$this->toolbar = array(
			array(
				'icon' => 'back',
				'name' => JText::_('HIKA_BACK'),
				'url' => hikamarket::completeLink('order')
			),
			array(
				'url' => '#export',
				'linkattribs' => 'onclick="return window.hikamarket.submitform(\'export\',\'hikamarket_order_export_form\');"',
				'icon' => 'report',
				'name' => JText::_('HIKA_EXPORT'), 'pos' => 'right'
			)
		);
	}

	public function export() {
		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$ctrl = '';
		$this->paramBase = HIKAMARKET_COMPONENT.'.'.$this->getName().'.listing';

		$config = hikamarket::config();
		$this->assignRef('config', $config);

		$vendor = hikamarket::loadVendor(true, false);
		$this->assignRef('vendor', $vendor);

		global $Itemid;
		$url_itemid = '';
		if(!empty($Itemid))
			$url_itemid='&Itemid='.$Itemid;
		$this->assignRef('Itemid', $Itemid);

		$this->loadRef(array(
			'export' => 'shop.helper.spreadsheet'
		));

		$pageInfo = new stdClass();
		$pageInfo->search = JString::strtolower($app->getUserStateFromRequest($this->paramBase.'.search', 'search', '', 'string'));
		$pageInfo->filter = new stdClass();
		$pageInfo->filter->filter_status = $app->getUserStateFromRequest($this->paramBase.'.filter_status', 'filter_status', '', 'string');
		$pageInfo->filter->filter_payment = $app->getUserStateFromRequest($this->paramBase.'.filter_payment', 'filter_payment', '', 'string');
		$pageInfo->filter->filter_startdate = $app->getUserStateFromRequest($this->paramBase.'.filter_startdate', 'filter_startdate', '', 'string');
		$pageInfo->filter->filter_enddate = $app->getUserStateFromRequest($this->paramBase.'.filter_enddate', 'filter_enddate', '', 'string');
		$this->assignRef('pageInfo', $pageInfo);

		$formData = JRequest::getVar('data', array(), '', 'array');
		$export_format = strtolower(@$formData['export']['format']);
		if(empty($export_format) || !in_array($export_format, array('csv', 'xls'))) {
			$export_format = 'csv';
		}
		$this->assignRef('export_format', $export_format);

		$cfg = array(
			'table' => 'shop.order',
			'main_key' => 'order_id',
			'order_sql_value' => 'hkorder.order_id'
		);

		$filters = array();
		$searchMap = array(
			'hkorder.order_id',
			'hkorder.order_user_id',
			'hkorder.order_full_price',
			'hkorder.order_number',
			'hkuser.user_email',
			'juser.username',
			'juser.name'
		);
		$orderingAccept = array('hkorder.','hkuser.');
		$order = ' ORDER BY hkorder.order_id';

		if(!empty($pageInfo->filter->filter_status))
			$filters[] = 'hkorder.order_status = ' . $db->Quote($pageInfo->filter->filter_status);
		if(!empty($pageInfo->filter->filter_payment))
			$filters[] = 'hkorder.order_payment_method = ' . $db->Quote($pageInfo->filter->filter_payment);

		if($vendor->vendor_id > 1) {
			$filters[] = 'hkorder.order_vendor_id = ' . $vendor->vendor_id;
			$filters[] = 'hkorder.order_type = ' . $db->Quote('subsale');
		} else {
			$filters[] = '(hkorder.order_vendor_id = 0 OR hkorder.order_vendor_id = 1)';
			$filters[] = 'hkorder.order_type = ' . $db->Quote('sale');
		}

		if(!empty($pageInfo->filter->filter_enddate)) {
			$filter_end = explode('-', $pageInfo->filter->filter_enddate);
			$noHourDay = explode(' ', $filter_end[2]);
			$filter_end[2] = $noHourDay[0];
			$filter_end = mktime(23, 59, 59, $filter_end[1], $filter_end[2], $filter_end[0]);
		}

		if(!empty($pageInfo->filter->filter_startdate)) {
			$filter_start = explode('-',$pageInfo->filter->filter_startdate);
			$noHourDay = explode(' ',$filter_start[2]);
			$filter_start[2] = $noHourDay[0];
			$filter_start = mktime(0, 0, 0, $filter_start[1], $filter_start[2], $filter_start[0]);

			if(!empty($pageInfo->filter->filter_enddate)) {
				$filters[] = 'hkorder.order_created > '.$filter_start. ' AND hkorder.order_created < '.$filter_end;
			} else {
				$filters[] = 'hkorder.order_created > '.$filter_start;
			}
		} else if(!empty($pageInfo->filter->filter_enddate)) {
			$filters[] = 'hkorder.order_created < '.$filter_end;
		}

		$select = '';
		$from = '';

		JPluginHelper::importPlugin('hikashop');
		JPluginHelper::importPlugin('hikamarket');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onBeforeOrderExportQuery', array(&$select, &$from, &$filters, &$order, &$searchMap, &$orderingAccept) );

		$this->processFilters($filters, $order, $searchMap, $orderingAccept);

		$query = 'FROM '.hikamarket::table($cfg['table']).' AS hkorder '.
			'LEFT JOIN '.hikamarket::table('shop.user').' AS hkuser ON hkorder.order_user_id = hkuser.user_id '.
			'LEFT JOIN '.hikamarket::table('joomla.users').' AS juser ON hkuser.user_cms_id = juser.id '.
			$from.' '.$filters.' '.$order;
		if(!empty($select) && substr($select, 0, 1) != ',')
			$select = ','.$select;
		$db->setQuery('SELECT hkorder.*, hkuser.*, juser.name, juser.username '.$select.$query);

		$rows = $db->loadObjectList('order_id');
		if(empty($rows)) {
			$app->enqueueMessage(JText::_('HIKAM_NOTHING_TO_EXPORT'), 'error');
			$app->redirect(hikamarket::completeLink('order&task=export'.$url_itemid, false, true));
			return false;
		}


		$addressIds = array();

		foreach($rows as &$row) {
			$row->products = array();
			$addressIds[$row->order_shipping_address_id] = $row->order_shipping_address_id;
			$addressIds[$row->order_billing_address_id] = $row->order_billing_address_id;
		}
		unset($row);

		if(!empty($addressIds)) {
			$db->setQuery('SELECT * FROM '.hikamarket::table('shop.address').' WHERE address_id IN ('.implode(',',$addressIds).')');
			$addresses = $db->loadObjectList('address_id');

			if(!empty($addresses)) {
				$zoneNamekeys = array();
				foreach($addresses as $address) {
					$zoneNamekeys[$address->address_country] = $db->Quote($address->address_country);
					$zoneNamekeys[$address->address_state] = $db->Quote($address->address_state);
				}

				if(!empty($zoneNamekeys)) {
					$db->setQuery('SELECT zone_namekey,zone_name FROM '.hikamarket::table('shop.zone').' WHERE zone_namekey IN ('.implode(',',$zoneNamekeys).')');
					$zones = $db->loadObjectList('zone_namekey');
					if(!empty($zones)) {
						foreach($addresses as &$address) {
							if(!empty($zones[$address->address_country]))
								$address->address_country = $zones[$address->address_country]->zone_name;
							if(!empty($zones[$address->address_state]))
								$address->address_state = $zones[$address->address_state]->zone_name;
						}
						unset($address);
					}
				}

				$fields = array_keys(get_object_vars(reset($addresses)));
				foreach($rows as $k => $row) {
					if(!empty($addresses[$row->order_shipping_address_id])) {
						foreach($addresses[$row->order_shipping_address_id] as $key => $val) {
							$key = 'shipping_'.$key;
							$rows[$k]->$key = $val;
						}
					} else {
						foreach($fields as $field){
							$key = 'shipping_'.$field;
							$rows[$k]->$key = '';
						}
					}

					if(!empty($addresses[$row->order_billing_address_id])) {
						foreach($addresses[$row->order_billing_address_id] as $key => $val) {
							$key = 'billing_'.$key;
							$rows[$k]->$key = $val;
						}
					} else {
						foreach($fields as $field) {
							$key = 'billing_'.$field;
							$rows[$k]->$key = '';
						}
					}
				}
			}
		}

		$orderIds = array_keys($rows);
		$db->setQuery('SELECT * FROM '.hikamarket::table('shop.order_product').' WHERE order_id IN ('.implode(',', $orderIds).')');
		$products = $db->loadObjectList();

		foreach($products as $product) {
			$order =& $rows[$product->order_id];
			$order->products[] = $product;
			if(!isset($order->order_full_tax)) {
				$order->order_full_tax = 0;
			}
			$order->order_full_tax += round($product->order_product_quantity * $product->order_product_tax, 2);
		}
		foreach($rows as $k => $row) {
			$rows[$k]->order_full_tax += $row->order_shipping_tax - $row->order_discount_tax;
		}

		$dispatcher->trigger('onBeforeOrderExport', array(&$rows, &$this) );
		$this->assignRef('orders', $rows);
	}

	public function mail() {
		$order_id = hikashop_getCID('order_id');
		if(!empty($order_id)) {
			$orderClass = hikamarket::get('shop.class.order');
			$order = $orderClass->get($order_id);
			$order->url_itemid = '';
			$orderClass->loadOrderNotification($order);
		} else {
			$order = new stdClass();
		}

		$config = hikamarket::config();
		$this->assignRef('config', $config);

		$this->assignRef('element', $order);
		$editor = hikamarket::get('shop.helper.editor');
		$editor->setEditor($config->get('editor', ''));
		$editor->name = 'hikamarket_mail_body';
		$editor->content = $order->mail->body;
		if($config->get('editor_disable_buttons', 0))
			$editor->options = false;
		$this->assignRef('editor', $editor);

		$vendor = hikamarket::loadVendor(true);
		$this->assignRef('vendor', $vendor);
		if($vendor->vendor_id > 1 ) {
			$order->mail->from_email = $vendor->vendor_email;
			$order->mail->from_name = $vendor->vendor_name;
		}

	}

	protected function getShippingName($shipping_method, $shipping_id) {
		static $cache = array();

		$key = md5($shipping_method . '##' . $shipping_id);
		if(isset($cache[$key]))
			return $cache[$key];

		$shipping_name = $shipping_method . ' ' . $shipping_id;
		if(strpos($shipping_id, '-') !== false) {
			if(empty($this->shippingClass))
				$this->shippingClass = hikamarket::get('shop.class.shipping');
			$shipping_ids = explode('-', $shipping_id, 2);
			$shipping = $this->shippingClass->get($shipping_ids[0]);
			if(!empty($shipping->shipping_params) && is_string($shipping->shipping_params))
				$shipping->shipping_params = unserialize($shipping->shipping_params);

			$shippingMethod = hikamarket::import('hikashopshipping', $shipping_method);
			$methods = array();
			if(!empty($shippingMethod))
				$methods = $shippingMethod->shippingMethods($shipping);

			if(isset($methods[$shipping_id]))
				$shipping_name = $shipping->shipping_name.' - '.$methods[$shipping_id];

			$cache[$key] = $shipping_name;
		} else if($shipping_method === null) {
			if(empty($this->shippingClass))
				$this->shippingClass = hikamarket::get('shop.class.shipping');
			$shipping = $this->shippingClass->get($shipping_id);
			$shipping_name = $shipping->shipping_name;
			$cache[$key] = $shipping_name;
		}
		return $shipping_name;
	}
}
