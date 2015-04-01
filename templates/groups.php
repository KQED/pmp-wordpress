<div class="wrap">
	<h2>PMP Groups &amp; Permissions</h2>

	<div id="pmp-groups-actions">
		<p class="submit">
			<input type="submit" name="pmp-create-group" id="pmp-create-group" class="button button-primary" value="Create new group">
		</p>
	</div>

	<div id="pmp-groups">
	<?php foreach ($groups as $group) { ?>
		<div><?php echo $group->attributes->title; ?></div>
	<?php } ?>
	</div>
</div>

<?php pmp_modal_underscore_template(); ?>

<script type="text/template" id="pmp-create-new-group-form-tmpl">
	<h2>Create a group</h2>
	<form id="pmp-group-create-form">
		<label>Title</label>
		<input type="text" name="title" id="title" placeholder="Group title">

		<label>Tags</label>
		<input type="text" name="tags" id="tags" placeholder="Group tags">
	</form>
</script>

<script type="text/javascript">
	var CREATORS = <?php echo json_encode(array_flip($creators)); ?>;
</script>
