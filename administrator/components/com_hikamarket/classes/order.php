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
class hikamarketOrderClass extends hikamarketClass {

	protected $tables = array('shop.order_product', 'shop.order');
	protected $pkeys = array('order_id', 'order_id');
	private static $creatingSubSales = false;

	public function frontSaveForm($task = '', $acl = true) {
		$do = false;
		$vendor_id = 0;
		$forbidden = array();
		if($acl) {
			if(!hikamarket::loginVendor())
				return false;
			$vendor_id = hikamarket::loadVendor(false);

			if($vendor_id > 1) {
				$forbidden = array( 'billing_address' => 1, 'shipping_address' => 1, 'additional' => 1, 'custom_fields' => 1, 'customer' => 1 );
				if(isset($forbidden[$task]))
					return false;
			}
			if($vendor_id == 1)
				$vendor_id = 0;
		}

		$order_id = hikamarket::getCID('order_id');
		$orderClass = hikamarket::get('shop.class.order');
		$addressClass = hikamarket::get('shop.class.address');
		$fieldsClass = hikamarket::get('shop.class.field');

		jimport('joomla.filter.filterinput');
		$safeHtmlFilter = JFilterInput::getInstance(null, null, 1, 1);

		$oldOrder = $this->getRaw($order_id);
		$order = clone($oldOrder);
		$order->history = new stdClass();
		$data = JRequest::getVar('data', array(), '', 'array');

		if(empty($order_id) || empty($order->order_id)) {
			$orderClass->sendEmailAfterOrderCreation = false;
		} else {
			if($acl && $order->order_vendor_id != $vendor_id)
				return hikamarket::deny('order', JText::sprintf('HIKAM_PAGE_DENY'));

			$order->history->history_notified = false;
		}

		$currentTask = 'billing_address';
		$aclTask = 'billingaddress';
		if( (empty($task) || $task == $currentTask) && !empty($data[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$aclTask)) ) {
			$oldAddress = null;
			if(!empty($oldOrder->order_billing_address_id)) {
				$oldAddress = $addressClass->get($oldOrder->order_billing_address_id);
			}
			$billing_address = $fieldsClass->getInput(array($currentTask, 'address'), $oldAddress);

			if(!empty($billing_address) && !empty($order_id)){
				$result = $addressClass->save($billing_address, $order_id, 'billing');
				if($result){
					$order->order_billing_address_id = $result;
					$order->history->history_reason = 'Billing address modified';
					$do = true;
				}
			}
		}

		$currentTask = 'shipping_address';
		$aclTask = 'shippingaddress';
		if( (empty($task) || $task == $currentTask) && !empty($data[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$aclTask)) ) {
			$oldAddress = null;
			if(!empty($oldOrder->order_shipping_address_id)) {
				$oldAddress = $addressClass->get($oldOrder->order_shipping_address_id);
			}
			$shipping_address = $fieldsClass->getInput(array($currentTask, 'address'), $oldAddress);

			if(!empty($shipping_address) && !empty($order_id)){
				$result = $addressClass->save($shipping_address, $order_id, 'shipping');
				if($result){
					$order->order_shipping_address_id = $result;
					$order->history->history_reason = 'Shipping address modified';
					$do = true;
				}
			}
		}

		$currentTask = 'general';
		if( (empty($task) || $task == $currentTask) && !empty($data[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$currentTask)) ) {

			if(!empty($data['order']['order_status']) && $this->isValidOrderStatus($data['order']['order_status'])) {
				$order->order_status = $data['order']['order_status'];

				if($oldOrder->order_type == 'subsale' && (int)$oldOrder->order_vendor_paid > 0) {
					$config = hikamarket::config();
					$valid_order_statuses = explode(',', $config->get('valid_order_statuses', 'confirmed,shipped'));
					if(in_array($oldOrder->order_status, $valid_order_statuses) && in_array($order->order_status, $valid_order_statuses)) {
						$order->order_status = $data['order']['order_status'];
						$do = true;
					}
				} else {
					$order->order_status = $data['order']['order_status'];
					$do = true;
				}
			}

			if($vendor_id == 0 && !empty($data['notify']) && hikamarket::acl('order/edit/notify')) {
				if(empty($order->history))
					$order->history = new stdClass();
				$order->history->history_notified = true;
			}
		}

		$currentTask = 'additional';
		if( (empty($task) || $task == $currentTask) && !empty($data[$currentTask]) && !isset($forbidden[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$currentTask)) ) {
			$history_data = array();

			if(isset($data['order']['order_discount_code'])) {
				$order->order_discount_code = $safeHtmlFilter->clean(strip_tags($data['order']['order_discount_code']), 'string');
				$do = true;
			}
			if(isset($data['order']['order_discount_price'])) {
				$order->order_discount_price = (float)hikamarket::toFloat($data['order']['order_discount_price']);
				$do = true;
			}
			if(isset($data['order']['order_discount_tax'])) {
				$order->order_discount_tax = (float)hikamarket::toFloat($data['order']['order_discount_tax']);
				$do = true;
			}
			if(isset($data['order']['order_discount_tax_namekey'])) {
				$order->order_discount_tax_namekey = $safeHtmlFilter->clean(strip_tags($data['order']['order_discount_tax_namekey']), 'string');
				$do = true;
			}

			if(!empty($data['order']['shipping'])) {

				if(is_string($data['order']['shipping'])) {
					$s = $safeHtmlFilter->clean(strip_tags($data['order']['shipping']), 'string');
					list($shipping_method, $shipping_id) = explode('_', $s, 2);
					$order->order_shipping_method = $shipping_method;
					$order->order_shipping_id = $shipping_id;
					$do = true;
				}

				if(is_array($data['order']['shipping'])) {
					$order->order_shipping_method = '';
					$shippings = array();
					$order->order_shipping_params->prices = array();

					foreach($data['order']['shipping'] as $shipping_group => $shipping_value) {
						list($shipping_method, $shipping_id) = explode('_', $shipping_value, 2);
						$n = $shipping_id . '@' . $shipping_group;
						$shippings[] = $n;
						$order->order_shipping_params->prices[$n] = new stdClass();
						$order->order_shipping_params->prices[$n]->price_with_tax = (float)hikamarket::toFloat(@$data['order']['order_shipping_prices'][$shipping_group]);
						$order->order_shipping_params->prices[$n]->tax = (float)hikamarket::toFloat(@$data['order']['order_shipping_taxs'][$shipping_group]);
					}
					$order->order_shipping_id = implode(';', $shippings);
					$do = true;

					if(!empty($data['order']['warehouses'])) {
						$orderProductClass = hikamarket::get('shop.class.order_product');
						$this->db->setQuery('SELECT * FROM '.hikamarket::table('shop.order_product').' WHERE order_id = '.(int)$order_id);
						$order_products = $this->db->loadObjectList('order_product_id');
						foreach($data['order']['warehouses'] as $pid => $w) {
							if(isset($order_products[$pid])) {
								$p = $order_products[$pid];
								list($shipping_method, $shipping_id) = explode('_', $data['order']['shipping'][$w], 2);
								$p->order_product_shipping_id = $shipping_id . '@' . $w;
								$p->order_product_shipping_method = $shipping_method;
								$orderProductClass->update($p);
							}
						}
					}
				}
			}
			if(isset($data['order']['order_shipping_price'])) {
				$order->order_shipping_price = (float)hikamarket::toFloat(trim($data['order']['order_shipping_price']));
				$do = true;
			}
			if(isset($data['order']['order_shipping_tax'])) {
				$order->order_shipping_tax = (float)hikamarket::toFloat(trim($data['order']['order_shipping_tax']));
				$do = true;
			}

			if(!empty($data['order']['payment'])) {
				list($payment_method, $payment_id) = explode('_', $data['order']['payment'], 2);
				$order->order_payment_method = $payment_method;
				$order->order_payment_id = $payment_id;
				$do = true;
			}
			if(isset($data['order']['order_payment_price'])) {
				$order->order_payment_price = (float)hikamarket::toFloat(trim($data['order']['order_payment_price']));
				$do = true;
			}
			if(isset($data['order']['order_payment_tax'])) {
				$order->order_payment_tax = (float)hikashop_toFloat($data['order']['order_payment_tax']);
				$do = true;
			}
			if(isset($data['order']['order_payment_tax_namekey'])) {
				$order->order_payment_tax_namekey = $safeHtmlFilter->clean($data['order']['order_payment_tax_namekey'], 'string');
				$do = true;
			}

			if($do && !empty($history_data)) {
				$order->history->history_reason = 'Order additional modified';
				$order->history->history_data = implode('<br/>', $history_data);
			}
		}

		$currentTask = 'customfields';
		$validTasks = array('customfields', 'additional');
		if( (empty($task) || in_array($task, $validTasks)) && !empty($data[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$currentTask)) ) {

			$old = null;
			$orderFields = $fieldsClass->getInput(array('orderfields','order'), $old, true, 'data', false, 'backend');
			if(!empty($orderFields)) {
				$do = true;
				foreach($orderFields as $key => $value) {
					$order->$key = $value;
				}
			}
		}

		$currentTask = 'customer';
		if( (empty($task) || $task == $currentTask) && (!$acl || hikamarket::acl('order/edit/'.$currentTask)) ) {
			$order_user_id = (int)$data['order']['order_user_id'];
			if($order_user_id > 0) {
				$order->order_user_id = $order_user_id;
				$do = true;

				$set_address = JRequest::getInt('set_user_address', 0);
				if($set_address) {
					$db = JFactory::getDBO();
					$db->setQuery('SELECT address_id FROM '.hikamarket::table('shop.address').' WHERE address_user_id = '. (int)$order_user_id . ' AND address_published = 1 ORDER BY address_default DESC, address_id ASC LIMIT 1');
					$address_id = $db->loadResult();
					if($address_id)
						$order->order_billing_address_id = (int)$address_id;
				}
			}
		}

		$currentTask = 'products';
		if( (empty($task) || $task == $currentTask) && !empty($data[$currentTask]) && (!$acl || hikamarket::acl('order/edit/'.$currentTask)) ) {
			$orderProductClass = hikamarket::get('shop.class.order_product');
			$productData = $data['order']['product'];
			if(isset($productData['order_id'])) {
				$product = new stdClass();
				foreach($productData as $key => $value) {
					hikamarket::secureField($key);
					$product->$key = $safeHtmlFilter->clean($value, 'string');
				}

				if($order->order_type == 'sale') {
					$product_id = (int)$productData['product_id'];
					$order_product_id = null;
					if(isset($productData['order_product_id']))
						$order_product_id = (int)$productData['order_product_id'];
					$order_product_vendor_price = (float)hikamarket::toFloat(trim($productData['order_product_vendor_price']));
					$order_product_vendor_id = null;
					if(isset($productData['order_product_vendor_id']))
						$order_product_vendor_id = (int)trim($productData['order_product_vendor_id']);
					$order_product_quantity = null;
					if(isset($data['order']['product']['order_product_quantity']))
						$order_product_quantity = (int)trim($data['order']['product']['order_product_quantity']);

					if(empty($order->hikamarket))
						$order->hikamarket = new stdClass();
					$order->hikamarket->products = array(
						'product_id' => $product_id,
						'order_product_id' => $order_product_id,
						'vendor_id' => $order_product_vendor_id,
						'vendor_price' => $order_product_vendor_price,
					);
					if($order_product_quantity !== null)
						$order->hikamarket->products['order_product_quantity'] = $order_product_quantity;
					unset($product->order_product_vendor_id);
					unset($product->order_product_vendor_price);
				}

				$product->order_id = (int)$order_id;
				$orderProductClass->update($product);
			} else {
				foreach($productData as $data) {
					$product = new stdClass();
					foreach($data as $key => $value) {
						hikamarket::secureField($key);
						$product->$key = $safeHtmlFilter->clean(strip_tags($value), 'string');
					}
					$product->order_id = (int)$order_id;
					$orderProductClass->update($product);
				}
			}
			$orderClass->recalculateFullPrice($order);
			$do = true;
		}

		if(!empty($task) && $task == 'product_delete' && (!$acl || hikamarket::acl('order/edit/products')) ) {
			$order_product_id = JRequest::getInt('order_product_id', 0);
			if($order_product_id > 0) {
				$orderProductClass = hikamarket::get('shop.class.order_product');
				$order_product = $orderProductClass->get($order_product_id);
				if(!empty($order_product) && $order_product->order_id == $order_id) {
					$order_product->order_product_quantity = 0;
					$orderProductClass->update($order_product);

					$order->history->history_reason = 'Delete order product';
					$order->history->history_data = JText::sprintf('HIKAM_ORDER_PRODUCT_REMOVED', $order_product->order_product_name, $order_product->product_id);

					$orderClass->recalculateFullPrice($order);
					$do = true;
				}
			}
		}

		if($do) {
			if(!empty($data['history']['store_data'])) {
				if(isset($data['history']['msg']))
					$order->history->history_data = $safeHtmlFilter->clean($data['history']['msg'], 'string');
				else
					$order->history->history_data = $safeHtmlFilter->clean(@$data['history']['history_data'], 'string');
			}
			$result = $orderClass->save($order);

			if($result && $order->order_type == 'subsale' && $oldOrder->order_status != $order->order_status) {
				$shopConfig = hikamarket::config(false);
				$admin_notify_orders = explode(',', $shopConfig->get('admin_notify_subsale', 'cancelled,refunded'));
				if(in_array($order->order_status, $admin_notify_orders)) {

					$mailClass = hikamarket::get('class.mail');
					$vendorClass = hikamarket::get('class.vendor');
					$mainVendor = $vendorClass->get(1);
					if(empty($mainVendor->vendor_email))
						$mainVendor->vendor_email = $shopConfig->get('payment_notification_email', '');
					if(empty($mainVendor->vendor_email))
						$mainVendor->vendor_email = $shopConfig->get('order_creation_notification_email', '');

					if(!isset($order->hikamarket))
						$order->hikamarket = new stdClass();
					$order->hikamarket->vendor = $mainVendor;
					$mailClass->sendVendorOrderEmail($order);
				}
			}

			return $result;
		}
		return false;
	}

	public function save(&$order) {
		return false;
	}

	public function delete(&$elements) {
		return false;
	}

	public function beforeCreate(&$order, &$do) {
		$this->beforeUpdate($order, $do, true);
	}

	public function afterCreate(&$order, &$send_email) {
		if(empty($order) || empty($order->order_type))
			return;
		if($order->order_type == 'subsale')
			$send_email = false;

		if(isset($order->hikamarket->do_not_process))
			return;

		if($order->order_type == 'subsale' && (int)$order->order_vendor_id > 1 && (int)$order->order_user_id > 0) {
			$query = 'INSERT IGNORE INTO `'.hikamarket::table('customer_vendor').'` (customer_id, vendor_id) VALUES ('.(int)$order->order_user_id.','.(int)$order->order_vendor_id.')';
			$this->db->setQuery($query);
			$this->db->query();
		}

		if($order->order_type == 'subsale' && !empty($order->cart->products)) {
			foreach($order->cart->products as $product) {
				$query = 'UPDATE ' . hikamarket::table('shop.order_product') .
						' SET order_product_parent_id = ' . (int)$product->order_product_parent_id.', order_product_vendor_price = ' . (float)$product->order_product_vendor_price;

				if(!empty($product->order_product_id))
					$query .= ' WHERE order_product_id = ' . $product->order_product_id;
				else
					$query .= ' WHERE order_id = ' . (int)$product->order_id . ' AND product_id = ' . (int)$product->product_id . ' AND order_product_price = ' . (float)hikamarket::toFloat($product->order_product_price);
				$this->db->setQuery($query);
				$this->db->query();
			}
		}

		if($order->order_type == 'sale' && !empty($order->cart->products)) {
			$products = array();
			foreach($order->cart->products as $product) {
				$products[(int)$product->cart_product_id] = array(
					'_id' => (int)$product->cart_product_id,
					'id' => (int)$product->product_id,
					'vendor' => null,
					'fee' => array(),
					'qty' => (int)$product->order_product_quantity,
					'price' => (float)hikamarket::toFloat($product->order_product_price),
					'price_tax' => (float)hikamarket::toFloat($product->order_product_tax),
				);
			}

			$vendors = $this->getVendorsByProducts($products, $order);
			$vendor_ids = array_keys($vendors);

			if(count($vendors) > 1) {
				$feeClass = hikamarket::get('class.fee');
				$allFees = $feeClass->getProducts($products, $vendor_ids);

				if(!empty($order->order_discount_code)) {
					if(empty($order->cart->coupon)) {
					}
					if(!empty($order->cart->coupon) && (int)$order->cart->coupon->discount_target_vendor >= 1) {
						$order->cart->coupon->products_full_price = 0.0;
						if(!empty($order->cart->coupon->products)) {
							foreach($order->cart->coupon->products as $p) {
								$order->cart->coupon->products_full_price += (int)$p->cart_product_quantity * (float)$p->prices[0]->price_value;
							}
						} else {
							foreach($order->cart->products as $p) {
								$order->cart->coupon->products_full_price += (int)$p->order_product_quantity * (float)$p->order_product_price;
							}
						}
					}
				}

				self::$creatingSubSales = true;

				foreach($vendors as $vendor_id => $vendor) {
					if(empty($vendor))
						continue;
					$subsale = $this->createSubOrder($order, $vendor_id, $products);
					if(isset($statuses[$subsale->order_status]))
						$statuses[$subsale->order_status]++;
					else
						$statuses[$subsale->order_status] = 1;
				}

				self::$creatingSubSales = false;

				if(count($statuses) == 1 && empty($statuses[$order->order_status])) {
					$update_order = new stdClass();
					$update_order->order_id = $order->order_id;
					$update_order->order_status = reset(array_keys($statuses));
					$update_order->old = $order;

					$orderClass = hikamarket::get('shop.class.order');
					$orderClass->save($update_order);
				}
			}
		}

		if(!empty($order->hikamarket) && (
		  (isset($order->hikamarket->send_email) && $order->hikamarket->send_email === true) ||
		  (isset($order->hikamarket->internal_process) && $order->hikamarket->internal_process == true && (isset($order->hikamarket->send_email) && $order->hikamarket->send_email === true))
		  )) {

			$config = hikamarket::config();
			$statuses = $config->get('vendor_email_order_status_notif_statuses', '');
			if(!empty($statuses))
				$statuses = explode(',', $statuses);

			if(!isset($order->hikamarket->vendor)) {
				$vendorClass = hikamarket::get('class.vendor');
				$vendor_id = (int)@$order->old->order_vendor_id;
				if(isset($order->order_vendor_id))
					$vendor_id = (int)$order->order_vendor_id;
				$order->hikamarket->vendor = $vendorClass->get( $vendor_id );
			}

			if(!empty($order->hikamarket->vendor)) {
				if(!empty($order->hikamarket->vendor->vendor_params->notif_order_statuses))
					$statuses = explode(',', $order->hikamarket->vendor->vendor_params->notif_order_statuses);

				if(empty($statuses) || in_array($order->order_status, $statuses)) {
					$mailClass = hikamarket::get('class.mail');
					$mailClass->sendVendorOrderEmail($order);
				}
			}
		}
	}

	public function beforeUpdate(&$order, &$do, $creation = false) {
		$vendor_id = 0;
		if(!empty($order->order_vendor_id)) {
			$vendor_id = $order->order_vendor_id;
		} elseif(!empty($order->old->order_vendor_id)) {
			$vendor_id = $order->old->order_vendor_id;
		}

		$order_type = (!empty($order->order_type)) ? $order->order_type : @$order->old->order_type;

		$app = JFactory::getApplication();
		$ctrl = JRequest::getCmd('ctrl', '');
		$task = JRequest::getCmd('task', '');
		if($app->isAdmin() && $ctrl == 'order' && $task == 'save') {
			$this->processForm($order, $do, 'order');
			if($order_type == 'subsale' && !empty($order->hikamarket->products) && empty($order->hikamarket->products['order_product_id'])) {
				$do = false;
				return;
			}
		}

		if($vendor_id > 0) {
			if(!empty($order->order_status) && empty($order->order_invoice_id) && empty($order->old->order_invoice_id)) {
				$shopConfig = hikamarket::config(false);
				$invoice_statuses = explode(',', $shopConfig->get('invoice_order_statuses','confirmed,shipped'));
				if(empty($invoice_statuses))
					$invoice_statuses = array('confirmed','shipped');
				$excludeFreeOrders = $shopConfig->get('invoice_exclude_free_orders', 0);
				if(isset($order->order_full_price))
					$total = $order->order_full_price;
				else
					$total = $order->old->order_full_price;

				if(in_array($order->order_status, $invoice_statuses) && ($total > 0 || !$excludeFreeOrders)) {
					$format = $shopConfig->get('invoice_number_format','{automatic_code}');
					$vendorClass = hikamarket::get('class.vendor');
					$vendor = $vendorClass->get($vendor_id);
					if(!empty($vendor->vendor_params->invoice_number_format))
						$format = $vendor->vendor_params->invoice_number_format;

					$query = 'SELECT MAX(a.order_invoice_id)+1 FROM '.hikamarket::table('shop.order').' AS a WHERE a.order_vendor_id = '. (int)$vendor_id;
					$resetFrequency = $shopConfig->get('invoice_reset_frequency', '');
					if(!empty($resetFrequency)) {
						$y = (int)date('Y');
						$m = 1;
						$d = 1;
						if($resetFrequency == 'month')
							$m = (int)date('m');

						if(strpos($resetFrequency, '/') !== false) {
							list($d,$m) = explode('/', $resetFrequency, 2);
							$d = ($d == '*') ? (int)date('d') : (int)$d;
							$m = ($m == '*') ? (int)date('m') : (int)$m;
							if($d <= 0) $d = 1;
							if($m <= 0) $m = 1;
						}

						$query .= ' AND a.order_invoice_created >= '.mktime(0, 0, 0, $m, $d, $y);
					}
					$this->db->setQuery($query);

					$order->order_invoice_id = (int)$this->db->loadResult();
					if(empty($order->order_invoice_id)) $order->order_invoice_id = 1;
					$order->order_invoice_number = hikamarket::encodeNumber($order, 'invoice', $format);
					$order->order_invoice_created = time();
				}
			}
		}

		if(empty($order->hikamarket))
			$order->hikamarket = new stdClass();

		if($order_type == 'subsale') {

			if(!empty($order->old->order_vendor_paid) && $order->old->order_vendor_paid > 0 && (empty($order->hikamarket->internal_process) || !$order->hikamarket->internal_process) ) {
				$do = false;
				return;
			}

			if(empty($order->hikamarket->parent)) {
				$parent_id = 0;
				if(!empty($order->old->order_parent_id))
					$parent_id = (int)$order->old->order_parent_id;
				if(!empty($order->order_parent_id))
					$parent_id = (int)$order->order_parent_id;
				$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS a WHERE order_id = ' . $parent_id;
				$this->db->setQuery($query);
				$order->hikamarket->parent = $this->db->loadObject();
			}
		}

		if($order_type == 'vendorrefund' && (empty($order->hikamarket->internal_process) || !$order->hikamarket->internal_process)) {
			$do = false;
			return;
		}

		if($order_type == 'sale') {
			if(empty($order->hikamarket->children) && !empty($order->order_id)) {
				$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS a WHERE order_type = '. $this->db->Quote('subsale') .' AND order_parent_id = ' . (int)$order->order_id;
				$this->db->setQuery($query);
				$order->hikamarket->children = $this->db->loadObjectList('order_id');
				foreach($order->hikamarket->children as &$suborder) {
					if(!empty($suborder->order_tax_info))
						$suborder->order_tax_info = unserialize($suborder->order_tax_info);
					unset($suborder);
				}
			}
			if(empty($order->hikamarket->refunds)  && !empty($order->order_id)) {
				$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS a WHERE order_type = '. $this->db->Quote('vendorrefund') .' AND order_parent_id = ' . (int)$order->order_id;
				$this->db->setQuery($query);
				$order->hikamarket->refunds = $this->db->loadObjectList('order_id');
			}
		}
	}

	public function beforeProductsUpdate(&$order, &$do) {
		$order_type = (!empty($order->order_type)) ? $order->order_type : @$order->old->order_type;

		$app = JFactory::getApplication();
		$ctrl = JRequest::getCmd('ctrl', '');
		$task = JRequest::getCmd('task', '');
		if($app->isAdmin() && $ctrl == 'order' && $task == 'save') {
			$this->processForm($order, $do, 'products');
		}

		if($order_type == 'vendorrefund')
			$do = false;

		if(($order_type == 'subsale') && !empty($order->product) && (empty($order->hikamarket->internal_process) || !$order->hikamarket->internal_process))
			$do = false;

		if($order_type == 'sale' && !empty($order->product) && !empty($order->hikamarket->reprocess)) {
			$query = 'SELECT op.*, o.order_vendor_params FROM ' . hikamarket::table('shop.order_product') . ' AS op '.
					' INNER JOIN ' . hikamarket::table('shop.order') . ' AS o ON op.order_id = o.order_id '.
					' WHERE o.order_type = ' . $db->Quote('subsale') . ' AND o.order_parent_id = ' . $order->order_id;
			$this->db->setQuery($query);
			$suborder_products = $this->db->loadObjectList();

			if(is_array($order->product))
				$order_products = $order->product;
			else
				$order_products = array($order->product);

			$updates = array();
			foreach($order_products as $order_product) {
				foreach($suborder_products as $suborder_product) {
					if((int)$suborder_product->order_product_parent_id != (int)$order_product->order_product_id)
						continue;
					if($suborder_product->order_product_price == $order_product->order_product_price)
						break;
					$suborder_product->order_product_price = $order_product->order_product_price;

					$op = array(
						'order_product_price' => $suborder_product->order_product_price,
						'order_product_vendor_price' => $this->recalculateProductPrice($suborder_product, $order_vendor_params)
					);
					if($op['order_product_vendor_price'] === false)
						unset($op['order_product_vendor_price']);

					$updates[$suborder_product->order_product_id] = $op;
					break;
				}
			}
			unset($order_product);

			if(!empty($updates)) {
				foreach($updates as $i => $update) {
					$query = 'UPDATE ' . hikamarket::table('shop.order_product') . ' SET ';
					foreach($update as $k => $v) {
						$query .= $k . ' = ' . $this->db->Quote($v) . ' ';
					}
					$query .= ' WHERE order_product_id = ' . $i;
					$this->db->setQuery($query);
					$this->db->query();
				}
				unset($updates);

				$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS o WHERE o.order_type = ' . $db->Quote('subsale') . ' AND o.order_parent_id = ' . $order->order_id;
				$this->db->setQuery($query);
				$suborders = $this->db->loadObjectList();
				foreach($suborders as $suborder) {
					$ret = $this->recalculateVendorPrice($suborder);
					$query = 'UPDATE ' . hikamarket::table('shop.order') . ' SET order_vendor_price = ' . $this->db->Quote($ret) . ' WHERE order_id = ' . (int)$suborder->order_id . ' AND o.order_type = ' . $db->Quote('subsale') . ' AND o.order_parent_id = ' . $order->order_id;
					$this->db->setQuery($query);
					$this->db->query();
				}
			}
		}

	}

	public function afterUpdate(&$order, &$send_email) {

		$config = hikamarket::config();
		$updatableOrderStatuses = explode(',', $config->get('updatable_order_statuses', 'created'));
		$confirmedOrderStatuses = explode(',', $config->get('valid_order_statuses', 'confirmed,shipped'));

		$order_type = '';
		if(!empty($order->order_type)) {
			$order_type = $order->order_type;
		} else {
			$order_type = @$order->old->order_type;
		}

		if($order_type == 'sale') {

			$updateOrders = array();
			$somePaid = array();

			if(!empty($order->hikamarket->products)) {
				if(!empty($order->hikamarket->products['order_product_id'])) {
					$query = 'UPDATE ' . hikamarket::table('shop.order_product') .
						' SET order_product_vendor_price = ' . $this->db->Quote($order->hikamarket->products['vendor_price']);
					if(isset($order->hikamarket->products['order_product_quantity']))
						$query .=  ', order_product_quantity = ' . $this->db->Quote($order->hikamarket->products['order_product_quantity']);
					$query .= ' WHERE order_product_parent_id = ' . $order->hikamarket->products['order_product_id'] . ' AND product_id = ' . $order->hikamarket->products['product_id'];
					$this->db->setQuery($query);
					$this->db->query();
				} else {
					$found = false;
					$vendor_id = (int)$order->hikamarket->products['vendor_id'];
					foreach($order->hikamarket->children as &$suborder) {
						if((int)$suborder->order_vendor_id == $vendor_id) {
							list($fields, $values) = $this->getQuotedObject($order->product[0]);
							$values['order_id'] = $suborder->order_id;
							$fields['order_product_id'] = 'order_product_parent_id';
							$fields['order_product_vendor_price'] = 'order_product_vendor_price';
							$values['order_product_vendor_price'] = $this->db->Quote($order->hikamarket->products['vendor_price']);

							$query = 'INSERT IGNORE INTO ' . hikamarket::table('shop.order_product') . ' ('.implode(',', $fields).') VALUES ('.implode(',', $values).')';
							$this->db->setQuery($query);
							$this->db->query();
							unset($values);
							unset($fields);

							$found = true;
							break;
						}
					}
					if(!$found) {
						$newOrder = $this->createNewSubOrder($order, $vendor_id, $order->hikamarket->products);
						$order->hikamarket->children[] = $newOrder;
					}
				}
			} else if(!empty($order->product)) {
				$product = hikamarket::cloning($order->product[0]);
				if((int)$product->order_product_quantity === 0) {
					$query = 'DELETE FROM ' . hikamarket::table('shop.order_product') . ' WHERE order_product_parent_id = ' . (int)$product->order_product_id;
					$this->db->setQuery($query);
					$this->db->query();
				} else {
					$pcid = $product->order_product_id;
					unset($product->order_product_id);
					unset($product->order_product_parent_id);
					unset($product->order_product_vendor_price);
					list($fields, $values) = $this->getQuotedObject($product);
					$query = 'UPDATE ' . hikamarket::table('shop.order_product') . ' SET ';
					$sep = '';
					foreach($fields as $k => $v) {
						$query .= $sep.' '. $v . '=' . $values[$k];
						$sep = ',';
					}
					$query .= ' WHERE order_product_parent_id = '.(int)$pcid;
					$this->db->setQuery($query);
					$this->db->query();
					unset($values);
					unset($fields);
				}
				unset($product);
			}

			if(!empty($order->order_status) && $order->order_status != $order->old->order_status) {
				foreach($order->hikamarket->children as &$suborder) {
					if((int)$suborder->order_vendor_paid > 0 && (int)$suborder->order_vendor_id > 0) {
						$somePaid[(int)$suborder->order_vendor_id] = (int)$suborder->order_vendor_id;
						continue;
					}
					if($order->order_status == $suborder->order_status)
						continue;

					$updatedOrder = new stdClass();
					$updatedOrder->hikamarket = new stdClass();
					$updatedOrder->hikamarket->internal_process = true;
					$updatedOrder->hikamarket->parent = $order;

					$updatedOrder->order_id = $suborder->order_id;
					$updatedOrder->order_status = $order->order_status;

					$updatedOrder->history = new stdClass();
					if(isset($order->history))
						$updatedOrder->history = $order->history;
					else
						$updatedOrder->history->history_notified = true && $send_email;

					$updateOrders[$updatedOrder->order_id] = $updatedOrder;
				}
			}

			$reprocess_suborders = false;
			$oti = is_string($order->order_tax_info) ? $order->order_tax_info : serialize($order->order_tax_info);
			$ooti = is_string($order->old->order_tax_info) ? $order->old->order_tax_info : serialize($order->old->order_tax_info);
			$osp = is_string($order->order_shipping_params) ? $order->order_shipping_params : serialize($order->order_shipping_params);
			$oosp = is_string($order->old->order_shipping_params) ? $order->old->order_shipping_params : serialize($order->old->order_shipping_params);
			$opp = is_string($order->order_payment_params) ? $order->order_payment_params : serialize($order->order_payment_params);
			$oopp = is_string($order->old->order_payment_params) ? $order->old->order_payment_params : serialize($order->old->order_payment_params);

			if(!empty($order->product) || !empty($order->hikamarket->products) || !empty($order->products) || $order->order_payment_price != $order->old->order_payment_price || $order->order_shipping_id != $order->old->order_shipping_id ||
			  $order->order_shipping_price != $order->old->order_shipping_price || $order->order_shipping_tax != $order->old->order_shipping_tax || $order->order_discount_price != $order->old->order_discount_price ||
			  $order->order_discount_tax != $order->old->order_discount_tax || hikamarket::toFloat($order->order_full_price) != $order->old->order_full_price || $order->order_payment_tax != $order->old->order_payment_tax ||
			  $oti != $ooti || $osp != $oosp || $opp != $oopp
			 )
				$reprocess_suborders = true;

			$confirmingOrder = (in_array($order->order_status, $confirmedOrderStatuses) && !in_array($order->old->order_status, $confirmedOrderStatuses));
			$refundingOrder  = (!in_array($order->order_status, $confirmedOrderStatuses) && in_array($order->old->order_status, $confirmedOrderStatuses));

			if(count($somePaid) > 0 && ($confirmingOrder || $refundingOrder))
				$reprocess_suborders = true;

			unset($oti); unset($opoti); unset($osp); unset($oosp); unset($opp); unset($oopp);

			$includeFields = array(
				'order_billing_address_id',
				'order_shipping_address_id',
				'order_user_id',
				'order_discount_code',
				'order_ip',
				'order_currency_id',
				'order_payment_id',
				'order_payment_method',
			);

			$query = 'SELECT field_namekey FROM '.hikamarket::table('shop.field').' AS a WHERE a.field_table = '.$this->db->Quote('order');
			$this->db->setQuery($query);
			if(!HIKASHOP_J25)
				$customFields = $this->db->loadResultArray();
			else
				$customFields = $this->db->loadColumn();
			$includeFields = array_merge($includeFields, $customFields);

			foreach($includeFields as $field) {
				if(!isset($order->$field))
					continue;

				$value = $order->$field;
				if($field == 'order_payment_method')
					$value = 'market-' . $order->$field;

				foreach($order->hikamarket->children as &$suborder) {
					if(!isset($updateOrders[$suborder->order_id])) {
						$updatedOrder = new stdClass();
						$updatedOrder->hikamarket = new stdClass();
						$updatedOrder->hikamarket->internal_process = true;
						$updatedOrder->hikamarket->parent = $order;
						$updatedOrder->order_id = $suborder->order_id;
						$updateOrders[$suborder->order_id] = $updatedOrder;
					}
					$updateOrders[$suborder->order_id]->$field = $value;
				}
			}

			if($reprocess_suborders) {
				$shopOrderClass = hikamarket::get('shop.class.order');

				$products = array();
				if(!empty($order->products) && empty($order->product))
					$order->product = $order->products;
				if(!empty($order->product)) {
					if(!is_array($order->product))
						$order->product = array($order->product);
					foreach($order->product as $product) {
						$products[(int)$product->order_product_id] = array(
							'_id' => (int)$product->order_product_id,
							'id' => (int)$product->product_id,
							'vendor' => null,
							'fee' => array(),
							'qty' => (int)$product->order_product_quantity,
							'price' => (float)hikamarket::toFloat($product->order_product_price),
							'price_tax' => (float)hikamarket::toFloat($product->order_product_tax),
						);
					}
				}

				foreach($order->hikamarket->children as $subOrder) {

					$subOrder->order_vendor_price = (float)hikamarket::toFloat($subOrder->order_vendor_price);
					$vendor_new_total = $this->recalculateVendorPrice($subOrder);

					if(is_string($subOrder->order_payment_params) && !empty($subOrder->order_payment_params))
						$subOrder->order_payment_params = unserialize($subOrder->order_payment_params);
					$feeMode = true;
					if(isset($subOrder->order_payment_params->market_mode))
						$feeMode = $subOrder->order_payment_params->market_mode;

					if(!$feeMode) {
						if($config->get('shipping_per_vendor', 1) && !empty($order->order_shipping_price))
							$vendor_new_total = $vendor_new_total - $subOrder->order_full_price;
						else
							$vendor_new_total = $vendor_new_total - $subOrder->order_full_price - (float)$subOrder->order_shipping_price;
					}

					if($vendor_new_total != $subOrder->order_vendor_price || $subOrder->order_vendor_paid > 0) {
						if($subOrder->order_vendor_paid > 0) {
							$this->updateVendorRefund($subOrder, $vendor_new_total, $order);

							unset($subOrder->order_status);
							unset($subOrder->order_vendor_price);
							$shopOrderClass->save($subOrder);
						} else {
							$subOrder->order_vendor_price = $vendor_new_total;
							$shopOrderClass->save($subOrder);
						}
					}
				}
			} else if(count($somePaid) > 0) {
				$query = 'UPDATE ' . hikamarket::table('shop.order') .
					' SET order_status = ' . $this->db->Quote($order->order_status) .
					' WHERE order_parent_id = ' . (int)$order->order_id . ' AND order_vendor_paid = 0 AND order_type = ' . $this->db->Quote('vendorrefund');
				$this->db->setQuery($query);
				$this->db->query();
			}

			if(!empty($updateOrders)) {
				$shopOrderClass = hikamarket::get('shop.class.order');
				foreach($updateOrders as &$suborder) {
					$shopOrderClass->save($suborder);
				}
			}
		}

		if($order_type == 'subsale' && (!isset($order->hikamarket->internal_process) || !$order->hikamarket->internal_process) && !self::$creatingSubSales) {

			if(!empty($order->order_status) && $order->order_status != $order->old->order_status) {
				if($order->hikamarket->parent->order_status != $order->order_status) {

					$query = 'SELECT a.order_status, count(a.order_id) as count FROM ' . hikamarket::table('shop.order') . ' AS a'.
							' WHERE order_type = ' . $this->db->Quote('subsale') . ' AND order_parent_id = ' . (int)$order->hikamarket->parent->order_id .
							' GROUP BY a.order_status';
					$this->db->setQuery($query);
					$statuses = $this->db->loadObjectList();

					if(count($statuses) == 1) {
						$shopOrderClass = hikamarket::get('shop.class.order');
						$parentOrder = new stdClass();
						$parentOrder->order_id = $order->hikamarket->parent->order_id;
						$parentOrder->order_status = $order->order_status;

						if($config->get('send_mail_subsale_update_main', 0)) {
							$parentOrder->history = new stdClass();
							$parentOrder->history->history_reason = JText::sprintf('AUTOMATIC_UPDATE_WITH_VENDORS');
							$parentOrder->history->history_notified = true;
							$parentOrder->history->history_type = 'modification';
						}

						$shopOrderClass->save($parentOrder);
					}
				}
			}

			if(!empty($order->hikamarket->products) && !empty($order->hikamarket->products['order_product_id'])) {
				$query = 'UPDATE ' . hikamarket::table('shop.order_product') .
					' SET order_product_vendor_price = ' . $this->db->Quote($order->hikamarket->products['vendor_price']);
				if(isset($order->hikamarket->products['order_product_quantity']))
					$query .= ', order_product_quantity = ' . $this->db->Quote($order->hikamarket->product['order_product_quantity']);
				$query .= ' WHERE order_product_id = ' . (int)$order->hikamarket->products['order_product_id'];
				$this->db->setQuery($query);
				$this->db->query();
			}
		}

		if($order_type == 'subsale' && !empty($order->order_status) && (empty($order->old) || $order->order_status != $order->old->order_status) && (!isset($order->hikamarket->send_email) || !$order->hikamarket->send_email)) {
			$statuses = $config->get('vendor_email_order_status_notif_statuses', '');
			if(!empty($statuses))
				$statuses = explode(',', $statuses);

			if(!isset($order->hikamarket->vendor)) {
				$vendorClass = hikamarket::get('class.vendor');
				$vendor_id = (int)@$order->old->order_vendor_id;
				if(isset($order->order_vendor_id))
					$vendor_id = (int)$order->order_vendor_id;
				$order->hikamarket->vendor = $vendorClass->get( $vendor_id );
			}

			if(!empty($order->hikamarket->vendor)) {
				if(!empty($order->hikamarket->vendor->vendor_params->notif_order_statuses))
					$statuses = explode(',', $order->hikamarket->vendor->vendor_params->notif_order_statuses);

				if(empty($statuses) || in_array($order->order_status, $statuses)) {
					$mailClass = hikamarket::get('class.mail');
					$mailClass->sendVendorOrderEmail($order);
				}
			}
		}
	}

	public function beforeDelete(&$elements, &$do) {
		$string = array();
		foreach($elements as $key => $val) {
			$string[$val] = $this->db->Quote($val);
		}

		$query = 'SELECT order_id, order_type, order_status FROM ' . hikamarket::table('shop.order') . ' WHERE order_id IN (' . implode(',', $string) . ')';
		$this->db->setQuery($query);
		$orders = $this->db->loadObjectList();

		$removedList = array();
		foreach($orders as $order) {
			if($order->order_type == 'subsale') {
				foreach($elements as $k => $e) {
					if($e == $order->order_id)
						unset($elements[$k]);
				}
				$removedList[] = $order->order_id;
			}
		}
		if(!empty($removedList)) {
			$app = JFactory::getApplication();
			if(count($removedList) == 1) {
				$app->enqueueMessage(JText::sprintf('CANNOT_DELETE_SUBORDER', $removedList[0]));
			} else {
				$app->enqueueMessage(JText::sprintf('CANNOT_DELETE_SUBORDERS', implode(', ',$removedList)));
			}
		}

		if(empty($elements))
			$do = false;
	}

	public function afterDelete(&$elements) {
		$string = array();
		foreach($elements as $key => $val) {
			$string[$val] = $val;
		}

		$query = 'SELECT order_id, order_billing_address_id, order_shipping_address_id FROM '.hikamarket::table('shop.order').' WHERE order_type = '.$this->db->Quote('subsale').' AND order_parent_id IN ('.implode(',',$string).')';
		$this->db->setQuery($query);
		$orders = $this->db->loadObjectList();

		if(!empty($orders)) {

			$addr = array();
			$string = array();
			foreach($orders as $o) {
				$addr[$o->order_billing_address_id] = $o->order_billing_address_id;
				$addr[$o->order_shipping_address_id] = $o->order_shipping_address_id;
				$string[] = $this->db->Quote($o->order_id);
			}

			$query = 'DELETE FROM ' . hikamarket::table('shop.order') . ' WHERE order_id IN (' . implode(',', $string) . ')';
			$this->db->setQuery($query);
			$this->db->query();

			$query = 'DELETE FROM ' . hikamarket::table('shop.order_product') . ' WHERE order_id IN (' . implode(',', $string) . ')';
			$this->db->setQuery($query);
			$this->db->query();

			$addressClass = hikamarket::get('shop.class.address');
			foreach($addr as $address) {
				$addressClass->delete($address, true);
			}
		}
	}

	public function isValidOrderStatus($order_status) {
		static $order_statuses = null;
		if($order_statuses === null) {
			$categoryClass = hikamarket::get('shop.class.category');
			$filters = array();
			$rows = $categoryClass->loadAllWithTrans('status', false, $filters);
			foreach($rows as $row) {
				$order_statuses[$row->category_name] = $row->category_name;
			}
			unset($rows);
		}
		return isset($order_statuses[$order_status]);
	}

	private function createSubOrder(&$order, $vendor_id, &$products) {
		$shopOrderClass = hikamarket::get('shop.class.order');
		$config = hikamarket::config();

		$v_order = unserialize(serialize($order));
		unset($v_order->cart->products);

		$v_order->order_type = 'subsale';
		$v_order->order_parent_id = $v_order->order_id;
		$v_order->order_vendor_id = $vendor_id;
		$v_order->order_payment_method = 'market-' . $v_order->order_payment_method;

		$v_order->order_partner_id = 0;
		$v_order->order_partner_price = 0.0;

		$v_order->order_payment_price = 0.0;
		$v_order->order_payment_tax = 0.0;
		$v_order->order_shipping_price = 0.0;
		$v_order->order_shipping_tax = 0.0;
		$v_order->order_discount_price = 0.0;
		$v_order->order_discount_tax = 0.0;
		$v_order->order_discount_code = '';

		$total_products = 0.0;
		$total_products_vendor = 0.0;

		$v_order->cart->products = array();
		foreach($order->cart->products as $product) {
			$pid = (int)$product->product_id;
			$pcid = (isset($product->cart_product_id)) ? (int)$product->cart_product_id : (int)$product->order_product_id;

			$total_products += $product->order_product_price;

			if(!isset($products[$pcid]))
				continue;

			if($products[$pcid]['vendor'] != $vendor_id)
				continue;

			$newProduct = hikamarket::cloning($product);
			$newProduct->order_product_parent_id = (int)$newProduct->order_product_id;
			$newProduct->cart_product_parent_id = (int)$newProduct->cart_product_id;
			unset($newProduct->order_product_id);
			unset($newProduct->order_id);


			$discount_apply_vendor = !isset($newProduct->discount->discount_target_vendor) ? 0 : (int)$newProduct->discount->discount_target_vendor;
			if(!empty($newProduct->discount) && $newProduct->discount->discount_type == 'discount' && $discount_apply_vendor <= 0) {
				if(isset($newProduct->discount->price_value_without_discount)) {
					$newProduct->order_product_price = $newProduct->discount->price_value_without_discount;
					$newProduct->order_product_tax = $newProduct->discount->price_value_without_discount_with_tax - $newProduct->discount->price_value_without_discount;
					$newProduct->order_product_tax_info = $newProduct->discount->taxes_without_discount;
				} else {
					if(!empty($newProduct->discount->discount_percent_amount)) {
						$percent = (float)hikamarket::toFloat($newProduct->discount->discount_percent_amount);
						$newProduct->order_product_price /= (1 - $percent/100);
					}
					if(!empty($newProduct->discount->discount_flat_amount)) {
						$value = (float)hikamarket::toFloat($newProduct->discount->discount_flat_amount);
						$newProduct->order_product_price += $value;
					}
				}
			}

			$newProduct->order_product_vendor_price = null;

			if(!empty($newProduct->cart_product_option_parent_id)) {
				$f = false;
				foreach($order->cart->products as $p) {
					$pcid = (isset($p->cart_product_id)) ? (int)$p->cart_product_id : (int)$p->order_product_id;
					if($p->cart_product_id == $newProduct->cart_product_option_parent_id && isset($products[$pcid])) {
						$f = true;
						break;
					}
				}
				if(!$f)
					unset($newProduct->cart_product_option_parent_id);
			}

			$newProduct->no_update_qty = true;
			$v_order->cart->products[] = $newProduct;

			$total_products_vendor += $newProduct->order_product_price;
		}

		if($config->get('split_order_payment_fees', 0) && !empty($total_products)) {
			$v_order->order_payment_price = $order->order_payment_price * $total_products_vendor / $total_products;
			$v_order->order_payment_tax = ((int)@$order->order_payment_tax) * $total_products_vendor / $total_products;
		}

		$this->processShippingParams($order, $vendor_id, $v_order, $total_products, $total_products_vendor, $products);

		if(!empty($order->cart->coupon) && !empty($order->cart->coupon->products_full_price)) {
			$vendor_coupon_total = 0.0;
			if(!empty($order->cart->coupon->products)) {
				foreach($order->cart->coupon->products as $product) {
					if($vendor_id > 1 && (int)$product->product_vendor_id != $vendor_id)
						continue;
					if($vendor_id <= 1 && (int)$product->product_vendor_id > 1)
						continue;
					foreach($products as $p) {
						if($p['id'] != (int)$product->product_id)
							continue;
						$vendor_coupon_total += (int)$product->cart_product_quantity * (float)$product->prices[0]->price_value;
					}
				}
			} else {
				foreach($v_order->cart->products as $product) {
					$vendor_coupon_total += (int)$product->order_product_quantity * (float)$product->order_product_price;
				}
			}
			if(empty($order->cart->coupon->vendors))
				$order->cart->coupon->vendors = array();
			$order->cart->coupon->vendors[$vendor_id] = $vendor_coupon_total;

			if($vendor_coupon_total > 0.0 && ($order->cart->coupon->discount_target_vendor == 1 || $order->cart->coupon->discount_target_vendor == $vendor_id)) {
				$v_order->order_discount_code = $order->order_discount_code;

				$coupon_percentage = (float)($vendor_coupon_total / $order->cart->coupon->products_full_price);

				$v_order->order_discount_price = $order->order_discount_price * $coupon_percentage;
				if($order->order_discount_tax > 0)
					$v_order->order_discount_tax = $order->order_discount_tax * $coupon_percentage;
				else
					$v_order->order_discount_tax = 0.0;
			}
		}

		if(empty($order->cart->coupon))
			$order->cart->coupon = null;

		$shopOrderClass->recalculateFullPrice($v_order, $v_order->cart->products);
		$v_order->order_vendor_price = $this->calculateVendorPrice($vendor_id, $v_order, $products, $order->cart->coupon);

		$feeMode = ($config->get('market_mode', 'fee') == 'fee');
		$payment_params = null;
		if(!empty($order->order_payment_params))
			$payment_params = is_string($order->order_payment_params) ? unserialize($order->order_payment_params) : $order->order_payment_params;
		if(!empty($payment_params->market_mode)) {
			$feeMode = (($payment_params->market_mode === true) || ($payment_params->market_mode === 'fee'));
		} else {
			$payment_id = 0;
			if(!empty($order->order_payment_id))
				$payment_id = (int)$order->order_payment_id;
			if($payment_id > 0) {
				$paymentClass = hikamarket::get('shop.class.payment');
				$payment = $paymentClass->get($payment_id);

				if(!empty($payment->market_mode)) {
					$feeMode = true;
				} else if(!empty($payment->payment_params->payment_market_mode)) {
					$feeMode = ($payment->payment_params->payment_market_mode == 'fee');
				}
			}
		}
		if(!$feeMode) {
			if($config->get('shipping_per_vendor', 1))
				$v_order->order_vendor_price = $v_order->order_vendor_price - $order->order_full_price;
			else
				$v_order->order_vendor_price = $v_order->order_vendor_price - $order->order_full_price - (float)$v_order->order_shipping_price;
		}

		if(empty($v_order->order_payment_params))
			$v_order->order_payment_params = new stdClass();
		$v_order->order_payment_params->market_mode = $feeMode;

		unset($v_order->order_id);
		if(!$config->get('use_same_order_number', 0))
			unset($v_order->order_number);
		unset($v_order->order_invoice_id);
		unset($v_order->order_invoice_number);

		$v_order->hikamarket = new stdClass();
		$v_order->hikamarket->internal_process = true;
		$v_order->hikamarket->send_email = true;
		$v_order->hikamarket->parent = $order;

		if(!empty($v_order->order_vendor_params) && is_object($v_order->order_vendor_params))
			$v_order->order_vendor_params = serialize($v_order->order_vendor_params);
		$shopOrderClass->save($v_order);

		return $v_order;
	}

	private function createNewSubOrder(&$order, $vendor_id, &$products, $otherSubOrders = null) {
		$shopOrderClass = hikamarket::get('shop.class.order');
		$config = hikamarket::config();

		$v_order = unserialize(serialize($order));
		unset($v_order->cart->products);
		unset($v_order->product);

		$v_order->order_type = 'subsale';
		$v_order->order_parent_id = $v_order->order_id;
		$v_order->order_vendor_id = $vendor_id;
		$v_order->order_payment_method = 'market-' . $v_order->order_payment_method;

		$v_order->order_partner_id = 0;
		$v_order->order_partner_price = 0.0;

		$v_order->order_payment_price = 0.0;
		$v_order->order_payment_tax = 0.0;
		$v_order->order_shipping_price = 0.0;
		$v_order->order_shipping_tax = 0.0;
		$v_order->order_discount_price = 0.0;
		$v_order->order_discount_tax = 0.0;
		$v_order->order_discount_code = '';

		$v_order->cart = new stdClass();
		$v_order->cart->products = array();
		foreach($order->product as $product) {
			$newProduct = hikamarket::cloning($product);
			$newProduct->order_product_parent_id = (int)$newProduct->order_product_id;
			unset($newProduct->order_product_id);
			unset($newProduct->order_id);

			$newProduct->order_product_vendor_price = (float)hikamarket::toFloat($products['vendor_price']);

			$newProduct->no_update_qty = true;
			$v_order->cart->products[] = $newProduct;
		}

		$v_order->order_payment_price = 0.0;

		$v_order->order_shipping_price = 0.0;
		$v_order->order_shipping_id = '';

		$shopOrderClass->recalculateFullPrice($v_order, $v_order->cart->products);
		$v_order->order_vendor_price = (float)hikamarket::toFloat($products['vendor_price']); // $this->calculateVendorPrice($vendor_id, $v_order, $products, $order->cart->coupon);

		$feeMode = ($config->get('market_mode', 'fee') == 'fee');
		$payment_params = null;
		if(!empty($order->order_payment_params))
			$payment_params = is_string($order->order_payment_params) ? unserialize($order->order_payment_params) : $order->order_payment_params;
		if(!empty($payment_params->market_mode)) {
			$feeMode = (($payment_params->market_mode === true) || ($payment_params->market_mode === 'fee'));
		} else {
			$payment_id = 0;
			if(!empty($order->order_payment_id))
				$payment_id = (int)$order->order_payment_id;
			if($payment_id > 0) {
				$paymentClass = hikamarket::get('shop.class.payment');
				$payment = $paymentClass->get($payment_id);

				if(!empty($payment->market_mode)) {
					$feeMode = true;
				} else if(!empty($payment->payment_params->payment_market_mode)) {
					$feeMode = ($payment->payment_params->payment_market_mode == 'fee');
				}
			}
		}
		if(!$feeMode) {
			if($config->get('shipping_per_vendor', 1))
				$v_order->order_vendor_price = $v_order->order_vendor_price - $order->order_full_price;
			else
				$v_order->order_vendor_price = $v_order->order_vendor_price - $order->order_full_price - (float)$v_order->order_shipping_price;
		}

		if(empty($v_order->order_payment_params))
			$v_order->order_payment_params = new stdClass();
		$v_order->order_payment_params->market_mode = $feeMode;

		unset($v_order->order_id);
		if(!$config->get('use_same_order_number', 0))
			unset($v_order->order_number);
		unset($v_order->order_invoice_id);
		unset($v_order->order_invoice_number);

		$v_order->hikamarket = new stdClass();
		$v_order->hikamarket->internal_process = true;
		$v_order->hikamarket->send_email = false;
		$v_order->hikamarket->parent = $order;

		if(!empty($v_order->order_vendor_params) && is_object($v_order->order_vendor_params))
			$v_order->order_vendor_params = serialize($v_order->order_vendor_params);

		$shopOrderClass->save($v_order);

		$pcid = (int)$v_order->cart->products[0]->order_product_id;
		$parent_pcid = (int)$order->product[0]->order_product_id;
		$query = 'UPDATE ' . hikamarket::table('shop.order_product') .
			' SET order_product_vendor_price = ' . $this->db->Quote($order->hikamarket->products['vendor_price']) . ', order_product_parent_id = ' . (int)$parent_pcid;
		if(isset($order->hikamarket->products['order_product_quantity']))
			$query .= ', order_product_quantity = ' . $this->db->Quote($order->hikamarket->products['order_product_quantity']);
		$query .= ' WHERE  order_product_id = ' . (int)$pcid;
		$this->db->setQuery($query);
		$this->db->query();

		return $v_order;
	}

	protected function getVendorsByProducts(&$products, $order = null) {
		$vendors = array(0 => array());
		if(empty($products))
			return $vendors;

		$product_ids = array();
		foreach($products as $product) { $product_ids[] = $product['id']; }

		$query = 'SELECT a.product_id, a.product_vendor_id, a.product_parent_id, b.product_vendor_id as `parent_vendor_id`'.
				' FROM ' . hikamarket::table('shop.product') . ' AS a'.
				' LEFT JOIN ' . hikamarket::table('shop.product') . ' AS b ON a.product_parent_id = b.product_id'.
				' WHERE a.product_id IN (' . implode(',', $product_ids) . ')';
		$this->db->setQuery($query);
		$productObjects = $this->db->loadObjectList('product_id');
		if(!empty($productObjects)) {
			foreach($productObjects as $product) {
				$vid = $product->product_vendor_id;
				if(empty($vid) && !empty($product->parent_vendor_id)) {
					$vid = $product->parent_vendor_id;
				}
				if($vid == 1)
					$vid = 0;
				$pid = (int)$product->product_id;
				foreach($products as $key => &$product) {
					if($product['id'] == $pid)
						$product['vendor'] = $vid;
				}
				unset($product);
			}
		}

		JPluginHelper::importPlugin('hikamarket');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onBeforeProductsVendorAttribution', array(&$products, &$productObjects, &$order));

		foreach($products as $key => &$product) {
			$vid = $product['vendor'];
			if($vid == 1) { $vid = 0; $product['vendor'] = 0; }
			if(empty($vendors[$vid]))
				$vendors[$vid] = array();
			$vendors[$vid][$key] = $key;
		}
		unset($product);

		$config = hikamarket::config();

		$vendorselection_custom_field = $config->get('vendor_select_custom_field', '');
		if(!empty($vendorselection_custom_field) && !empty($vendors[0]) && !empty($order)) {
			$query = 'SELECT field.field_namekey, field.field_table '.
				' FROM ' . hikamarket::table('shop.field') . ' AS field '.
				' WHERE field.field_namekey = '.$this->db->Quote($vendorselection_custom_field).' AND (field.field_table = \'order\' OR field.field_table = \'item\') '.
				' AND field.field_type = \'plg.market_vendorselectfield\' AND field_published = 1 AND field_frontcomp = 1';
			$this->db->setQuery($query);
			$result = $this->db->loadObject();
			if(!empty($result)) {
				if($result->field_table == 'order' && isset($order->$vendorselection_custom_field) && !empty($order->$vendorselection_custom_field)) {
					$query = 'SELECT vendor.vendor_id FROM '.hikamarket::table('vendor').' AS vendor WHERE vendor.vendor_id = '.(int)$order->$vendorselection_custom_field.' AND vendor.vendor_published = 1';
					$this->db->setQuery($query);
					$selected_vendor_id = $this->db->loadResult();
					if(!empty($selected_vendor_id)) {
						$selected_vendor_id = (int)$selected_vendor_id;
						if(empty($vendors[$selected_vendor_id]))
							$vendors[$selected_vendor_id] = array();
						foreach($vendors[0] as $product_cart_id) {
							$vendors[$selected_vendor_id][$product_cart_id] = $product_cart_id;
							$products[$product_cart_id]['vendor'] = $selected_vendor_id;
						}
						$vendors[0] = array();
					}
				}
				$cart_products = isset($order->cart->products) ? $order->cart->products : $order->products;
				if($result->field_table == 'item' && !empty($cart_products)) {
					$affectedProducts = array();

					foreach($cart_products as $order_product) {
						$pcid = 0;
						if(isset($order_product->cart_product_id))
							$pcid = (int)$order_product->cart_product_id;
						else if(isset($order_product->order_product_id))
							$pcid = (int)$order_product->order_product_id;
						$pid = (int)$order_product->product_id;
						if(isset($vendors[0][$pcid]) && isset($order_product->$vendorselection_custom_field)) {
							$vid = (int)$order_product->$vendorselection_custom_field;
							if($vid > 1)
								$affectedProducts[$pcid] = $vid;
						}
					}

					if(!empty($affectedProducts)) {

						$vendor_ids = array();
						foreach($affectedProducts as $pcid => $vendor_id) {
							$vendor_ids[(int)$vendor_id] = (int)$vendor_ids;
						}
						$query = 'SELECT vendor_id, vendor_published, vendor_params FROM ' . hikamarket::table('vendor').' WHERE vendor_id IN ('.implode(',',$vendor_ids).') AND vendor_published = 1';
						$this->db->setQuery($query);
						$valid_vendors = $this->db->loadObjectList('vendor_id');

						foreach($affectedProducts as $pcid => $vendor_id) {
							if(isset($valid_vendors[$vendor_id]) && !empty($valid_vendors[$vendor_id]->vendor_published)) {
								if(is_string($valid_vendors[$vendor_id]->vendor_params))
									$valid_vendors[$vendor_id]->vendor_params = unserialize($valid_vendors[$vendor_id]->vendor_params);
								if(!isset($valid_vendors[$vendor_id]->vendor_params['vendor_selector']) || !empty($valid_vendors[$vendor_id]->vendor_params['vendor_selector'])) {
									if(isset($products[$pcid]) && $products[$pcid]['vendor'] == 0) {
										$products[$pcid]['vendor'] = $vendor_id;
										unset($vendors[0][$pcid]);
										$vendors[$vendor_id][$pcid] = $pcid;
									}
								}
							}
						}
					}
				}
			}
		}

		if($config->get('allow_zone_vendor', 0) && !empty($vendors[0]) && !empty($order)) {
			$zoneClass = hikamarket::get('shop.class.zone');
			$zones = $zoneClass->getOrderZones($order);
			if(count($zones) == 1) {
				$zones = $zoneClass->getZoneParents($zones);
			}
			$zonesQuoted = array();
			foreach($zones as $z) {
				$zonesQuoted[] = $this->db->Quote($z);
			}

			$query = 'SELECT vendor.vendor_id, vendor.vendor_zone_id, zone.zone_namekey, zone.zone_type '.
				' FROM ' . hikamarket::table('vendor') . ' AS vendor '.
				' INNER JOIN ' . hikamarket::table('shop.zone') . ' AS zone ON vendor.vendor_zone_id = zone.zone_id '.
				' WHERE vendor.vendor_zone_id > 0 AND zone.zone_namekey IN ('.implode(',', $zonesQuoted).') ORDER BY vendor.vendor_id ASC';
			$this->db->setQuery($query);
			$zoneVendors = $this->db->loadObjectList('zone_namekey');
			$zone_vendor_id = null;

			if(!empty($zoneVendors)) {
				foreach($zones as $z) {
					if(isset($zoneVendors[$z])) {
						$zone_vendor_id = (int)$zoneVendors[$z]->vendor_id;
						break;
					}
				}
			}

			if(!empty($zone_vendor_id)) {
				if(empty($vendors[$zone_vendor_id]))
					$vendors[$zone_vendor_id] = array();
				foreach($vendors[0] as $k => $p) {
					$vendors[$zone_vendor_id][$k] = $p;
					$products[$k]['vendor'] = $zone_vendor_id;
				}
				$vendors[0] = array();
			}
		}

		$dispatcher->trigger('onAfterProductsVendorAttribution', array(&$vendors, &$products, &$productObjects, &$order));

		return $vendors;
	}

	public function getProductVendorAttribution(&$order) {
		$products = array();
		$cart_products = isset($order->cart->products) ? $order->cart->products : $order->products;
		foreach($cart_products as $product) {
			$products[(int)$product->cart_product_id] = array(
				'_id' => (int)$product->cart_product_id,
				'id' => (int)$product->product_id,
				'vendor' => null
			);
		}
		$this->getVendorsByProducts($products, $order);
		return $products;
	}

	public function calculateVendorPrice($vendor_id, &$v_order, &$products, $coupon) {
		$ret = 0.0;
		$total_qty = 0;
		$config = hikamarket::config();

		if($vendor_id <= 1)
			return 0.0;

		$order_products =& $v_order->cart->products;

		$do = true;
		JPluginHelper::importPlugin('hikamarket');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onBeforeMarketCalculateVendorPrice', array($vendor_id, &$ret, &$order_products, &$products, $coupon, $v_order, &$do));

		if(!$do)
			return $ret;

		$global_fixed_fees = array();

		if(empty($v_order->order_vendor_params))
			$v_order->order_vendor_params = new stdClass();
		if(empty($v_order->order_vendor_params->fees))
			$v_order->order_vendor_params->fees = new stdClass();
		if(empty($v_order->order_vendor_params->fees->rules))
			$v_order->order_vendor_params->fees->rules = array();
		if(empty($v_order->order_vendor_params->fees->fixed))
			$v_order->order_vendor_params->fees->fixed = array();
		if(empty($v_order->order_vendor_params->fees->shipping))
			$v_order->order_vendor_params->fees->shipping = 0.0;

		$total_quantity = 0;
		$total_price = 0.0;
		$total_price_with_tax = 0.0;
		foreach($products as $product) {
			if($product['vendor'] == $vendor_id) {
				$total_quantity += $product['qty'];
				$total_price += $product['price'];
				$total_price_with_tax += $product['price_tax'];
			}
		}
		if($config->get('fee_on_shipping', 0) && !empty($v_order->order_shipping_price)) {
			$total_price += (float)$v_order->order_shipping_price - (float)$v_order->order_shipping_tax;
			$total_price_with_tax += (float)$v_order->order_shipping_price;
		}

		foreach($order_products as &$product) {
			if($product->order_product_quantity == 0)
				continue;

			if($config->get('calculate_vendor_price_with_tax', false))
				$full_price = (float)($product->order_product_price + $product->order_product_tax) * (int)$product->order_product_quantity;
			else
				$full_price = (float)$product->order_product_price * (int)$product->order_product_quantity;

			if(!empty($coupon) && !empty($coupon->products) && empty($coupon->all_products)) {
				foreach($coupon->products as $couponProduct) {
					if($couponProduct->product_id != $product->product_id)
						continue;

					if(isset($couponProduct->processed_discount_value)) {
						$full_price -= floatval($couponProduct->processed_discount_value);
					} elseif(bccomp($coupon->discount_flat_amount, 0, 5) !== 0) {
						$percent = 1.0;
						if(!empty($coupon->products_full_price))
							$percent = floatval($full_price / $coupon->products_full_price);
						$full_price -= floatval($coupon->discount_flat_amount) * $percent;
					} elseif(bccomp($coupon->discount_percent_amount, 0, 5) !== 0) {
						$full_price *= floatval((100 - floatval($coupon->discount_percent_amount)) / 100);
					}
				}
			}

			$pcid = isset($product->cart_product_parent_id) ? $product->cart_product_parent_id : $product->order_product_parent_id;
			if($config->get('calculate_vendor_price_with_tax', false))
				$product->order_product_vendor_price = ($product->order_product_price + $product->order_product_tax);
			else
				$product->order_product_vendor_price = $product->order_product_price;

			$product_fee = false;
			if(isset($products[$pcid]))
				$product_fee = $this->getProductFee($product, $products[$pcid]['fee'], $full_price, $total_price, $total_quantity);

			if(!empty($product_fee)) {
				$products[$pcid]['vendor_fee'] = $product_fee;
				$products[$pcid]['vendor_price'] = $product_fee['vendor'];
				$product->order_product_vendor_price = $product_fee['vendor'];
				$ret += $product_fee['price'];

				foreach($products[$pcid]['fee'] as $fee) {
					if($fee->fee_id == 	$product_fee['id']) {
						$v_order->order_vendor_params->fees->rules[] = $fee;
						break;
					}
				}

				if(!empty($product_fee['fixed'])) {
					if(empty($v_order->order_vendor_params->fees->fixed[ $product_fee['id'] ]))
						$v_order->order_vendor_params->fees->fixed[ $product_fee['id'] ] = $product_fee['fixed'];
				}

				if(substr($product_fee['mode'], -7) == '_global') {
					if(!isset($global_fixed_fees[ $product_fee['id'] ])) {
						$ret -= $product_fee['fixed'];
						$global_fixed_fees[ $product_fee['id'] ] = true;
					}
				} else {
					$ret -= $product_fee['fixed'];
				}
			} else {
				$ret += $product->order_product_vendor_price;
			}
		}
		unset($product);

		if(!empty($coupon) && !empty($coupon->discount_target_vendor) && (int)$coupon->discount_target_vendor > 0 && (empty($coupon->products) || !empty($coupon->all_products))) {
			if(empty($v_order->order_vendor_params->coupon))
				$v_order->order_vendor_params->coupon = new stdClass();

			if(bccomp($coupon->discount_flat_amount, 0, 5) !== 0 && (!isset($coupon->discount_percent_amount_orig) || bccomp($coupon->discount_percent_amount_orig, 0, 5) === 0)) {
				$coupon_percentage = (float)($coupon->vendors[$vendor_id] / $coupon->products_full_price);
				$v_order->order_discount_price = floatval($coupon->discount_flat_amount) * $coupon_percentage;

				$v_order->order_vendor_params->coupon->mode = 'flat';
				$v_order->order_vendor_params->coupon->value = floatval($coupon->discount_flat_amount);
				$v_order->order_vendor_params->coupon->ratio = $coupon_percentage;

				$ret -= floatval($coupon->discount_flat_amount) * $coupon_percentage;

			} elseif(bccomp($coupon->discount_percent_amount, 0, 5) !== 0 || (isset($coupon->discount_percent_amount_orig) && bccomp($coupon->discount_percent_amount_orig, 0, 5) !== 0)) {
				$percent_amount = (float)hikamarket::toFloat($coupon->discount_percent_amount);
				if(empty($percent_amount) && isset($coupon->discount_percent_amount_orig))
					$percent_amount = (float)hikamarket::toFloat($coupon->discount_percent_amount_orig);

				$v_order->order_vendor_params->coupon->mode = 'percent';
				$v_order->order_vendor_params->coupon->value = $percent_amount;
				$v_order->order_vendor_params->coupon->target_vendor = $coupon->discount_target_vendor;

				if($coupon->discount_target_vendor > 1) {
					if($config->get('calculate_vendor_price_with_tax', false))
						$ret -= $v_order->order_discount_price;
					else
						$ret -= $v_order->order_discount_price + $v_order->order_discount_tax;
				} else {
					$ret *= floatval((100 - $percent_amount) / 100);
				}
			}
		}

		if(!empty($v_order->order_payment_price)) {
			$ret += $v_order->order_payment_price;
			if(!$config->get('calculate_vendor_price_with_tax', false))
				$ret -= (float)hikamarket::toFloat($v_order->order_payment_tax);
		}

		if(!empty($v_order->order_shipping_price)) {
			$ret += $v_order->order_shipping_price;
			if(!$config->get('calculate_vendor_price_with_tax', false))
				$ret -= (float)$v_order->order_shipping_tax;

			if($config->get('fee_on_shipping', 0)) {
				$f = false;
				foreach($v_order->order_vendor_params->fees->rules as $rule) {
					if(substr($rule->fee_type, -7) == '_global') {
						$v_order->order_vendor_params->fees->shipping = (float)((100 - (float)$rule->fee_percent) * $v_order->order_shipping_price / 100) - $rule->fee_value;
						$f = true;
						break;
					}
				}

				if(!$f) {
					$feeClass = hikamarket::get('class.fee');
					$vendorFees = $feeClass->getVendor($vendor_id, true);
					foreach($vendorFees as $fee) {
						if((int)$fee->fee_min_quantity > 1)
							continue;
						if((int)$fee->fee_currency_id != $v_order->order_currency_id)
							continue;
						if((float)hikamarket::toFloat($fee->fee_min_price) > $v_order->order_shipping_price)
							continue;

						$v_order->order_vendor_params->fees->shipping = (float)((100 - (float)hikamarket::toFloat($fee->fee_percent)) * $v_order->order_shipping_price / 100) - (float)hikamarket::toFloat($fee->fee_value);
						break;
					}
				}

				if(!empty($v_order->order_vendor_params->fees->shipping)) {
					if($v_order->order_vendor_params->fees->shipping > $v_order->order_shipping_price)
						$v_order->order_vendor_params->fees->shipping = $v_order->order_shipping_price;
					$ret -= $v_order->order_vendor_params->fees->shipping;
				}
			}
		}

		$dispatcher->trigger('onAfterMarketCalculateVendorPrice', array($vendor_id, &$ret, &$order_products, &$products, $coupon, $v_order));

		return $ret;
	}

	private function getProductFee(&$product, &$fees, $full_price, $total_price = 0, $total_quantity = 0) {
		$current_product_qty = (int)$product->order_product_quantity;

		$product_fee = array();
		$modes = array('product','vendor','config');
		$global_modes = array('vendor','config');
		$config = hikamarket::config();
		$price_with_tax = ($config->get('calculate_vendor_price_with_tax', false));

		for($i = 0; $i < count($modes) && empty($product_fee); $i++) {
			$mode = $modes[$i];
			$fee_processing = array(
				'qty' => array(-1,$full_price,-1,0,null),
				'price' => array(-1,$full_price,-1,0,null)
			);
			$mode_gbl = null;
			if(in_array($mode, $global_modes))
				$mode_gbl = $mode.'_global';

			foreach($fees as $fee) {
				if($fee->fee_type != $mode && $fee->fee_type != $mode_gbl)
					continue;

				$global_mode = ($fee->fee_type == $mode_gbl);
				if(empty($fee->fee_min_quantity) && empty($fee->fee_min_price))
					$fee->fee_min_quantity = 1;

				if(
				 (!$global_mode && (($current_product_qty >= $fee->fee_min_quantity || $fee->fee_min_quantity <= 1) && ($product->order_product_price >= $fee->fee_min_price || $fee->fee_min_price == 0)))
				 ||
				 ($global_mode && (($total_quantity >= $fee->fee_min_quantity || $fee->fee_min_quantity <= 1) || ($total_price >= $fee->fee_min_price || $fee->fee_min_price == 0)))
				) {
					$product_full_price = (float)((100 - (float)$fee->fee_percent) * $full_price / 100) - (float)($fee->fee_value * $current_product_qty);
					$product_vendor_unit_price = (float)((100 - (float)$fee->fee_percent) * $product->order_product_vendor_price / 100) - $fee->fee_value;

					if($fee->fee_min_quantity > 0 && $fee->fee_min_quantity > $fee_processing['qty'][0])
						$fee_processing['qty'] = array($fee->fee_min_quantity, $product_full_price, $product_vendor_unit_price, $fee->fee_fixed, $fee->fee_id);
					if($fee->fee_min_price > 0 && $fee->fee_min_price > $fee_processing['price'][0])
						$fee_processing['price'] = array($fee->fee_min_price, $product_full_price, $product_vendor_unit_price, $fee->fee_fixed, $fee->fee_id);
				}
			}

			if($fee_processing['qty'][0] >= 0 || $fee_processing['price'][0] >= 0) {
				if($fee_processing['qty'][0] >= 0 && ($fee_processing['price'][0] < 0 || $fee_processing['qty'][1] > $fee_processing['price'][1])) {
					$product_fee = array(
						'price' => $fee_processing['qty'][1],
						'vendor' => $fee_processing['qty'][2],
						'fixed' => $fee_processing['qty'][3],
						'id' => $fee_processing['qty'][4],
						'type' => 'qty',
						'mode' => $fee->fee_type
					);
				} else {
					$product_fee = array(
						'price' => $fee_processing['price'][1],
						'vendor' => $fee_processing['price'][2],
						'fixed' => $fee_processing['qty'][3],
						'id' => $fee_processing['price'][4],
						'type' => 'price',
						'mode' => $fee->fee_type
					);
				}
			}
		}
		return $product_fee;
	}

	private function recalculateVendorPrice(&$order) {
		if((int)$order->order_vendor_id <= 1)
			return 0.0;

		$config = hikamarket::config();
		$query = 'SELECT * FROM ' . hikamarket::table('shop.order_product') . ' WHERE order_id = ' . $order->order_id;
		$this->db->setQuery($query);
		$order_products = $this->db->loadObjectList();

		$ret = 0.0;
		foreach($order_products as $order_product) {
			$ret += (int)$order_product->order_product_quantity * (float)hikamarket::toFloat($order_product->order_product_vendor_price);
		}

		if(!empty($order->order_payment_price))
			$ret += (float)hikamarket::toFloat($order->order_payment_price);
		if(!empty($order->order_shipping_price))
			$ret += (float)hikamarket::toFloat($order->order_shipping_price);

		if(!empty($order->order_discount_price))
			$ret -= (float)hikamarket::toFloat($order->order_discount_price);

		if($config->get('calculate_vendor_price_with_tax', false)) {
			if(!empty($order->order_discount_tax))
				$ret -= (float)hikamarket::toFloat($order->order_discount_tax);
		} else {
			if(!empty($order->order_shipping_tax))
				$ret -= (float)hikamarket::toFloat($order->order_shipping_tax);
			if(!empty($order->order_payment_tax))
				$ret -= (float)hikamarket::toFloat($order->order_payment_tax);
		}

		$order_vendor_params = is_string($order->order_vendor_params) ? unserialize($order->order_vendor_params) : $order->order_vendor_params;
		if(!empty($order_vendor_params->fees->fixed)) {
			foreach($order_vendor_params->fees->fixed as $fixedFee) {
				$ret -= (float)hikamarket::toFloat($fixedFee);
			}
		}
		if(!empty($order_vendor_params->fees->shipping)) {
			$ret -= (float)hikamarket::toFloat($order_vendor_params->fees->shipping);
		}
		return $ret;
	}

	private function recalculateProductPrice($suborder_product, $order_vendor_params) {
		if(empty($order_vendor_params))
			return false;
		$order_vendor_params = is_string($order_vendor_params) ? unserialize($order_vendor_params) : $order_vendor_params;

		if(empty($order_vendor_params->fees->rules))
			return false;

		foreach($order_vendor_params->fees->rules as $rule) {
			if((int)$rule->product_id == (int)$suborder_product->product_id || (int)$rule->product_parent_id == (int)$suborder_product->product_id) {
				$ret = (float)hikamarket::toFloat($suborder_product->order_product_price);
				$ret = (float)((100 - (float)$rule->fee_percent) * $ret / 100) - $rule->fee_value;

				return $ret;
			}
		}
		return false;
	}

	private function getQuotedObject($obj) {
		$fields = array();
		$values = array();
		foreach(get_object_vars($obj) as $k => $v) {
			if(is_array($v) || is_object($v) || $v === null || $k[0] == '_' )
				continue;
			if(!HIKASHOP_J25) {
				$fields[$k] = $this->db->nameQuote($k);
				$values[$k] = $this->db->isQuoted($k) ? $this->db->Quote($v) : (int)$v ;
			} else {
				$fields[$k] = $this->db->quoteName($k);
				$values[$k] = $this->db->Quote($v);
			}
		}
		return array($fields, $values);
	}

	private function updateVendorRefund(&$subOrder, $vendor_new_total, $order) {
		$config = hikamarket::config();
		$confirmedOrderStatuses = explode(',', $config->get('valid_order_statuses', 'confirmed,shipped'));

		$refundUnpaid = 0;
		$refunds_total = 0.0;
		if(!empty($order->hikamarket->refunds)) {
			foreach($order->hikamarket->refunds as $refund_id => $refund) {
				if($refund->order_vendor_id != $subOrder->order_vendor_id)
					continue;

				if($refund->order_vendor_paid == 0) {
					$refundUnpaid = $refund_id;
				} else {
					$refunds_total += (float)hikamarket::toFloat($subOrder->order_vendor_price);
				}
			}
		}

		if(in_array($order->order_status, $confirmedOrderStatuses)) {
			$refunds_total = $vendor_new_total - $refunds_total - $subOrder->order_vendor_price;
		} else {
			$refunds_total = - $subOrder->order_vendor_price - $refunds_total;
		}

		if($refundUnpaid > 0) {
			$query = 'UPDATE ' . hikamarket::table('shop.order') .
				' SET order_vendor_price = ' . (float)$refunds_total . ', order_status = ' . $this->db->Quote($order->order_status) .
				' WHERE order_id = ' . (int)$refundUnpaid . ' AND order_type = ' . $this->db->Quote('vendorrefund');
		} else {
			$query = 'INSERT INTO ' . hikamarket::table('shop.order') .
				' (order_type, order_vendor_id, order_parent_id, order_status, order_vendor_price, order_currency_id) '.
				' VALUES ('.$this->db->Quote('vendorrefund').','.(int)$subOrder->order_vendor_id.','.(int)$order->order_id.','.$this->db->Quote($order->order_status).','.(float)$refunds_total.','.(int)$order->order_currency_id.')';
		}

		$this->db->setQuery($query);
		$this->db->query();
	}

	private function processShippingParams(&$order, $vendor_id, &$vendor_order, $total_products, $total_products_vendor, $products = null) {
		$config = hikamarket::config();
		$shipping_per_vendor = false;
		$shipping_found = false;

		JPluginHelper::importPlugin('hikamarket');
		$dispatcher = JDispatcher::getInstance();
		$continue = true;
		$dispatcher->trigger('onBeforeMarketProcessShippingParams', array(&$vendor_order, $vendor_id, $total_products, $total_products_vendor, $order, $products, $continue));
		if(!$continue)
			return;

		if($vendor_id == 0)
			$vendor_id = 1;

		if(!empty($order->cart->shipping)) {
			$vendor_order->cart->shipping = array();
			foreach($order->cart->shipping as $shipping) {
				$warehouse = null; $shipping_vendor = null;
				if(!empty($shipping->shipping_warehouse_id) && is_string($shipping->shipping_warehouse_id)) {
					if(strpos($shipping->shipping_warehouse_id, 'v') !== false)
						list($warehouse, $shipping_vendor) = explode('v', $shipping->shipping_warehouse_id, 2);
					else
						$warehouse = $shipping->shipping_warehouse_id;
				}
				if(!empty($shipping->shipping_warehouse_id) && is_array($shipping->shipping_warehouse_id)) {
					$warehouse = $shipping->shipping_warehouse_id[''];
					$shipping_vendor = $shipping->shipping_warehouse_id['v'];
				}

				if($shipping_vendor !== null) {
					if((int)$shipping_vendor == $vendor_id) {
						$vendor_order->order_shipping_price += $shipping->shipping_price_with_tax;
						$vendor_order->order_shipping_tax += $shipping->shipping_price_with_tax - $shipping->shipping_price;
						$shipping_found = true;
					}
					$vendor_order->cart->shipping[] = $shipping;
				} else if($vendor_id == 1 && $shipping_vendor === null) {
					$vendor_order->order_shipping_price += $shipping->shipping_price_with_tax;
					$vendor_order->order_shipping_tax += $shipping->shipping_price_with_tax - $shipping->shipping_price;
					$shipping_found = true;

					$vendor_order->cart->shipping[] = $shipping;
				}

				if(!$shipping_per_vendor && $shipping_vendor !== null)
					$shipping_per_vendor = true;
			}
		} else if(!empty($order->order_shipping_params)) {
			foreach($order->order_shipping_params->prices as $key => $prices) {
				if(strpos($key, 'v') !== false) {
					list($null, $shipping_vendor) = explode('v', $key, 2);
					if((int)$shipping_vendor == $vendor_id) {
						$vendor_order->order_shipping_price += $prices->price_with_tax;
						$vendor_order->order_shipping_tax += $prices->tax;
						$shipping_found = true;
					}
				} else if($vendor_id == 1 && strpos($key, 'v') === false) {
					$vendor_order->order_shipping_price += $prices->price_with_tax;
					$vendor_order->order_shipping_tax += $prices->tax;
					$shipping_found = true;
				}
				if(!$shipping_per_vendor && (
						(is_string($shipping->shipping_warehouse_id) && strpos($shipping->shipping_warehouse_id, 'v') !== false) ||
						(is_array($shipping->shipping_warehouse_id) && isset($shipping->shipping_warehouse_id['v']))
					)
				) {
					$shipping_per_vendor = true;
				}
			}
		}

		if(!empty($vendor_order->order_shipping_price) || $shipping_found) {
			$vendor_order->order_shipping_id = '';
			$order_shipping_id = explode(';', $order->order_shipping_id);
			$order_shipping_vendor = 'v' . $vendor_id;

			foreach($order_shipping_id as $order_shipping) {
				if(($vendor_id == 1 && strpos($order_shipping, 'v') === false) || substr($order_shipping, -strlen($order_shipping_vendor)) == $order_shipping_vendor) {
					if(!empty($vendor_order->order_shipping_id))
						$vendor_order->order_shipping_id .= ';';
					$vendor_order->order_shipping_id .= $order_shipping;
				}
				if(!$shipping_per_vendor && strpos($order_shipping, 'v') !== false)
					$shipping_per_vendor = true;
			}
			if(empty($vendor_order->order_shipping_params))
				$vendor_order->order_shipping_params = new stdClass();
			$vendor_order->order_shipping_params->prices = array();
			foreach($order->order_shipping_params->prices as $k => $v) {
				if(($vendor_id == 1 && strpos($k, 'v') === false) || substr($k, -strlen($order_shipping_vendor)) == $order_shipping_vendor) {
					$vendor_order->order_shipping_params->prices[$k] = $v;
					if(empty($vendor_order->order_shipping_id))
						$vendor_order->order_shipping_id = substr($k, 0, strpos('@', $k));
				}
				if(!$shipping_per_vendor && strpos($k, 'v') !== false)
					$shipping_per_vendor = true;
			}

			if(empty($vendor_order->order_shipping_id) && !empty($vendor_order->cart->shipping)) {
				foreach($vendor_order->cart->shipping as $s) {
					if(!empty($vendor_order->order_shipping_id))
						$vendor_order->order_shipping_id .= ';';
					$vendor_order->order_shipping_id .= $s->shipping_id;
				}
			}
		}

		if(empty($vendor_order->order_shipping_price) && !$shipping_per_vendor && $config->get('split_order_shipping_fees', 0) && !empty($total_products)) {
			$vendor_order->order_shipping_price = $order->order_shipping_price * $total_products_vendor / $total_products;
			$vendor_order->order_shipping_tax = $order->order_shipping_tax * $total_products_vendor / $total_products;
		}

		if(!empty($vendor_order->order_tax_info)) {
			foreach($vendor_order->order_tax_info as $tax_namekey => &$tax) {
				$tax->tax_amount_for_shipping = 0;
			}
			unset($tax);
		}
		if(!empty($vendor_order->order_shipping_params) && !empty($vendor_order->order_shipping_params->prices)) {
			foreach($vendor_order->order_shipping_params->prices as $shipping_price) {
				if(empty($shipping_price->taxes))
					continue;
				foreach($shipping_price->taxes as $tax_namekey => $tax_value) {
					$vendor_order->order_tax_info[$tax_namekey]->tax_amount_for_shipping += $tax_value;
				}
			}
		}

		if(empty($vendor_order->order_shipping_price) && $shipping_per_vendor && !$shipping_found) {
			$vendor_order->order_shipping_id = '';
		}
	}



	public function processView(&$view) {
		$app = JFactory::getApplication();
		$layout = $view->getLayout();

		if($app->isAdmin() && ($layout == 'show' || substr($layout, 0, 5) == 'show_')) {
			$currencyClass = hikamarket::get('shop.class.currency');

			if($view->order->order_type == 'subsale') {
				$order_vendor_params = $view->order->order_vendor_params;
				if(is_string($order_vendor_params) && !empty($order_vendor_params))
					$order_vendor_params = unserialize($view->order->order_vendor_params);
				else
					$order_vendor_params = null;

				if(!empty($view->order->order_vendor_id)) {
					$vendorClass = hikamarket::get('class.vendor');
					$vendor = $vendorClass->get( $view->order->order_vendor_id );

					if(!empty($vendor)) {
						$view->extra_data['general']['order_vendor'] = array(
							'title' => JText::_('HIKA_VENDOR'),
							'data' => '<a href="'.hikamarket::completeLink('vendor&task=edit&cid='.$vendor->vendor_id).'">'.$vendor->vendor_name.'</a>'
						);
					} else {
						$view->extra_data['general']['order_vendor'] = array(
							'title' => JText::_('HIKA_VENDOR'),
							'data' => $view->order->order_vendor_id
						);
					}
				}

				$view->extra_data['general']['order_parent_id'] = array(
					'title' => JText::_('HIKAM_PARENT_ORDER'),
					'data' => $view->order->order_parent_id.' <a href="'.hikamarket::completeLink('shop.order&task=edit&cid='.$view->order->order_parent_id).'"><img style="vertical-align:middle;" src="'.HIKASHOP_IMAGES.'go.png" alt="'.JText::_('GO').'"></a>'
				);

				$fixed_fees = 0.0;
				if(!empty($order_vendor_params->fees->fixed)) {
					foreach($order_vendor_params->fees->fixed as $fixed_fee) {
						$fixed_fees += $fixed_fee;
					}
				}
				$view->extra_data['additional']['vendor_fixed_fees'] = array(
					'title' => JText::_('HIKAM_VENDOR_FIXED_FEES'),
					'data' => $currencyClass->format($fixed_fees, $view->order->order_currency_id)
				);

				if(!empty($order_vendor_params->fees->shipping)) {
					$view->extra_data['additional']['vendor_shipping_fees'] = array(
						'title' => JText::_('HIKAM_VENDOR_SHIPPING_FEES'),
						'data' => $currencyClass->format($order_vendor_params->fees->shipping, $view->order->order_currency_id)
					);
				}

				if($view->order->order_vendor_paid > 0) {
					$query = 'SELECT * '.
						' FROM ' . hikamarket::table('shop.order') .
						' WHERE order_parent_id = ' . $view->order->order_parent_id . ' AND order_type = ' . $this->db->Quote('vendorrefund');
					$this->db->setQuery($query);
					$refunds = $this->db->loadObjectList();
					$total = $view->order->order_vendor_price;
					$paid = $view->order->order_vendor_price;
					if(!empty($refunds)) {
						foreach($refunds as $refund) {
							$total += (float)hikamarket::toFloat($refund->order_vendor_price);
							if($refund->order_vendor_paid > 0)
								$paid += (float)hikamarket::toFloat($refund->order_vendor_price);
						}
					}
					$paidIcon = ' <img src="'.HIKAMARKET_IMAGES.'icon-16/save2.png" style="vertical-align:middle;" alt="('.JText::_('PAID').')" />';
					$unpaidIcon = ' <a href="'.hikamarket::completeLink('vendor&task=pay&cid='.(int)$view->order->order_vendor_id).'"><img src="'.HIKAMARKET_IMAGES.'icon-16/notset.png" style="vertical-align:middle;" alt="('.JText::_('ORDERS_UNPAID').')" /></a>';
					$view->extra_data['additional']['order_vendor_price'] = array(
						'title' => JText::_('VENDOR_TOTAL'),
						'data' => $currencyClass->format($total, $view->order->order_currency_id) . (($paid != $total) ? $unpaidIcon : $paidIcon)
					);
					if($paid != $total) {
						$view->extra_data['additional']['order_vendor_paid'] = array(
							'title' => JText::_('VENDOR_TOTAL_PAID'),
							'data' => $currencyClass->format($paid, $view->order->order_currency_id)
						);
					}
				} else {
					$view->extra_data['additional']['order_vendor_price'] = array(
						'title' => JText::_('VENDOR_TOTAL'),
						'data' => $currencyClass->format($view->order->order_vendor_price, $view->order->order_currency_id)
					);
				}

				$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.vendor_name, hmv.vendor_id '.
					' FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
					' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
					' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
					' WHERE hko.order_type = \'subsale\' AND hko.order_id = '. (int)$view->order->order_id .
					' ORDER BY hko.order_id DESC';
				$this->db->setQuery($query);
				$vendorProducts = $this->db->loadObjectList('order_product_id');

				if(!isset($view->extra_data['products']))
					$view->extra_data['products'] = array();
				$view->extra_data['products']['vendor'] = JText::_('HIKA_VENDOR');
				foreach($view->order->products as &$product) {
					$product->extra_data['vendor'] = '-';
					if(isset($vendorProducts[$product->order_product_id])) {
						$product->extra_data['vendor'] = $currencyClass->format(
							 (float)$vendorProducts[$product->order_product_id]->order_product_vendor_price,
							$view->order->order_currency_id
						);
					}
				}
				unset($product);
				return;
			}

			if($view->order->order_type == 'sale') {
				$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.vendor_name, hmv.vendor_id '.
					' FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
					' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
					' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
					' WHERE hko.order_type = \'subsale\' AND hko.order_parent_id = '. (int)$view->order->order_id .
					' ORDER BY hko.order_id DESC';
				$this->db->setQuery($query);
				$vendorProducts = $this->db->loadObjectList();

				if(!isset($view->extra_data['products']))
					$view->extra_data['products'] = array();
				$view->extra_data['products']['vendor'] = JText::_('HIKA_VENDOR');
				foreach($view->order->products as &$product) {
					$product->extra_data['vendor'] = '-';
					foreach($vendorProducts as $vendorProduct) {
						if((int)$vendorProduct->order_product_parent_id == $product->order_product_id) {
							if((int)$vendorProduct->vendor_id > 1) {
								$product->extra_data['vendor'] = ''.$vendorProduct->vendor_name.'<br/>'.
									$currencyClass->format(
										 (float)$vendorProduct->order_product_vendor_price,
										$view->order->order_currency_id
									);
							}
							break;
						}
					}
				}
				unset($product);
				return;
			}
			return;
		}

		if($app->isAdmin() && $layout == 'edit_products') {
			if(empty($view->extra_data))
				$view->extra_data = array();
			if(empty($view->extra_data['products']))
				$view->extra_data['products'] = array();

			$vendor = null;
			$vendor_price = false;

			if(!empty($view->orderProduct->order_product_id) && (int)$view->orderProduct->order_product_id > 0) {
				if(!empty($view->orderProduct->order_product_parent_id)) {
					$vendor_price = true;
					$vendorProduct = $view->orderProduct;
				} else {
					$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.* FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
						' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
						' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
						' WHERE hko.order_type = \'subsale\' AND order_product_parent_id = '. (int)$view->orderProduct->order_product_id .
						' ORDER BY hko.order_id DESC';
					$this->db->setQuery($query);
					$vendorProduct = $this->db->loadObject();
				}

				if(!empty($vendorProduct) && !empty($vendorProduct->order_vendor_id) && $vendorProduct->order_vendor_id > 1) {
					$vendor = @$vendorProduct->order_vendor_id;
					if(!empty($vendorProduct->vendor_name))
						$vendor .= ' - ' . $vendorProduct->vendor_name;
					$vendor_price = true;
				}
			} else {
				$vendor = '<input type="text" name="data[market][product][order_product_vendor_id]" value=""/>';
				$vendor_price = true;

				if(!empty($view->orderProduct->product_id)) {
					$query = 'SELECT p.product_vendor_id, pp.product_vendor_id AS parent_vendor_id FROM '.hikamarket::table('shop.product').' AS p '.
						' LEFT JOIN '.hikamarket::table('shop.product').' AS pp ON p.product_parent_id = pp.product_id '.
						' WHERE p.product_id = '. (int)$view->orderProduct->product_id;
					$this->db->setQuery($query);
					$productVendor = $this->db->loadObject();
					$vendor_id = 0;
					if(!empty($productVendor->product_vendor_id))
						$vendor_id = (int)$productVendor->product_vendor_id;
					else if(!empty($productVendor->parent_vendor_id))
						$vendor_id = (int)$productVendor->parent_vendor_id;

					if(!empty($vendor_id)) {
						$vendorClass = hikamarket::get('class.vendor');
						$vendorObj = $vendorClass->get($vendor_id);
						$vendor = $vendorObj->vendor_id . ' - ' . $vendorObj->vendor_name . '<input type="hidden" name="data[market][product][order_product_vendor_id]" value="'.$vendorObj->vendor_id.'"/>';
					}

					if(!empty($vendor_id) && empty($vendorProduct->order_product_vendor_price)) {
						$vendor_ids = array((int)$vendorObj->vendor_id => (int)$vendorObj->vendor_id);
						$products = array(
							0 => array(
								'_id' => (int)@$view->orderProduct->order_product_id,
								'id' => (int)$view->orderProduct->product_id,
								'vendor' => (int)$vendorObj->vendor_id,
								'fee' => array(),
								'qty' => (int)$view->orderProduct->order_product_quantity,
								'price' => (float)hikamarket::toFloat($view->orderProduct->order_product_price),
								'price_tax' => (float)hikamarket::toFloat($view->orderProduct->order_product_tax)
							)
						);

						$config = hikamarket::config();
						if($config->get('calculate_vendor_price_with_tax', false))
							$full_price = (float)($products[0]['price'] + $products[0]['price_tax']) * (int)$products[0]['qty'];
						else
							$full_price = (float)$products[0]['price'] * (int)$products[0]['qty'];

						$feeClass = hikamarket::get('class.fee');
						$allFees = $feeClass->getProducts($products, $vendor_ids);
						if($config->get('calculate_vendor_price_with_tax', false))
							$view->orderProduct->order_product_vendor_price = (float)hikamarket::toFloat($view->orderProduct->order_product_price) + (float)hikamarket::toFloat($view->orderProduct->order_product_tax);
						else
							$view->orderProduct->order_product_vendor_price = $view->orderProduct->order_product_price;
						$product_fee = $this->getProductFee($view->orderProduct, $products[0]['fee'], $full_price, $full_price, $products[0]['qty']);

						if(empty($vendorProduct))
							$vendorProduct = new stdClass();
						$vendorProduct->order_product_vendor_price = $product_fee['vendor'];
					}
				}
			}

			if(!empty($vendor)) {
				$view->extra_data['products']['vendor_id'] = array(
					'title' => 'HIKA_VENDOR',
					'data' => $vendor
				);
			}
			if($vendor_price) {
				$view->extra_data['products']['vendor_price'] = array(
					'title' => 'HIKAM_VENDOR_UNIT_PRICE',
					'data' => '<input type="text" name="data[market][product][order_product_vendor_price]" value="'.@$vendorProduct->order_product_vendor_price.'"/>'
				);
			}
			return;
		}

		if($app->isAdmin() && $layout == 'edit_additional') {
			$fixed_fees = 0.0;
			$order_vendor_params = is_string($view->order->order_vendor_params) ? unserialize($view->order->order_vendor_params) : $view->order->order_vendor_params;
			if(!empty($order_vendor_params->fees->fixed)) {
				foreach($order_vendor_params->fees->fixed as $fixed_fee) {
					$fixed_fees += $fixed_fee;
				}
			}
			$view->extra_data['additional']['vendor_fixed_fee'] = array(
				'title' => 'HIKAM_VENDOR_FIXED_FEES',
				'data' => '<input type="text" name="data[market][fixed_fees]" value="'.$fixed_fees.'"/>'
			);
			$view->extra_data['additional']['vendor_shipping_fee'] = array(
				'title' => 'HIKAM_VENDOR_SHIPPING_FEES',
				'data' => '<input type="text" name="data[market][shipping_fees]" value="'.@$order_vendor_params->fees->shipping.'"/>'
			);
			return;
		}

		if(!$app->isAdmin() && $layout == 'show') {
			$config = hikamarket::config();
			$query = 'SELECT o.order_vendor_id FROM ' . hikamarket::table('shop.order') . ' AS o '.
					' WHERE order_type = '. $this->db->Quote('subsale') .' AND order_parent_id = ' . (int)$view->order->order_id .
					' GROUP BY o.order_vendor_id';
			$this->db->setQuery($query);
			if(!HIKASHOP_J25)
				$vendors = $this->db->loadResultArray();
			else
				$vendors = $this->db->loadColumn();

			if(count($vendors) == 1 && (int)$config->get('vendors_in_cart', 0) == 1) {
				$vendorClass = hikamarket::get('class.vendor');
				$fieldsClass = hikamarket::get('shop.class.field');
				$vendor = $vendorClass->get( reset($vendors) );

				$vendorFields = $vendor;
				$extraFields = array(
					'vendor' => $fieldsClass->getFields('frontcomp', $vendorFields, 'plg.hikamarket.vendor')
				);

				$params = null; $js = null;
				$html = hikamarket::getLayout('shop.address', 'address_template', $params, $js);
				foreach($extraFields['vendor'] as $field) {
					$fieldname = $field->field_namekey;
					$html = str_replace('{' . str_replace('vendor_', '', $fieldname) . '}', $fieldsClass->show($field, $vendor->$fieldname), $html);
				}
				$view->store_address =  str_replace("\n","<br/>\n",trim(str_replace("\n\n","\n",preg_replace('#{(?:(?!}).)*}#i','',$html)),"\n"));
			} else if(count($vendors) > 0 && (int)$config->get('show_sold_by', 0) == 1) {
				$query = 'SELECT hkop.*, hko.order_vendor_id, hmv.vendor_name, hmv.vendor_id '.
					' FROM ' . hikamarket::table('shop.order_product') . ' as hkop '.
					' INNER JOIN ' . hikamarket::table('shop.order'). ' AS hko ON hkop.order_id = hko.order_id '.
					' LEFT JOIN ' . hikamarket::table('vendor'). ' AS hmv ON hmv.vendor_id = hko.order_vendor_id '.
					' WHERE hko.order_type = \'subsale\' AND hko.order_parent_id = '. (int)$view->order->order_id .
					' ORDER BY hko.order_id DESC';
				$this->db->setQuery($query);
				$vendorProducts = $this->db->loadObjectList();

				foreach($view->order->products as &$product) {
					foreach($vendorProducts as $vendorProduct) {
						if((int)$vendorProduct->order_product_parent_id != $product->order_product_id)
							continue;

						if((int)$vendorProduct->vendor_id <= 1)
							break;

						if(empty($product->extraData))
							$product->extraData = array();
						$product->extraData['vendor'] = '<span class="order_product_vendor">'.JText::sprintf('SOLD_BY_VENDOR', $vendorProduct->vendor_name).'</span>';
						break;
					}
				}
				unset($product);
			}
		}

	}

	private function processForm(&$order, &$do, $from = 'order') {
		$order_id = hikamarket::getCID('order_id');
		$task = JRequest::getVar('subtask', '');
		$data = JRequest::getVar('data', array(), '', 'array');

		if($task == 'products' && $from == 'order')
			return;

		if($task == 'products' && isset($data['market']['product']['order_product_vendor_price'])) {
			$product_id = (int)$data['order']['product']['product_id'];
			$order_product_id = null;
			if(isset($data['order']['product']['order_product_id']))
				$order_product_id = (int)$data['order']['product']['order_product_id'];
			$order_product_vendor_price = trim($data['market']['product']['order_product_vendor_price']);
			$order_product_vendor_id = null;
			if(isset($data['market']['product']['order_product_vendor_id']))
				$order_product_vendor_id = (int)trim($data['market']['product']['order_product_vendor_id']);
			$order_product_quantity = null;
			if(isset($data['order']['product']['order_product_quantity']))
				$order_product_quantity = (int)trim($data['order']['product']['order_product_quantity']);

			if(empty($order->hikamarket))
				$order->hikamarket = new stdClass();
			$order->hikamarket->products = array(
				'product_id' => $product_id,
				'order_product_id' => $order_product_id,
				'vendor_id' => $order_product_vendor_id,
				'vendor_price' => $order_product_vendor_price,
			);
			if($order_product_quantity !== null)
				$order->hikamarket->products['order_product_quantity'] = $order_product_quantity;
		}

		if($task == 'additional' && isset($data['market']['fixed_fees'])) {
			$order_vendor_params = is_string($order->order_vendor_params) ? unserialize($order->order_vendor_params) : $order->order_vendor_params;

			if(!isset($order_vendor_params->fees)) $order_vendor_params->fees = new stdClass();
			$order_vendor_params->fees->fixed = array(
				0 => (float)hikamarket::toFloat($data['market']['fixed_fees'])
			);
			if(isset($data['market']['shipping_fees']))
				$order_vendor_params->fees->shipping = (float)hikamarket::toFloat($data['market']['shipping_fees']);
			$order->order_vendor_params = $order_vendor_params;

			$vendor_new_total = $this->recalculateVendorPrice($order);

			if(is_string($order->order_payment_params) && !empty($order->order_payment_params))
				$order->order_payment_params = unserialize($order->order_payment_params);
			$feeMode = true;
			if(isset($order->order_payment_params->market_mode))
				$feeMode = $order->order_payment_params->market_mode;

			if(!$feeMode) {
				$config = hikamarket::config();
				if($config->get('shipping_per_vendor', 1) && !empty($order->order_shipping_price))
					$vendor_new_total = $vendor_new_total - $order->order_full_price;
				else
					$vendor_new_total = $vendor_new_total - $order->order_full_price - (float)$order->order_shipping_price;
			}

			if($vendor_new_total != $order->order_vendor_price || $order->order_vendor_paid > 0) {
				if($order->order_vendor_paid > 0) {
					$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS a WHERE order_type = '. $this->db->Quote('sale') .' AND order_id = ' . (int)$order->order_parent_id;
					$this->db->setQuery($query);
					$parentOrder = $this->db->loadObject();
					$parentOrder->hikamarket = new stdClass();

					$query = 'SELECT * FROM ' . hikamarket::table('shop.order') . ' AS a WHERE order_type = '. $this->db->Quote('vendorrefund') .' AND order_parent_id = ' . (int)$parentOrder->order_id;
					$this->db->setQuery($query);
					$parentOrder->hikamarket->refunds = $this->db->loadObjectList('order_id');

					$this->updateVendorRefund($order, $vendor_new_total, $parentOrder);
				} else {
					$order->order_vendor_price = $vendor_new_total;
				}
			}

			if(!empty($order->order_payment_params) && !is_string($order->order_payment_params))
				$order->order_payment_params = serialize($order->order_payment_params);

			$order->order_vendor_params = serialize($order->order_vendor_params);
		}
	}
}
