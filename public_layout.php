<?php
/*
 * Shared layout for every public GoCycle page (Home, Login, Register,
 * Forgot password, Verify, Change password, Privacy).
 * One header, shared public stylesheet and one look.
 */

if (!function_exists('gc_pub_icon')) {
    function gc_pub_icon($name, $size = 20, $class = '')
    {
        static $paths = [
            'home' =>
                '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/><path d="M10 19.5v-5h4v5"/>',
            'login' =>
                '<path d="M14 4h3.5A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5H14"/><path d="M4 12h10M10.5 8l3.5 4-3.5 4"/>',
            'register' =>
                '<circle cx="9.5" cy="8" r="3.5"/><path d="M3 20c.7-3.6 3.2-5.5 6.5-5.5s5.8 1.9 6.5 5.5"/><path d="M18 8v6M15 11h6"/>',
            'user' =>
                '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.8-4 3.8-6 7.5-6s6.7 2 7.5 6"/>',
            'lock' =>
                '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
            'mail' =>
                '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m3.5 7 8.5 6.5L20.5 7"/>',
            'eye' =>
                '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
            'eyeoff' =>
                '<path d="M3 3l18 18"/><path d="M10.6 6a9.7 9.7 0 0 1 1.4-.1c6 0 9.5 6.5 9.5 6.5a16 16 0 0 1-2.6 3.4M6.4 7.4A16 16 0 0 0 2.5 12S6 18.5 12 18.5c1.5 0 2.9-.4 4.1-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'bike' =>
                '<circle cx="5.5" cy="16.5" r="3.5"/><circle cx="18.5" cy="16.5" r="3.5"/><path d="M5.5 16.5 9.5 8.5h6l3 8"/><path d="M9.5 8.5l3 8h-7"/><path d="M9.5 8.5V6.5M7.8 6.5h3.4M15.5 8.5l-.7-2.5M13.5 6h2.8"/>',
            'shield' =>
                '<path d="M12 3l7.5 3v5.5c0 4.6-3.1 8.2-7.5 9.5-4.4-1.3-7.5-4.9-7.5-9.5V6L12 3z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
            'leaf' => '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19l8-8"/>',
            'dashboard' =>
                '<rect x="3" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.8"/>',
            'logout' =>
                '<path d="M9.5 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3.5"/><path d="M15 8l4 4-4 4M19 12H9.5"/>',
            'calendar' =>
                '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
            'users' =>
                '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 19.5c.6-3.4 3.2-5.2 6.5-5.2s5.9 1.8 6.5 5.2"/><path d="M15.5 5.3a3.5 3.5 0 0 1 0 6.4M17.5 14.6c2.1.6 3.6 2.2 4 4.9"/>',
            'route' =>
                '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5"/>',
            'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M16 7l3 3M14 9l2 2"/>',
        ];
        $inner = isset($paths[$name]) ? $paths[$name] : '';
        return '<svg class="gc-ico ' .
            $class .
            '" width="' .
            (int) $size .
            '" height="' .
            (int) $size .
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' .
            $inner .
            '</svg>';
    }
}


if (!function_exists('gc_pub_ride_scene')) {
    /* Animated cycling scene used behind Login / Register (body class "is-auth"). */
    function gc_pub_ride_scene($mode = 'auth')
    {
        $bike = static function ($cls) {
            return '<div class="rs-bike ' . $cls . '"><svg viewBox="-34 -14 190 96" fill="none" stroke-linecap="round" stroke-linejoin="round">'
                . '<g class="rs-wind"><path d="M-30 18h22"/><path d="M-22 30h22"/><path d="M-32 42h18"/></g>'
                . '<defs><linearGradient id="rs-beam-' . $cls . '" x1="0" x2="1"><stop offset="0" stop-color="#fff6c8" stop-opacity=".55"/><stop offset="1" stop-color="#fff6c8" stop-opacity="0"/></linearGradient></defs>'
                . '<path class="rs-beam" d="M99 21 156 4v34z" fill="url(#rs-beam-' . $cls . ')" stroke="none"/>'
                . '<g transform="translate(26 52)"><g class="rs-spin"><circle r="18" stroke-width="3.4"/><path d="M-18 0h36M0-18v36M-12.7-12.7l25.4 25.4M12.7-12.7l-25.4 25.4" stroke-width="1"/></g></g>'
                . '<g transform="translate(104 52)"><g class="rs-spin"><circle r="18" stroke-width="3.4"/><path d="M-18 0h36M0-18v36M-12.7-12.7l25.4 25.4M12.7-12.7l-25.4 25.4" stroke-width="1"/></g></g>'
                . '<g class="rs-frame" stroke-width="3.2"><path d="M26 52 58 52 50 28 26 52M50 28 92 26 58 52M92 26 96 34 104 52M92 26 88 18H97"/><path d="M42 25h14" stroke-width="4.4"/></g>'
                . '<g class="rs-rider"><path class="rs-leg rs-leg-b" d="M50 21 58 52"/><path d="M50 21 72 4" stroke-width="5"/><path d="M72 4 86 13 96 18" stroke-width="3.6"/><circle cx="78" cy="-3" r="5.6" fill="currentColor" stroke="none"/><path class="rs-leg rs-leg-f" d="M50 21 58 52"/></g>'
                . '<g transform="translate(58 52)"><g class="rs-crank"><circle r="7" stroke-width="2"/><path d="M0 0 8 0M0 0-8 0" stroke-width="3"/></g></g>'
                . '</svg></div>';
        };
        $html = '<div class="gc-ride-scene" aria-hidden="true">';
        $html .= '<span class="rs-moon"></span>';
        $html .= '<div class="rs-stars">';
        for ($i = 0; $i < 26; $i++) {
            $x = fmod($i * 37.7 + 11, 100);
            $y = fmod($i * 23.3 + 5, 46);
            $html .= '<i style="left:' . round($x, 1) . '%;top:' . round($y, 1) . '%;animation-delay:-' . ($i % 7) * 0.6 . 's"></i>';
        }
        $html .= '</div>';
        $html .= '<span class="rs-cloud c1"></span><span class="rs-cloud c2"></span><span class="rs-cloud c3"></span>';
        foreach (['far', 'mid'] as $layer) {
            $html .= '<div class="rs-layer ' . $layer . '"><svg viewBox="0 0 2400 200" preserveAspectRatio="none">';
            if ($layer === 'far') {
                $d = 'M0 130C150 60 300 70 450 115S750 170 900 105 1150 70 1200 130V200H0Z';
                $html .= '<path d="' . $d . '"/><path transform="translate(1200 0)" d="' . $d . '"/>';
            } else {
                $trees = '';
                for ($t = 0; $t < 2; $t++) {
                    $ox = $t * 1200;
                    for ($i = 0; $i < 12; $i++) {
                        $x = $ox + 60 + $i * 94 + (($i * 29) % 40);
                        $r = 20 + (($i * 13) % 16);
                        $trees .= '<rect x="' . ($x - 3) . '" y="' . (150 - $r) . '" width="6" height="' . (50 + $r) . '"/>'
                            . '<circle cx="' . $x . '" cy="' . (140 - $r) . '" r="' . $r . '"/>';
                        if ($i % 4 === 2) {
                            $lx = $x + 46;
                            $trees .= '<rect x="' . $lx . '" y="96" width="3" height="104"/><circle class="lamp" cx="' . ($lx + 1.5) . '" cy="94" r="6"/>';
                        }
                    }
                }
                $html .= $trees;
            }
            $html .= '</svg></div>';
        }
        if ($mode === 'auth') {
            $html .= '<div class="rs-road"><span class="rs-lane"></span></div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('gc_pub_head')) {
    /**
     * Prints <!doctype> ... through the opening <body>, the scene background and the nav bar.
     *
     * @param string $title     <title> text
     * @param string $active    home | login | register | '' (which nav pill is highlighted)
     * @param bool   $inLab     true for pages inside bruteforce_lab/, false for /index.php
     * @param string $bodyClass extra body class (e.g. "is-home")
     */
    function gc_pub_head($title, $active = '', $inLab = true, $bodyClass = '')
    {
        $root = $inLab ? '../' : '';
        $lab = $inLab ? '' : 'bruteforce_lab/';
        $loggedIn = !empty($_SESSION['user_id']);

        $links = [['home', 'Home', $root . 'index.php', 'home']];
        if ($loggedIn) {
            $links[] = ['dashboard', 'Dashboard', $lab . 'dashboard.php', 'dashboard'];
            $links[] = ['logout', 'Log out', $lab . 'logout.php', 'logout'];
        } else {
            $links[] = ['login', 'Login', $lab . 'login.php', 'login'];
            $links[] = ['register', 'Register', $lab . 'register.php', 'register'];
        }
        ?>
<!doctype html>
<html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="stylesheet" href="<?php echo $lab; ?>css/public.css?v=20261112-1">
        <?php if (strpos($bodyClass, 'is-auth') !== false || strpos($bodyClass, 'is-home') !== false): ?>
        <link rel="stylesheet" href="<?php echo $lab; ?>css/auth-motion.css?v=20261113-1">
        <?php endif; ?>
        <?php if (strpos($bodyClass, 'is-home') !== false): ?>
        <link rel="stylesheet" href="<?php echo $lab; ?>css/home-motion.css?v=20261113-1">
        <?php endif; ?>
    </head>

    <body class="gc-pub <?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="gc-scene" aria-hidden="true"><svg class="gc-leaf gc-leaf-a" viewBox="0 0 24 24"
                fill="currentColor">
                <path d="M4 20C4 9 10 3 21 3c0 11-6 17-15 17" />
                <path d="M4 20 14 10" stroke="#0b2016" stroke-width=".5" fill="none" />
            </svg><svg class="gc-leaf gc-leaf-b" viewBox="0 0 24 24" fill="currentColor">
                <path d="M4 20C4 9 10 3 21 3c0 11-6 17-15 17" />
                <path d="M4 20 14 10" stroke="#0b2016" stroke-width=".5" fill="none" />
            </svg><span class="gc-floating-leaf gc-floating-leaf-a"></span><span class="gc-floating-leaf gc-floating-leaf-b"></span><span class="gc-floating-leaf gc-floating-leaf-c"></span></div>
        <?php if (strpos($bodyClass, 'is-auth') !== false) { echo gc_pub_ride_scene(); } ?>
        <header class="gc-nav">
            <a class="gc-brand" href="<?php echo $root; ?>index.php"
                aria-label="GoCycle home"><span>Go<b>Cycle</b></span></a>
            <nav class="gc-links" aria-label="Main navigation">
                <?php foreach ($links as $l):
                    $isActive = $active === $l[0]; ?>
                <a href="<?php echo $l[2]; ?>" <?php echo $isActive
    ? ' class="is-active" aria-current="page"'
    : ''; ?>><?php echo gc_pub_icon($l[3], 20); ?><span class="lbl"><?php echo $l[1]; ?></span></a>
                <?php
                endforeach; ?>
            </nav>
        </header>
        <?php
    }
}

if (!function_exists('gc_pub_foot')) {
    function gc_pub_foot($withJs = true)
    {
        if ($withJs) {
            echo '<script src="js/auth.js?v=20261118-3"></script>' . "\n";
        }
        echo "</body>\n</html>\n";
    }
}

if (!function_exists('gc_pub_password_field')) {
    // Password input with one show/hide eye button (works with auth.js).
    function gc_pub_password_field($id, $name, $placeholder, $autocomplete = '')
    {
        $ac = $autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '';
        return '<div class="gc-input has-toggle">' .
            gc_pub_icon('lock', 20, 'lead password-lock') .
            '<input id="' .
            $id .
            '" name="' .
            $name .
            '" type="password" maxlength="64" placeholder="' .
            $placeholder .
            '"' .
            $ac .
            ' required>' .
            '<button type="button" class="password-toggle" data-password-toggle="' .
            $id .
            '" aria-pressed="false" aria-label="Show password">' .
            gc_pub_icon('eye', 22, 'eye-single') .
            '</button></div>';
    }
}
