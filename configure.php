<?php
// Cron Heartbeat - admin config page (Envesko). admin.php?view=plugins_config&plugin=cronheartbeat
if (!in_array($user->data()->id, $master_account)) {
    die('You do not have permission to configure this plugin.');
}
global $db, $settings;
$selfUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=cronheartbeat';
$inspectUrl = $us_url_root . 'usersc/plugins/cronheartbeat/files/run_inspect.php';

function ehbCfgRow()
{
    global $db;
    if ($db->query('SELECT id FROM cron_heartbeat_config WHERE id = 1')->count() === 0) {
        $db->insert('cron_heartbeat_config', ['id' => 1]);
    }
}

if (Input::exists()) {
    if (!Token::check(Input::get('csrf'))) {
        usError('Invalid security token. Please refresh and try again.');
        Redirect::to($selfUrl);
    }
    $do = Input::get('do');
    ehbCfgRow();

    if ($do === 'save_settings') {
        $parts = preg_split('/[\s,]+/', (string)Input::get('allowed_ips'), -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ($parts as $p) {
            if (preg_match('/^[0-9a-fA-F:.\/]+$/', $p)) {
                $clean[] = $p;
            }
        }
        $staleHours = (int)Input::get('stale_hours');
        if ($staleHours < 1) {
            $staleHours = 25;
        }
        $db->update('cron_heartbeat_config', 1, [
            'AllowedIps'   => implode(',', $clean),
            'StaleHours'   => $staleHours,
            'OpenAccess'   => Input::get('open_access') ? 1 : 0,
            'AlertEnabled' => Input::get('alert_enabled') ? 1 : 0,
            'AlertEmail'   => Input::get('alert_email'),
        ]);
        logger($user->data()->id, 'CronHeartbeat', 'Settings updated');
        usSuccess('Cron Heartbeat settings saved.');
        Redirect::to($selfUrl);
    }

    if ($do === 'regen_secret') {
        $db->update('cron_heartbeat_config', 1, ['CronSecret' => bin2hex(random_bytes(16))]);
        usSuccess('CLI secret regenerated.');
        Redirect::to($selfUrl);
    }

    if ($do === 'expected') {
        $slug = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string)Input::get('slug'));
        $mins = (int)Input::get('mins');
        $db->query('UPDATE cron_heartbeat SET ExpectedMins = ? WHERE CronSlug = ?', [$mins > 0 ? $mins : null, $slug]);
        usSuccess('Expected interval updated for ' . $slug . '.');
        Redirect::to($selfUrl);
    }

    if ($do === 'check') {
        $n = cronHeartbeatRunAlerts();
        usSuccess("Stale check ran - {$n} alert(s) sent.");
        Redirect::to($selfUrl);
    }
}

$cfg = cronHeartbeatConfig();
$allowedIps = $cfg ? $cfg->AllowedIps : '';
$staleHours = $cfg ? (int)$cfg->StaleHours : 25;
$openAccess = $cfg ? (int)$cfg->OpenAccess === 1 : false;
$alertEnabled = $cfg ? (int)$cfg->AlertEnabled === 1 : false;
$alertEmail = $cfg ? $cfg->AlertEmail : '';
$cronSecret = $cfg ? $cfg->CronSecret : '';
$jobs = cronHeartbeatJobs();
$myIp = Server::getClientIp();
$allow = cronGuardAllowedIps();
$coreCronIp = isset($settings->cron_ip) ? $settings->cron_ip : '';
$ajaxToken = Token::generate();
?>
<style>
  .ehb-hero { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); color:#fff; border-radius:.6rem; padding:1rem 1.25rem; margin-bottom:1rem; }
  .ehb-hero h2 { color:#fff; margin:0; font-weight:600; }
  .ehb-hero p { margin:.25rem 0 0; opacity:.95; }
  .ehb-accent { color:#2ea5cb; }
  .ehb-btn { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); border:0; color:#fff; }
  .ehb-btn:hover { filter:brightness(1.07); color:#fff; }
  .ehb-badge-ok { background:#2edcb7; color:#06302a; }
  .ehb-badge-bad { background:#e7515a; color:#fff; }
  .ehb-chip { display:inline-block; background:rgba(46,165,203,.14); color:#1c6f8c; border:1px solid rgba(46,165,203,.3); border-radius:1rem; padding:.05rem .55rem; font-size:.8rem; margin:.1rem; }
  .ehb-code { position:relative; }
  .ehb-code pre { background:#0f1117; color:#eef0f4; border-radius:.5rem; padding:1rem; margin:0; cursor:text; overflow:auto; }
  .ehb-code .ehb-copy { position:absolute; top:.5rem; right:.5rem; }
  .ehb-runs td { font-size:.85rem; }
  .ehb-inspect { cursor:pointer; color:#2ea5cb; }
</style>

<div class="container-fluid">
  <div class="ehb-hero">
    <h2><i class="fa fa-heartbeat me-2"></i>Cron Heartbeat <span style="font-weight:400;opacity:.85;font-size:1rem">by Envesko</span></h2>
    <p>Confirms your scheduled jobs are actually running. Add one line to each cron file and watch them here.</p>
  </div>

  <?php if ($openAccess) { ?>
    <div class="alert alert-danger">
      <strong>Open access is ON.</strong> Guarded cron endpoints accept requests from <em>any</em> IP.
      Turn this off in production and rely on the allowlist or the CLI secret.
    </div>
  <?php } ?>

  <div class="row">
    <div class="col-12 col-lg-5 mb-4">
      <div class="card">
        <div class="card-header"><i class="fa fa-shield-alt me-1 ehb-accent"></i> Access &amp; alerts</div>
        <div class="card-body">
          <p class="small text-muted mb-2">
            By default the gate is <strong>closed</strong>: only loopback, the core
            <code>settings.cron_ip</code>, your allowlist below, or a request carrying the CLI secret may run a
            guarded job. This plugin never overrides <code>settings.cron_ip</code> - it only adds to it.
          </p>
          <div class="mb-2">
            <div class="small text-muted">Effective allowlist:</div>
            <?php foreach ($allow['ips'] as $ip) { ?><span class="ehb-chip"><?= safeReturn($ip) ?></span><?php } ?>
            <?php if ($openAccess) { ?><span class="ehb-chip" style="background:#fde8e8;color:#a12;border-color:#f5c2c2">+ open (all IPs)</span><?php } ?>
          </div>
          <div class="small text-muted mb-3">Core <code>settings.cron_ip</code>: <code><?= $coreCronIp !== '' ? safeReturn($coreCronIp) : 'not set' ?></code> · Your IP: <code><?= safeReturn($myIp) ?></code></div>

          <form method="post" action="<?= safeReturn($selfUrl) ?>?view=plugins_config&plugin=cronheartbeat">
            <?= tokenHere() ?><input type="hidden" name="do" value="save_settings">
            <div class="mb-3">
              <label for="allowed_ips" class="form-label">Additional allowed IP(s)</label>
              <textarea id="allowed_ips" name="allowed_ips" class="form-control" rows="2" placeholder="203.0.113.10, 203.0.113.11"><?= safeReturn($allowedIps) ?></textarea>
              <div class="form-text">Comma/newline separated. Loopback + <code>settings.cron_ip</code> always included.</div>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="open_access" id="open_access" value="1" <?= $openAccess ? 'checked' : '' ?>>
              <label class="form-check-label" for="open_access">Allow <strong>all</strong> IPs (not recommended)</label>
            </div>
            <div class="mb-3">
              <label for="stale_hours" class="form-label">Default stale threshold (hours)</label>
              <input type="number" min="1" id="stale_hours" name="stale_hours" class="form-control" style="max-width:140px" value="<?= safeReturn((string)$staleHours) ?>">
              <div class="form-text">Used when a job has no per-job expected interval set.</div>
            </div>
            <hr>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="alert_enabled" id="alert_enabled" value="1" <?= $alertEnabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="alert_enabled">Email me when a job goes stale</label>
            </div>
            <div class="mb-3">
              <label for="alert_email" class="form-label">Alert email</label>
              <input type="email" id="alert_email" name="alert_email" class="form-control" value="<?= safeReturn($alertEmail) ?>" placeholder="ops@example.com">
              <div class="form-text">Sent once per stale episode (cleared on recovery). Schedule <code>files/check_stale.php</code> to drive it.</div>
            </div>
            <button type="submit" class="btn ehb-btn">Save settings</button>
          </form>

          <hr>
          <label class="form-label">CLI secret (for php-cli schedulers where the IP gate doesn't apply)</label>
          <div class="input-group">
            <input type="text" class="form-control" readonly value="<?= safeReturn($cronSecret) ?>">
          </div>
          <div class="form-text mb-2">Run via: <code>php check_stale.php key=THE_SECRET</code> or pass <code>?key=…</code> over HTTP.</div>
          <form method="post" action="<?= safeReturn($selfUrl) ?>"><?= tokenHere() ?><input type="hidden" name="do" value="regen_secret">
            <button class="btn btn-sm btn-outline-secondary">Regenerate secret</button>
          </form>
        </div>
      </div>

      <div class="card mt-3 ehb-code">
        <div class="card-header"><i class="fa fa-code me-1 ehb-accent"></i> How to use</div>
        <div class="card-body">
          <p class="mb-2">Put this at the top of any cron file (click the code to select it all):</p>
          <div class="ehb-code">
            <button type="button" class="btn btn-sm btn-light ehb-copy" id="ehbCopyBtn"><i class="fa fa-copy"></i> Copy</button>
            <pre id="ehbSnippet"><code>require_once '../../users/init.php';
$slug = cronGuard(['slug' => 'my-job']);

// ... your job work ...

cronBeat($slug, 'ok', 'processed 12 rows', [
    'processed' => 12,
    'skipped'   => 0,
]);   // metadata array is optional &amp; inspectable below</code></pre>
          </div>
          <p class="mb-0 mt-2 text-muted small">Example: <code>files/example_cron.php</code>. CLI: <code>php example_cron.php key=SECRET</code>.</p>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-7 mb-4">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="fa fa-heartbeat me-1 ehb-accent"></i> Tracked jobs (<?= count($jobs) ?>)</span>
          <form method="post" action="<?= safeReturn($selfUrl) ?>" class="m-0"><?= tokenHere() ?><input type="hidden" name="do" value="check">
            <button class="btn btn-sm btn-outline-primary" title="Run the stale-alert check now"><i class="fa fa-bell me-1"></i>Run stale check</button>
          </form>
        </div>
        <div class="card-body p-0">
          <?php if (empty($jobs)) { ?>
            <div class="p-3 text-muted">No heartbeats recorded yet. Run a guarded cron job and it will appear here.</div>
          <?php } else { ?>
            <table class="table table-striped mb-0 align-middle">
              <thead><tr><th>Job</th><th>Last run</th><th>Runs</th><th>Every (min)</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($jobs as $i => $j) {
                    $ok = !$j->stale;
                    $label = $ok ? 'Healthy' : 'Stale';
                    if ($j->LastStatus === 'fail') { $label = 'Failed'; }
                    if ($j->LastStatus === 'blocked') { $label = 'Blocked'; }
                    $runs = cronHeartbeatRuns($j->CronSlug, 25);
                ?>
                  <tr>
                    <td><code><?= safeReturn($j->CronSlug) ?></code>
                      <?php if (!empty($j->LastNote)) { ?><br><small class="text-muted"><?= safeReturn($j->LastNote) ?></small><?php } ?>
                    </td>
                    <td><?= $j->LastRun ? safeReturn($j->LastRun) : '<span class="text-muted">never</span>' ?></td>
                    <td><?= safeReturn((string)$j->Runs) ?></td>
                    <td>
                      <form method="post" action="<?= safeReturn($selfUrl) ?>" class="d-flex m-0" style="gap:.25rem">
                        <?= tokenHere() ?><input type="hidden" name="do" value="expected"><input type="hidden" name="slug" value="<?= safeReturn($j->CronSlug) ?>">
                        <input type="number" min="0" name="mins" class="form-control form-control-sm" style="width:80px" value="<?= safeReturn((string)($j->ExpectedMins ?? '')) ?>" placeholder="-">
                        <button class="btn btn-sm btn-outline-secondary" title="Save expected interval"><i class="fa fa-save"></i></button>
                      </form>
                    </td>
                    <td><span class="badge <?= $ok ? 'ehb-badge-ok' : 'ehb-badge-bad' ?>"><?= safeReturn($label) ?></span></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ehbRuns<?= $i ?>">Runs (<?= count($runs) ?>)</button>
                    </td>
                  </tr>
                  <tr class="collapse ehb-runs" id="ehbRuns<?= $i ?>">
                    <td colspan="6" class="p-0">
                      <table class="table table-sm mb-0">
                        <thead><tr><th>When</th><th>Status</th><th>Note</th><th>Meta</th></tr></thead>
                        <tbody>
                          <?php if (empty($runs)) { ?>
                            <tr><td colspan="4" class="text-muted">No run history yet.</td></tr>
                          <?php } else { foreach ($runs as $r) { ?>
                            <tr>
                              <td><?= safeReturn($r->RunAt) ?></td>
                              <td><?= safeReturn($r->Status) ?></td>
                              <td><?= safeReturn($r->Note) ?></td>
                              <td>
                                <?php if (!empty($r->Metadata)) { ?>
                                  <i class="fa fa-magnifying-glass ehb-inspect" data-run-id="<?= (int)$r->id ?>" title="Inspect metadata"></i>
                                <?php } else { ?><span class="text-muted">-</span><?php } ?>
                              </td>
                            </tr>
                          <?php } } ?>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal" id="ehbMeta" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Run #<span id="ehbMetaID"></span> metadata</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="ehbMetaBody"></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>">
(function () {
  var snippet = document.getElementById('ehbSnippet');
  var copyBtn = document.getElementById('ehbCopyBtn');
  if (snippet) {
    snippet.addEventListener('click', function () {
      var range = document.createRange(); range.selectNodeContents(snippet);
      var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
    });
  }
  if (copyBtn && snippet) {
    copyBtn.addEventListener('click', function () {
      navigator.clipboard.writeText(snippet.innerText).then(function () {
        copyBtn.innerHTML = '<i class="fa fa-check"></i> Copied';
        setTimeout(function () { copyBtn.innerHTML = '<i class="fa fa-copy"></i> Copy'; }, 1500);
      });
    });
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-run-id]');
    if (!el) return;
    var id = el.getAttribute('data-run-id');
    $('#ehbMetaID').text(id);
    $('#ehbMetaBody').html('<div class="text-muted">Loading…</div>');
    $.get('<?= $inspectUrl ?>?id=' + encodeURIComponent(id) + '&token=<?= $ajaxToken ?>', function (data) { $('#ehbMetaBody').html(data); });
    $('#ehbMeta').modal('show');
  });
})();
</script>
