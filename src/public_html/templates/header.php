<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title><?=substr($_SERVER['HTTP_HOST'],0,strpos($_SERVER['HTTP_HOST'], '.'));?> - Reqad &gt; <?=$route=='ssl'?'SSL':ucwords(str_replace('-', ' ', $route));?> </title>
    <base href="<?php echo $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']; ?>"/>
    <!-- CSS files -->
	<? /*	
    <link href="./dist/libs/jqvmap/dist/jqvmap.min.css" rel="stylesheet"/>
	*/ ?>
    <link href="./dist/css/tabler.min.css" rel="stylesheet" async/>
	<? /*	
    <link href="./dist/css/tabler-flags.min.css" rel="stylesheet"/>
    <link href="./dist/css/tabler-payments.min.css" rel="stylesheet"/>
    <link href="./dist/css/tabler-vendors.min.css" rel="stylesheet"/>
    <link href="./dist/css/demo.min.css" rel="stylesheet"/>
	*/ ?>
	<? /*	
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v3/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v3/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa25L7SUc.woff2" as="font" type="font/woff2" crossorigin>
*/ ?>
	<link href="./dist/fonts/fonts.css" rel="stylesheet"/>
    <style>
        .navbar-dark .dropdown-item.active, .navbar-dark .dropdown-item:active { color: #FFF; background-color:transparent; }
        @media (min-width:992px) {
			#pagehead { margin-top: -14.5px; display: block; border-top:15px solid #475d7a; position:fixed !important; height: 75px; width:calc(100% - 240px); z-index: 10; background-color: rgba(255,255,255,0.9); }
			#pagehead .container-xl { display: flex; margin-left: auto; marigin-right: auto;}
            /* .page { border-top:15px solid #26B; } #0075df? */
            .page { border-top:15px solid #475d7a; }
            .content { margin-top: 100px; }
            .navbar-expand-lg { border-top:15px solid #475d7a; }
            .top27 { margin-top:27px; }
        }
        .navbar-dark {
            background: #35465c !important;
        }
		.nav-link {
			line-height:18px !important;
			padding-top: 1px !important;
			padding-bottom: 1px !important;
		}
		.navbar .dropdown-item {
			padding-left:53px;
			line-height:14px !important;
			padding-top: 3px !important;
			padding-bottom: 3px !important;
		}
        .pwstrength_viewport_progress .progress {
          border-top-left-radius: 0 !important;
          border-top-right-radius: 0 !important;
          height: 3px;
        }
        .pwstrength_viewport_progress {
          margin-top:-4px;
          margin-left:1px;
          margin-right:1px;
        }
		.tbl1 {
			margin: 20px;
			padding: 4px;
			width: auto;
		}
		.tbl1 td {
			border-bottom: 1px solid #CCC;
			padding: 2px;
		}
		.tbl1 th {
			border-bottom: 6px solid #CCC;
			padding: 2px;
		}
		.modal-content {
			border: 1px solid #888;
			box-shadow: 4px 6px rgba(0,0,0,0.1);
		}
		/* Reusable modal title styling (replaces the inline
		   style="font-size:16pt;margin:40px 0 15px 0;" repeated across modals). */
		.modal-title-lg {
			font-size: 16pt;
			margin: 40px 0 15px 0;
		}
		/* Button size between .btn-sm and the default .btn */
		.btn-md {
			padding: .3rem .6rem;
			font-size: .8125rem;
			border-radius: .25rem;
		}
		.popover {
			left:-30px !important;
			box-shadow: 4px 6px rgba(0,0,0,0.1);
		}
		.popover-header {
			background: #FFFFEE;
			font-size: 10px;
			text-transform: uppercase;
		}
		.popover-close {
			font-size: 14px;
			float:right;
			margin-top:13px;
		}
		.popover-body {
			font-size: 14px;
		}
		.popover-arrow {
			display:none !important;
		}
/*		
		.tooltip-inner {
			min-width: 400px !important;
			font-size: 14px;
			padding: 10px 15px 10px 20px;
			background: #FFFFFF;
			color: #222;
			border: 1px solid #ccc;
			text-align: left;
			margin-left:-14px;
			box-shadow: 4px 6px rgba(0,0,0,0.1);
		}
		.tooltip.show {
			opacity:1;
		}

		.tooltip-arrow {
			left:-15px !important;
			top:-2px !important;

		}
		.bs-tooltip-end .tooltip-arrow::before {
      		border-right-color: #ccc;
	    }
    	.bs-tooltip-start .tooltip-arrow::before {
      		border-left-color: #ccc;
	    }
    	.bs-tooltip-top .tooltip-arrow::before {
      		border-top-color: #ccc;
	    }
    	.bs-tooltip-bottom .tooltip-arrow::before {
		    border-bottom-color: #ccc;
    	}
*/		
		.loading {
			width:100%;
			color:#fff;
			font-weight:normal;
/*			padding:10px; */
/*			background:linear-gradient(-45deg, #e8e, #8ee, #ee8, #ccc); */
			background:linear-gradient(-90deg, #fff, #cde, #def, #def); 
			background-size: 400% 400%;
			animation: gradient 10s ease infinite;		
		}
		@keyframes gradient {
			0% {
				background-position: 100% 50%;
			}
			50% {
				background-position: 0% 50%;
			}
			100% {
				background-position: 100% 50%;
			}
		}
		.bg-grey-lt {
			background-color:#F2F2F2 !important;
			border-color:#DDD !important;
		}
		.text-grey {
			color:#DDD !important;
		}
		.term-container {
		  background: #222;
		  color: #ddd;
		  word-break: break-word;
		  overflow-wrap: break-word;
		  font-family: "Roboto Mono", "JetBrainsMono Nerd Font Medium", monospace, monospace;
		  font-size: 1.0em;
		  line-height: 1.3em;
		  padding: 15px 15px;
		  white-space: pre-wrap;
		}
		</style>
    <script>
      // Generate a password string
      function genPass() {
        var charset = '#@$%&-[]<>0123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ0123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ0123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ0123456789+-=![]{}()%&*$#^<>~@';
        var pass = '';
        for(var i=0; i < 12; i++) {
          pass += charset.charAt(Math.floor(Math.random() * charset.length));
        }
        return pass;
      }
    </script>        
  </head>
  <body class="antialiased">
    <div class="page">
      <aside class="navbar navbar-vertical navbar-expand-lg navbar-dark">
        <div class="container-fluid">
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
            <span class="navbar-toggler-icon"></span>
          </button>
          <h1 class="navbar-brand navbar-brand-autodark d-block d-md-block d-lg-none">
            <a href="#">
                <img src="images/reqad2.svg" width="80" height="30" alt="Reqad" class="navbar-brand-image">
            </a>
          </h1>
<!--
          <div class="navbar-nav flex-row d-lg-none">
            <div class="nav-item dropdown d-none d-md-flex me-3">
              <a href="#" class="nav-link px-0" data-bs-toggle="dropdown" tabindex="-1" aria-label="Show notifications">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 0 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" /></svg>
                <span class="badge bg-red"></span>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-card">
                <div class="card">
                  <div class="card-body">
                    Lorem ipsum dolor sit amet, consectetur adipisicing elit. Accusamus ad amet consectetur exercitationem fugiat in ipsa ipsum, natus odio quidem quod repudiandae sapiente. Amet debitis et magni maxime necessitatibus ullam.
                  </div>
                </div>
              </div>
            </div>
            <div class="nav-item dropdown">
              <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Open user menu">
                <span class="avatar avatar-sm" style="background-image: url(./images/user-64.png)"></span>
                <div class="d-none d-xl-block ps-2">
                  <div>user1</div>
                  <div class="mt-1 small text-muted">Admin</div>
                </div>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <a href="#" class="dropdown-item">Email & password</a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">Settings</a>
                <a href="#" class="dropdown-item">Logout</a>
              </div>
            </div>
          </div>
-->
          <div class="collapse navbar-collapse" id="navbar-menu" style="margin-top:30px;">
            <ul class="navbar-nav pt-lg-3">
<?php /* Appliance mode (a plugin declared 'menu'): hide the panel sections,
         keep only Dashboard, add-on plugin items, and Reboot. */ ?>
<?php if(!menu_minimal()): ?>
              <li class="nav-item">
                <div class="nav-link" style="margin-left:-10px;">
                  <form action="." method="get" onsubmit="return false">
                    <div class="input-icon">
                      <span class="input-icon-addon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7" /><line x1="21" y1="21" x2="15" y2="15" /></svg>
                      </span>
                      <input type="text" id="menu-search" class="form-control" placeholder="Search" aria-label="Search in menu" autocomplete="off" style="padding-left:36px;">
                    </div>
                  </form>
                </div>
              </li>
<?php endif; /* end hide: search box */ ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='dashboard'?'active':'');?>" href="./" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><polyline points="5 12 3 12 12 3 21 12 19 12" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" /></svg>
                  </span>
                  <span class="nav-link-title">
                    Dashboard
                  </span>
                </a>
              </li>
<?php if(!menu_minimal()): /* hide: hosting-panel sections */ ?>
              <li class="nav-item dropdown">
                <a class="nav-link <?=($route=='accounts'?'active':'');?>" href="accounts/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-users" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <circle cx="9" cy="7" r="4" /> <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /> <path d="M16 3.13a4 4 0 0 1 0 7.75" /> <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Accounts & Domains
                  </span>
                </a>
                <div class="collapse show" id="menu-accounts">
                    <ul class="btn-toggle-nav list-unstyled fw-normal pb-1 small">
                      <li><a class="dropdown-item <?php if($route=='ssh-keys') echo 'active'; ?>" href="/ssh-keys/">SSH Keys</a>
                    </ul>
                </div>
              </li>
<!--
              <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#navbar-base" data-bs-toggle="dropdown" role="button" aria-expanded="false" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><polyline points="12 3 20 7.5 20 16.5 12 21 4 16.5 4 7.5 12 3" /><line x1="12" y1="12" x2="20" y2="7.5" /><line x1="12" y1="12" x2="12" y2="21" /><line x1="12" y1="12" x2="4" y2="7.5" /><line x1="16" y1="5.25" x2="8" y2="9.75" /></svg>
                  </span>
                  <span class="nav-link-title">
                    Webserver
                  </span>
                </a>
                <div class="dropdown-menu show">
                  <div class="dropdown-menu-columns">
                    <div class="dropdown-menu-column">
                      <a class="dropdown-item" href="">Select Webserver</a>
                    </div>
                  </div>
                </div>
              </li>
-->
<? if( isset($ini["email"]) && $ini["email"]==1 ) { ?>
              <li class="nav-item">
                <a class="nav-link" href="#" role="button" data-bs-toggle="collapse" data-bs-target="#menu-email" aria-expanded="true">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-mail" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <rect x="3" y="5" width="18" height="14" rx="2" /> <polyline points="3 7 12 13 21 7" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Email
                  </span>
                </a>
                <div class="collapse show" id="menu-email">
                    <ul class="btn-toggle-nav list-unstyled fw-normal pb-1 small">
                      <li><a class="dropdown-item <?php if($route=='email-accounts') echo 'active'; ?>" href="/email-accounts/">Email Accounts</a>
                      <li><a class="dropdown-item <?php if($route=='forwarders') echo 'active'; ?>" href="/forwarders/">Forwarders</a>
                      <li><a class="dropdown-item <?php if($route=='autoresponders') echo 'active'; ?>" href="/autoresponders/">Autoresponders</a>
<? /*
                      <li><a class="dropdown-item" href="">Anti-Spam Settings</a>
*/ ?>
                      <li><a class="dropdown-item" href="/webmail/" target="_blank">Webmail</a>
                      <li><a class="dropdown-item <?php if($route=='check-email-settings') echo 'active'; ?>" href="/check-email-settings/">Check Email Settings</a>
                      <li><a class="dropdown-item <?php if($route=='email-stats') echo 'active'; ?>" href="/email-stats/" style="padding-left:53px;">SMTP Statistics</a>
                    </ul>
                </div>
              </li>
<? } ?>			  
              <li class="nav-item">
                <a class="nav-link <?=($route=='databases'?'active':'');?>" href="databases/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-database" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <ellipse cx="12" cy="6" rx="8" ry="3"></ellipse> <path d="M4 6v6a8 3 0 0 0 16 0v-6" /> <path d="M4 12v6a8 3 0 0 0 16 0v-6" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Databases
                  </span>
                </a>
                <div class="collapse show" id="menu-database">
                    <ul class="btn-toggle-nav list-unstyled fw-normal pb-1 small">
                      <li><a class="dropdown-item" href="/phpmyadmin/" target="_blank">phpMyAdmin</a>
                      <li><a class="dropdown-item <?=($route=='mysqltuner'?'active':'');?>" href="mysqltuner/">MySQLTuner</a>
                    </ul>
                </div>
              </li>
<? if( isset($ini["backupdb"]) && $ini["backupdb"]==1 ) { ?>
			  <li class="nav-item <?=($route=='backupdb'?'active':'');?>">
                <a class="nav-link " href="backupdb/">
		  		  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-certificate" height="1em" viewBox="0 0 384 512"><path d="M64 464c-8.8 0-16-7.2-16-16V64c0-8.8 7.2-16 16-16h48c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16h48v80c0 17.7 14.3 32 32 32h80V448c0 8.8-7.2 16-16 16H64zM64 0C28.7 0 0 28.7 0 64V448c0 35.3 28.7 64 64 64H320c35.3 0 64-28.7 64-64V154.5c0-17-6.7-33.3-18.7-45.3L274.7 18.7C262.7 6.7 246.5 0 229.5 0H64zm48 112c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16s-7.2-16-16-16H128c-8.8 0-16 7.2-16 16zm0 64c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16s-7.2-16-16-16H128c-8.8 0-16 7.2-16 16zm-6.3 71.8L82.1 335.9c-1.4 5.4-2.1 10.9-2.1 16.4c0 35.2 28.8 63.7 64 63.7s64-28.5 64-63.7c0-5.5-.7-11.1-2.1-16.4l-23.5-88.2c-3.7-14-16.4-23.8-30.9-23.8H136.6c-14.5 0-27.2 9.7-30.9 23.8zM128 336h32c8.8 0 16 7.2 16 16s-7.2 16-16 16H128c-8.8 0-16-7.2-16-16s7.2-16 16-16z" style="fill:#FFF"></path></svg></span>
                  <span class="nav-link-title">
                    Backup DB 
                  </span>
                </a>
              </li>
<? } ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='ssl'?'active':'');?>" href="ssl/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-certificate" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <circle cx="15" cy="15" r="3" /> <path d="M13 17.5v4.5l2 -1.5l2 1.5v-4.5" /> <path d="M10 19h-5a2 2 0 0 1 -2 -2v-10c0 -1.1 .9 -2 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -1 1.73" /> <line x1="6" y1="9" x2="18" y2="9" /> <line x1="6" y1="12" x2="9" y2="12" /> <line x1="6" y1="15" x2="8" y2="15" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    SSL/TLS Certificates
                  </span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?=($route=='dns'?'active':'');?>" href="dns/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-server" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <rect x="3" y="4" width="18" height="8" rx="3" /> <rect x="3" y="12" width="18" height="8" rx="3" /> <line x1="7" y1="8" x2="7" y2="8.01" /> <line x1="7" y1="16" x2="7" y2="16.01" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    DNS Zones
                  </span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?=($route=='cron'?'active':'');?>" href="cron/">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-clock-bolt" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <path d="M20.984 12.53a9 9 0 1 0 -7.552 8.355" /> <path d="M12 7v5l3 3" /> <path d="M19 16l-2 3h4l-2 3" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Cron Jobs
                  </span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?=($route=='monit'?'active':'');?>" href="monit/" target="_blank">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-certificate" width="20" height="20" viewBox="0 0 1792 1536" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="0" y="0" width="1792" height="1536" fill="none" stroke="none" /><path fill="white" d="M1280 896h305q-5 6-10 10.5t-9 7.5l-3 4l-623 600q-18 18-44 18t-44-18L228 916q-5-2-21-20h369q22 0 39.5-13.5T638 848l70-281l190 667q6 20 23 33t39 13q21 0 38-13t23-33l146-485l56 112q18 35 57 35zm512-428q0 145-103 300h-369l-111-221q-8-17-25.5-27t-36.5-8q-45 5-56 46L962 988L766 302q-6-20-23.5-33T703 256t-39 13.5t-22 34.5L526 768H103Q0 613 0 468q0-220 127-344T478 0q62 0 126.5 21.5t120 58T820 148t76 68q36-36 76-68t95.5-68.5t120-58T1314 0q224 0 351 124t127 344z"/></svg>
                  </span>
                  <span class="nav-link-title">
                    Monit
                  </span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?=($route=='services'?'active':'');?>" href="services/">
				  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-traffic-lights" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <rect x="7" y="2" width="10" height="20" rx="5" /> <circle cx="12" cy="7" r="1" /> <circle cx="12" cy="12" r="1" /> <circle cx="12" cy="17" r="1" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Services Status
                  </span>
                </a>
              </li>
<? if(in_array('httpd', $_services)) { ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='apache'?'active':'');?>" href="apache/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-world-www" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <path d="M19.5 7a9 9 0 0 0 -7.5 -4a8.991 8.991 0 0 0 -7.484 4" /> <path d="M11.5 3a16.989 16.989 0 0 0 -1.826 4" /> <path d="M12.5 3a16.989 16.989 0 0 1 1.828 4" /> <path d="M19.5 17a9 9 0 0 1 -7.5 4a8.991 8.991 0 0 1 -7.484 -4" /> <path d="M11.5 21a16.989 16.989 0 0 1 -1.826 -4" /> <path d="M12.5 21a16.989 16.989 0 0 0 1.828 -4" /> <path d="M2 10l1 4l1.5 -4l1.5 4l1 -4" /> <path d="M17 10l1 4l1.5 -4l1.5 4l1 -4" /> <path d="M9.5 10l1 4l1.5 -4l1.5 4l1 -4" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Apache status
                  </span>
                </a>
              </li>
<? } ?>
<? /*			  
              <li class="nav-item">
                <a class="nav-link disabled" href="#" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-apps" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <rect x="4" y="4" width="6" height="6" rx="1" /> <rect x="4" y="14" width="6" height="6" rx="1" /> <rect x="14" y="14" width="6" height="6" rx="1" /> <line x1="14" y1="7" x2="20" y2="7" /> <line x1="17" y1="4" x2="17" y2="10" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Apps 
                  </span>
                </a>
              </li>
*/ ?>
<? if( isset($ini["wptoolkit"]) && $ini["wptoolkit"]==1 ) { ?>
			  <li class="nav-item">
                <a class="nav-link <?=($route=='wp-toolkit'?'active':'');?>" href="wp-toolkit/" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="1.5"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-brand-wordpress"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9.5 9h3" /><path d="M4 9h2.5" /><path d="M11 9l3 11l4 -9" /><path d="M5.5 9l3.5 11l3 -7" /><path d="M18 11c.177 -.528 1 -1.364 1 -2.5c0 -1.78 -.776 -2.5 -1.875 -2.5c-.898 0 -1.125 .812 -1.125 1.429c0 1.83 2 2.058 2 3.571z" /><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /></svg>
                  </span>
                  <span class="nav-link-title">
                    Wordpress Toolkit
                  </span>
                </a>
              </li>
<? } ?>
<?php endif; /* end hide: hosting-panel sections (Accounts…WP) */ ?>
              <!-- Add-on modules: plugins/*/plugin.php, gated by each plugin's feature flag -->
<?php $__plugins = isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array();
      foreach($__plugins as $pl):
          if(!empty($pl['feature']) && !(isset($ini[$pl['feature']]) && $ini[$pl['feature']]==1)) continue; ?>
              <li class="nav-item">
                <a class="nav-link <?=($route==$pl['route']?'active':'');?>" href="<?=htmlspecialchars($pl['route'])?>/">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><?=isset($pl['icon'])?$pl['icon']:''?></span>
                  <span class="nav-link-title"><?=htmlspecialchars($pl['title'])?></span>
                </a>
              </li>
<?php endforeach; ?>
<?php if(!menu_minimal()): /* hide: Settings…Documentation */ ?>
              <li class="nav-item">
                <a class="nav-link <?php if($route=='settings') echo 'active'; ?>" href="settings/">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-settings" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" /> <circle cx="12" cy="12" r="3" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    Settings
                  </span>
                </a>
                <div class="collapse show" id="menu-settings">
                    <ul class="btn-toggle-nav list-unstyled fw-normal pb-1 small">
                      <li><a class="dropdown-item <?php if($route=='dns-settings') echo 'active'; ?>" href="/dns-settings/">DNS Settings</a>
                      <li><a class="dropdown-item <?php if($route=='php-settings') echo 'active'; ?>" href="/php-settings/">PHP Settings</a>
                    </ul>
                </div>
              </li>
<? /*			  
              <li class="nav-item">
                <a class="nav-link disabled" href="#" >
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-files" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/> <path d="M15 3v4a1 1 0 0 0 1 1h4" /> <path d="M18 17h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h4l5 5v7a2 2 0 0 1 -2 2z" /> <path d="M16 17v2a2 2 0 0 1 -2 2h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h2" /> </svg>
                  </span>
                  <span class="nav-link-title">
                    File Manager 
                  </span>
                </a>
              </li>
*/ ?>
<? if( isset($ini["backup"]) && $ini["backup"]==1 ) { ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='backup'?'active':'');?>" href="/backup/" >
				  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-brand-tabler icon-tabler-file-zip" width="44" height="44" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 20.735a2 2 0 0 1 -1 -1.735v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2h-1" /><path d="M11 17a2 2 0 0 1 2 2v2a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-2a2 2 0 0 1 2 -2z" /><path d="M11 5l-1 0" /><path d="M13 7l-1 0" /><path d="M11 9l-1 0" /><path d="M13 11l-1 0" /><path d="M11 13l-1 0" /><path d="M13 15l-1 0" /></svg></span>
                  <span class="nav-link-title">
                    Backup
                  </span>
                </a>
              </li>
<? } ?>
<? if( isset($ini["terminal"]) && $ini["terminal"]==1 ) { ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='terminal'?'active':'');?>" href="/terminal/">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-terminal-2"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M8 9l3 3l-3 3" /><path d="M13 15l3 0" /><path d="M3 6a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2l0 -12" /></svg></span>
                  <span class="nav-link-title">Terminal</span>
                </a>
              </li>
<? } ?>
<? if( isset($ini["transfer"]) && $ini["transfer"]==1 ) { ?>
              <li class="nav-item">
                <a class="nav-link <?=($route=='transfer-tool'?'active':'');?>" href="/transfer-tool/">
				  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-transfer" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 10h-16l5.5 -6" /><path d="M4 14h16l-5.5 6" /></svg></span>
                  <span class="nav-link-title">Transfer Tool</span>
                </a>
              </li>
<? } ?>
              <li class="nav-item">
                <a class="nav-link" href="https://reqad.com/docs" target="_blank" rel="noopener">
                  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><line x1="9" y1="9" x2="10" y2="9" /><line x1="9" y1="13" x2="15" y2="13" /><line x1="9" y1="17" x2="15" y2="17" /></svg>
                  </span>
                  <span class="nav-link-title">
                    Documentation
                  </span>
                </a>
              </li>
<?php endif; /* end hide: Settings…Documentation */ ?>
              <li class="nav-item">
                <a class="nav-link" href="#"  data-bs-toggle="modal" data-bs-target="#modal-reboot-server">
				  <span class="nav-link-icon d-md-none d-lg-inline-block"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-power" width="24" height="24" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 6a7.75 7.75 0 1 0 10 0" /><path d="M12 4l0 8" /></svg>
                  </span>
                  <span class="nav-link-title">
                    Reboot server
                  </span>
                </a>
              </li>
            </ul>
            <script>
            /* Sidebar menu live-filter: hides menu rows that don't match the
               search box. Matches top-level titles and submenu items; a child
               match keeps its parent visible and expanded. Vanilla JS (runs
               before jQuery loads). */
            (function () {
              var input = document.getElementById('menu-search');
              if (!input) return;
              var list = input.closest('.navbar-nav');
              if (!list) return;
              var searchRow = input.closest('li.nav-item');
              // Top-level rows to filter (skip the search box's own <li>).
              var rows = Array.prototype.filter.call(
                list.querySelectorAll(':scope > li.nav-item'),
                function (li) { return li !== searchRow; }
              );
              // Remember each submenu's original collapse state to restore on clear.
              var submenus = [];
              rows.forEach(function (li) {
                li.querySelectorAll('.collapse').forEach(function (sub) {
                  submenus.push({ el: sub, open: sub.classList.contains('show') });
                });
              });
              function norm(s) { return (s || '').toLowerCase().trim(); }

              function filter() {
                var q = norm(input.value);
                if (q === '') {
                  rows.forEach(function (li) {
                    li.hidden = false;
                    li.querySelectorAll('.dropdown-item').forEach(function (a) { a.hidden = false; });
                  });
                  submenus.forEach(function (s) { s.el.classList.toggle('show', s.open); });
                  return;
                }
                rows.forEach(function (li) {
                  var titleEl = li.querySelector('.nav-link-title');
                  var titleMatch = titleEl && norm(titleEl.textContent).indexOf(q) !== -1;
                  var children = li.querySelectorAll('.dropdown-item');
                  var anyChild = false;
                  children.forEach(function (a) {
                    var m = norm(a.textContent).indexOf(q) !== -1;
                    a.hidden = !(titleMatch || m);
                    if (m) anyChild = true;
                  });
                  li.hidden = !(titleMatch || anyChild);
                  // Expand the submenu when a child match is what kept the row visible.
                  if (anyChild) {
                    li.querySelectorAll('.collapse').forEach(function (sub) { sub.classList.add('show'); });
                  }
                });
              }
              input.addEventListener('input', filter);
            })();
            </script>
          </div>
        </div>
      </aside>
      <header id="pagehead" class="navbar navbar-expand-md navbar-light d-none d-lg-flex d-print-none">
        <div class="container-xl">
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
            <span class="navbar-toggler-icon"></span>
          </button>
          <div class="navbar-nav flex-row order-md-last">
            <div class="nav-item dropdown d-none d-md-flex me-3">
              <img src="./images/reqad.svg" width="80" height="30" alt="Reqad" class="navbar-brand-image">
<!--
              <a href="#" class="nav-link px-0" data-bs-toggle="dropdown" tabindex="-1" aria-label="Show notifications">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 0 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" /></svg>
                <span class="badge bg-red"></span>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-card">
                <div class="card" style="width:800px;">
                  <div class="card-body">
                    Lorem ipsum dolor sit amet, consectetur adipisicing elit. Accusamus ad amet consectetur exercitationem fugiat in ipsa ipsum, natus odio quidem quod repudiandae sapiente. Amet debitis et magni maxime necessitatibus ullam.
                  </div>
                </div>
              </div>
            </div>
            <div class="nav-item dropdown">
              <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Open user menu">
                <span class="avatar avatar-sm" style="background-color: #FFF;background-image: url(./images/user-64.png); opacity: 0.2"></span>
                <div class="d-none d-xl-block ps-2">
                  <div>ruser67</div>
                  <div class="mt-1 small text-muted">Admin</div>
                </div>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <a href="#" class="dropdown-item">Set status</a>
                <a href="#" class="dropdown-item">Profile & account</a>
                <a href="#" class="dropdown-item">Feedback</a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item">Settings</a>
                <a href="#" class="dropdown-item">Logout</a>
              </div>
-->
            </div>
          </div>
          <div class="collapse navbar-collapse" id="navbar-menu">
            <div></div>
          </div>
<!--
          <div class="collapse navbar-collapse" id="navbar-menu">
            <div>
              <form action="." method="get">
                <div class="input-icon">
                  <span class="input-icon-addon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7" /><line x1="21" y1="21" x2="15" y2="15" /></svg>
                  </span>
                  <input type="text" class="form-control" placeholder="Search" aria-label="Search in website">
                </div>
              </form>
            </div>
          </div>
-->

        </div>
      </header>
      <div class="content">
        <div class="container-xl">
