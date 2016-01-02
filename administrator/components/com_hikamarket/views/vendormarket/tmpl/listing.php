<?php
/**
 * @package    HikaMarket for Joomla!
 * @version    1.6.7
 * @author     Obsidev S.A.R.L.
 * @copyright  (C) 2011-2015 OBSIDEV. All rights reserved.
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');
?><div class="iframedoc" id="iframedoc"></div>
<form action="<?php echo hikamarket::completeLink('vendor'); ?>" method="post" name="adminForm" id="adminForm">
<?php if(HIKASHOP_BACK_RESPONSIVE) { ?>
	<div class="row-fluid">
		<div class="span6">
			<div class="input-prepend input-append">
				<span class="add-on"><i class="icon-filter"></i></span>
				<input type="text" name="search" id="search" value="<?php echo $this->escape($this->pageInfo->search);?>" onchange="this.form.submit();" />
				<button class="btn" onclick="this.form.limitstart.value=0;this.form.submit();"><i class="icon-search"></i></button>
				<button class="btn" onclick="this.form.limitstart.value=0;document.getElementById('search').value='';this.form.submit();"><i class="icon-remove"></i></button>
			</div>
		</div>
		<div class="span6">
<?php } else { ?>
	<table>
		<tr>
			<td width="100%">
				<?php echo JText::_('FILTER'); ?>:
				<input type="text" name="search" id="search" value="<?php echo $this->escape($this->pageInfo->search);?>" class="text_area" onchange="this.form.submit();" />
				<button class="btn" onclick="this.form.submit();"><?php echo JText::_('GO'); ?></button>
				<button class="btn" onclick="document.getElementById('search').value='';this.form.submit();"><?php echo JText::_('RESET'); ?></button>
			</td>
			<td nowrap="nowrap">
<?php } ?>
				<!-- Filters -->
<?php
	$values = array(
		JHTML::_('select.option', 0, JText::_('ALL_VENDORS')),
		JHTML::_('select.option', 1, JText::_('HIKAM_VENDORS_WITH_UNPAID_ORDERS')),
	);
	echo JHTML::_('select.genericlist', $values, 'filter_vendors_unpaid', ' onchange="this.form.submit();"', 'value', 'text', @$this->pageInfo->filter->vendors_unpaid);
?>
<?php if(HIKASHOP_BACK_RESPONSIVE) { ?>
		</div>
	</div>
<?php } else { ?>
			</td>
		</tr>
	</table>
<?php } ?>
	<table class="adminlist pad5 table table-striped table-hover">
		<thead>
			<tr>
				<th class="hikamarket_vendor_num_title title titlenum"><?php echo JText::_( 'HIKA_NUM' );?></th>
				<th class="hikamarket_vendor_select_title title titlebox">
					<input type="checkbox" name="toggle" value="" onclick="hikashop.checkAll(this);" />
				</th>
				<th class="hikamarket_vendor_name_title title"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_NAME'), 'a.vendor_name', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value);
				?></th>
				<th class="hikamarket_vendor_email_title title"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_EMAIL'), 'a.vendor_email', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value);
				?></th>
				<th class="title titletoggle"><?php
					if($this->config->get('market_mode','fee') == 'fee') {
						echo JText::_('ORDERS_UNPAID');
					} else {
						echo JText::_('ORDERS_UNINVOICED');
					}
				?></th>
				<th class="title titletoggle"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_PUBLISHED'), 'a.vendor_published', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value);
				?></th>
				<th class="hikamarket_vendor_id_title title"><?php
					echo JHTML::_('grid.sort', JText::_('ID'), 'a.vendor_id', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value);
				?></th>
			</tr>
		</thead>
		<tfoot>
			<tr>
				<td colspan="7">
					<?php echo $this->pagination->getListFooter(); ?>
					<?php echo $this->pagination->getResultsCounter(); ?>
				</td>
			</tr>
		</tfoot>
		<tbody>
<?php
$myUrl = urlencode(base64_encode(hikamarket::completeLink('vendor', false, true, true)));
$k = 0;
for($i = 0,$a = count($this->rows); $i < $a; $i++) {
	$row =& $this->rows[$i];
?>
		<tr class="row<?php echo $k; ?>">
			<td class="hikamarket_vendor_num_value" align="center"><?php
				echo $this->pagination->getRowOffset($i);
			?></td>
			<td class="hikamarket_vendor_select_value"><?php
				echo JHTML::_('grid.id', $i, $row->vendor_id );
			?></td>
			<td class="hikamarket_vendor_name_value">
				<a href="<?php echo hikamarket::completeLink('vendor&task=edit&cid[]='.$row->vendor_id.'&cancel_redirect='.$myUrl); ?>"><?php echo $this->escape($row->vendor_name); ?></a>
			</td>
			<td class="hikamarket_vendor_email_value"><?php
				echo $this->escape($row->vendor_email);
			?></td>
			<td class="hikamarket_vendor_unpaid_value"><?php
				if($this->config->get('market_mode','fee') == 'fee') {
					echo $row->number_unpaid . ' - ' . $this->currencyClass->format($row->price_unpaid, $row->vendor_currency_id);
				} else {
					echo $row->number_unpaid . ' - ' . $this->currencyClass->format($row->price_full - $row->price_unpaid, $row->vendor_currency_id);
				}
			?></td>
			<td class="hikamarket_vendor_publish_value" align="center"><?php
				$publishedid = 'vendor_published-'.$row->vendor_id;
				if($this->manage) {
					echo $this->toggleClass->toggle($publishedid, (int)$row->vendor_published, 'vendor');
				} else {
					echo $this->toggleClass->display('activate', $row->vendor_published);
				}
			?></td>
			<td class="hikamarket_vendor_id_value" align="center"><?php
				echo (int)$row->vendor_id;
			?></td>
		</tr>
<?php
	$k = 1 - $k;
}
?>
		</tbody>
	</table>
	<input type="hidden" name="option" value="<?php echo HIKAMARKET_COMPONENT; ?>" />
	<input type="hidden" name="task" value="<?php echo @$this->task; ?>" />
	<input type="hidden" name="ctrl" value="<?php echo JRequest::getCmd('ctrl'); ?>" />
	<input type="hidden" name="boxchecked" value="0" />
	<input type="hidden" name="filter_order" value="<?php echo $this->pageInfo->filter->order->value; ?>" />
	<input type="hidden" name="filter_order_Dir" value="<?php echo $this->pageInfo->filter->order->dir; ?>" />
	<?php echo JHTML::_( 'form.token' ); ?>
</form>
