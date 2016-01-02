<?php
/**
 * @package	HikaShop for Joomla!
 * @version	2.6.0
 * @author	hikashop.com
 * @copyright	(C) 2010-2015 HIKARI SOFTWARE. All rights reserved.
 * @license	GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php

class plgHikashopEmail_history extends JPlugin
{

	function __construct(&$subject, $config){
		parent::__construct($subject, $config);
	}

	function onBeforeMailSend(&$mail,&$mailer){
		$data= new stdClass();

		$data->email_log_sender_email = strip_tags($mail->from_email);
		$data->email_log_sender_name = strip_tags($mail->from_name);

		if(is_array($mail->dst_email))
			$data->email_log_recipient_email = strip_tags(implode(',', $mail->dst_email));
		else
			$data->email_log_recipient_email = strip_tags($mail->dst_email);

		if(isset($mail->dst_name))
			$data->email_log_recipient_name = strip_tags($mail->dst_name);

		$data->email_log_reply_email = strip_tags($mail->reply_email);
		$data->email_log_reply_name = strip_tags($mail->reply_name);
		if(is_array($mail->cc_email))
			$data->email_log_cc_email = strip_tags(implode(',', $mail->cc_email));
		else
			$data->email_log_cc_email = strip_tags($mail->cc_email);
		if(is_array($mail->bcc_email))
			$data->email_log_bcc_email = strip_tags(implode(',', $mail->bcc_email));
		else
			$data->email_log_bcc_email = strip_tags($mail->bcc_email);
		$data->email_log_subject = strip_tags($mail->subject);
		$data->email_log_altbody = strip_tags($mail->altbody);
		$data->email_log_body = $mail->body;
		if(!isset($mail->email_log_published)){
			$config =& hikashop_config();
			$mail->email_log_published = $config->get($mail->mail_name.'.email_log_published',1);
		}
		$data->email_log_published = $mail->email_log_published;
		$data->email_log_date = time();
		$data->email_log_name = $mail->mail_name;
		switch($mail->mail_name){
			case 'user_account':
				$data->email_log_ref_id = $mail->data->user_data->user_id;
				break;
			case 'user_account_admin_notification':
				$data->email_log_ref_id = $mail->data->user_data->user_id;
				break;
			case 'order_notification':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			case 'order_admin_notification':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			case 'order_creation_notification':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			case 'order_status_notification':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			case 'payment_notification':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			case 'contact_request':
				$data->email_log_ref_id = $mail->data->product->product_id;
				break;
			case 'new_comment':
				$data->email_log_ref_id = $mail->data->type->product_id;
				break;
			case 'order_cancel':
				$data->email_log_ref_id = $mail->data->order_id;
				break;
			default:
				break;
		}

		$class = hikashop_get('class.email_log');
		$class->save($data);
	}
}
