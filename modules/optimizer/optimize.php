<?php
/**
 * Optimizer runner
 *
 * Two modes:
 *   - CLI (scheduled task): plain text output, no HTML;
 *   - HTTP: live progress page. Requires an authorized session, because the
 *     procedure deletes history irreversibly.
 *
 * Optional GET parameter: id=<optimizerdata.ID> - apply a single rule.
 */

Define('ALLOW_RUNNING_WITH_ERRORS', 1);

chdir(dirname(__FILE__) . '/../../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(3 * 60 * 60);

include_once("./load_settings.php");
include_once(DIR_MODULES . "optimizer/optimizer.class.php");

$IS_WEB = isset($_SERVER['REQUEST_METHOD']);
$RULE_ID = 0;

// ---------------------------------------------------------------------------
//  Web mode: authorization + streaming setup
// ---------------------------------------------------------------------------
if ($IS_WEB) {
    $session = new session("prj");

    $admin_exists = SQLSelectOne("SELECT ID FROM users WHERE IS_ADMIN=1");
    $auth_required = isset($admin_exists['ID']) && $admin_exists['ID'];

    if ($auth_required && empty($session->data['AUTHORIZED'])) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Authentication required';
        exit;
    }

    $RULE_ID = (int)gr('id');

    // the page must arrive piece by piece, so every buffer in the way is off
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_implicit_flush(true);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');       // nginx
    header('Cache-Control: no-store');
}

/**
 * Sends one chunk to the browser and pushes it through the buffers.
 *
 * @param string $html Chunk
 * @return void
 */
function opt_emit($html)
{
    echo $html;
    @flush();
}

/**
 * Sends a progress event to the page.
 *
 * @param string $event Event name
 * @param array $data Payload
 * @return void
 */
function opt_event($event, $data = array())
{
    $data['e'] = $event;
    opt_emit('<script>P(' . json_encode($data, JSON_UNESCAPED_UNICODE) . ');</script>' . "\n");
}

// ---------------------------------------------------------------------------
//  CLI mode
// ---------------------------------------------------------------------------
if (!$IS_WEB) {
    $out = array();
    $optimizer = new optimizer();
    $optimizer->getConfig();

    if ($optimizer->config['AUTO_OPTIMIZE']) {
        $optimizer->analyze($out, (int)$optimizer->config['AUTO_OPTIMIZE'], 1);
    }
    $optimizer->optimizeAll();
    exit;
}

// ---------------------------------------------------------------------------
//  Web mode: page shell
// ---------------------------------------------------------------------------
$page_title = $RULE_ID ? 'One rule optimization' : 'History optimization';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Optimizer</title>
<style>
:root{
  --bg:#f5f6f8; --card:#fff; --line:#e2e5ea; --text:#1f2430; --muted:#6b7280;
  --accent:#2f6fd0; --ok:#2e9e5b; --warn:#c8860d; --err:#c0392b;
}
*{box-sizing:border-box}
body{margin:0;padding:14px;background:var(--bg);color:var(--text);
     font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:12px}
h1{margin:0 0 2px;font-size:16px;font-weight:600}
.sub{color:var(--muted);font-size:12px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.timer{font-variant-numeric:tabular-nums;font-size:20px;font-weight:600;color:var(--accent)}
.phase{margin:12px 0 6px;font-weight:600}
.bar{height:8px;background:#e8eaee;border-radius:5px;overflow:hidden}
.bar>i{display:block;height:100%;width:0;background:var(--accent);transition:width .25s ease}
.bar.done>i{background:var(--ok)}
.pos{display:flex;justify-content:space-between;gap:12px;margin-top:6px;font-size:12px;color:var(--muted)}
.cur{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:ui-monospace,Menlo,Consolas,monospace}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.tile b{display:block;font-size:20px;font-weight:600;font-variant-numeric:tabular-nums}
.tile span{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.03em}
.tile.ok b{color:var(--ok)}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;font-weight:600;color:var(--muted);border-bottom:1px solid var(--line);padding:6px 8px;
   text-transform:uppercase;font-size:10px;letter-spacing:.04em}
td{padding:6px 8px;border-bottom:1px solid #f0f2f5;vertical-align:middle}
tr:last-child td{border-bottom:0}
td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
td.name{font-family:ui-monospace,Menlo,Consolas,monospace}
.tag{display:inline-block;padding:1px 6px;border-radius:4px;background:#eef2f8;color:#41506b;font-size:10px;
     text-transform:uppercase;letter-spacing:.03em}
.spark{height:6px;background:#edf0f4;border-radius:3px;overflow:hidden;min-width:60px}
.spark>i{display:block;height:100%;background:var(--ok)}
.empty{color:var(--muted);padding:10px 8px}
details{margin-top:2px}
summary{cursor:pointer;color:var(--muted);font-size:12px;user-select:none}
#log{margin-top:8px;max-height:220px;overflow:auto;background:#12161f;color:#c8d0dc;border-radius:6px;
     padding:8px 10px;font:11px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap}
.final{border-left:4px solid var(--ok)}
.final.err{border-left-color:var(--err)}
.final h2{margin:0 0 6px;font-size:15px}
.hint{color:var(--muted);font-size:12px;margin-top:8px}
.warn{color:var(--warn)}
.err{color:var(--err)}
@media (prefers-color-scheme: dark){
  :root{--bg:#171a21;--card:#1e222b;--line:#2c313c;--text:#e6e9ef;--muted:#98a2b3;--accent:#5b9bf8}
  .bar{background:#2a2f3a}.spark{background:#2a2f3a}.tag{background:#2a3346;color:#b7c6e4}
  td{border-bottom-color:#252a34}
}
</style>
</head>
<body>

<div class="card">
  <div class="head">
    <div>
      <h1><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h1>
      <div class="sub" id="started"></div>
    </div>
    <div class="timer" id="timer">00:00</div>
  </div>

  <div class="phase" id="phase">Running…</div>
  <div class="bar" id="bar"><i></i></div>
  <div class="pos">
    <span class="cur" id="cur">&nbsp;</span>
    <span id="pos"></span>
  </div>
</div>

<div class="tiles" id="tiles">
  <div class="tile"><b id="t_props">0</b><span>Properties processed</span></div>
  <div class="tile"><b id="t_before">0</b><span>Before</span></div>
  <div class="tile"><b id="t_after">0</b><span>After</span></div>
  <div class="tile ok"><b id="t_removed">0</b><span>Removed</span></div>
</div>

<div class="card">
  <table>
    <thead><tr>
      <th>Property</th><th>Mode</th><th class="n">Before</th><th class="n">After</th>
      <th class="n">Removed</th><th style="width:22%">Compression</th>
    </tr></thead>
    <tbody id="rows"><tr id="norows"><td colspan="6" class="empty">No changes yet…</td></tr></tbody>
  </table>
  <details>
    <summary>Detailed log</summary>
    <div id="log"></div>
  </details>
</div>

<div id="final"></div>

<script>
(function () {
  var started = Date.now(), finished = false;
  var totalBefore = 0, totalAfter = 0, totalRemoved = 0, doneProps = 0;
  var curItem = '', stageNo = 0;
  var $ = function (id) { return document.getElementById(id); };
  var nf = function (n) { return (n || 0).toLocaleString('ru-RU'); };

  $('started').textContent = 'Начало: ' + new Date().toLocaleTimeString('ru-RU');

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    if (finished) return;
    var s = Math.floor((Date.now() - started) / 1000);
    var h = Math.floor(s / 3600);
    $('timer').textContent = (h ? h + ':' : '') + pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
  }
  setInterval(tick, 1000);

  function setBar(i, total) {
    var pct = total > 0 ? Math.round(i * 100 / total) : 0;
    $('bar').firstChild.style.width = pct + '%';
    $('pos').textContent = total > 0 ? (nf(i) + ' / ' + nf(total) + '  (' + pct + '%)') : '';
  }

  var logBox = $('log'), logLines = 0;
  function log(text) {
    var d = document.createElement('div');
    d.textContent = text;
    logBox.appendChild(d);
    if (++logLines > 400) { logBox.removeChild(logBox.firstChild); logLines--; }
    logBox.scrollTop = logBox.scrollHeight;
  }

  function addRow(d) {
    var no = $('norows');
    if (no) { no.parentNode.removeChild(no); }
    var pct = d.before > 0 ? Math.round(d.removed * 100 / d.before) : 0;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="name"></td>' +
      '<td><span class="tag"></span></td>' +
      '<td class="n">' + nf(d.before) + '</td>' +
      '<td class="n">' + nf(d.after) + '</td>' +
      '<td class="n">' + nf(d.removed) + '</td>' +
      '<td><div class="spark"><i style="width:' + pct + '%"></i></div></td>';
    tr.children[0].textContent = d.title;
    tr.children[1].firstChild.textContent = d.mode || '—';
    $('rows').insertBefore(tr, $('rows').firstChild);
    while ($('rows').children.length > 200) { $('rows').removeChild($('rows').lastChild); }
  }

  window.P = function (d) {
    switch (d.e) {
      case 'phase':
        $('phase').textContent = d.text;
        curItem = ''; stageNo = 0;
        $('cur').innerHTML = '&nbsp;';
        setBar(0, d.total || 0);
        log('=== ' + d.text + ' ===');
        break;

      case 'scan':
        $('cur').textContent = d.title || '';
        setBar(d.index, d.total);
        break;

      case 'item':
        curItem = d.title + '  (' + nf(d.before) + ')';
        stageNo = 0;
        $('cur').textContent = curItem;
        break;

      case 'item_done':
        doneProps++;
        totalBefore += d.before;
        totalAfter += d.after;
        totalRemoved += d.removed;
        $('t_props').textContent = nf(doneProps);
        $('t_before').textContent = nf(totalBefore);
        $('t_after').textContent = nf(totalAfter);
        $('t_removed').textContent = nf(totalRemoved);
        if (d.removed > 0) { addRow(d); }
        break;

      case 'rule':
        log('+ rule created: ' + d.title + ' (' + nf(d.total) + ' records)');
        break;

      case 'analyzed':
        log('Analyzed: properties ' + nf(d.properties) + ', records ' + nf(d.records) +
            ', new rules ' + nf(d.rules_added));
        break;

      case 'stage':
        stageNo++;
        if (curItem) { $('cur').textContent = curItem + '  — stage ' + stageNo + '/4'; }
        log('  ' + d.from + ' .. ' + d.to + ' | step ' + d.interval + ' c | ' +
            nf(d.values) + ' → ~' + nf(d.target));
        break;

      case 'log':
        log(d.text);
        break;

      case 'warning':
        $('phase').innerHTML = '<span class="warn">' + d.text + '</span>';
        log('! ' + d.text);
        break;

      case 'error':
        finished = true;
        $('bar').firstChild.style.width = '100%';
        $('final').innerHTML =
          '<div class="card final err"><h2>Error</h2><div id="emsg"></div>' +
          '<div class="hint">Data may have been partially processed. Details in the log above.</div></div>';
        $('emsg').textContent = d.text;
        break;

      case 'done':
        finished = true;
        tick();
        $('bar').className = 'bar done';
        $('bar').firstChild.style.width = '100%';
        $('phase').textContent = 'Done';
        $('cur').innerHTML = '&nbsp;';
        var secs = Math.round((Date.now() - started) / 1000);
        var pct = totalBefore > 0 ? Math.round(totalRemoved * 100 / totalBefore) : 0;
        $('final').innerHTML =
          '<div class="card final"><h2>Optimization completed</h2>' +
          '<div>Properties checked: <b>' + nf(d.properties) + '</b>, changed: <b>' + nf(d.changed) + '</b>.</div>' +
          '<div>Records removed: <b>' + nf(d.removed) + '</b>' +
          (totalBefore > 0 ? ' (' + nf(totalBefore) + ' → ' + nf(totalAfter) + ', minus ' + pct + '%)' : '') +
          '.</div>' +
          '<div>Execution time: <b>' + $('timer').textContent + '</b>' +
          (secs > 0 ? '' : '') + '.</div>' +
          '<div class="hint">Window can be closed. The list of rules will be updated after page reload.</div>' +
          '</div>';
        break;
    }
  };
})();
</script>
<?php

opt_emit('<!--' . str_repeat(' ', 2048) . "-->\n");

// ---------------------------------------------------------------------------
//  Run
// ---------------------------------------------------------------------------
$optimizer = new optimizer();
$optimizer->getConfig();

$optimizer->progress = function ($event, $data) {
    static $last_scan = 0.0;

    if ($event === 'phase') {
        $last_scan = 0.0;
    }

    if ($event === 'scan') {
        $index = isset($data['index']) ? (int)$data['index'] : 0;
        $total = isset($data['total']) ? (int)$data['total'] : 0;
        $must_pass = ($index <= 1) || ($total > 0 && $index >= $total);
        $now = microtime(true);
        if (!$must_pass && ($now - $last_scan) < 0.15) {
            return;
        }
        $last_scan = $now;
    }

    opt_event($event, $data);
};

try {
    if (!$RULE_ID && $optimizer->config['AUTO_OPTIMIZE']) {
        $out = array();
        $optimizer->analyze($out, (int)$optimizer->config['AUTO_OPTIMIZE'], 1);
    }

    $optimizer->optimizeAll($RULE_ID);
} catch (Throwable $e) {
    DebMes('Optimization failed: ' . $e->getMessage(), 'optimizer');
    opt_event('error', array('text' => get_class($e) . ': ' . $e->getMessage()));
}

opt_emit("</body>\n</html>\n");
