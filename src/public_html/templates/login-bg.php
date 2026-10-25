<?php
/*
 * Animated background for the login page - three variants to compare
 * (?bg=1 sea waves, ?bg=2 sine lines, ?bg=0 none).
 * Pure inline SVG + SMIL: no JS library, no requests, nothing to load, and
 * it sits behind the form with pointer-events off. Colours are tints of the
 * card's #2a6099. Paused for visitors who ask for reduced motion.
 */
$login_bg = isset($_GET['bg']) && in_array($_GET['bg'], array('0', '1', '2'), true) ? $_GET['bg'] : '1';

/*
 * A sine-like wave as an SVG path: quadratic halves of wavelength $L, peak
 * height $A around baseline $y, from $x0 to $x1. With $bottom it is closed
 * down to that y (a filled wave), otherwise it is an open line. Paths drawn
 * over 0..2880 with a wavelength that divides 1440 loop seamlessly when
 * translated by -1440.
 */
function login_wave($L, $A, $y, $x0, $x1, $bottom = null) {
	$h = $L / 2;
	$d = 'M'.round($x0, 1).','.round($y, 1).' Q'.round($x0 + $h / 2, 1).','.round($y - 2 * $A, 1).' '.round($x0 + $h, 1).','.round($y, 1);
	for ($x = $x0 + 2 * $h; $x <= $x1 + 0.01; $x += $h)
		$d .= ' T'.round($x, 1).','.round($y, 1);
	if ($bottom !== null)
		$d .= ' L'.round($x1, 1).','.$bottom.' L'.round($x0, 1).','.$bottom.' Z';
	return $d;
}

/* A drifting group: translate 0 -> -1440 (or back) forever. */
function login_drift($seconds, $reverse = false) {
	return '<animateTransform attributeName="transform" type="translate" from="'.($reverse ? '-1440 0' : '0 0')
		.'" to="'.($reverse ? '0 0' : '-1440 0').'" dur="'.$seconds.'s" repeatCount="indefinite"/>';
}

?>
<style>
	.login-bg { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
	.login-bg svg { position: absolute; left: 0; width: 100%; display: block; }
	.login-wrap { position: relative; z-index: 1; }
	.login-bg-pick { position: fixed; right: 12px; bottom: 10px; z-index: 2; font-size: .75rem; color: #6c7a91; }
	.login-bg-pick a { margin-left: .4rem; color: #2a6099; }
	.login-bg-pick a.on { font-weight: 700; text-decoration: underline; }
</style>
<div class="login-bg" aria-hidden="true">
<?php if ($login_bg === '0'): /* none */ ?>
<?php elseif ($login_bg === '1'): /* ── 1. sea waves along the bottom ───────────────── */ ?>
	<svg viewBox="0 0 1440 360" preserveAspectRatio="none" style="bottom:0;height:46vh">
		<defs>
			<linearGradient id="sea" x1="0" y1="0" x2="0" y2="1">
				<stop offset="0" stop-color="#2a6099" stop-opacity=".22"/>
				<stop offset="1" stop-color="#2a6099" stop-opacity=".06"/>
			</linearGradient>
		</defs>
		<g opacity=".55"><path fill="#9db8d6" d="<?=login_wave(720, 18, 120, 0, 2880, 360)?>"/><?=login_drift(48, true)?></g>
		<g opacity=".6"><path fill="#7ea2c9" d="<?=login_wave(480, 14, 170, 0, 2880, 360)?>"/><?=login_drift(36)?></g>
		<g opacity=".7"><path fill="url(#sea)" d="<?=login_wave(1440, 26, 215, 0, 2880, 360)?>"/><?=login_drift(60, true)?></g>
		<g opacity=".9"><path fill="#5884b4" fill-opacity=".28" d="<?=login_wave(360, 9, 275, 0, 2880, 360)?>"/><?=login_drift(26)?></g>
	</svg>
<?php elseif ($login_bg === '2'): /* ── 2. thin sine lines across the page ───────── */ ?>
	<svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" style="top:0;height:100%">
<?php
	$lines = 16;
	for ($i = 0; $i < $lines; $i++) {
		$t   = $i / ($lines - 1);
		$L   = 1440 / (1 + $i % 3);                 // wavelengths that loop seamlessly
		$A   = 22 + 38 * sin($t * M_PI);            // fuller in the middle of the page
		$y   = 90 + $t * 720;
		$op  = 0.10 + 0.16 * sin($t * M_PI);
		$dur = 40 + ($i * 7) % 35;
		echo "\t\t".'<g><path fill="none" stroke="#2a6099" stroke-opacity="'.round($op, 3).'" stroke-width="1.3" d="'
			.login_wave($L, $A, $y, 0, 2880).'"/>'.login_drift($dur, $i % 2 === 1).'</g>'."\n";
	}
?>
	</svg>
<?php endif; ?>
</div>
<div class="login-bg-pick">Background
	<?php foreach (array('1' => 'waves', '2' => 'lines', '0' => 'off') as $k => $n): ?>
	<a href="<?=h(strtok($_SERVER['REQUEST_URI'], '?'))?>?bg=<?=$k?>" class="<?=$login_bg === $k ? 'on' : ''?>"><?=$n?></a>
	<?php endforeach; ?>
</div>
<script>
	// Respect "reduce motion": keep the picture, stop the movement.
	if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)
		document.querySelectorAll('.login-bg svg').forEach(function (s) { s.pauseAnimations(); });
</script>
