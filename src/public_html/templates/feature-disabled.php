<?php
	// Rendered by index.php when a route's required ini feature flag is disabled.
	// Expects: $route (the requested section) and $blocked_feature (the ini flag).
	$feature_labels = array(
		'email'       => 'Email',
		'wptoolkit'   => 'WordPress Toolkit',
		'backup'      => 'Backup',
		'backupdb'    => 'Database Backup',
		'terminal'    => 'Terminal',
		'transfer'    => 'Transfer Tool',
		'root_access' => 'Root Access',
	);
	$feature_name = isset($feature_labels[$blocked_feature]) ? $feature_labels[$blocked_feature] : $blocked_feature;
	include('templates/header.php');
?>
		<div class="card">
			<div class="card-body">
				<div class="empty">
					<div class="empty-icon">
						<svg xmlns="http://www.w3.org/2000/svg" class="icon text-danger" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v2m0 4v.01"/><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"/></svg>
					</div>
					<p class="empty-title">The <b><?=clean($feature_name);?></b> section is disabled</p>
					<p class="empty-subtitle text-muted">
						This feature is turned off in the server configuration
						(<code><?=clean($blocked_feature);?></code> in <code>server-software.ini</code>).
					</p>
					<div class="empty-action">
						<a href="/" class="btn btn-primary">Back to Dashboard</a>
					</div>
				</div>
			</div>
		</div>
<?php include('templates/footer.php'); ?>
</body>
</html>
