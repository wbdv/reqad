<?php
	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
		#echo $row["name"].' = '.$row["value"].'<br>';
		$settings_name = $row["name"];
		$settings[$settings_name] = $row["value"];
	}
	#echo "<pre>"; print_r($settings);exit;
	include('templates/header.php');
?>
        <!-- Page title -->
        <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col" style="padding-left:22px;">
                <!-- Page pre-title -->
                <div class="page-pretitle">
                  Settings
                </div>
                <h2 class="page-title">
                  DNS Settings
                </h2>
              </div>
            </div>
        </div>

		<? if(isset($errmsg) && $errmsg != '') { ?>
          <div class="alert alert-warning fadeout" role="alert" style="background:#FFE;">
            <div class="d-flex">
				<div style="width:55px;">
                	<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-danger icon-md" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"></path><path d="M12 9v2m0 4v.01"></path><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"></path></svg>
             	</div>
             	<div>
				 <h3 class="text-danger" style="margin-top:6px;margin-bottom:0">Error</h3>
				 <div class="text-danger"><?=str_replace('Error: ', '', clean($errmsg));?></div>
              	</div>
            </div>
          </div>
		<? } ?>
		<? if(isset($successmsg) && $successmsg != '') { ?>
          <div class="alert alert-success fadeout" role="alert" style="background:#EFE;">
            <div class="d-flex">
				<div style="width:55px;">
					<svg xmlns="http://www.w3.org/2000/svg" class="icon mb-2 text-success icon-md icon-tabler-circle-check" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><path d="M9 12l2 2l4 -4" /></svg>
              	</div>
              	<div>
                	<h3 class="text-success" style="margin-top:6px;margin-bottom:0">Success</h3>
                	<div class="text-success"><?=clean($successmsg);?></div>
              	</div>
            </div>
          </div>
		<? } ?>

		<form method="post" action="/" id="dns-settings" class="needs-validation" novalidate="" style="padding-left:14px;">
		<input type="hidden" name="action" value="dns-settings">
		<label class="form-label">Select DNS Provider:</label>

		<div class="form-selectgroup form-selectgroup-boxes d-flex flex-column">
		<div class="row row-deck row-cards">
            <div class="col-md-6 col-lg-4 col-xl-3">

			  <label class="form-selectgroup-item flex-fill" style="height:94px;">
					<input type="radio" name="dns-provider" id="cloudflare" value="cloudflare" class="form-selectgroup-input" <?=$settings['dns-provider']=='cloudflare'?'checked=""':'';?>>
					<div class="form-selectgroup-label d-flex align-items-center p-3">
						<div class="me-3">
							<span class="form-selectgroup-check"></span>
						</div>
						<div style="padding:10px 0;height:50px;">
							<svg xmlns="http://www.w3.org/2000/svg" width="150" height="30" fill="none" viewBox="0 0 204 30" style="width:150px;height:30px"><g clip-path="url(#a)"><path fill="#FBAD41" d="M52.688 13.028c-.22 0-.437.008-.654.015a.297.297 0 0 0-.102.024.365.365 0 0 0-.236.255l-.93 3.249c-.401 1.397-.252 2.687.422 3.634.618.876 1.646 1.39 2.894 1.45l5.045.306c.15.008.28.08.359.199a.492.492 0 0 1 .051.434.64.64 0 0 1-.547.426l-5.242.306c-2.848.132-5.912 2.456-6.987 5.29l-.378 1a.28.28 0 0 0 .248.382h18.054a.48.48 0 0 0 .464-.35 13.12 13.12 0 0 0 .48-3.54c0-7.22-5.789-13.072-12.933-13.072"></path><path fill="#000" d="M85.519 18.886h2.99v8.249h5.218v2.647h-8.208V18.886ZM96.819 24.365v-.032c0-3.13 2.493-5.665 5.821-5.665 3.327 0 5.789 2.508 5.789 5.633v.032c0 3.129-2.493 5.665-5.821 5.665s-5.79-2.505-5.79-5.633Zm8.562 0v-.032c0-1.573-1.123-2.942-2.773-2.942-1.65 0-2.725 1.337-2.725 2.91v.032c0 1.572 1.122 2.942 2.757 2.942 1.634 0 2.741-1.338 2.741-2.91ZM112.086 25.003V18.89h3.033v6.055c0 1.572.783 2.317 1.985 2.317 1.201 0 1.985-.717 1.985-2.242v-6.134h3.032v6.039c0 3.519-1.985 5.056-5.049 5.056s-4.99-1.573-4.99-4.98M126.694 18.889h4.159c3.848 0 6.081 2.241 6.081 5.382v.032c0 3.14-2.265 5.477-6.144 5.477h-4.096V18.886v.004Zm4.202 8.216c1.788 0 2.97-.995 2.97-2.754v-.032c0-1.744-1.185-2.755-2.97-2.755h-1.217v5.541h1.217ZM141.277 18.886h8.621v2.648h-5.636v1.85h5.096v2.505h-5.096v3.893h-2.985V18.886ZM154.054 18.886h2.989v8.249h5.219v2.647h-8.208V18.886ZM170.067 18.809h2.878l4.589 10.971h-3.202l-.788-1.946h-4.159l-.768 1.946h-3.143l4.589-10.971h.004Zm2.619 6.676-1.202-3.097-1.217 3.097h2.419ZM181.383 18.889h5.096c1.647 0 2.789.438 3.509 1.182.635.621.954 1.465.954 2.536v.032c0 1.664-.879 2.77-2.218 3.344l2.572 3.797h-3.45l-2.17-3.3h-1.308v3.3h-2.989V18.886l.004.004Zm4.959 5.23c1.016 0 1.602-.497 1.602-1.29v-.031c0-.856-.614-1.29-1.618-1.29h-1.954v2.616h1.973l-.003-.004ZM195.253 18.886h8.669v2.568h-5.711v1.648h5.175v2.384h-5.175v1.728h5.79v2.568h-8.748V18.886ZM78.976 25.642c-.418.956-1.3 1.633-2.47 1.633-1.63 0-2.756-1.37-2.756-2.942V24.3c0-1.573 1.094-2.91 2.725-2.91 1.229 0 2.166.764 2.564 1.807h3.147c-.505-2.591-2.757-4.53-5.683-4.53-3.324 0-5.821 2.536-5.821 5.665v.032c0 3.129 2.461 5.633 5.79 5.633 2.843 0 5.068-1.864 5.655-4.36h-3.155l.004.004Z"></path><path fill="#F6821F" d="m44.808 29.578.334-1.175c.402-1.397.253-2.687-.42-3.634-.62-.876-1.647-1.39-2.896-1.45l-23.665-.306a.467.467 0 0 1-.374-.199.492.492 0 0 1-.052-.434.64.64 0 0 1 .552-.426l23.886-.306c2.836-.131 5.9-2.456 6.975-5.29l1.362-3.6a.914.914 0 0 0 .04-.477C48.998 5.259 42.79 0 35.368 0c-6.842 0-12.647 4.462-14.73 10.665a6.92 6.92 0 0 0-4.911-1.374c-3.28.33-5.92 3.002-6.246 6.318a7.148 7.148 0 0 0 .18 2.472c-5.36.16-9.66 4.598-9.66 10.052 0 .493.035.979.106 1.453a.46.46 0 0 0 .457.402h43.704a.57.57 0 0 0 .54-.418"></path></g><defs><clipPath id="a"><path fill="#FFF" d="M0 0h204v30H0z"></path></clipPath></defs></svg>
						</div>
					</div>
				</label>

			</div><div class="col-md-6 col-lg-4 col-xl-3">

				<label class="form-selectgroup-item flex-fill" style="height:94px;">
					<input type="radio" name="dns-provider" id="cpanel" value="cpanel" class="form-selectgroup-input" <?=$settings['dns-provider']=='cpanel'?'checked=""':'';?>>
					<div class="form-selectgroup-label d-flex align-items-center p-3">
						<div class="me-3">
							<span class="form-selectgroup-check"></span>
						</div>
						<div style="padding:8px 0 2px 0;height:50px;">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300" style="width:150px;height:40px;"><defs><style>.cls-1{fill:#ff6c2c;}</style></defs><title>cPanel</title><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path class="cls-1" d="M89.69,59.1h67.8L147,99.3a25.38,25.38,0,0,1-9,13.5,24.32,24.32,0,0,1-15.3,5.1H91.19a30.53,30.53,0,0,0-19,6.3,33,33,0,0,0-11.55,17.1,31.91,31.91,0,0,0-.45,15.3A33.1,33.1,0,0,0,66,169.35a30.29,30.29,0,0,0,10.8,8.85,31.74,31.74,0,0,0,14.4,3.3h19.2a10.8,10.8,0,0,1,8.85,4.35,10.4,10.4,0,0,1,2,9.75l-12,44.4h-21a84.77,84.77,0,0,1-39.75-9.45A89.78,89.78,0,0,1,18.29,205.5,88.4,88.4,0,0,1,1.94,170,87.51,87.51,0,0,1,3,129l1.2-4.5A88.69,88.69,0,0,1,35.84,77.25a89.91,89.91,0,0,1,25-13.35A87,87,0,0,1,89.69,59.1Z"/><path class="cls-1" d="M123.89,240,183,18.6a25.38,25.38,0,0,1,9-13.5A24.32,24.32,0,0,1,207.29,0H270a84.77,84.77,0,0,1,39.75,9.45,89.21,89.21,0,0,1,46.65,60.6,83.8,83.8,0,0,1-1.2,41l-1.2,4.5a89.88,89.88,0,0,1-12,26.55,87.65,87.65,0,0,1-73.2,39.15h-54.3l10.8-40.5a25.38,25.38,0,0,1,9-13.2,24.32,24.32,0,0,1,15.3-5.1H267a31.56,31.56,0,0,0,30.6-23.7A29.39,29.39,0,0,0,298,84a33.1,33.1,0,0,0-5.85-12.75,31.76,31.76,0,0,0-10.8-9A30.61,30.61,0,0,0,267,58.8h-33.6l-43.8,162.9a25.38,25.38,0,0,1-9,13.2,23.88,23.88,0,0,1-15,5.1Z"/><path class="cls-1" d="M498,121.8l.9-3.3a4.41,4.41,0,0,0-.75-4,4.58,4.58,0,0,0-3.75-1.65h-97.5a24,24,0,0,1-11.4-2.7,24.94,24.94,0,0,1-8.4-7,24.6,24.6,0,0,1-4.5-10,25.5,25.5,0,0,1,.3-11.7l6-22.8h132a47.39,47.39,0,0,1,22.5,5.4,51.93,51.93,0,0,1,17,14.1,50.34,50.34,0,0,1,9.3,20,49.79,49.79,0,0,1-.45,23.25l-23.7,88.2a40.62,40.62,0,0,1-39.6,30.3l-97.5-.3A51.59,51.59,0,0,1,357,219.15a54.4,54.4,0,0,1-9.6-21A49.48,49.48,0,0,1,348,174l1.2-4.5a47.58,47.58,0,0,1,7.05-15.6,54,54,0,0,1,11.55-12.3,52.06,52.06,0,0,1,14.7-7.95,51.14,51.14,0,0,1,17.1-2.85h81.9l-6,22.5a25.49,25.49,0,0,1-9,13.2,23.92,23.92,0,0,1-15,5.1h-36.6q-5.11,0-6.6,5.1a6.13,6.13,0,0,0,1.2,5.85,6.65,6.65,0,0,0,5.4,2.55H474a9.27,9.27,0,0,0,5.7-1.8,7.76,7.76,0,0,0,3-4.8l.6-2.4Z"/><path class="cls-1" d="M672.59,59.1a85.39,85.39,0,0,1,40,9.45,89.82,89.82,0,0,1,30.16,25,88.39,88.39,0,0,1,16.34,35.7,85.78,85.78,0,0,1-1.34,41.1l-15,56.4a16.53,16.53,0,0,1-6.45,9.6,18.22,18.22,0,0,1-11,3.6H693a11,11,0,0,1-10.81-14.1l18-68.1a29.39,29.39,0,0,0,.45-14.7,33.23,33.23,0,0,0-5.84-12.75,32,32,0,0,0-10.8-9,30.67,30.67,0,0,0-14.4-3.45H636L606.88,226.8a16.4,16.4,0,0,1-6.45,9.6,18.65,18.65,0,0,1-11.25,3.6h-32.1a10.78,10.78,0,0,1-8.84-4.35,10.43,10.43,0,0,1-2-9.75l44.4-166.8Z"/><path class="cls-1" d="M849.28,116.25a15.34,15.34,0,0,0-5.1,7.35l-13.5,51a9,9,0,0,0,8.7,11.4h124.2L954,221.7a25.38,25.38,0,0,1-9,13.2,23.88,23.88,0,0,1-15,5.1H816.88a48.43,48.43,0,0,1-22.5-5.25,49.48,49.48,0,0,1-17-14.1,51.48,51.48,0,0,1-9.3-20.1,46,46,0,0,1,.75-23l18.3-68.1a67.5,67.5,0,0,1,9.3-20.4,67.3,67.3,0,0,1,34-26.25,65.91,65.91,0,0,1,22.05-3.75h80.1a47.34,47.34,0,0,1,22.5,5.4,51.83,51.83,0,0,1,17,14.1,48.65,48.65,0,0,1,9.15,20.1,50.2,50.2,0,0,1-.6,23.1l-5.4,20.4A39.05,39.05,0,0,1,960.73,164,40.08,40.08,0,0,1,936,172.2h-90.6l6-22.2a23.78,23.78,0,0,1,8.7-13.2,24.32,24.32,0,0,1,15.3-5.1H912q5.1,0,6.6-5.1l1.2-4.5a6.92,6.92,0,0,0-6.6-8.7h-55.8A12.71,12.71,0,0,0,849.28,116.25Z"/><path class="cls-1" d="M963.28,240l60.3-226.5A17.06,17.06,0,0,1,1030,3.75,18.14,18.14,0,0,1,1041.28,0h32.1a11.11,11.11,0,0,1,9.15,4.35,10.43,10.43,0,0,1,2,9.75l-45,167.1a74.52,74.52,0,0,1-10.65,24,78.66,78.66,0,0,1-17.4,18.45,81.65,81.65,0,0,1-22.35,12A76.85,76.85,0,0,1,963.28,240Z"/><path class="cls-1" d="M1094.83,21.06a20.4,20.4,0,0,1,2.75-10.29A20.6,20.6,0,0,1,1115.48.42a20.39,20.39,0,0,1,10.29,2.74,20.13,20.13,0,0,1,7.58,7.55,20.73,20.73,0,0,1,.11,20.51,20.67,20.67,0,0,1-36,0A20.37,20.37,0,0,1,1094.83,21.06Zm2.88,0a17.76,17.76,0,0,0,8.91,15.39,17.67,17.67,0,0,0,17.73,0,17.89,17.89,0,0,0,6.49-6.47,17.21,17.21,0,0,0,2.4-8.91,17.18,17.18,0,0,0-2.39-8.86,17.89,17.89,0,0,0-6.46-6.5,17.7,17.7,0,0,0-17.78,0,17.87,17.87,0,0,0-6.49,6.46A17.17,17.17,0,0,0,1097.71,21.06Zm26.14-5a6.64,6.64,0,0,1-1.17,3.88,6.79,6.79,0,0,1-3.28,2.51l6.54,10.85h-4.61l-5.69-9.72h-3.7v9.72h-4.07V8.85H1115c3,0,5.26.59,6.68,1.78A6.69,6.69,0,0,1,1123.85,16.07Zm-11.91,4.14h3a5.24,5.24,0,0,0,3.53-1.14,3.63,3.63,0,0,0,1.33-2.89,3.44,3.44,0,0,0-1.18-2.95,6.19,6.19,0,0,0-3.73-.9h-2.91Z"/></g></g></svg>
						</div>
					</div>
				</label>

			</div><div class="col-md-6 col-lg-4 col-xl-3">

				<label class="form-selectgroup-item flex-fill" style="height:94px;">
					<input type="radio" name="dns-provider" id="powerdns" value="powerdns" class="form-selectgroup-input" <?=$settings['dns-provider']=='powerdns'?'checked=""':'';?>>
					<div class="form-selectgroup-label d-flex align-items-center p-3">
						<div class="me-3">
							<span class="form-selectgroup-check"></span>
						</div>
						<div style="padding:15px 0 14px 0;height:50px;">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1088.50394 147.40157" style="width:140px;"><defs><style>.cls-1 { fill: #e66e00; } .cls-2 { fill: #666; }</style></defs><title>Powerdns logo</title><g id="Ebene_2" data-name="Ebene 2"><g id="Ebene_1-2" data-name="Ebene 1"><path class="cls-1" d="M929.76378,34.017A34.01575,34.01575,0,1,1,895.748.00131,34.01538,34.01538,0,0,1,929.76378,34.017"/><path class="cls-1" d="M929.76378,113.38584A34.01575,34.01575,0,1,1,895.748,79.37011a34.01538,34.01538,0,0,1,34.01579,34.01573"/><path class="cls-1" d="M1009.13386,34.017A34.01575,34.01575,0,1,1,975.11807.00131,34.01538,34.01538,0,0,1,1009.13386,34.017"/><path class="cls-1" d="M1009.13386,113.38584a34.01575,34.01575,0,1,1-34.01579-34.01573,34.01538,34.01538,0,0,1,34.01579,34.01573"/><path class="cls-1" d="M1088.50394,34.017A34.01575,34.01575,0,1,1,1054.48814.00131a34.01539,34.01539,0,0,1,34.0158,34.01573"/><path class="cls-1" d="M1088.50394,113.38584a34.01575,34.01575,0,1,1-34.0158-34.01573,34.01539,34.01539,0,0,1,34.0158,34.01573"/><path class="cls-2" d="M37.12315,66.25226c.804.07118,1.37372.10381,1.709.10381a15.16057,15.16057,0,0,0,9.35793-3.01915q5.43406-3.95931,5.43258-12.91889,0-7.70505-4.225-12.50069A12.21658,12.21658,0,0,0,39.74,33.64663c-.67351,0-1.54284.03559-2.61689.1038Zm0,78.54537H0V2.604H35.91557q26.86326,0,39.9418,11.35592,15.29194,13.3326,15.29194,37.08395,0,15.52133-6.4384,26.97957Q76.157,93.34009,57.94854,96.98354a76.505,76.505,0,0,1-14.9893,1.24858q-1.71345,0-5.83609-.1038v46.66931"/>
							<path class="cls-2" d="M151.79628,35.83536q-5.73226,0-9.355,7.708-4.83325,10.00055-4.83028,30.10549,0,22.08309,5.13,31.56466,3.32007,6.25033,8.453,6.24886,5.02907,0,8.75266-6.56324,5.13143-8.95959,5.13-30.4169,0-38.64987-13.2803-38.64691m0-35.83536a43.72737,43.72737,0,0,1,29.27541,10.834Q203.609,30.1055,203.609,74.17086q0,34.477-15.69543,54.89635a48.11124,48.11124,0,0,1-15.99512,13.54168,42.69846,42.69846,0,0,1-19.71871,4.79268q-15.194,0-28.37048-9.89677Q99.178,118.86052,99.17944,72.6079q0-35.10874,16.19687-54.48112Q130.468-.00147,151.79628,0"/>
							<polygon class="cls-2" points="330.977 144.798 295.162 144.798 281.276 58.752 270.31 144.798 234.193 144.798 203.609 2.604 240.231 2.604 252.401 88.65 265.281 2.604 297.173 2.604 311.157 89.691 322.627 2.604 358.846 2.604 330.977 144.798"/>
							<polygon class="cls-2" points="368.535 144.798 368.535 2.604 437.45 2.604 437.45 36.043 405.658 36.043 405.658 56.774 434.23 56.774 434.23 88.961 405.658 88.961 405.658 110.629 437.45 110.629 437.45 144.798 368.535 144.798"/>
							<path class="cls-2" d="M493.021,68.3372q1.50874.10232,2.516.1038a12.7242,12.7242,0,0,0,10.56253-5.00028,20.07071,20.07071,0,0,0,4.32587-12.91889q0-11.87343-9.355-14.99787a26.696,26.696,0,0,0-8.04948-.94015Zm60.56547,76.46043H513.44585L493.021,86.35719v58.44044H455.89787V2.604H493.926q25.15422,0,37.52665,10.20817a41.14544,41.14544,0,0,1,11.6722,16.0448,53.944,53.944,0,0,1,4.12414,21.14591q0,25.8333-21.22891,35.62478Z"/>
							<path class="cls-2" d="M600.433,110.42143q19.92045-.93867,19.92048-35.521,0-23.23084-9.45588-33.1276-3.42242-3.54112-10.4646-3.64493Zm-37.12312,34.3762V2.604h32.094q29.97864,0,45.97669,18.75254,16.903,19.792,16.903,52.3962,0,35.42014-17.00391,54.6917a46.4536,46.4536,0,0,1-22.93791,14.1645,79.77785,79.77785,0,0,1-20.32394,2.18874Z"/>
							<polygon class="cls-2" points="670.273 144.798 670.273 2.604 705.687 2.604 731.541 76.879 731.541 2.604 766.956 2.604 766.956 144.798 731.541 144.798 705.687 70.004 705.687 144.798 670.273 144.798"/>
							<path class="cls-2" d="M778.66055,141.25651V96.67214q10.966,15.52134,23.641,15.51985a11.791,11.791,0,0,0,8.04952-2.81154,8.23921,8.23921,0,0,0,2.8186-6.45944q0-4.89351-4.52765-9.271-1.00573-.93867-8.04943-6.667a81.36344,81.36344,0,0,1-13.38121-13.12352,43.65749,43.65749,0,0,1-9.0553-27.19014,51.61349,51.61349,0,0,1,6.8419-25.6272Q796.872,0,819.90776,0q13.18242,0,26.66156,7.29283V50.83621a39.25472,39.25472,0,0,0-7.74691-9.37478q-7.2409-6.35712-13.98344-6.35564a9.63531,9.63531,0,0,0-8.15038,3.85549,7.4729,7.4729,0,0,0-1.709,4.68592q0,4.37747,5.12987,9.27395,1.10827,1.1433,8.35212,6.56027a70.893,70.893,0,0,1,12.675,11.87789q9.25713,10.93479,9.257,27.70916,0,21.2512-12.47621,34.79141-12.47481,13.5417-31.18912,13.54169a70.65345,70.65345,0,0,1-28.06782-6.14506"/>
							</g></g></svg>							
						</div>
					</div>
				</label>

			</div><div class="col-md-6 col-lg-4 col-xl-3">

					<label class="form-selectgroup-item flex-fill" style="height:94px;min-width:200px;">
						<input type="radio" name="dns-provider" value="" class="form-selectgroup-input" <?=$settings['dns-provider']==''?'checked=""':'';?>>
						<div class="form-selectgroup-label d-flex align-items-center p-3">
							<div class="me-3">
								<span class="form-selectgroup-check"></span>
							</div>
							<div style="padding:12px 0 0 0;height:50px;"><h2>None (manual)</h2></div>
						</div>
					</label>
				</div>
        	</div>


			<div class="card" id="cloudflare-settings" style="<?=$settings['dns-provider']=='cloudflare'?'':'display:none';?>;">
				<div class="card-header border-0">
					<div class="card-title">Cloudflare API Settings</div>
				</div>
				<div class="card-body" style="max-width:400px;">
					<label class="form-label">API Token:</label>
					<input type="text" name="cloudflare-api-token" value="<?=$settings['cloudflare-api-token'];?>" class="form-control" style="font-family:monospace;" placeholder=""><br>
<? /* no need					
					<label class="form-label">Zone ID:</label>
					<input type="text" name="cloudflare-zone-id" value="<?=$settings['cloudflare-zone-id'];?>" class="form-control" style="font-family:monospace;" placeholder=""><br>
					<label class="form-label">Account ID:</label>
					<input type="text" name="cloudflare-account-id" value="<?=$settings['cloudflare-account-id'];?>" class="form-control" style="font-family:monospace;" placeholder=""><br>
*/ ?>
					<label class="form-check" style="cursor:pointer;">
						<input class="form-check-input" type="checkbox" name="cloudflare-test" value="1">
						<span class="form-check-label">Test Cloudflare API connection</span>
					</label>
				</div>
			</div>


			<div class="card" id="cpanel-settings" style="<?=$settings['dns-provider']=='cpanel'?'':'display:none';?>;">
				<div class="card-header border-0">
					<div class="card-title">cPanel API Settings</div>
				</div>
				<div class="card-body" style="max-width:600px;">
					<?
						if($settings['dns-provider']=='cpanel' && $settings['cpanel-api-token']!='' && $settings['cpanel-server']!='' && $settings['cpanel-username']!='') {
							$cp_http = trim(shell_exec('curl -s -o /dev/null -w "%{http_code}" https://'.$settings['cpanel-server'].':2087/json-api/listzones?api.version=1 --header "Authorization: whm '.$settings['cpanel-username'].':'.$settings['cpanel-api-token'].'"'));
							if($cp_http == '200' || $cp_http == '403') {
								echo '<div class="alert alert-info" role="alert"><div class="alert-icon" style="float:left;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon alert-icon icon-2"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"></path><path d="M12 9h.01"></path><path d="M11 12h1v4h1"></path></svg></div><div class="text" style="float:left"><b>Connected</b> to '.$settings['cpanel-server'].' as '.$settings['cpanel-username'].'</div><br></div>';
							}
						}
					?>
					<label class="form-label">API Token:</label>
					<input type="text" name="cpanel-api-token" value="<?=$settings['cpanel-api-token'];?>" class="form-control" style="font-family:monospace;" placeholder=""><br>
					<label class="form-label">Server address:</label>
					<input type="text" name="cpanel-server" value="<?=$settings['cpanel-server'];?>" class="form-control" style="font-family:monospace;" placeholder="server.dom"><br>
					<label class="form-label">Username:</label>
					<input type="text" name="cpanel-username" value="<?=$settings['cpanel-username'];?>" class="form-control" style="font-family:monospace;" placeholder="username"><br>
					<label class="form-check" style="cursor:pointer;">
						<input class="form-check-input" type="checkbox" name="cpanel-test" value="1">
						<span class="form-check-label">Test cPanel API connection</span>
					</label>
				</div>
			</div>

			<div class="card" id="powerdns-settings" style="<?=$settings['dns-provider']=='powerdns'?'':'display:none';?>;">
				<div class="card-header border-0">
					<div class="card-title">PowerDNS API Settings</div>
				</div>
				<div class="card-body" style="max-width:500px;">
					<label class="form-label">Server address:</label>
					<input type="text" name="powerdns-server" value="<?=$settings['powerdns-server'];?>" class="form-control" style="font-family:monospace;" placeholder="http://127.0.0.1:8081"><br>
					<label class="form-label">API Key:</label>
					<input type="text" name="powerdns-api-key" value="<?=$settings['powerdns-api-key'];?>" class="form-control" style="font-family:monospace;" placeholder=""><br>

					<label class="form-label mt-2">Mode:</label>
					<div class="mb-3">
						<label class="form-check">
							<input class="form-check-input" type="radio" name="powerdns-mode" id="pdns-mode-direct" value=""
								<?=($settings['powerdns-mode']??'')==''?'checked':''?>>
							<span class="form-check-label">Direct (local authoritative)</span>
						</label>
						<label class="form-check">
							<input class="form-check-input" type="radio" name="powerdns-mode" id="pdns-mode-hidden-master" value="hidden-master"
								<?=($settings['powerdns-mode']??'')=='hidden-master'?'checked':''?>>
							<span class="form-check-label">Hidden master (push to cPanel DNS via agent)</span>
						</label>
					</div>

					<div id="powerdns-hidden-master-settings" style="<?=($settings['powerdns-mode']??'')=='hidden-master'?'':'display:none';?>;">
						<label class="form-label">NS1 (public, on cPanel):</label>
						<input type="text" name="powerdns-ns1" value="<?=$settings['powerdns-ns1']??'';?>" class="form-control" style="font-family:monospace;" placeholder="ns1.yourdomain.com"><br>
						<label class="form-label">NS2 (public, on cPanel):</label>
						<input type="text" name="powerdns-ns2" value="<?=$settings['powerdns-ns2']??'';?>" class="form-control" style="font-family:monospace;" placeholder="ns2.yourdomain.com"><br>
						<label class="form-label">Agent URL:</label>
						<input type="text" name="powerdns-agent-url" value="<?=$settings['powerdns-agent-url']??'';?>" class="form-control" style="font-family:monospace;" placeholder="https://cpanel.example.com:2089"><br>
						<label class="form-label">Agent token:</label>
						<input type="text" name="powerdns-agent-token" value="<?=$settings['powerdns-agent-token']??'';?>" class="form-control" style="font-family:monospace;" placeholder=""><br>
					</div>

					<label class="form-check" style="cursor:pointer;">
						<input class="form-check-input" type="checkbox" name="powerdns-test" value="1">
						<span class="form-check-label">Test PowerDNS API connection</span>
					</label>
				</div>
			</div>

			<br><input type="submit" id="submit-btn" class="btn btn-primary" value="Save" style="max-width:100px;"><br>
		</div>
	</form>
	<?php
    include('templates/footer.php'); 
?>
<script>
jQuery(document).ready(function () {
	'use strict';
	$('.fadeout').delay(5000).fadeOut(2000);
	$('input[type=radio][name=dns-provider]').change(function() {
		if($('#cloudflare').prop('checked')) {
			$('#cloudflare-settings').show();
		} else {
			$('#cloudflare-settings').hide();
		}
		if($('#cpanel').prop('checked')) {
			$('#cpanel-settings').show();
		} else {
			$('#cpanel-settings').hide();
		}
		if($('#powerdns').prop('checked')) {
			$('#powerdns-settings').show();
		} else {
			$('#powerdns-settings').hide();
		}
	});
	$('input[type=radio][name=powerdns-mode]').change(function() {
		if($('#pdns-mode-hidden-master').prop('checked')) {
			$('#powerdns-hidden-master-settings').show();
		} else {
			$('#powerdns-hidden-master-settings').hide();
		}
	});
	$('#dns-settings').on('submit', function (event) {
		event.preventDefault();
		event.stopPropagation();
		var button = event.relatedTarget;
		$('#submit-btn').prop('value', 'Saving...');
		$('#submit-btn').prop('disabled', true);
		$('#dns-settings').unbind('submit').submit();
	});
});
</script>
</body>
</html>