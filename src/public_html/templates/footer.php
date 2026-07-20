<form method="post" action="/" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="reboot-server">
    <div class="modal modal-blur fade" id="modal-reboot-server" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" style="font-size:16pt;margin:40px 0 15px 0;">Reboot Server</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
			<p>Please confirm that you want to reboot the server.</p>
			<div class="modal-footer">
            <a href="#" class="btn btn-link link-secondary" data-bs-dismiss="modal">
              Cancel
            </a>
            <button id="submit-btn-reboot" class="btn btn-primary" type="submit">
				<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-power" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="#ffffff" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 6a7.75 7.75 0 1 0 10 0" /><path d="M12 4l0 8" /></svg>
				Reboot server</button>
          </div>
        </div>
      </div>
    </div>
</form>

	<!-- Libs JS -->
    <script src="./dist/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="./dist/libs/jquery/dist/jquery-3.6.0.min.js"></script>
    <script src="./dist/libs/apexcharts/dist/apexcharts.min.js"></script>
    <!--
    <script src="./dist/libs/jqvmap/dist/jquery.vmap.min.js"></script>
    <script src="./dist/libs/jqvmap/dist/maps/jquery.vmap.world.js"></script>
    -->
    <script src="./dist/libs/jquery.pwstrength.bootstrap/pwstrength-bootstrap.min.js"></script>
    <!-- Tabler Core -->
    <script src="./dist/js/tabler.min.js"></script>
    <script>
    // CSRF: expose the token, attach it to every AJAX request as a header, and
    // inject it as a hidden field into every same-origin POST form (covers both
    // user submits and programmatic form.submit()). See modules/functions.php.
    window.CSRF = <?=json_encode(isset($csrf_token) ? $csrf_token : csrf_token());?>;
    jQuery(function ($) {
        $(document).ajaxSend(function (e, xhr) { xhr.setRequestHeader('X-CSRF-Token', window.CSRF); });
        function addToken(form) {
            if (((form.getAttribute('method') || '').toLowerCase()) !== 'post') return;
            if (form.querySelector('input[name="csrf"]')) return;
            $('<input>', { type: 'hidden', name: 'csrf', value: window.CSRF }).appendTo(form);
        }
        $('form').each(function () { addToken(this); });
        $(document).on('submit', 'form', function () { addToken(this); });
    });
    </script>
    <script>
    jQuery(document).ready(function () {
    "use strict";
    var options = {};
    options.ui = {
        container: "#pwd-container",
        viewports: {
            progress: ".pwstrength_viewport_progress"
        },
        // showVerdictsInsideProgressBar: true
        showVerdicts: false,
        showProgressBar: true,
        //showStatus: true
        progressBarEmptyPercentage: 10,
        progressBarMinPercentage: 15
    };
    options.common = {
        // debug: true,
    };
    $('#password').pwstrength(options);

    var options2 = {};
    options2.ui = {
        container: "#pwd-container2",
        viewports: {
            progress: ".pwstrength_viewport_progress"
        },
        // showVerdictsInsideProgressBar: true
        showVerdicts: false,
        showProgressBar: true,
        //showStatus: true
        progressBarEmptyPercentage: 10,
        progressBarMinPercentage: 15
    };
    $('#password2').pwstrength(options2);
    });
  </script>
