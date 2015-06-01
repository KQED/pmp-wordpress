<div class="wrap">
<h2>Manage saved searches</h2>

	<div id="pmp-saved-searches-list">
		<?php foreach ($searches as $id => $search) { ?>
			<div class="pmp-saved-search">
				<h3 class="pmp-saved-search-title"><?php echo $search->options->title; ?></h3>
				<div class="pmp-saved-search-details">
				<?php if (!empty($search->options->query_auto_create)) {
					if ($search->options->query_auto_create == 'draft') { ?>
						<p>Draft posts will be automatically created from results for this query.</p>
					<?php } else { ?>
						<p>Posts will be automatically published from results for this query.</p>
					<?php }
				} else { ?>
					<p>Do nothing with results for this query.</p>
				<?php } ?>
				</div>
				<div class="pmp-saved-search-actions">
				<a href="<?php echo admin_url('admin.php?page=pmp-search&search_id=' . $id); ?>">See results</a> | <a href="#">Edit</a> |  <a href="#">Delete</a>
				</div>
			</div>
		<?php } ?>
	</div>
</div>

<?php pmp_save_search_query_template(); ?>

<script type="text/javascript">
	var PMP = <?php echo json_encode($PMP); ?>;
</script>
