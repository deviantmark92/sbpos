<?php
/**
 * Small helper functions used across the app.
 */

/** HTML-escape a value for safe output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as currency, e.g. 140 -> "₱140.00". */
function money($amount): string
{
    $cfg = config();
    return $cfg['currency_symbol'] . number_format((float) $amount, 2);
}

/**
 * Suggest a selling price from a recipe cost and the chosen pricing mode.
 *   percentage -> cost + markup% of cost
 *   addon      -> cost + flat add-on
 *   manual     -> null (owner sets the price directly)
 */
function suggest_price(float $cost, string $mode, float $markup): ?float
{
    return match ($mode) {
        'percentage' => round($cost * (1 + $markup / 100), 2),
        'addon'      => round($cost + $markup, 2),
        default      => null,
    };
}

/**
 * Absolute path of the custom (owner-uploaded) logo file, or null when the
 * app is using the built-in default logo.
 */
function app_logo_file(): ?string
{
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        $file = config()['upload_dir'] . '/logo.' . $ext;
        if (is_file($file)) {
            return $file;
        }
    }
    return null;
}

/** URL of the app logo: the owner-uploaded one if set, else the default sbPOS mark. */
function app_logo_url(): string
{
    $file = app_logo_file();
    if ($file !== null) {
        return config()['upload_url'] . '/' . basename($file) . '?v=' . filemtime($file);
    }
    return 'assets/logo-default.svg';
}

/* ---------------- App settings (file-backed, no DB) ---------------- */

/** Path of the JSON file that stores owner-configurable settings. */
function app_settings_file(): string
{
    return config()['upload_dir'] . '/app-settings.json';
}

/** All stored settings as an associative array (cached for the request). */
function app_settings(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $f = app_settings_file();
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    return $s;
}

/** Read a single setting with a default. */
function app_setting(string $key, $default = null)
{
    return app_settings()[$key] ?? $default;
}

/** Persist a single setting. Returns false if the file could not be written. */
function save_app_setting(string $key, $value): bool
{
    $dir = config()['upload_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $s = app_settings();
    $s[$key] = $value;
    return @file_put_contents(app_settings_file(), json_encode($s, JSON_PRETTY_PRINT)) !== false;
}

/* ---------------- Color schemes (theming) ---------------- */

/**
 * Available color schemes. Each overrides the primary/brand color family and
 * the warm surface tints so the whole app re-themes from one choice.
 */
function color_themes(): array
{
    return [
        'brown'  => ['label' => 'Broasted Brown', 'primary' => '#8b3a00', 'dark' => '#5e2700',
                     'container' => '#ffdcc2', 'onContainer' => '#311300', 'secondary' => '#b8531f',
                     'surface' => '#fff8f4', 'surfaceVariant' => '#f3e0d4', 'bg' => '#fbf5f0', 'line' => '#ecdfd6'],
        'blue'   => ['label' => 'Ocean Blue', 'primary' => '#0b5ba6', 'dark' => '#073e73',
                     'container' => '#cfe4ff', 'onContainer' => '#001d36', 'secondary' => '#1f6fb8',
                     'surface' => '#f5f9ff', 'surfaceVariant' => '#dbe7f3', 'bg' => '#f0f5fb', 'line' => '#d8e4f0'],
        'green'  => ['label' => 'Forest Green', 'primary' => '#1f6d3b', 'dark' => '#124a27',
                     'container' => '#c8ecd2', 'onContainer' => '#002110', 'secondary' => '#37814f',
                     'surface' => '#f5fbf6', 'surfaceVariant' => '#dcecdf', 'bg' => '#f0f8f2', 'line' => '#d8ebdd'],
        'teal'   => ['label' => 'Teal', 'primary' => '#00695c', 'dark' => '#003f39',
                     'container' => '#bdeee5', 'onContainer' => '#00201c', 'secondary' => '#2a8378',
                     'surface' => '#f4fbf9', 'surfaceVariant' => '#d5e8e4', 'bg' => '#eef7f5', 'line' => '#d3e6e2'],
        'purple' => ['label' => 'Royal Purple', 'primary' => '#5b3aa6', 'dark' => '#3e2673',
                     'container' => '#e5dbff', 'onContainer' => '#1d0060', 'secondary' => '#6f4fb8',
                     'surface' => '#f9f6ff', 'surfaceVariant' => '#e6ddf3', 'bg' => '#f4f0fb', 'line' => '#e2d8f0'],
        'crimson' => ['label' => 'Crimson Red', 'primary' => '#b3261e', 'dark' => '#7a1712',
                     'container' => '#ffdad6', 'onContainer' => '#410002', 'secondary' => '#c34b3f',
                     'surface' => '#fff7f6', 'surfaceVariant' => '#f3dcd9', 'bg' => '#fbf1f0', 'line' => '#f0ddda'],
        'slate'  => ['label' => 'Slate Gray', 'primary' => '#37474f', 'dark' => '#22303a',
                     'container' => '#d3dee4', 'onContainer' => '#101f27', 'secondary' => '#546e7a',
                     'surface' => '#f7f9fa', 'surfaceVariant' => '#dde4e8', 'bg' => '#f1f4f6', 'line' => '#dde3e7'],
    ];
}

/** The active color-scheme key, falling back to the default when unset/invalid. */
function app_theme_key(): string
{
    $k = (string) app_setting('theme', 'slate');
    return isset(color_themes()[$k]) ? $k : 'slate';
}

/** A <style> block that overrides the CSS variables for the active color scheme. */
function theme_style_tag(): string
{
    $t = color_themes()[app_theme_key()];
    $vars = [
        '--md-sys-color-primary'              => $t['primary'],
        '--md-sys-color-primary-container'    => $t['container'],
        '--md-sys-color-on-primary-container' => $t['onContainer'],
        '--md-sys-color-secondary'            => $t['secondary'],
        '--md-sys-color-surface'              => $t['surface'],
        '--md-sys-color-surface-variant'      => $t['surfaceVariant'],
        '--brown'                             => $t['primary'],
        '--brown-d'                           => $t['dark'],
        '--bg'                                => $t['bg'],
        '--line'                              => $t['line'],
    ];
    $css = ':root{';
    foreach ($vars as $k => $v) { $css .= $k . ':' . $v . ';'; }
    $css .= '}';
    return '<style id="theme-vars">' . $css . '</style>';
}

/** Build a URL to a page within the front controller. */
function url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

/** Redirect to a page and stop execution. */
function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

/** Read a request value (GET/POST) with a default. */
function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/* ---------------- Excel (.xlsx) export ---------------- */

/**
 * Stream an .xlsx spreadsheet as a download and exit.
 * Builds a minimal Office Open XML workbook (no external libraries needed).
 *
 * @param string  $filename Suggested download name, e.g. "sales.xlsx".
 * @param array   $headers  Column header labels (rendered bold on row 1).
 * @param array[] $rows     Each row is a list of cell values; numeric values
 *                          (int/float) become number cells, everything else text.
 */
function xlsx_stream(string $filename, array $headers, array $rows): void
{
    $colName = static function (int $i): string {       // 0 -> A, 26 -> AA
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    };
    $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $buildRow = static function (array $cells, int $r, ?int $style) use ($colName, $esc): string {
        $xml = '<row r="' . $r . '">';
        foreach ($cells as $c => $val) {
            $ref = $colName($c) . $r;
            $s   = $style !== null ? ' s="' . $style . '"' : '';
            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $ref . '"' . $s . '><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                      . $esc($val) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    };

    $sheetData = $buildRow($headers, 1, 1);   // header row uses bold style (index 1)
    $r = 2;
    foreach ($rows as $row) {
        $sheetData .= $buildRow(array_values($row), $r++, null);
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetData . '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sales" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    // Build the .zip (xlsx container) in memory — no temp file, so this works
    // regardless of temp-dir / open_basedir restrictions on the server.
    $payload = zip_string([
        '[Content_Types].xml'        => $contentTypes,
        '_rels/.rels'                => $rels,
        'xl/workbook.xml'            => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml'              => $styles,
        'xl/worksheets/sheet1.xml'   => $sheet,
    ]);

    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { ob_end_clean(); }
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: max-age=0');
    echo $payload;
    exit;
}

/**
 * Build a ZIP archive in memory from [name => contents] pairs (deflate-compressed).
 * Returns the raw bytes. Pure PHP — needs no ZipArchive extension or temp files.
 */
function zip_string(array $files): string
{
    $local = '';
    $central = '';
    $count = 0;

    foreach ($files as $name => $content) {
        $offset     = strlen($local);
        $crc        = crc32($content);
        $uncompSize = strlen($content);
        $deflated   = gzdeflate($content, 6);
        if ($deflated === false) {            // fall back to "stored" if deflate fails
            $deflated = $content;
            $method   = 0;
        } else {
            $method = 8;
        }
        $compSize = strlen($deflated);

        $local .= "\x50\x4b\x03\x04"
            . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', 0)  . pack('v', 0)                       // mod time/date
            . pack('V', $crc) . pack('V', $compSize) . pack('V', $uncompSize)
            . pack('v', strlen($name)) . pack('v', 0)
            . $name . $deflated;

        $central .= "\x50\x4b\x01\x02"
            . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $method)
            . pack('v', 0)  . pack('v', 0)
            . pack('V', $crc) . pack('V', $compSize) . pack('V', $uncompSize)
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
            . pack('v', 0)  . pack('v', 0) . pack('V', 0)
            . pack('V', $offset)
            . $name;

        $count++;
    }

    $eocd = "\x50\x4b\x05\x06"
        . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central)) . pack('V', strlen($local))
        . pack('v', 0);

    return $local . $central . $eocd;
}

/* ---------------- Flash messages ---------------- */

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- CSRF protection ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        die('Invalid or expired form token. Please go back and try again.');
    }
}
