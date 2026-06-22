<?php
// Cron Heartbeat dashboard widget. Auto-discovered by UserSpice from usersc/widgets/.
// Inherits the dashboard theme via the standard dash-card markup.

$chJobs = function_exists('cronHeartbeatJobs') ? cronHeartbeatJobs() : [];
$chStale = 0;
foreach ($chJobs as $j) {
    if (!empty($j->stale)) {
        $chStale++;
    }
}
?>
<div class="card dash-card" data-id="<?= $widgetName ?>" id="<?= $widgetName ?>-card">
  <div class="card-header" id="<?= $widgetName ?>-card-header">
    <span class="collapseCard" data-card="<?= $widgetName ?>" id="<?= $widgetName ?>-caret"><i class="fa fa-caret-down"></i></span>
    <span class="card-title-text">Cron Heartbeat</span>
    <span class="float-end"><i class="fa-solid fa-grip ps-2 grippy"></i></span>
  </div>
  <div class="card-body" id="<?= $widgetName ?>-card-body">
    <?php if (empty($chJobs)) { ?>
      <p class="card-text text-muted mb-0">No cron heartbeats recorded yet.</p>
    <?php } else { ?>
      <p class="card-text mb-2">
        <?php if ($chStale === 0) { ?>
          <span class="badge bg-success">All <?= count($chJobs) ?> jobs healthy</span>
        <?php } else { ?>
          <span class="badge bg-danger"><?= $chStale ?> of <?= count($chJobs) ?> job(s) need attention</span>
        <?php } ?>
      </p>
      <ul class="list-unstyled mb-2" style="max-height:180px;overflow:auto">
        <?php foreach ($chJobs as $j) {
            $dot = !empty($j->stale) ? 'text-danger' : 'text-success';
        ?>
          <li class="d-flex justify-content-between border-bottom py-1">
            <span><i class="fa fa-circle <?= $dot ?> me-1" style="font-size:.6rem"></i><?= safeReturn($j->CronSlug) ?></span>
            <small class="text-muted"><?= $j->LastRun ? safeReturn($j->LastRun) : 'never' ?></small>
          </li>
        <?php } ?>
      </ul>
    <?php } ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=cronheartbeat">Manage</a>
  </div>
</div>
