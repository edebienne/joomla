<?php
/**
 * @package    HikaMarket for Joomla!
 * @version    1.6.7
 * @author     Obsidev S.A.R.L.
 * @copyright  (C) 2011-2015 OBSIDEV. All rights reserved.
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php if( !$this->singleSelection ) { ?>
<fieldset>
	<div class="toolbar" id="toolbar" style="float: right;">
		<button class="btn" type="button" onclick="hikamarket_setId(this);"><img src="<?php echo HIKAMARKET_IMAGES; ?>icon-16/add.png"/><?php echo JText::_('OK'); ?></button>
	</div>
<script type="text/javascript">
function hikamarket_setId(el) {
	if(document.hikamarket_form.boxchecked.value==0){
		alert('<?php echo JText::_('PLEASE_SELECT_SOMETHING', true); ?>');
	}else{
		el.form.ctrl.value = '<?php echo $this->ctrl ?>';
		hikamarket.submitform("<?php echo $this->task; ?>",el.form);
	}
}
</script>
</fieldset>
<?php } else { ?>
<script type="text/javascript">
function hikamarket_setId(id) {
	var form = document.getElementById("hikamarket_form");
	form.cid.value = id;
	form.ctrl.value = '<?php echo $this->ctrl ?>';
	hikamarket.submitform("<?php echo $this->task; ?>",form);
}
</script>
<?php } ?>
<form action="<?php echo hikamarket::completeLink(JRequest::getCmd('ctrl')) ;?>" method="post" name="hikamarket_form" id="hikamarket_form">
	<table class="hikam_filter">
		<tr>
			<td width="100%">
				<?php echo JText::_('FILTER'); ?>:
				<input type="text" id="hikamarket_user_search" name="search" value="<?php echo $this->escape($this->pageInfo->search);?>" onchange="this.form.submit();" />
				<button class="btn" onclick="this.form.submit();"><?php echo JText::_('GO'); ?></button>
				<button class="btn" onclick="document.getElementById('hikamarket_user_search').value='';this.form.submit();"><?php echo JText::_('RESET'); ?></button>
			</td>
		</tr>
	</table>
	<table class="hikam_listing <?php echo (HIKASHOP_RESPONSIVE)?'table table-striped table-hover':'hikam_table'; ?>" style="cell-spacing:1px">
		<thead>
			<tr>
				<th class="title titlenum">
					<?php echo JText::_( 'HIKA_NUM' );?>
				</th>
<?php if( !$this->singleSelection ) { ?>
				<th class="title titlebox">
					<input type="checkbox" name="toggle" value="" onclick="checkAll(this);" />
				</th>
<?php } ?>
				<th class="title"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_LOGIN'), 'juser.username', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value );
				?></th>
				<th class="title"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_NAME'), 'juser.name', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value );
				?></th>
				<th class="title"><?php
					echo JHTML::_('grid.sort', JText::_('HIKA_EMAIL'), 'hkuser.user_email', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value );
				?></th>
				<th class="title"><?php
					echo JHTML::_('grid.sort', JText::_('ID'), 'hkuser.user_id', $this->pageInfo->filter->order->dir, $this->pageInfo->filter->order->value );
				?></th>
			</tr>
		</thead>
		<tfoot>
			<tr>
				<td colspan="10">
					<?php echo $this->pagination->getListFooter(); ?>
					<?php echo $this->pagination->getResultsCounter(); ?>
				</td>
			</tr>
		</tfoot>
		<tbody>
<?php
	$k = 0;
	foreach($this->rows as $i => $row) {

		$lbl1 = ''; $lbl2 = '';
		$extraTr = '';
		if( $this->singleSelection ) {
			if($this->confirm) {
				$data = '{id:'.$row->user_id;
				foreach($this->elemStruct as $s) {
					if($s == 'id')
						continue;
					$data .= ','.$s.':\''. str_replace(array('\'','"'),array('\\\'','\\\''),$row->$s).'\'';
				}
				$data .= '}';
				$extraTr = ' style="cursor:pointer" onclick="window.top.hikamarket.submitBox('.$data.');"';
			} else {
				$extraTr = ' style="cursor:pointer" onclick="hikamarket_setId(\''.$row->user_id.'\');"';
			}
		} else {
			$lbl1 = '<label for="cb'.$i.'">';
			$lbl2 = '</label>';
			$extraTr = ' onclick="hikamarket.checkRow(\'cb'.$i.'\');"';
		}

		if(!empty($this->pageInfo->search)) {
			$row = hikamarket::search($this->pageInfo->search, $row, 'user_id');
		}
?>
			<tr class="row<?php echo $k; ?>"<?php echo $extraTr; ?>>
				<td align="center">
					<?php echo $this->pagination->getRowOffset($i); ?>
				</td>
<?php if( !$this->singleSelection ) { ?>
				<td align="center">
					<?php echo JHTML::_('grid.id', $i, $row->user_id ); ?>
				</td>
<?php } ?>
				<td>
					<?php echo $lbl1 . $row->username . $lbl2; ?>
				</td>
				<td>
					<?php echo $lbl1 . $row->name . $lbl2; ?>
				</td>
				<td>
					<?php echo $lbl1 . $row->user_email . $lbl2; ?>
				</td>
				<td width="1%" align="center">
					<?php echo $row->user_id; ?>
				</td>
			</tr>
<?php
		$k = 1-$k;
	}
?>
		</tbody>
	</table>
<?php if( $this->singleSelection ) { ?>
	<input type="hidden" name="cid" value="0" />
<?php } ?>
	<input type="hidden" name="option" value="<?php echo HIKAMARKET_COMPONENT; ?>" />
	<input type="hidden" name="task" value="selection" />
	<input type="hidden" name="tmpl" value="component" />
	<input type="hidden" name="confirm" value="<?php echo $this->confirm ? '1' : '0'; ?>" />
	<input type="hidden" name="single" value="<?php echo $this->singleSelection ? '1' : '0'; ?>" />
	<input type="hidden" name="ctrl" value="<?php echo JRequest::getCmd('ctrl'); ?>" />
	<input type="hidden" name="boxchecked" value="0" />
	<input type="hidden" name="filter_order" value="<?php echo $this->pageInfo->filter->order->value; ?>" />
	<input type="hidden" name="filter_order_Dir" value="<?php echo $this->pageInfo->filter->order->dir; ?>" />
<?php
	if(!empty($this->afterParams)) {
		foreach($this->afterParams as $p) {
			if(empty($p[0]) || !isset($p[1]))
				continue;
			echo '<input type="hidden" name="'.$this->escape($p[0]).'" value="'.$this->escape($p[1]).'"/>' . "\r\n";
		}
	}
?>
	<?php echo JHTML::_('form.token'); ?>
</form>
<script type="text/javascript">
document.adminForm = document.getElementById("hikamarket_form");
</script>
