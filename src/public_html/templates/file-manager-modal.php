<?php
	/* File Manager — modal-xl, opened from the Accounts list "Files" action.
	   Account-level browse / edit / upload / create / rename / chmod / delete for
	   files under /home/<user>. Every filesystem op is served by an ajax-fm-*
	   handler in modules/ajax.php (each shells `sudo -u <user>` and jails paths
	   inside the home via fm_resolve() — see modules/functions.php). Endpoints:
	     list   POST ajax-fm-list    {user,path}          -> {rows:[...]}
	     read   POST ajax-fm-read    {user,path}          -> {content}
	     save   POST ajax-fm-save    {user,path,content}
	     mkdir  POST ajax-fm-mkdir   {user,path}
	     newfile POST ajax-fm-newfile{user,path}
	     rename POST ajax-fm-rename  {user,src,dst}
	     chmod  POST ajax-fm-chmod   {user,path,mode}
	     delete POST ajax-fm-delete  {user,path}
	     upload POST ajax-fm-upload  {user,path,files[]}   (multipart)
	     download GET ajax-fm-download {user,path}         (streamed)
	   Gated by the `filemanager` ini flag (feature_enabled / ajax_required_feature). */
?>
<!-- Reuse the CodeMirror that Advanced Config already vendors (dist/libs/codemirror). -->
<link href="./dist/libs/codemirror/codemirror.min.css" rel="stylesheet">
<style>
	#modal-file-manager .modal-body { position: relative; min-height: 0; }
	/* fill the modal as a proper flex child (min-height:0 lets the inner #fm-pane
	   scroll instead of the whole list overflowing the modal) */
	#fm-browser, #fm-editor { flex: 1 1 auto; min-height: 0; }
	#fm-breadcrumb { margin: 0; padding-left: 10px; font-size: 0.95rem; white-space: nowrap; }
	#fm-breadcrumb .fm-path-static { color: #868e96; }
	#fm-breadcrumb .fm-path-sep { color: #adb5bd; margin: 0; }
	#fm-breadcrumb .fm-path-link { cursor: pointer; color: var(--tblr-primary, #206bc4); }
	#fm-breadcrumb .fm-path-link:hover { text-decoration: underline; }
	#fm-breadcrumb .fm-path-cur { font-weight: 600; }
	#fm-tbody tr.fm-dir .fm-name { cursor: pointer; font-weight: 500; }
	#fm-tbody tr.fm-updir { cursor: pointer; }
	#fm-tbody tr.fm-updir .fm-name { cursor: pointer; font-weight: 500; }
	#fm-tbody tr.fm-file { cursor: pointer; }
	/* files with the execute bit are highlighted in primary (name + icon) */
	#fm-tbody .fm-exec, #fm-tbody .fm-exec .fm-icon { color: var(--tblr-primary, #206bc4); }
	/* align the Name column with the /home breadcrumb (same left inset as the title);
	   in select mode the checkbox column provides the indent, so reset it there */
	#modal-file-manager .fm-c-name { padding-left: 18px; }
	#fm-browser.selecting .fm-c-name { padding-left: .5rem; }
	/* pull the modal title left to align with the content below it */
	#modal-file-manager .modal-header { padding-left: 18px; }
	#fm-tbody tr.fm-file .fm-name, #fm-tbody tr td:first-child { -webkit-user-select: none; user-select: none; }
	#fm-tbody td { vertical-align: middle; }
	#fm-tbody td, #modal-file-manager thead th { white-space: nowrap; }   /* never wrap any cell value */
	/* row hover: light Reqad-blue tint instead of the default grey.
	   Tabler paints hover via a box-shadow overlay driven by --tblr-table-accent-bg,
	   so that's the variable to override (a background-color sits under the overlay). */
	#modal-file-manager .table-hover > tbody > tr:hover { --tblr-table-accent-bg: #DDEEFF66; cursor: pointer; }
	#modal-file-manager .table-hover > thead > tr > th { background-color:#DEF; }
	/* keep the column header visible while the list scrolls */
	#modal-file-manager thead th { position: sticky; top: 0; z-index: 3; }
	/* thin, barely-visible scrollbar for the file list */
	#fm-pane { scrollbar-width: thin; scrollbar-color: rgba(0,0,0,.18) transparent; }
	#fm-pane::-webkit-scrollbar { width: 8px; height: 8px; }
	#fm-pane::-webkit-scrollbar-track { background: transparent; }
	#fm-pane::-webkit-scrollbar-thumb { background: rgba(0,0,0,.16); border-radius: 4px; }
	#fm-pane::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,.28); }
	#modal-file-manager .table-hover { border-bottom: 1px solid #DDD; }
	#fm-tbody .fm-icon { width: 20px; height: 20px; margin-right: 8px; flex: 0 0 auto; }
	#fm-tbody .fm-icon.dir { color: var(--tblr-primary, #206bc4); }
	#fm-tbody .fm-icon.file { color: #6c757d; }
	#fm-tbody .fm-icon.php { color: #8892bf; }
	#fm-tbody .fm-icon.archive { color: #f08c00; }             /* archives: amber */
	#fm-tbody .fm-icon.link { color: #17a2b8; }                 /* symlinks: cyan */
	#fm-tbody tr .fm-broken, #fm-tbody tr .fm-broken .fm-icon { color: #adb5bd; text-decoration: line-through; }  /* broken symlink */
	/* non-fullscreen (modal-xl) needs an explicit height so the list/editor can fill + scroll */
	#modal-file-manager .modal-content { height: 85vh; }
	#fm-tbody .fm-perms { font-family: var(--tblr-font-monospace, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace); font-size: 0.8rem; color: #626976; background: transparent; padding: 0; }
	#fm-tbody .fm-row-actions { white-space: nowrap; }
	/* keep Size/Permissions/Modified on one line; Name gives up the width (class-based
	   so a checkbox column can be inserted without breaking positional rules) */
	#modal-file-manager .fm-c-size, #modal-file-manager .fm-c-perms, #modal-file-manager .fm-c-mtime { white-space: nowrap; }
	#modal-file-manager .fm-c-name { max-width: 0; }
	#fm-tbody .fm-c-name span { overflow: hidden; text-overflow: ellipsis; min-width: 0; }
	/* selection mode: checkbox column (hidden until active) + hidden per-row actions */
	.fm-sel-col { display: none; width: 1%; white-space: nowrap; text-align: center; }
	#fm-browser.selecting .fm-sel-col { display: table-cell; }
	#fm-browser.selecting .fm-row-actions > * { display: none; }
	#fm-browser.selecting #fm-tbody tr.fm-dir .fm-name,
	#fm-browser.selecting #fm-tbody tr.fm-file { cursor: pointer; }
	.fm-sel, #fm-selectall { cursor: pointer; }
	/* permissions grid in the Change dialog */
	.fm-perm-table { width: 100%; }
	.fm-perm-table th { font-weight: 500; color: #868e96; text-align: center; font-size: .8rem; padding: 2px 4px; }
	.fm-perm-table td { text-align: center; padding: 3px 4px; }
	.fm-perm-table td:first-child { text-align: left; color: #354052; }
	.fm-perm-table input { cursor: pointer; }
	#fm-empty { padding: 60px 20px; }
	#fm-editor .CodeMirror { height: 100%; font-size: 13px; }
	#fm-editor-path { font-size: 0.95rem; color: #868e96; padding: 7px 0 7px 10px; }   /* same size the filename used to be, aligned with the breadcrumb */
	#fm-editor-path .fm-fname { font-weight: 700; color: #354052; }
	#fm-editor-pane { flex: 1; min-height: 0; }
	/* hand-rolled dialog overlay (avoids fragile modal-in-modal stacking) */
	#fm-dialog, #fm-props, #fm-archive { position: absolute; inset: 0; z-index: 20; background: rgba(0,0,0,.45);
	             display: flex; align-items: center; justify-content: center; }
	#fm-dialog .card { width: 400px; max-width: 92%; }
	#fm-props .card { width: 440px; max-width: 92%; }
	#fm-archive .card { width: 420px; max-width: 92%; }
	/* toast stack: bottom-right, newest below, never overlapping */
	#fm-toasts { position: absolute; bottom: 16px; right: 16px; z-index: 30;
	             display: flex; flex-direction: column; align-items: flex-end; gap: 8px; pointer-events: none; }
	#fm-toasts .fm-toast { margin: 0; max-width: 340px; pointer-events: auto; }
</style>

<div class="modal modal-blur fade" id="modal-file-manager" tabindex="-1" role="dialog" aria-hidden="true" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title modal-title-lg">
          File Manager
          <span class="text-muted fw-normal"> - <span id="fm-title"></span></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0 d-flex flex-column">

        <!-- ===================== BROWSER VIEW ===================== -->
        <div id="fm-browser" class="d-flex flex-column">

          <div class="d-flex align-items-center flex-wrap gap-2 p-2 border-bottom bg-light">
            <div class="flex-grow-1 text-truncate" id="fm-breadcrumb"></div>
            <!-- normal toolbar -->
            <div class="btn-list" id="fm-tools-normal">
              <button class="btn" id="fm-refresh">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg>
                Refresh
              </button>
              <button class="btn" id="fm-new-folder">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 19h-7a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2h4l3 3h7a2 2 0 0 1 2 2v3.5"/><path d="M16 19h6"/><path d="M19 16v6"/></svg>
                New Folder
              </button>
              <button class="btn" id="fm-new-file">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11l0 6"/><path d="M9 14l6 0"/></svg>
                New File
              </button>
              <button class="btn" id="fm-upload-btn">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 9l5 -5l5 5"/><path d="M12 4l0 12"/></svg>
                Upload
              </button>
              <button class="btn" id="fm-select-btn">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11l3 3l8 -8"/><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9"/></svg>
                Select
              </button>
              <input type="file" id="fm-upload-input" multiple class="d-none">
            </div>
            <!-- selection toolbar (shown while selecting) -->
            <div class="btn-list d-none" id="fm-tools-select">
              <button class="btn btn-primary" id="fm-compress" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>
                Compress and download<span id="fm-selcount"></span>
              </button>
              <button class="btn btn-outline-danger" id="fm-delete-selected" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>
                Delete<span id="fm-delcount"></span>
              </button>
              <button class="btn" id="fm-cancel-select">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                Cancel selection
              </button>
            </div>
          </div>

          <div class="table-responsive" id="fm-pane" style="flex:1; min-height:0; overflow:auto;">
            <table class="table table-vcenter card-table table-hover mb-0">
              <thead>
                <tr>
                  <th class="fm-sel-col"><input type="checkbox" class="form-check-input m-0" id="fm-selectall"></th>
                  <th class="fm-c-name" style="width:40%;">Name</th>
                  <th class="fm-c-size w-1">Size</th>
                  <th class="fm-c-perms w-1">Permissions</th>
                  <th class="fm-c-mtime w-1">Modified</th>
                  <th class="fm-c-actions w-1"></th>
                </tr>
              </thead>
              <tbody id="fm-tbody"></tbody>
            </table>
            <div id="fm-empty" class="text-center text-muted d-none">This folder is empty.</div>
          </div>

          <!-- status bar: item counts, total size, current path -->
          <div id="fm-statusbar" class="d-flex align-items-center gap-2 px-3 py-1 border-top bg-light text-muted small">
            <span id="fm-stat-counts"></span>
            <span id="fm-stat-size" class="ms-auto"></span>
            <span class="text-secondary">·</span>
            <span id="fm-stat-path" class="text-truncate" style="max-width:40%;"></span>
          </div>
        </div>

        <!-- ===================== EDITOR VIEW ===================== -->
        <div id="fm-editor" class="d-none flex-column">
          <div class="d-flex align-items-center gap-2 p-2 border-bottom bg-light">
            <span id="fm-editor-path" class="text-truncate"></span>
          </div>
          <div id="fm-editor-pane" class="d-flex flex-column">
            <textarea id="fm-editor-textarea" class="form-control border-0 rounded-0 font-monospace" style="flex:1;"></textarea>
          </div>
          <div class="d-flex justify-content-end gap-2 p-2 border-top bg-light">
            <button class="btn btn-primary" id="fm-editor-save">Save</button>
            <button class="btn btn-white" id="fm-editor-cancel">Cancel</button>
          </div>
        </div>

        <!-- ===================== HAND-ROLLED DIALOG (name / confirm) ===================== -->
        <div id="fm-dialog" class="d-none">
          <div class="card">
            <div class="card-header"><h3 class="card-title" id="fm-dialog-title"></h3></div>
            <div class="card-body">
              <p id="fm-dialog-text" class="d-none mb-0"></p>
              <div id="fm-dialog-info" class="d-none"></div>
              <div id="fm-dialog-inputwrap">
                <label class="form-label" id="fm-dialog-label"></label>
                <input type="text" class="form-control" id="fm-dialog-input" autocomplete="off">
              </div>
            </div>
            <div class="card-footer d-flex">
              <button class="btn ms-auto" id="fm-dialog-cancel">Cancel</button>
              <button class="btn btn-primary ms-2" id="fm-dialog-ok">OK</button>
            </div>
          </div>
        </div>

        <!-- ===================== CHANGE DIALOG (rename + permissions) ===================== -->
        <div id="fm-props" class="d-none">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Change <span id="fm-props-name-title"></span></h3></div>
            <div class="card-body">
              <label class="form-label">Name</label>
              <input type="text" class="form-control mb-3" id="fm-props-name" autocomplete="off">
              <label class="form-label">Permissions</label>
              <table class="fm-perm-table">
                <thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th></tr></thead>
                <tbody>
                  <tr><td>Owner</td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="0" data-b="4"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="0" data-b="2"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="0" data-b="1"></td></tr>
                  <tr><td>Group</td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="1" data-b="4"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="1" data-b="2"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="1" data-b="1"></td></tr>
                  <tr><td>Others</td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="2" data-b="4"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="2" data-b="2"></td>
                    <td><input type="checkbox" class="form-check-input fm-perm" data-c="2" data-b="1"></td></tr>
                </tbody>
              </table>
              <div class="text-muted mt-2">Mode: <code id="fm-props-octal"></code> · <code id="fm-props-sym"></code></div>
              <div id="fm-props-err" class="text-danger small mt-2 d-none"></div>
            </div>
            <div class="card-footer d-flex">
              <button class="btn ms-auto" id="fm-props-cancel">Cancel</button>
              <button class="btn btn-primary ms-2" id="fm-props-save">Save</button>
            </div>
          </div>
        </div>

        <!-- ===================== COMPRESS DIALOG (type + archive name) ===================== -->
        <div id="fm-archive" class="d-none">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Compress <span id="fm-arc-count"></span></h3></div>
            <div class="card-body">
              <label class="form-label">Compression type</label>
              <div class="mb-3">
                <label class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="fm-arc-type" value="zip" checked>
                  <span class="form-check-label">zip</span>
                </label>
                <label class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="fm-arc-type" value="tgz">
                  <span class="form-check-label">tar.gz</span>
                </label>
              </div>
              <label class="form-label">Archive name</label>
              <div class="input-group">
                <input type="text" class="form-control" id="fm-arc-name" autocomplete="off">
                <span class="input-group-text" id="fm-arc-ext">.zip</span>
              </div>
              <div id="fm-arc-err" class="text-danger small mt-2 d-none"></div>
            </div>
            <div class="card-footer d-flex">
              <button class="btn ms-auto" id="fm-arc-cancel">Cancel</button>
              <button class="btn btn-primary ms-2" id="fm-arc-ok">Compress</button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="./dist/libs/codemirror/codemirror.min.js"></script>
<!-- Language modes for the editor (self-hosted, no CDN). Load order matters:
     htmlmixed needs xml/javascript/css; php needs htmlmixed + clike. -->
<script src="./dist/libs/codemirror/mode/xml/xml.min.js"></script>
<script src="./dist/libs/codemirror/mode/javascript/javascript.min.js"></script>
<script src="./dist/libs/codemirror/mode/css/css.min.js"></script>
<script src="./dist/libs/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="./dist/libs/codemirror/mode/clike/clike.min.js"></script>
<script src="./dist/libs/codemirror/mode/php/php.min.js"></script>
<script src="./dist/libs/codemirror/mode/markdown/markdown.min.js"></script>
<script src="./dist/libs/codemirror/mode/python/python.min.js"></script>
<script src="./dist/libs/codemirror/mode/shell/shell.min.js"></script>
<script src="./dist/libs/codemirror/mode/sql/sql.min.js"></script>
<script>
jQuery(document).ready(function () {
	'use strict';

	/* This partial is included after footer.php, which leaves one <div> unclosed
	   (its reboot modal), so we'd otherwise be nested inside a display:none modal
	   and never render. Relocate to be a direct child of <body> — the reliable
	   place for a Bootstrap modal — before wiring anything up. */
	$('#modal-file-manager').appendTo(document.body);

	/* All filesystem operations run server-side via the ajax-fm-* handlers
	   (modules/ajax.php), each shelling `sudo -u <user>` and jailing paths inside
	   /home/<user>. fmPost() is the shared JSON caller; the current directory's
	   rows are cached in FM_ROWS so click/dblclick handlers can look a row up. */
	var FM_ROWS = [];

	// POST an ajax-fm-* action (user is injected). done(res) on success;
	// on {error} or transport failure, fail(msg) runs (defaults to a toast).
	function fmPost(action, data, done, fail){
		data = data || {};
		data.action = action;
		data.user = fm.user;
		$.post('/?ajax=1', data, null, 'json')
			.done(function(res){
				if(!res || res.error){ (fail || toast)(res && res.error ? res.error : 'Request failed.'); return; }
				if(done) done(res);
			})
			.fail(function(){ (fail || toast)('Request failed. Please try again.'); });
	}

	var EDIT_MAX = 100 * 1024;                 // 100 KB edit ceiling (matches plan)
	var TEXT_EXT = ['txt','md','php','js','css','html','htm','xml','json','yml','yaml',
	                'ini','conf','htaccess','sh','py','log','bashrc','env','sql'];

	var fm = { user:'', domain:'', path:'', editing:null, propsRow:null, selectMode:false };

	// ---- helpers -------------------------------------------------------------
	function esc(s){ return String(s).replace(/[&<>"']/g, function(c){
		return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

	function humanSize(t, n){
		if(t === 'dir') return '—';
		if(n < 1024) return n + ' B';
		var u = ['KB','MB','GB','TB'], i = -1;
		do { n /= 1024; i++; } while(n >= 1024 && i < u.length-1);
		return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
	}

	// ls -l style modified date from a "YYYY-MM-DD HH:MM" string:
	//   today -> "14:30",  this year -> "Jul 4 14:30",  older -> "Jun 8, 2025"
	var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
	function fmtMtime(s){
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
		if(!m) return s || '';
		var d = new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5]), now = new Date();
		if(d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate())
			return m[4] + ':' + m[5];                                  // today -> time
		if(d.getFullYear() === now.getFullYear())
			return MON[d.getMonth()] + ' ' + d.getDate() + ' ' + m[4] + ':' + m[5];   // this year -> "Jul 4 14:30"
		return MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();  // older -> "Jun 8, 2025"
	}

	function extOf(name){
		var base = name.indexOf('.') === 0 ? name.slice(1) : name;   // .bashrc -> bashrc
		var dot = base.lastIndexOf('.');
		return (dot === -1 ? base : base.slice(dot+1)).toLowerCase();
	}
	function isTextName(name){ return TEXT_EXT.indexOf(extOf(name)) !== -1; }
	function isEditable(row){
		return row.type === 'file' && row.size < EDIT_MAX && isTextName(row.name);
	}
	function cmMode(name){
		var e = extOf(name);
		if(e === 'php')  return 'application/x-httpd-php';
		if(e === 'js')   return 'javascript';
		if(e === 'json') return {name:'javascript', json:true};
		if(e === 'css')  return 'css';
		if(e === 'html' || e === 'htm') return 'htmlmixed';
		if(e === 'xml')  return 'xml';
		if(e === 'md' || e === 'markdown') return 'markdown';
		if(e === 'py')   return 'python';
		if(e === 'sh' || e === 'bash' || e === 'bashrc' || e === 'zsh' || e === 'env') return 'shell';
		if(e === 'sql')  return 'sql';
		return null;   // plain text (yaml/ini/etc. have no vendored mode)
	}

	function joinPath(dir, name){ return dir === '' ? name : dir + '/' + name; }
	function parentOf(path){
		if(path === '') return '';
		var i = path.lastIndexOf('/');
		return i === -1 ? '' : path.slice(0, i);
	}
	// name of the directory we're currently in (home shows the username)
	function currentFolderName(){ return fm.path === '' ? fm.user : fm.path.slice(fm.path.lastIndexOf('/') + 1); }
	// archive detection + base name (strip the archive extension) for extract defaults
	function isArchive(name){ return /\.(zip|tgz|tar\.gz|tar)$/i.test(name); }
	function archiveBase(name){ return name.replace(/\.(zip|tgz|tar\.gz|tar)$/i, ''); }

	var SVG_DIR  = '<svg class="fm-icon dir" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2"/></svg>';
	var SVG_FILE = '<svg class="fm-icon file" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/></svg>';
	// Tabler "file-type-php" (tabler.io/icons?icon=file-type-php)
	var SVG_PHP  = '<svg class="fm-icon php" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4"/><path d="M5 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6"/><path d="M17 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6"/><path d="M11 21v-6"/><path d="M14 15v6"/><path d="M11 18h3"/></svg>';
	// Tabler "file-zip" — archives (tabler.io/icons?icon=file-zip)
	var SVG_ARCHIVE = '<svg class="fm-icon archive" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 20.735a2 2 0 0 1 -1 -1.735v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2h-1"/><path d="M11 17a2 2 0 0 1 2 2v2l-2 -.5v-1.5"/><path d="M11 5l-1 0"/><path d="M13 7l-1 0"/><path d="M11 9l-1 0"/><path d="M13 11l-1 0"/><path d="M11 13l-1 0"/><path d="M13 15l-1 0"/></svg>';
	function fileIcon(name){
		if(isArchive(name)) return SVG_ARCHIVE;
		return extOf(name) === 'php' ? SVG_PHP : SVG_FILE;
	}
	// Tabler "link" icon — symlinks (tabler.io/icons?icon=link)
	var SVG_LINK = '<svg class="fm-icon link" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/></svg>';

	// ---- render --------------------------------------------------------------
	function renderBreadcrumb(){
		// Full clickable path: /home/<user>/<dir>/... — every segment from the
		// username onward is a link (except the current dir, shown bold).
		var parts = fm.path === '' ? [] : fm.path.split('/');
		var atHome = parts.length === 0;
		var html = '<span class="fm-path-static">/home/</span>';
		html += atHome
			? '<span class="fm-path-cur">' + esc(fm.user) + '</span>'
			: '<a class="fm-path-link" data-path="">' + esc(fm.user) + '</a>';
		var acc = '';
		parts.forEach(function(p, i){
			acc = acc === '' ? p : acc + '/' + p;
			var last = (i === parts.length - 1);
			html += '<span class="fm-path-sep">/</span>';
			html += last
				? '<span class="fm-path-cur">' + esc(p) + '</span>'
				: '<a class="fm-path-link" data-path="' + esc(acc) + '">' + esc(p) + '</a>';
		});
		$('#fm-breadcrumb').html(html);
	}

	// Fetch the current directory from the server, then paint. Rows are cached in
	// FM_ROWS so per-row handlers can resolve a clicked <tr> back to its row object.
	function renderList(){
		$('#fm-tbody').empty();
		$('#fm-empty').addClass('d-none');
		$('#fm-stat-counts').text('Loading…');
		$('#fm-stat-size').text('');
		fmPost('ajax-fm-list', { path: fm.path }, function(res){
			FM_ROWS = res.rows || [];
			paintList(FM_ROWS);
		}, function(msg){
			FM_ROWS = [];
			$('#fm-tbody').empty();
			$('#fm-empty').removeClass('d-none').text(msg || 'Could not read this folder.');
			$('#fm-stat-counts').text('');
		});
	}

	function paintList(rows){
		var tb = $('#fm-tbody').empty();
		// ".." parent-folder shortcut for subdirectories — navigable, but not selectable
		if(fm.path !== ''){
			$('<tr class="fm-updir">').html(
				'<td class="fm-sel-col"></td>' +
				'<td class="fm-c-name"><span class="d-flex align-items-center fm-name">' + SVG_DIR + '..</span></td>' +
				'<td class="fm-c-size text-muted">—</td>' +
				'<td class="fm-c-perms"></td>' +
				'<td class="fm-c-mtime text-muted"></td>' +
				'<td class="fm-c-actions"></td>'
			).appendTo(tb);
		}
		rows.forEach(function(r){
			var actions = '';
			// archive files (zip / tar.gz) get an Extract action first
			if(r.type === 'file' && !r.link && isArchive(r.name))
				actions += '<a href="#" class="btn btn-md btn-white fm-extract" title="Extract archive">Extract</a> ';
			if(r.type === 'file')
				actions += '<a href="#" class="btn btn-md btn-white fm-download" title="Download">Download</a> ';
			// One "Change" button (rename + permissions). Editing is on double-click for small text files.
			actions += '<a href="#" class="btn btn-md btn-white fm-change" title="Rename &amp; permissions">Change</a> ';
			actions += '<a href="#" class="btn btn-md btn-white text-danger fm-delete">Delete</a>';

			// icon: symlink -> link glyph; else folder / typed-file glyph
			var icon = r.link ? SVG_LINK : (r.type === 'dir' ? SVG_DIR : fileIcon(r.name));
			// a real (non-link) file with the owner execute bit set is highlighted in primary
			var execCls = (!r.link && r.type === 'file' && (r.perms || '')[3] === 'x') ? ' fm-exec' : '';
			var brokenCls = r.broken ? ' fm-broken' : '';
			var nameCell = (r.type === 'dir')
				? '<span class="d-flex align-items-center fm-name' + brokenCls + '">' + icon + esc(r.name) + '</span>'
				: '<span class="d-flex align-items-center' + execCls + brokenCls + '">' + icon + esc(r.name) + '</span>';

			$('<tr>')
				.addClass(r.type === 'dir' ? 'fm-dir' : 'fm-file')
				.data('row', r)
				.html(
					'<td class="fm-sel-col"><input type="checkbox" class="form-check-input m-0 fm-sel"></td>' +
					'<td class="fm-c-name">' + nameCell + '</td>' +
					'<td class="fm-c-size text-muted">' + humanSize(r.type, r.size) + '</td>' +
					'<td class="fm-c-perms"><code class="fm-perms">' + esc(r.perms || '') + '</code></td>' +
					'<td class="fm-c-mtime text-muted" title="' + esc(r.mtime || '') + '">' + esc(fmtMtime(r.mtime)) + '</td>' +
					'<td class="fm-c-actions fm-row-actions text-end">' + actions + '</td>'
				)
				.appendTo(tb);
		});
		$('#fm-empty').text('This folder is empty.').toggleClass('d-none', rows.length !== 0);
		renderStatus(rows);
		if(fm.selectMode) updateSelCount();     // fresh rows are unchecked -> reset count/select-all
	}

	// Status bar: folder/file counts, total size of files here, and the current path.
	function renderStatus(rows){
		var nDir = 0, nFile = 0, total = 0;
		rows.forEach(function(r){ if(r.type === 'dir') nDir++; else { nFile++; total += r.size || 0; } });
		$('#fm-stat-counts').text(nDir + ' folder' + (nDir === 1 ? '' : 's') + ', ' + nFile + ' file' + (nFile === 1 ? '' : 's'));
		$('#fm-stat-size').text(nFile ? 'Total ' + humanSize('file', total) : '');
		var full = '/home/' + fm.user + (fm.path ? '/' + fm.path : '');
		$('#fm-stat-path').text(full).attr('title', full);
	}

	function navigate(path){ fm.path = path; renderBreadcrumb(); renderList(); }

	// ---- hand-rolled dialog (promise-ish via callback) -----------------------
	function showPrompt(title, label, value, onOk){
		$('#fm-dialog-title').text(title);
		$('#fm-dialog-text, #fm-dialog-info').addClass('d-none');
		$('#fm-dialog-inputwrap').removeClass('d-none');
		$('#fm-dialog-cancel').removeClass('d-none');
		$('#fm-dialog-label').text(label);
		$('#fm-dialog-input').val(value || '');
		$('#fm-dialog-ok').removeClass('btn-danger').addClass('btn-primary').text('OK');
		$('#fm-dialog').removeClass('d-none');
		setTimeout(function(){ $('#fm-dialog-input').trigger('focus').select(); }, 50);
		$('#fm-dialog').data('onOk', function(){ return onOk($('#fm-dialog-input').val().trim()); });
	}
	function showConfirm(title, text, onOk){
		$('#fm-dialog-title').text(title);
		$('#fm-dialog-inputwrap, #fm-dialog-info').addClass('d-none');
		$('#fm-dialog-cancel').removeClass('d-none');
		$('#fm-dialog-text').removeClass('d-none').removeClass('text-danger').text(text);
		$('#fm-dialog-ok').removeClass('btn-primary').addClass('btn-danger').text('Delete');
		$('#fm-dialog').removeClass('d-none');
		$('#fm-dialog').data('onOk', function(){ return onOk(); });
	}
	// Read-only file/folder properties popup (used for non-editable files on dblclick).
	function showInfo(row){
		var full = '/home/' + fm.user + '/' + joinPath(fm.path, row.name);
		var kind = row.type === 'dir' ? 'Folder'
		         : (isTextName(row.name) ? (isEditable(row) ? 'Text file' : 'Text file (too large to edit)') : 'Binary file');
		var rows = [
			['Name', row.name], ['Type', kind],
			['Size', row.type === 'dir' ? '—' : humanSize(row.type, row.size) + ' (' + row.size.toLocaleString() + ' bytes)'],
			['Permissions', row.perms || '—'], ['Modified', row.mtime || '—'], ['Path', full]
		];
		var html = '<dl class="row mb-0">';
		rows.forEach(function(kv){
			html += '<dt class="col-4 text-muted fw-normal">' + esc(kv[0]) + '</dt>' +
			        '<dd class="col-8 mb-1 text-break' + (kv[0]==='Permissions'||kv[0]==='Path' ? ' font-monospace small' : '') + '">' + esc(kv[1]) + '</dd>';
		});
		html += '</dl>';
		$('#fm-dialog-title').text('File info');
		$('#fm-dialog-text, #fm-dialog-inputwrap').addClass('d-none');
		$('#fm-dialog-info').removeClass('d-none').html(html);
		$('#fm-dialog-cancel').addClass('d-none');
		$('#fm-dialog-ok').removeClass('btn-danger').addClass('btn-primary').text('Close');
		$('#fm-dialog').removeClass('d-none').data('onOk', function(){});
	}
	function closeDialog(){ $('#fm-dialog').addClass('d-none').removeData('onOk'); }

	$('#fm-dialog-cancel').on('click', closeDialog);
	$('#fm-dialog').on('click', function(e){ if(e.target === this) closeDialog(); });
	$('#fm-dialog-ok').on('click', function(){
		var fn = $('#fm-dialog').data('onOk');
		if(fn && fn() === false) return;      // returning false keeps the dialog open (validation)
		closeDialog();
	});
	$('#fm-dialog-input').on('keydown', function(e){ if(e.key === 'Enter') $('#fm-dialog-ok').click(); });

	/* Esc handling: Bootstrap's keyboard-close is disabled on the main modal
	   (data-bs-keyboard="false") so Esc never tears down the whole File Manager.
	   Instead, Esc closes the topmost hand-rolled sub-dialog (Change / info-prompt)
	   if one is open. Capture phase so it runs before anything else. */
	document.addEventListener('keydown', function(e){
		if(e.key !== 'Escape') return;
		if(!$('#modal-file-manager').hasClass('show')) return;   // only while the FM modal is open
		if(!$('#fm-archive').hasClass('d-none')){ e.preventDefault(); e.stopPropagation(); closeArchiveDialog(); return; }
		if(!$('#fm-props').hasClass('d-none'))  { e.preventDefault(); e.stopPropagation(); closeProps();  return; }
		if(!$('#fm-dialog').hasClass('d-none')) { e.preventDefault(); e.stopPropagation(); closeDialog(); return; }
		// no sub-dialog open -> swallow Esc so the main window stays put
		e.preventDefault(); e.stopPropagation();
	}, true);

	// ---- Change dialog: rename + permissions ---------------------------------
	function permToBits(perms){                          // "-rw-r--r--" -> [owner,group,other] octal
		var p = (perms || '').replace(/^./, '');
		if(p.length < 9) p = 'rw-r--r--';
		function d(s){ return (s[0]==='r'?4:0) + (s[1]==='w'?2:0) + ((s[2]==='x'||s[2]==='s'||s[2]==='t')?1:0); }
		return [d(p.slice(0,3)), d(p.slice(3,6)), d(p.slice(6,9))];
	}
	function bitsToSym(isDir, b){
		function s(n){ return (n&4?'r':'-') + (n&2?'w':'-') + (n&1?'x':'-'); }
		return (isDir?'d':'-') + s(b[0]) + s(b[1]) + s(b[2]);
	}
	function currentPropsBits(){
		var bits = [0,0,0];
		$('#fm-props .fm-perm:checked').each(function(){ bits[+$(this).data('c')] += +$(this).data('b'); });
		return bits;
	}
	function updatePropsMode(){
		var bits = currentPropsBits(), isDir = fm.propsRow && fm.propsRow.type === 'dir';
		$('#fm-props-octal').text(bits.join(''));
		$('#fm-props-sym').text(bitsToSym(isDir, bits));
	}
	function openProps(row){
		fm.propsRow = row;
		$('#fm-props-name-title').text(row.name);
		$('#fm-props-name').val(row.name);
		$('#fm-props-err').addClass('d-none').text('');
		var bits = permToBits(row.perms);
		$('#fm-props .fm-perm').each(function(){
			$(this).prop('checked', (bits[+$(this).data('c')] & +$(this).data('b')) !== 0);
		});
		updatePropsMode();
		$('#fm-props').removeClass('d-none');
		setTimeout(function(){ $('#fm-props-name').trigger('focus').select(); }, 50);
	}
	function closeProps(){ $('#fm-props').addClass('d-none'); fm.propsRow = null; }

	$('#fm-props').on('change', '.fm-perm', updatePropsMode);
	$('#fm-props-cancel').on('click', closeProps);
	$('#fm-props').on('click', function(e){ if(e.target === this) closeProps(); });
	$('#fm-props-save').on('click', function(){
		var row = fm.propsRow; if(!row) return;
		var name = $('#fm-props-name').val().trim();
		if(!name){ $('#fm-props-err').removeClass('d-none').text('Name cannot be empty.'); return; }
		if(name.indexOf('/') !== -1){ $('#fm-props-err').removeClass('d-none').text('Name cannot contain “/”.'); return; }
		var mode = currentPropsBits().join('');
		var renamed = (name !== row.name);
		var propsErr = function(msg){ $('#fm-props-err').removeClass('d-none').text(msg); };

		// chmod applies to the final name, so rename first (if any), then chmod.
		var doChmod = function(){
			fmPost('ajax-fm-chmod', { path: joinPath(fm.path, name), mode: mode }, function(){
				closeProps();
				renderList();
				toast('Updated “' + name + '” (mode ' + mode + ').');
			}, propsErr);
		};
		if(renamed){
			fmPost('ajax-fm-rename', { src: joinPath(fm.path, row.name), dst: joinPath(fm.path, name) }, doChmod, propsErr);
		} else {
			doChmod();
		}
	});

	// ---- multi-select mode ---------------------------------------------------
	function enterSelect(){
		fm.selectMode = true;
		$('#fm-browser').addClass('selecting');
		$('#fm-tools-normal').addClass('d-none');
		$('#fm-tools-select').removeClass('d-none');
		$('#fm-selectall').prop('checked', false).prop('indeterminate', false);
		updateSelCount();
	}
	function exitSelect(){
		fm.selectMode = false;
		$('#fm-browser').removeClass('selecting');
		$('#fm-tools-select').addClass('d-none');
		$('#fm-tools-normal').removeClass('d-none');
		$('#fm-tbody .fm-sel').prop('checked', false);
	}
	function selectedRows(){
		var out = [];
		$('#fm-tbody .fm-sel:checked').each(function(){ out.push($(this).closest('tr').data('row')); });
		return out;
	}
	function updateSelCount(){
		var n = $('#fm-tbody .fm-sel:checked').length, total = $('#fm-tbody .fm-sel').length;
		$('#fm-compress, #fm-delete-selected').prop('disabled', n === 0);
		$('#fm-selcount, #fm-delcount').text(n ? ' (' + n + ')' : '');
		$('#fm-selectall').prop('checked', total > 0 && n === total).prop('indeterminate', n > 0 && n < total);
	}

	$('#fm-select-btn').on('click', enterSelect);
	$('#fm-cancel-select').on('click', exitSelect);
	// bulk delete: remove every selected item (folders recursively), one call each
	$('#fm-delete-selected').on('click', function(){
		var rows = selectedRows(); if(!rows.length) return;
		showConfirm('Delete ' + rows.length + ' item(s)',
			'Delete ' + rows.length + ' selected item(s)? Folders are removed with all their contents. This cannot be undone.',
			function(){
				var i = 0, failed = 0;
				(function next(){
					if(i >= rows.length){
						exitSelect(); renderList();
						toast(failed ? ('Deleted ' + (rows.length - failed) + ', ' + failed + ' failed.')
						             : ('Deleted ' + rows.length + ' item(s).'));
						return;
					}
					var r = rows[i++];
					fmPost('ajax-fm-delete', { path: joinPath(fm.path, r.name) }, next,
						function(){ failed++; next(); });
				})();
			});
	});
	$('#fm-selectall').on('change', function(){ $('#fm-tbody .fm-sel').prop('checked', this.checked); updateSelCount(); });
	$('#fm-tbody').on('change', '.fm-sel', updateSelCount);
	// click anywhere on a row (not the checkbox itself) toggles it while selecting
	$('#fm-tbody').on('click', 'tr', function(e){
		if(!fm.selectMode || $(e.target).is('.fm-sel') || $(this).hasClass('fm-updir')) return;
		var cb = $(this).find('.fm-sel'); cb.prop('checked', !cb.prop('checked'));
		updateSelCount();
	});
	// ".." row navigates to the parent (in both normal and select mode)
	$('#fm-tbody').on('click', 'tr.fm-updir', function(){
		var p = fm.path, i = p.lastIndexOf('/');
		navigate(i === -1 ? '' : p.slice(0, i));
	});
	$('#fm-compress').on('click', function(){
		if(selectedRows().length) openArchiveDialog();
	});

	// ---- compress dialog (type + archive name) -------------------------------
	function archiveExt(){ return $('#fm-archive input[name="fm-arc-type"]:checked').val() === 'tgz' ? '.tar.gz' : '.zip'; }
	function openArchiveDialog(){
		var n = selectedRows().length;
		$('#fm-arc-count').text(n + ' item' + (n === 1 ? '' : 's'));
		$('#fm-archive input[value="zip"]').prop('checked', true);
		$('#fm-arc-ext').text('.zip');
		$('#fm-arc-name').val(currentFolderName() + '-compressed');
		$('#fm-arc-err').addClass('d-none').text('');
		$('#fm-archive').removeClass('d-none');
		setTimeout(function(){ $('#fm-arc-name').trigger('focus').select(); }, 50);
	}
	function closeArchiveDialog(){ $('#fm-archive').addClass('d-none'); }
	$('#fm-archive').on('change', 'input[name="fm-arc-type"]', function(){ $('#fm-arc-ext').text(archiveExt()); });
	$('#fm-arc-cancel').on('click', closeArchiveDialog);
	$('#fm-archive').on('click', function(e){ if(e.target === this) closeArchiveDialog(); });
	$('#fm-arc-name').on('keydown', function(e){ if(e.key === 'Enter') $('#fm-arc-ok').click(); });
	$('#fm-arc-ok').on('click', function(){
		var rows = selectedRows(); if(!rows.length){ closeArchiveDialog(); return; }
		var name = $('#fm-arc-name').val().trim();
		if(!name || name.indexOf('/') !== -1){ $('#fm-arc-err').removeClass('d-none').text('Enter a valid name (no “/”).'); return; }
		var type = $('#fm-archive input[name="fm-arc-type"]:checked').val();
		var items = rows.map(function(r){ return r.name; });
		var $ok = $('#fm-arc-ok').prop('disabled', true);
		fmPost('ajax-fm-compress', { path: fm.path, items: items, type: type, name: name }, function(res){
			$ok.prop('disabled', false);
			closeArchiveDialog();
			exitSelect();
			renderList();
			toast('Created “' + res.archive + '” — downloading…');
			// "Compress and download": stream the freshly-created archive
			window.location = '/?ajax=1&action=ajax-fm-download&user=' + encodeURIComponent(fm.user) +
			                  '&path=' + encodeURIComponent(joinPath(fm.path, res.archive));
		}, function(msg){
			$ok.prop('disabled', false);
			$('#fm-arc-err').removeClass('d-none').text(msg);
		});
	});

	// ---- extract an archive (per-row action) ---------------------------------
	$('#fm-tbody').on('click', '.fm-extract', function(e){
		e.preventDefault();
		var r = $(this).closest('tr').data('row');
		showPrompt('Extract ' + r.name, 'Extract into folder', archiveBase(r.name), function(dest){
			if(!dest) return false;
			if(dest.indexOf('/') !== -1){ dialogError('Folder name cannot contain “/”.'); return false; }
			fmPost('ajax-fm-extract', { path: fm.path, name: r.name, dest: joinPath(fm.path, dest) },
				function(){ renderList(); toast('Extracted “' + r.name + '” into “' + dest + '”.'); },
				function(msg){ toast(msg); });
		});
	});

	// ---- browse interactions -------------------------------------------------
	$('#fm-breadcrumb').on('click', '.fm-path-link', function(e){ e.preventDefault(); navigate($(this).data('path')); });
	$('#fm-refresh').on('click', function(){ renderList(); });

	$('#fm-tbody').on('click', 'tr.fm-dir .fm-name', function(){
		if(fm.selectMode) return;                 // in select mode, clicks toggle the checkbox instead
		navigate(joinPath(fm.path, $(this).closest('tr').data('row').name));
	});

	// Double-click a file: edit if editable, otherwise show a read-only info popup.
	$('#fm-tbody').on('dblclick', 'tr.fm-file', function(){
		if(fm.selectMode) return;
		var r = $(this).data('row');
		if(isEditable(r)) openEditor(r); else showInfo(r);
	});

	$('#fm-tbody').on('click', '.fm-download', function(e){
		e.preventDefault();
		var r = $(this).closest('tr').data('row');
		var url = '/?ajax=1&action=ajax-fm-download&user=' + encodeURIComponent(fm.user) +
		          '&path=' + encodeURIComponent(joinPath(fm.path, r.name));
		window.location = url;
	});

	$('#fm-tbody').on('click', '.fm-change', function(e){ e.preventDefault(); openProps($(this).closest('tr').data('row')); });

	$('#fm-tbody').on('click', '.fm-delete', function(e){
		e.preventDefault();
		var r = $(this).closest('tr').data('row');
		showConfirm('Delete ' + r.name, 'Delete “' + r.name + '”? This cannot be undone.', function(){
			fmPost('ajax-fm-delete', { path: joinPath(fm.path, r.name) }, function(){ renderList(); });
		});
	});

	$('#fm-new-folder').on('click', function(){
		showPrompt('New folder', 'Folder name', '', function(v){
			if(!v) return false;
			if(v.indexOf('/') !== -1){ dialogError('Name cannot contain “/”.'); return false; }
			fmPost('ajax-fm-mkdir', { path: joinPath(fm.path, v) },
				function(){ renderList(); },
				function(msg){ toast(msg); });
			// close the prompt immediately; errors surface as a toast
		});
	});
	$('#fm-new-file').on('click', function(){
		showPrompt('New file', 'File name', '', function(v){
			if(!v) return false;
			if(v.indexOf('/') !== -1){ dialogError('Name cannot contain “/”.'); return false; }
			fmPost('ajax-fm-newfile', { path: joinPath(fm.path, v) },
				function(){ renderList(); },
				function(msg){ toast(msg); });
		});
	});

	$('#fm-upload-btn').on('click', function(){ $('#fm-upload-input').click(); });
	$('#fm-upload-input').on('change', function(){
		var files = this.files;
		if(!files.length){ this.value = ''; return; }
		var fd = new FormData();
		fd.append('action', 'ajax-fm-upload');
		fd.append('user', fm.user);
		fd.append('path', fm.path);
		for(var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
		this.value = '';
		var total = files.length;
		var $prog = toast('Uploading ' + total + ' file(s)…', { sticky: true });
		$.ajax({ url: '/?ajax=1', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
			.done(function(res){
				$prog.remove();
				if(!res || res.error){ toast(res && res.error ? res.error : 'Upload failed.'); return; }
				renderList();
				var ok = res.count || 0, failed = total - ok;
				if(failed > 0)
					toast(ok + ' of ' + total + ' uploaded — ' + failed + ' failed (check disk quota / permissions).');
				else
					toast(ok + ' file(s) uploaded.');
			})
			.fail(function(){ $prog.remove(); toast('Upload failed. Please try again.'); });
	});

	function dialogError(msg){
		var $t = $('#fm-dialog-text').removeClass('d-none').addClass('text-danger').text(msg);
		setTimeout(function(){ $t.addClass('d-none').removeClass('text-danger'); }, 2500);
	}

	// ---- editor (CodeMirror) -------------------------------------------------
	var cm = null;
	function openEditor(row){
		fm.editing = { row: row, dir: fm.path, path: joinPath(fm.path, row.name) };
		// path with the filename bolded, e.g. /home/aa1/<b>quota.txt</b>
		var prefix = '/home/' + fm.user + '/' + (fm.path ? fm.path + '/' : '');
		$('#fm-editor-path').html(esc(prefix) + '<span class="fm-fname">' + esc(row.name) + '</span>');
		$('#fm-browser').addClass('d-none');
		$('#fm-editor').removeClass('d-none').addClass('d-flex');
		if(!cm){
			var saveKey = function(){ $('#fm-editor-save').click(); };
			cm = CodeMirror.fromTextArea(document.getElementById('fm-editor-textarea'), {
				lineNumbers: true, lineWrapping: true,
				extraKeys: { 'Ctrl-S': saveKey, 'Cmd-S': saveKey }   // save from within the editor
			});
		}
		var mode = cmMode(row.name);
		cm.setOption('mode', mode);           // null -> plain text until extra modes ship (Phase 4)
		cm.setValue('Loading…');
		cm.setOption('readOnly', true);
		// content is read (and size/text-guarded) server-side by ajax-fm-read
		fmPost('ajax-fm-read', { path: fm.editing.path }, function(res){
			cm.setOption('readOnly', false);
			cm.setValue(res.content != null ? res.content : '');
			setTimeout(function(){ cm.refresh(); cm.focus(); }, 60);
		}, function(msg){
			toast(msg);
			closeEditor();
		});
	}
	function closeEditor(){
		$('#fm-editor').addClass('d-none').removeClass('d-flex');
		$('#fm-browser').removeClass('d-none');
		fm.editing = null;
	}
	$('#fm-editor-cancel').on('click', closeEditor);
	// Ctrl/Cmd+S saves whenever the editor pane is open (covers focus outside CodeMirror).
	$(document).on('keydown', function(e){
		if((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S') && !$('#fm-editor').hasClass('d-none')){
			e.preventDefault();
			$('#fm-editor-save').click();
		}
	});
	$('#fm-editor-save').on('click', function(){
		if(!fm.editing) return;
		var val = cm.getValue(), name = fm.editing.row.name;
		var $btn = $(this).prop('disabled', true);
		fmPost('ajax-fm-save', { path: fm.editing.path, content: val }, function(){
			$btn.prop('disabled', false);
			toast('Saved “' + name + '”.');
			closeEditor();
			renderList();
		}, function(msg){
			$btn.prop('disabled', false);
			toast(msg);
		});
	});

	// ---- tiny toast (reuses Tabler alert styling) ----------------------------
	// Toasts stack vertically in a bottom-right container so they never overlap.
	// Returns the element; pass {sticky:true} for a progress toast you'll remove
	// yourself (e.g. "Uploading…" replaced by the result).
	function toast(msg, opts){
		opts = opts || {};
		var $wrap = $('#fm-toasts');
		if(!$wrap.length) $wrap = $('<div id="fm-toasts">').appendTo('#modal-file-manager .modal-body');
		var $t = $('<div class="alert alert-info shadow fm-toast">').text(msg).appendTo($wrap);
		if(!opts.sticky) setTimeout(function(){ $t.fadeOut(300, function(){ $(this).remove(); }); }, 2600);
		return $t;
	}

	// ---- open: pull user/domain from the clicked row, reset to home ----------
	$('#modal-file-manager').on('show.bs.modal', function (event){
		var button = event.relatedTarget;
		fm.user   = button.getAttribute('data-bs-user')   || '';
		fm.domain = button.getAttribute('data-bs-domain') || '';
		fm.path   = '';
		$('#fm-title').text(fm.user + (fm.domain ? '  (' + fm.domain + ')': ''));
		closeEditor();
		closeDialog();
		renderBreadcrumb();
		renderList();
	});
	// CodeMirror measures wrong if created while hidden — refresh once shown.
	$('#modal-file-manager').on('shown.bs.modal', function(){ if(cm && !$('#fm-editor').hasClass('d-none')) cm.refresh(); });
});
</script>
