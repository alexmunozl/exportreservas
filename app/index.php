<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>OHIP External References Exporter</title>
<style>
body{font-family:system-ui;margin:24px;max-width:1100px}
.nav{margin-bottom:12px;color:#555}
.nav a{color:#0a58ca;text-decoration:none}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
button{padding:10px 16px;font-weight:700;cursor:pointer}
button[disabled]{opacity:.5;cursor:not-allowed}
#bar{width:100%;background:#eee;height:24px;border-radius:8px;margin-top:20px;overflow:hidden}
#progress{height:24px;background:#4caf50;width:0%;border-radius:8px;transition:width .2s}
#status{margin-top:14px;font-weight:600}
.meta{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px;margin-top:14px}
.card{background:#f7f7f7;border-radius:10px;padding:12px}
#log{margin-top:20px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#111;color:#0f0;padding:10px;height:320px;overflow:auto;white-space:pre-wrap}
.small{color:#555;font-size:13px}
</style>
</head>
<body>
<h1>OHIP External References Exporter</h1>
<p class="nav">
  <a href="settings.php">Settings</a>
  · <a href="health.php">Health</a>
  · <a href="logout.php">Logout</a>
  · <a href="test-token.php">Test token</a>
</p>

<p class="small">This app discovers reservations page by page, then fetches each reservation detail and exports the <code>ERP</code> and <code>IMPORTCNF</code> external references to CSV.</p>

<div class="actions">
  <button id="startBtn" onclick="startRun()">Start Export</button>
  <button id="cancelBtn" onclick="cancelRun()" disabled>Cancel</button>
  <button id="downloadBtn" onclick="downloadCsv()" disabled>Download CSV</button>
</div>

<div id="bar"><div id="progress"></div></div>
<div id="status">Idle</div>

<div class="meta">
  <div class="card">Phase: <strong id="phase">idle</strong></div>
  <div class="card">Processed: <strong id="processed">0</strong></div>
  <div class="card">Total: <strong id="total">0</strong></div>
  <div class="card">Success: <strong id="successCount">0</strong></div>
  <div class="card">Failed: <strong id="failedCount">0</strong></div>
  <div class="card">Pages scanned: <strong id="pagesScanned">0</strong></div>
  <div class="card">Current Reservation: <strong id="current">-</strong></div>
  <div class="card">Estimated time remaining: <strong id="eta">-</strong></div>
  <div class="card">Rows ready: <strong id="rowsReady">0</strong></div>
  <div class="card">Discovered ids: <strong id="idsReady">0</strong></div>
  <div class="card">State: <strong id="stateLabel">idle</strong></div>
</div>

<pre id="log"></pre>
<p class="small">Cancel stops new processing and keeps completed rows available for download. If you cancel during discovery, the download will contain discovered reservation ids only.</p>

<script>
let loopActive = false;
function byId(id){ return document.getElementById(id); }
function appendLog(line){
  const el = byId("log");
  el.textContent += line + "\n";
  el.scrollTop = el.scrollHeight;
}
function formatEta(seconds){
  if (seconds === null || seconds === undefined || seconds < 0 || !Number.isFinite(seconds)) return "-";
  const s = Math.round(seconds);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) return `${h}h ${m}m ${sec}s`;
  if (m > 0) return `${m}m ${sec}s`;
  return `${sec}s`;
}
function updateUi(j){
  byId("progress").style.width = (j.percent || 0) + "%";
  byId("status").innerText = (j.finished ? "Finished" : (j.cancelled ? "Cancelled" : "Running")) + " — " + (j.message || "");
  byId("phase").innerText = j.phase || "idle";
  byId("processed").innerText = j.done ?? 0;
  byId("total").innerText = j.total ?? 0;
  byId("successCount").innerText = j.successCount ?? 0;
  byId("failedCount").innerText = j.failedCount ?? 0;
  byId("pagesScanned").innerText = j.pagesScanned ?? 0;
  byId("current").innerText = j.currentReservationId || "-";
  byId("eta").innerText = formatEta(j.etaSeconds);
  byId("rowsReady").innerText = j.rowsReady ?? 0;
  byId("idsReady").innerText = j.idsReady ?? 0;
  byId("stateLabel").innerText = j.cancelled ? "cancelled" : (j.finished ? "finished" : "running");
  byId("cancelBtn").disabled = !!j.finished || !!j.cancelled || !(j.started || false);
  byId("downloadBtn").disabled = ((j.rowsReady ?? 0) === 0 && (j.idsReady ?? 0) === 0);
  byId("startBtn").disabled = (!j.finished && !j.cancelled && loopActive);
}
async function startRun(){
  byId("log").textContent = "";
  appendLog("Starting export...");
  byId("startBtn").disabled = true;
  byId("cancelBtn").disabled = false;
  byId("downloadBtn").disabled = true;
  const res = await fetch("exporter-run.php?start=1", {cache: "no-store"});
  const j = await res.json();
  updateUi(j);
  loopActive = true;
  setTimeout(processLoop, 150);
}
async function processLoop(){
  const res = await fetch("exporter-run.php?step=1", {cache: "no-store"});
  const j = await res.json();
  updateUi(j);
  appendLog(j.message || ("Processed " + (j.done || 0)));
  if (!j.finished && !j.cancelled) {
    setTimeout(processLoop, j.phase === "discover" ? 100 : 250);
    return;
  }
  loopActive = false;
  byId("startBtn").disabled = false;
  byId("cancelBtn").disabled = true;
  byId("downloadBtn").disabled = ((j.rowsReady ?? 0) === 0 && (j.idsReady ?? 0) === 0);
  if (j.cancelled) {
    appendLog("Export cancelled. Partial CSV is available.");
    return;
  }
  appendLog("Finished. CSV ready.");
}
async function cancelRun(){
  const res = await fetch("exporter-run.php?cancel=1", {cache: "no-store"});
  const j = await res.json();
  updateUi(j);
  appendLog("Cancel requested.");
}
function downloadCsv(){
  window.location = "exporter-run.php?download=1";
}
</script>
</body>
</html>
