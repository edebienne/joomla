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
class orderMarketController extends hikamarketController {
	protected $rights = array(
		'display' => array('listing','show','invoice','mail','status'),
		'add' => array('add'),
		'edit' => array('edit', 'edit_additional', 'product_add', 'product_delete', 'customer_set', 'export'),
		'modify' => array('apply','save','customer_save','sendmail'),
		'delete' => array(),
	);

	protected $subtasks = array(
		'customer',
		'billing_address',
		'shipping_address',
		'products',
		'additional',
		'general',
		'history'
	);

	protected $popupSubtasks = array(
		'additional',
		'products'
	);

	public function __construct($config = array(), $skip = false) {
		parent::__construct($config, $skip);
		if(!$skip)
			$this->registerDefaultTask('listing');
	}

	public function listing() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/listing'))
			return hikamarket::deny('vendor', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_LISTING')));
		JRequest::setVar('layout', 'listing');
		return parent::display();
	}

	public function save() {
		$status = $this->store();
		$tmpl = JRequest::getVar('tmpl', '');

		if($tmpl == 'component' && JRequest::getInt('closepopup', 0)) {
			if(!empty($status)) {
				$orderClass = hikamarket::get('class.order');
				$order = $orderClass->getRaw((int)$status);
				echo '<html><body>'.
					'<script type="text/javascript">'."\r\n".
					'window.parent.hikamarket.submitBox('.json_encode(array(
						'order_status' => $order->order_status,
						'name' => hikamarket::orderStatus($order->order_status)
					)).');'."\r\n".
					'</script>'."\r\n".
					'</body></html>';
				exit;
			} else
				return '';
		}

		if($tmpl == 'component')
			return $this->show();
		return $this->listing();
	}

	public function add() {
		if( !hikamarket::loginVendor() )
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if( !hikamarket::acl('order/add') )
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$app = JFactory::getApplication();
		$emptyOrder = new stdClass();
		$orderClass = hikamarket::get('shop.class.order');
		$orderClass->sendEmailAfterOrderCreation = false;
		if($orderClass->save($emptyOrder)) {
			$app->redirect( hikamarket::completeLink('order&task=show&cid=' . $emptyOrder->order_id ) );
		} else {
			$app->enqueueMessage(JText::_('HIKAM_ERR_ORDER_CREATION'));
			$app->redirect( hikamarket::completeLink('order') );
		}
	}

	public function store() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		$task = JRequest::getVar('subtask', '');
		if(!in_array($task, $this->subtasks))
			return false;
		if(!hikamarket::acl('order/edit/'.$task))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$class = hikamarket::get('class.order');
		if( $class === null )
			return false;
		$status = $class->frontSaveForm($task);
		if($status) {
			JRequest::setVar('cid', $status);
			JRequest::setVar('fail', null);
		}
		return $status;
	}

	public function show() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		$task = JRequest::getVar('subtask', '');
		if(!empty($task) && !in_array($task, $this->subtasks))
			return false;
		if(!hikamarket::acl('order/show'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_SHOW')));

		$order_id = hikamarket::getCID('order_id');
		if(!hikamarket::isVendorOrder($order_id))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_SHOW')));

		$vendor_id = hikamarket::loadVendor(false);

		JRequest::setVar('layout', 'show');
		if($vendor_id > 1 && !$config->get('order_vendor_edition_legacy', 0))
			JRequest::setVar('layout', 'show_vendor');
		else if(!empty($task))
			JRequest::setVar('layout', 'show_'.$task);

		$tmpl = JRequest::getVar('tmpl', '');
		if($tmpl == 'component') {
			ob_end_clean();
			parent::display();
			exit;
		}
		return parent::display();
	}

	public function status() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/general'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_MAIL')));

		$order_id = hikamarket::getCID('order_id');
		if(!hikamarket::isVendorOrder($order_id))
			return false;

		JRequest::setVar('layout', 'status');
		return parent::display();
	}

	public function invoice() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/show'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_SHOW')));

		$order_id = hikamarket::getCID('order_id');
		if(!hikamarket::isVendorOrder($order_id))
			return false;

		JRequest::setVar('layout', 'invoice');

		return parent::display();
	}

	public function export() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/export'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EXPORT')));

		JRequest::setVar('layout', 'export_show');

		$formData = JRequest::getVar('data', array(), '', 'array');
		if(!empty($formData)) {
			if(!JRequest::checkToken()) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(JText::_('INVALID_TOKEN'), 'error');
			} else {
				JRequest::setVar('layout', 'export');
			}
		}
		return parent::display();
	}

	public function mail() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/mail'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_MAIL')));

		$order_id = hikamarket::getCID('order_id');
		if(!hikamarket::isVendorOrder($order_id))
			return false;

		JRequest::setVar('layout', 'mail');
		return parent::display();
	}

	public function sendmail() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/mail'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_MAIL')));

		$element = new stdClass();
		$formData = JRequest::getVar('data', array(), '', 'array');
		$old = null;

		foreach($formData['order'] as $column => $value) {
			hikamarket::secureField($column);
			if(in_array($column, array('history', 'mail'))) {
				$element->$column = new stdClass();
				foreach($value as $k => $v) {
					$k = hikamarket::secureField($k);
					$element->$column->$k = strip_tags($v);
				}
			} else {
				if(is_array($value)) {
					$value = implode(',',$value);
				}
				$element->$column = strip_tags($value);
			}
		}
		if(!isset($element->mail))
			$element->mail = new stdClass();
		$element->mail->body = JRequest::getVar('hikamarket_mail_body','','','string',JREQUEST_ALLOWRAW);
		$element->mail->mail_name = 'order_status_notification';

		$vendor = hikamarket::loadVendor(true);
		if($vendor->vendor_id > 1 ) {
			$element->mail->from_email = $vendor->vendor_email;
			$element->mail->from_name = $vendor->vendor_name;
		}

		$mailClass = hikamarket::get('shop.class.mail');
		$mailClass->sendMail($element->mail);

		if(!$mailClass->mail_success) {
			JRequest::setVar('layout', 'mail');
			return parent::display();
		}

		hikamarket::headerNoCache();
		echo '<html><head><script type="text/javascript">window.parent.hikamarket.submitBox();</script></head><body></body></html>';
		exit;
	}

	private function show_products() {
		$tmpl = JRequest::getVar('tmpl', '');
		if($tmpl == 'component') {
			JRequest::setVar('layout', 'show_products');
			ob_end_clean();
			parent::display();
			exit;
		}
		JRequest::setVar('layout', 'show');
		return parent::display();
	}

	public function edit() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		$task = JRequest::getVar('subtask', '');
		if(!in_array($task, $this->subtasks)) {
			$tmpl = JRequest::getVar('tmpl', '');
			if($tmpl == 'component') {
				exit;
			}
			return false;
		}
		if(!hikamarket::acl('order/edit/'.$task))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		JRequest::setVar('layout', 'show_'.$task);

		$order_id = hikamarket::getCID('order_id');
		if(!hikamarket::isVendorOrder($order_id))
			return false;

		if(!in_array($task , $this->popupSubtasks)) {
			$tmpl = JRequest::getVar('tmpl', '');
			if($tmpl == 'component') {
				ob_end_clean();
				parent::display();
				exit;
			}
		} else {
			JRequest::setVar('layout', 'edit_'.$task);
		}
		return parent::display();
	}

	public function customer_save() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/customer'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$class = hikamarket::get('class.order');
		if( $class === null )
			return false;
		$status = $class->frontSaveForm('customer');
		if($status) {
			JRequest::setVar('cid', $status);
			JRequest::setVar('fail', null);
		}

		$tmpl = JRequest::getVar('tmpl', '');
		if($tmpl == 'component') {
			ob_end_clean();
			JRequest::setVar('layout', 'customer_set');
			return parent::display();
		}
		return $this->show();
	}

	public function customer_set() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/customer'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$order_id = JRequest::getInt('order_id', 0);
		if(!hikamarket::isVendorOrder($order_id))
			return false;

		JRequest::setVar('layout', 'customer_set');
		return parent::display();
	}

	public function product_add() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/products'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$formData = JRequest::getVar('data', array(), '', 'array');
		$product_quantity = -1;
		if(isset($formData['order']) && isset($formData['order']['product']['order_product_quantity']))
			$product_quantity = (int)$formData['order']['product']['order_product_quantity'];

		if($product_quantity >= 0) {
			if(!JRequest::checkToken())
				return false;

			$orderClass = hikamarket::get('class.order');
			if( $orderClass === null )
				return false;
			$status = $orderClass->saveForm('product');
			if($status) {
				JRequest::setVar('cid', $status);
				JRequest::setVar('fail', null);
			}
		} else {
			JRequest::setVar('layout', 'edit_products');
			return parent::display();
		}

		return $this->show_products();
	}

	public function product_delete() {
		if(!hikamarket::loginVendor())
			return false;
		$config = hikamarket::config();
		if( !$config->get('frontend_edition',0) )
			return false;
		if(!hikamarket::acl('order/edit/products'))
			return hikamarket::deny('order', JText::sprintf('HIKAM_ACTION_DENY', JText::_('HIKAM_ACT_ORDER_EDIT')));

		$orderClass = hikamarket::get('class.order');
		if( $orderClass === null )
			return false;
		$status = $orderClass->frontSaveForm('product_delete');
		if($status) {
			JRequest::setVar('cid', $status);
			JRequest::setVar('fail', null);
		}

		$tmpl = JRequest::getVar('tmpl', '');
		if($tmpl == 'component')
			return $this->show_products();
		return $this->show();
	}
}
