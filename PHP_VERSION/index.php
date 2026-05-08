<?php
declare(strict_types=1);

session_start();

const ALLOWED_OBJ_EXTENSIONS = ['obj'];
const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'jfif'];
const ALLOWED_OUTPUT_SIZES = [
    '700x525' => [700, 525],
    '1200x800' => [1200, 800],
    '1280x720' => [1280, 720],
    '1920x1080' => [1920, 1080],
    '2560x1440' => [2560, 1440],
];

$baseDir = __DIR__;
$objDir = $baseDir . '/obj';
$backgroundDir = $baseDir . '/background';
$stereogramDir = $baseDir . '/stereogrammi';

@mkdir($objDir, 0777, true);
@mkdir($backgroundDir, 0777, true);
@mkdir($stereogramDir, 0777, true);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function allowed_file(string $filename, array $allowedExtensions): bool
{
    $dotPos = strrpos($filename, '.');
    if ($dotPos === false) {
        return false;
    }

    $ext = strtolower(substr($filename, $dotPos + 1));
    return in_array($ext, $allowedExtensions, true);
}

function clamp(float $value, float $minValue, float $maxValue): float
{
    if ($value < $minValue) {
        return $minValue;
    }
    if ($value > $maxValue) {
        return $maxValue;
    }
    return $value;
}

function available_files(string $directory, array $allowedExtensions): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $items = scandir($directory);
    if ($items === false) {
        return [];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . '/' . $item;
        if (!is_file($path)) {
            continue;
        }
        if (!allowed_file($item, $allowedExtensions)) {
            continue;
        }
        $files[] = $item;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function resolve_existing_file(string $directory, string $filename, array $allowedExtensions): string
{
    $cleaned = trim($filename);
    if ($cleaned === '') {
        throw new RuntimeException('Nome file non valido.');
    }

    if (basename($cleaned) !== $cleaned) {
        throw new RuntimeException('Percorso file non valido.');
    }

    if (!allowed_file($cleaned, $allowedExtensions)) {
        throw new RuntimeException('Estensione file non supportata.');
    }

    $available = available_files($directory, $allowedExtensions);
    if (!in_array($cleaned, $available, true)) {
        throw new RuntimeException('File non trovato nella cartella prevista.');
    }

    return $directory . '/' . $cleaned;
}

function recent_stereograms(string $directory, int $limit = 12): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $items = scandir($directory);
    if ($items === false) {
        return [];
    }

    $result = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $lower = strtolower($name);
        if (!(str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg'))) {
            continue;
        }

        $path = $directory . '/' . $name;
        if (!is_file($path)) {
            continue;
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            $mtime = 0;
        }

        $result[] = [
            'name' => $name,
            'modified_epoch' => $mtime,
            'modified' => date('d/m/Y H:i', $mtime),
            'url' => '?action=serve&type=stereogrammi&file=' . rawurlencode($name),
        ];
    }

    usort(
        $result,
        static fn(array $a, array $b): int => ($b['modified_epoch'] <=> $a['modified_epoch'])
    );

    return array_slice($result, 0, $limit);
}

function parse_obj(string $content): array
{
    $vertices = [];
    $faces = [];

    $lines = preg_split('/\r\n|\r|\n/', $content);
    if ($lines === false) {
        throw new RuntimeException('Impossibile leggere il contenuto OBJ.');
    }

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = preg_split('/\s+/', $line);
        if ($parts === false || count($parts) < 1) {
            continue;
        }

        if ($parts[0] === 'v' && count($parts) >= 4) {
            $x = (float)$parts[1];
            $y = (float)$parts[2];
            $z = (float)$parts[3];
            $vertices[] = [$x, $y, $z];
            continue;
        }

        if ($parts[0] === 'f' && count($parts) >= 4) {
            $indices = [];
            for ($i = 1; $i < count($parts); $i++) {
                $token = $parts[$i];
                $idxText = explode('/', $token)[0] ?? '';
                if ($idxText === '' || !is_numeric($idxText)) {
                    continue;
                }

                $idx = (int)$idxText;
                if ($idx < 0) {
                    $idx = count($vertices) + $idx;
                } else {
                    $idx = $idx - 1;
                }

                if ($idx >= 0 && $idx < count($vertices)) {
                    $indices[] = $idx;
                }
            }

            for ($i = 1; $i < count($indices) - 1; $i++) {
                $faces[] = [$indices[0], $indices[$i], $indices[$i + 1]];
            }
        }
    }

    if (count($vertices) === 0 || count($faces) === 0) {
        throw new RuntimeException('Il file OBJ non contiene geometria valida.');
    }

    return [$vertices, $faces];
}

function render_depth_map(
    array $vertices,
    array $faces,
    int $width,
    int $height,
    float $objScale,
    float $rotXDeg,
    float $rotYDeg,
    float $rotZDeg,
    float $transXPct,
    float $transYPct
): array {
    $xs = [];
    $ys = [];
    $zs = [];
    foreach ($vertices as $v) {
        $xs[] = $v[0];
        $ys[] = $v[1];
        $zs[] = $v[2];
    }

    $minX = min($xs);
    $maxX = max($xs);
    $minY = min($ys);
    $maxY = max($ys);
    $minZ = min($zs);
    $maxZ = max($zs);

    $cx = ($minX + $maxX) / 2.0;
    $cy = ($minY + $maxY) / 2.0;
    $cz = ($minZ + $maxZ) / 2.0;

    $rx = deg2rad($rotXDeg);
    $ry = deg2rad($rotYDeg);
    $rz = deg2rad($rotZDeg);

    $cosX = cos($rx);
    $sinX = sin($rx);
    $cosY = cos($ry);
    $sinY = sin($ry);
    $cosZ = cos($rz);
    $sinZ = sin($rz);

    $rotated = [];
    foreach ($vertices as $v) {
        $tx = $v[0] - $cx;
        $ty = $v[1] - $cy;
        $tz = $v[2] - $cz;

        $x1 = ($tx * $cosY) - ($tz * $sinY);
        $z1 = ($tx * $sinY) + ($tz * $cosY);

        $y2 = ($ty * $cosX) - ($z1 * $sinX);
        $z2 = ($ty * $sinX) + ($z1 * $cosX);

        $x3 = ($x1 * $cosZ) - ($y2 * $sinZ);
        $y3 = ($x1 * $sinZ) + ($y2 * $cosZ);

        $rotated[] = [$x3, $y3, $z2];
    }

    $rxs = [];
    $rys = [];
    $rzs = [];
    foreach ($rotated as $v) {
        $rxs[] = $v[0];
        $rys[] = $v[1];
        $rzs[] = $v[2];
    }

    $rminX = min($rxs);
    $rmaxX = max($rxs);
    $rminY = min($rys);
    $rmaxY = max($rys);
    $rminZ = min($rzs);
    $rmaxZ = max($rzs);

    $spanX = max($rmaxX - $rminX, 1e-6);
    $spanY = max($rmaxY - $rminY, 1e-6);
    $spanZ = max($rmaxZ - $rminZ, 1e-6);

    $baseScale = min(($width * 0.92) / $spanX, ($height * 0.92) / $spanY);
    $scale = $baseScale * $objScale;

    $centerX = ($width / 2.0) + (($transXPct / 100.0) * $width * 0.45);
    $centerY = ($height / 2.0) - (($transYPct / 100.0) * $height * 0.45);

    $projected = [];
    foreach ($rotated as $v) {
        $sx = ($v[0] * $scale) + $centerX;
        $sy = $centerY - ($v[1] * $scale);
        $sz = ($v[2] - $rminZ) / $spanZ;
        $projected[] = [$sx, $sy, $sz];
    }

    $pixelCount = $width * $height;
    $depth = array_fill(0, $pixelCount, 0.0);
    $zBuffer = array_fill(0, $pixelCount, -1e9);

    $edge = static function (float $ax, float $ay, float $bx, float $by, float $px, float $py): float {
        return ($px - $ax) * ($by - $ay) - ($py - $ay) * ($bx - $ax);
    };

    foreach ($faces as $face) {
        [$i1, $i2, $i3] = $face;
        [$x1, $y1, $z1] = $projected[$i1];
        [$x2, $y2, $z2] = $projected[$i2];
        [$x3, $y3, $z3] = $projected[$i3];

        $area = $edge($x1, $y1, $x2, $y2, $x3, $y3);
        if (abs($area) < 1e-8) {
            continue;
        }

        $minPX = max(0, (int)floor(min($x1, $x2, $x3)));
        $maxPX = min($width - 1, (int)ceil(max($x1, $x2, $x3)));
        $minPY = max(0, (int)floor(min($y1, $y2, $y3)));
        $maxPY = min($height - 1, (int)ceil(max($y1, $y2, $y3)));

        for ($py = $minPY; $py <= $maxPY; $py++) {
            for ($px = $minPX; $px <= $maxPX; $px++) {
                $fx = $px + 0.5;
                $fy = $py + 0.5;

                $w0 = $edge($x2, $y2, $x3, $y3, $fx, $fy);
                $w1 = $edge($x3, $y3, $x1, $y1, $fx, $fy);
                $w2 = $edge($x1, $y1, $x2, $y2, $fx, $fy);

                $inside = ($area > 0)
                    ? ($w0 >= 0 && $w1 >= 0 && $w2 >= 0)
                    : ($w0 <= 0 && $w1 <= 0 && $w2 <= 0);

                if (!$inside) {
                    continue;
                }

                $z = (($w0 * $z1) + ($w1 * $z2) + ($w2 * $z3)) / $area;
                $index = ($py * $width) + $px;
                if ($z > $zBuffer[$index]) {
                    $zBuffer[$index] = $z;
                    $depth[$index] = clamp($z, 0.0, 1.0);
                }
            }
        }
    }

    return $depth;
}

function create_image_from_bytes(string $bytes)
{
    $img = @imagecreatefromstring($bytes);
    if ($img === false) {
        throw new RuntimeException('Impossibile leggere l\'immagine di sfondo.');
    }
    imagepalettetotruecolor($img);
    return $img;
}

function prepare_background(string $imageBytes, int $width, int $height, float $bgScale, string $bgMode)
{
    $image = create_image_from_bytes($imageBytes);

    $srcW = imagesx($image);
    $srcH = imagesy($image);

    if ($bgMode === 'tile') {
        $tileW = max(16, (int)round($srcW * $bgScale));
        $tileH = max(16, (int)round($srcH * $bgScale));

        $tile = imagecreatetruecolor($tileW, $tileH);
        imagecopyresampled($tile, $image, 0, 0, 0, 0, $tileW, $tileH, $srcW, $srcH);

        $canvas = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += $tileH) {
            for ($x = 0; $x < $width; $x += $tileW) {
                imagecopy($canvas, $tile, $x, $y, 0, 0, $tileW, $tileH);
            }
        }

        imagedestroy($tile);
        imagedestroy($image);
        return $canvas;
    }

    $coverScale = max($width / max(1, $srcW), $height / max(1, $srcH));
    $finalScale = max(0.01, $coverScale * $bgScale);

    $scaledW = max($width, (int)round($srcW * $finalScale));
    $scaledH = max($height, (int)round($srcH * $finalScale));

    $resized = imagecreatetruecolor($scaledW, $scaledH);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

    $left = (int)(($scaledW - $width) / 2);
    $top = (int)(($scaledH - $height) / 2);

    $canvas = imagecreatetruecolor($width, $height);
    imagecopy($canvas, $resized, 0, 0, $left, $top, $width, $height);

    imagedestroy($resized);
    imagedestroy($image);

    return $canvas;
}

function generate_stereogram($texture, array $depthMap, int $depthLevel)
{
    $width = imagesx($texture);
    $height = imagesy($texture);

    $out = imagecreatetruecolor($width, $height);
    $eyeSep = max(48, (int)($width * 0.08));
    $disparitySpan = max(2, (int)(($depthLevel / 100.0) * $eyeSep * 0.55));

    for ($y = 0; $y < $height; $y++) {
        $parent = [];
        for ($i = 0; $i < $width; $i++) {
            $parent[$i] = $i;
        }

        $find = static function (int $a) use (&$parent): int {
            while ($parent[$a] !== $a) {
                $parent[$a] = $parent[$parent[$a]];
                $a = $parent[$a];
            }
            return $a;
        };

        $union = static function (int $a, int $b) use (&$find, &$parent): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra === $rb) {
                return;
            }
            if ($ra < $rb) {
                $parent[$rb] = $ra;
            } else {
                $parent[$ra] = $rb;
            }
        };

        $rowOffset = ($y * 31) % max(1, $eyeSep);
        $baseStrip = [];
        for ($x = 0; $x < $eyeSep; $x++) {
            $h = ($x * 1103515245 + ($y + 1) * 12345 + $rowOffset * 977) & 0x7FFFFFFF;
            $tx = $h % $width;
            $ty = ((($h >> 9) + ($y * 3)) % $height);
            $baseStrip[$x] = imagecolorat($texture, $tx, $ty);
        }

        for ($x = 0; $x < $width; $x++) {
            $depth = $depthMap[($y * $width) + $x];
            $z = pow($depth, 1.8);
            $separation = $eyeSep - (int)($disparitySpan * $z);
            $separation = max(1, min($eyeSep, $separation));

            $left = $x - intdiv($separation, 2);
            $right = $left + $separation;

            if ($left >= 0 && $right >= 0 && $left < $width && $right < $width) {
                $union($left, $right);
            }
        }

        $rowColors = array_fill(0, $width, 0);
        for ($x = 0; $x < $width; $x++) {
            $root = $find($x);
            if ($root === $x) {
                $rowColors[$x] = $baseStrip[$x % $eyeSep];
            } else {
                $rowColors[$x] = $rowColors[$root] ?: $baseStrip[$root % $eyeSep];
            }
        }

        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($out, $x, $y, $rowColors[$x]);
        }
    }

    return $out;
}

function serve_static_file(string $objDir, string $backgroundDir, string $stereogramDir): void
{
    $type = (string)($_GET['type'] ?? '');
    $file = basename((string)($_GET['file'] ?? ''));

    $map = [
        'obj' => [$objDir, ALLOWED_OBJ_EXTENSIONS],
        'background' => [$backgroundDir, ALLOWED_IMAGE_EXTENSIONS],
        'stereogrammi' => [$stereogramDir, ['jpg', 'jpeg']],
    ];

    if (!isset($map[$type])) {
        http_response_code(404);
        exit('Not found');
    }

    [$directory, $allowed] = $map[$type];
    if ($file === '' || !allowed_file($file, $allowed)) {
        http_response_code(404);
        exit('Not found');
    }

    $path = $directory . '/' . $file;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $contentType = 'application/octet-stream';

    if ($type === 'obj') {
        $contentType = 'text/plain; charset=utf-8';
    } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
        $contentType = 'image/jpeg';
    } elseif ($ext === 'png') {
        $contentType = 'image/png';
    } elseif ($ext === 'webp') {
        $contentType = 'image/webp';
    } elseif ($ext === 'bmp') {
        $contentType = 'image/bmp';
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

if (($_GET['action'] ?? '') === 'serve') {
    serve_static_file($objDir, $backgroundDir, $stereogramDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Estensione PHP GD non disponibile.');
        }

        $objScale = clamp((float)($_POST['obj_scale'] ?? '1.0'), 0.2, 8.0);
        $bgScale = clamp((float)($_POST['bg_scale'] ?? '1.0'), 0.2, 3.0);
        $depthLevel = (int)clamp((float)($_POST['depth_level'] ?? '35'), 1, 100);
        $rotX = clamp((float)($_POST['rot_x'] ?? '0'), -180.0, 180.0);
        $rotY = clamp((float)($_POST['rot_y'] ?? '0'), -180.0, 180.0);
        $rotZ = clamp((float)($_POST['rot_z'] ?? '0'), -180.0, 180.0);
        $transX = clamp((float)($_POST['trans_x'] ?? '0'), -100.0, 100.0);
        $transY = clamp((float)($_POST['trans_y'] ?? '0'), -100.0, 100.0);

        $bgMode = (string)($_POST['bg_mode'] ?? 'full');
        if ($bgMode !== 'full' && $bgMode !== 'tile') {
            $bgMode = 'full';
        }

        $outputSize = (string)($_POST['output_size'] ?? '1200x800');
        if (!isset(ALLOWED_OUTPUT_SIZES[$outputSize])) {
            $outputSize = '1200x800';
        }
        [$outputWidth, $outputHeight] = ALLOWED_OUTPUT_SIZES[$outputSize];

        $objExisting = trim((string)($_POST['obj_existing'] ?? ''));
        $bgExisting = trim((string)($_POST['bg_existing'] ?? ''));

        if ($objExisting !== '') {
            $objPath = resolve_existing_file($objDir, $objExisting, ALLOWED_OBJ_EXTENSIONS);
            $objBytes = file_get_contents($objPath);
            if ($objBytes === false) {
                throw new RuntimeException('Impossibile leggere OBJ dalla cartella server.');
            }
            $objSourceName = basename($objPath);
        } else {
            if (!isset($_FILES['obj_file']) || ($_FILES['obj_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleziona un file OBJ o scegline uno dalla cartella server.');
            }
            $objName = (string)($_FILES['obj_file']['name'] ?? '');
            if (!allowed_file($objName, ALLOWED_OBJ_EXTENSIONS)) {
                throw new RuntimeException('Formato OBJ non valido.');
            }
            $tmpPath = (string)($_FILES['obj_file']['tmp_name'] ?? '');
            $objBytes = file_get_contents($tmpPath);
            if ($objBytes === false) {
                throw new RuntimeException('Impossibile leggere il file OBJ caricato.');
            }
            $objSourceName = $objName;
        }

        if ($bgExisting !== '') {
            $bgPath = resolve_existing_file($backgroundDir, $bgExisting, ALLOWED_IMAGE_EXTENSIONS);
            $bgBytes = file_get_contents($bgPath);
            if ($bgBytes === false) {
                throw new RuntimeException('Impossibile leggere sfondo dalla cartella server.');
            }
        } else {
            if (!isset($_FILES['bg_file']) || ($_FILES['bg_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleziona un\'immagine di sfondo o scegline una dalla cartella server.');
            }
            $bgName = (string)($_FILES['bg_file']['name'] ?? '');
            if (!allowed_file($bgName, ALLOWED_IMAGE_EXTENSIONS)) {
                throw new RuntimeException('Formato immagine non supportato.');
            }
            $tmpPath = (string)($_FILES['bg_file']['tmp_name'] ?? '');
            $bgBytes = file_get_contents($tmpPath);
            if ($bgBytes === false) {
                throw new RuntimeException('Impossibile leggere il file sfondo caricato.');
            }
        }

        [$vertices, $faces] = parse_obj($objBytes);
        $depthMap = render_depth_map(
            $vertices,
            $faces,
            $outputWidth,
            $outputHeight,
            $objScale,
            $rotX,
            $rotY,
            $rotZ,
            $transX,
            $transY
        );

        $texture = prepare_background($bgBytes, $outputWidth, $outputHeight, $bgScale, $bgMode);
        $stereogram = generate_stereogram($texture, $depthMap, $depthLevel);

        $timestamp = date('Ymd_His');
        $objBase = pathinfo($objSourceName, PATHINFO_FILENAME);
        $objBase = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)$objBase);
        if ($objBase === '' || $objBase === null) {
            $objBase = 'modello';
        }

        $outName = 'stereogramma_' . $objBase . '_' . $timestamp . '.jpg';
        $outPath = $stereogramDir . '/' . $outName;

        imagejpeg($stereogram, $outPath, 95);

        header('Content-Type: image/jpeg');
        header('Content-Disposition: attachment; filename="' . $outName . '"');
        header('Cache-Control: no-store');
        imagejpeg($stereogram, null, 95);

        imagedestroy($texture);
        imagedestroy($stereogram);
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_messages'] = [$e->getMessage()];
        header('Location: ./');
        exit;
    }
}

$flashMessages = $_SESSION['flash_messages'] ?? [];
unset($_SESSION['flash_messages']);

$availableObjFiles = available_files($objDir, ALLOWED_OBJ_EXTENSIONS);
$availableBgFiles = available_files($backgroundDir, ALLOWED_IMAGE_EXTENSIONS);
$galleryItems = recent_stereograms($stereogramDir, 12);
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generatore Stereogrammi PHP</title>
    <link rel="stylesheet" href="assets/main.css">
</head>
<body>
<main class="page">
    <section class="hero">
        <h1>Generatore di Stereogrammi (PHP)</h1>
        <p>Versione standalone in PHP: carica OBJ + sfondo, regola posa e profondita, poi genera e scarica JPG.</p>
    </section>

    <?php if (count($flashMessages) > 0): ?>
        <section class="alerts">
            <?php foreach ($flashMessages as $msg): ?>
                <p><?= h((string)$msg) ?></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <form class="generator-form" action="" method="post" enctype="multipart/form-data">
        <section class="panel">
            <h2>Oggetto 3D (OBJ)</h2>

            <label for="obj_existing">OBJ dalla cartella server</label>
            <select id="obj_existing" name="obj_existing">
                <option value="">Nessuno (usa upload)</option>
                <?php foreach ($availableObjFiles as $filename): ?>
                    <option value="<?= h($filename) ?>"><?= h($filename) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="obj_file">File OBJ</label>
            <input id="obj_file" name="obj_file" type="file" accept=".obj">

            <label for="obj_scale">Ridimensionamento oggetto: <strong id="obj_scale_value">1.0x</strong></label>
            <input id="obj_scale" name="obj_scale" type="range" min="0.2" max="8.0" step="0.1" value="1.0">

            <label for="rot_x">Rotazione X: <strong id="rot_x_value">0deg</strong></label>
            <input id="rot_x" name="rot_x" type="range" min="-180" max="180" step="1" value="0">

            <label for="rot_y">Rotazione Y: <strong id="rot_y_value">0deg</strong></label>
            <input id="rot_y" name="rot_y" type="range" min="-180" max="180" step="1" value="0">

            <label for="rot_z">Rotazione Z: <strong id="rot_z_value">0deg</strong></label>
            <input id="rot_z" name="rot_z" type="range" min="-180" max="180" step="1" value="0">

            <label for="trans_x">Posizione X: <strong id="trans_x_value">0%</strong></label>
            <input id="trans_x" name="trans_x" type="range" min="-100" max="100" step="1" value="0">

            <label for="trans_y">Posizione Y: <strong id="trans_y_value">0%</strong></label>
            <input id="trans_y" name="trans_y" type="range" min="-100" max="100" step="1" value="0">

            <div class="preview-box">
                <canvas id="obj_preview" width="640" height="420"></canvas>
            </div>
        </section>

        <section class="panel">
            <h2>Sfondo</h2>

            <label for="bg_existing">Sfondo dalla cartella server</label>
            <select id="bg_existing" name="bg_existing">
                <option value="">Nessuno (usa upload)</option>
                <?php foreach ($availableBgFiles as $filename): ?>
                    <option value="<?= h($filename) ?>"><?= h($filename) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="bg_file">Immagine di sfondo</label>
            <input id="bg_file" name="bg_file" type="file" accept="image/*,.jfif">

            <label for="bg_scale">Ridimensionamento sfondo: <strong id="bg_scale_value">1.0x</strong></label>
            <input id="bg_scale" name="bg_scale" type="range" min="0.2" max="3.0" step="0.1" value="1.0">

            <label for="bg_mode">Modalita sfondo</label>
            <select id="bg_mode" name="bg_mode">
                <option value="full" selected>Pieno sfondo</option>
                <option value="tile">Affiancato (tile)</option>
            </select>

            <div class="preview-box bg-preview-wrap">
                <img id="bg_preview" alt="Anteprima sfondo" hidden>
                <p id="bg_placeholder">Nessuna immagine selezionata</p>
            </div>
        </section>

        <section class="panel panel-full">
            <h2>Profondita e Generazione</h2>

            <label for="depth_level">Profondita: <strong id="depth_value">35</strong></label>
            <input id="depth_level" name="depth_level" type="range" min="1" max="100" value="35">
            <p class="hint">Significato: aumenta la disparita tra pattern sinistra/destra. Valori alti = rilievo 3D piu forte, ma piu difficile da fondere.</p>

            <label for="output_size">Risoluzione output</label>
            <select id="output_size" name="output_size">
                <?php foreach (ALLOWED_OUTPUT_SIZES as $key => $size): ?>
                    <option value="<?= h($key) ?>"><?= h($key) ?> (<?= (int)$size[0] ?> x <?= (int)$size[1] ?>)</option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Genera stereogramma</button>
            <p class="hint">Il file generato viene salvato in PHP_VERSION/stereogrammi e scaricato come JPG.</p>
        </section>
    </form>

    <section class="gallery panel-full">
        <h2>Ultimi stereogrammi salvati</h2>
        <?php if (count($galleryItems) > 0): ?>
            <div class="gallery-grid">
                <?php foreach ($galleryItems as $item): ?>
                    <article class="gallery-card">
                        <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer">
                            <img src="<?= h($item['url']) ?>" alt="<?= h($item['name']) ?>" loading="lazy">
                        </a>
                        <div class="gallery-meta">
                            <p><?= h($item['name']) ?></p>
                            <small><?= h($item['modified']) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="gallery-empty">Nessuno stereogramma salvato al momento.</p>
        <?php endif; ?>
    </section>
</main>

<script>
    const objInput = document.getElementById("obj_file");
    const bgInput = document.getElementById("bg_file");
    const objExisting = document.getElementById("obj_existing");
    const bgExisting = document.getElementById("bg_existing");

    const objScale = document.getElementById("obj_scale");
    const rotX = document.getElementById("rot_x");
    const rotY = document.getElementById("rot_y");
    const rotZ = document.getElementById("rot_z");
    const transX = document.getElementById("trans_x");
    const transY = document.getElementById("trans_y");

    const bgScale = document.getElementById("bg_scale");
    const depthLevel = document.getElementById("depth_level");

    const objScaleValue = document.getElementById("obj_scale_value");
    const rotXValue = document.getElementById("rot_x_value");
    const rotYValue = document.getElementById("rot_y_value");
    const rotZValue = document.getElementById("rot_z_value");
    const transXValue = document.getElementById("trans_x_value");
    const transYValue = document.getElementById("trans_y_value");
    const bgScaleValue = document.getElementById("bg_scale_value");
    const depthValue = document.getElementById("depth_value");

    const bgPreview = document.getElementById("bg_preview");
    const bgPlaceholder = document.getElementById("bg_placeholder");
    const bgModeSelect = document.getElementById("bg_mode");

    const canvas = document.getElementById("obj_preview");
    const ctx = canvas.getContext("2d");
    const MAX_PREVIEW_FACES = 4500;
    const MAX_PREVIEW_EDGES = 18000;
    const PREVIEW_FPS = 18;

    let modelData = null;
    let lastFrameTime = 0;
    let pointerDragging = false;
    let lastPointerX = 0;
    let lastPointerY = 0;

    function edgeKey(a, b) {
        return a < b ? `${a}_${b}` : `${b}_${a}`;
    }

    function showObjMessage(message) {
        const width = canvas.clientWidth || canvas.width;
        const height = canvas.clientHeight || canvas.height;
        canvas.width = width;
        canvas.height = height;
        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = "#f0f6ff";
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = "#3e556d";
        ctx.font = "600 14px Space Grotesk, sans-serif";
        ctx.textAlign = "center";
        ctx.fillText(message, width / 2, height / 2);
    }

    function parseObj(raw) {
        const vertices = [];
        const faces = [];
        const lines = raw.split(/\r?\n/);

        for (const lineRaw of lines) {
            const line = lineRaw.trim();
            if (!line || line.startsWith("#")) {
                continue;
            }

            const parts = line.split(/\s+/);
            if (parts[0] === "v" && parts.length >= 4) {
                const x = Number(parts[1]);
                const y = Number(parts[2]);
                const z = Number(parts[3]);
                if (Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z)) {
                    vertices.push([x, y, z]);
                }
            }

            if (parts[0] === "f" && parts.length >= 4) {
                const face = [];
                for (const token of parts.slice(1)) {
                    const indexText = token.split("/")[0];
                    const idx = Number(indexText);
                    if (!Number.isInteger(idx)) {
                        continue;
                    }
                    const zeroIndex = idx > 0 ? idx - 1 : vertices.length + idx;
                    if (zeroIndex >= 0 && zeroIndex < vertices.length) {
                        face.push(zeroIndex);
                    }
                }
                if (face.length >= 3) {
                    faces.push(face);
                }
            }
        }

        if (!vertices.length || !faces.length) {
            return null;
        }

        let minX = Infinity;
        let minY = Infinity;
        let minZ = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;
        let maxZ = -Infinity;

        for (const [x, y, z] of vertices) {
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            minZ = Math.min(minZ, z);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
            maxZ = Math.max(maxZ, z);
        }

        const cx = (minX + maxX) / 2;
        const cy = (minY + maxY) / 2;
        const cz = (minZ + maxZ) / 2;
        const spanX = maxX - minX;
        const spanY = maxY - minY;
        const spanZ = maxZ - minZ;
        const maxSpan = Math.max(spanX, spanY, spanZ, 1e-6);

        const normalized = vertices.map(([x, y, z]) => [
            (x - cx) / maxSpan,
            (y - cy) / maxSpan,
            (z - cz) / maxSpan,
        ]);

        const stride = Math.max(1, Math.ceil(faces.length / MAX_PREVIEW_FACES));
        const sampledFaces = [];
        for (let i = 0; i < faces.length; i += stride) {
            sampledFaces.push(faces[i]);
        }

        const edgeSet = new Set();
        const edges = [];
        for (const face of sampledFaces) {
            for (let i = 0; i < face.length; i += 1) {
                const a = face[i];
                const b = face[(i + 1) % face.length];
                const key = edgeKey(a, b);
                if (!edgeSet.has(key)) {
                    edgeSet.add(key);
                    edges.push([a, b]);
                    if (edges.length >= MAX_PREVIEW_EDGES) {
                        break;
                    }
                }
            }
            if (edges.length >= MAX_PREVIEW_EDGES) {
                break;
            }
        }

        return {
            vertices: normalized,
            edges,
            totalFaces: faces.length,
            sampledFaces: sampledFaces.length,
        };
    }

    function project([x, y, z], angleY, angleX, angleZ, scale, width, height, centerX, centerY) {
        const cy = Math.cos(angleY);
        const sy = Math.sin(angleY);
        const cx = Math.cos(angleX);
        const sx = Math.sin(angleX);
        const cz = Math.cos(angleZ);
        const sz = Math.sin(angleZ);

        const x1 = x * cy - z * sy;
        const z1 = x * sy + z * cy;
        const y2 = y * cx - z1 * sx;
        const z2 = y * sx + z1 * cx;
        const x3 = x1 * cz - y2 * sz;
        const y3 = x1 * sz + y2 * cz;

        const perspective = 1 / (2.2 - z2);
        const px = centerX + x3 * scale * perspective;
        const py = centerY - y3 * scale * perspective;
        return [px, py];
    }

    function drawObjPreview() {
        const width = canvas.clientWidth || canvas.width;
        const height = canvas.clientHeight || canvas.height;
        canvas.width = width;
        canvas.height = height;

        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = "#ecf4ff";
        ctx.fillRect(0, 0, width, height);

        if (!modelData) {
            showObjMessage("Nessun file OBJ selezionato");
            return;
        }

        const scale = Number(objScale.value);
        const angleX = (Number(rotX.value) * Math.PI) / 180;
        const angleY = (Number(rotY.value) * Math.PI) / 180;
        const angleZ = (Number(rotZ.value) * Math.PI) / 180;
        const centerX = width / 2 + (Number(transX.value) / 100) * width * 0.45;
        const centerY = height / 2 - (Number(transY.value) / 100) * height * 0.45;
        const drawScale = Math.min(width, height) * 1.9 * scale;

        const points = modelData.vertices.map((v) =>
            project(v, angleY, angleX, angleZ, drawScale, width, height, centerX, centerY)
        );

        ctx.strokeStyle = "#2b5f94";
        ctx.lineWidth = 1.1;
        ctx.beginPath();

        for (const edge of modelData.edges) {
            const a = points[edge[0]];
            const b = points[edge[1]];
            if (!a || !b) {
                continue;
            }
            ctx.moveTo(a[0], a[1]);
            ctx.lineTo(b[0], b[1]);
        }

        ctx.stroke();

        ctx.fillStyle = "rgba(13, 32, 51, 0.86)";
        ctx.font = "600 12px Space Grotesk, sans-serif";
        ctx.textAlign = "left";
        ctx.fillText(
            `Preview ottimizzata: facce ${modelData.sampledFaces}/${modelData.totalFaces} | spigoli ${modelData.edges.length}`,
            12,
            22
        );
    }

    function tick(now) {
        if (now - lastFrameTime >= 1000 / PREVIEW_FPS) {
            lastFrameTime = now;
            drawObjPreview();
        }
        requestAnimationFrame(tick);
    }

    function updateRotationLabels() {
        objScaleValue.textContent = `${Number(objScale.value).toFixed(1)}x`;
        rotXValue.textContent = `${Math.round(Number(rotX.value))}deg`;
        rotYValue.textContent = `${Math.round(Number(rotY.value))}deg`;
        rotZValue.textContent = `${Math.round(Number(rotZ.value))}deg`;
        transXValue.textContent = `${Math.round(Number(transX.value))}%`;
        transYValue.textContent = `${Math.round(Number(transY.value))}%`;
        bgScaleValue.textContent = `${Number(bgScale.value).toFixed(1)}x`;
        depthValue.textContent = depthLevel.value;
    }

    function updateBackgroundPreview() {
        if (!bgPreview.src) {
            return;
        }

        const scale = Number(bgScale.value);
        if (bgModeSelect.value === "tile") {
            bgPreview.hidden = true;
            bgPreview.style.objectFit = "none";
            bgPreview.style.objectPosition = "top left";
            bgPreview.style.width = `${100 / scale}%`;
            bgPreview.style.height = `${100 / scale}%`;
            bgPreview.style.transform = "none";
            bgPreview.parentElement.style.backgroundSize = `${100 / scale}%`;
            bgPreview.parentElement.style.backgroundImage = `url('${bgPreview.src}')`;
            bgPreview.parentElement.style.backgroundRepeat = "repeat";
        } else {
            bgPreview.hidden = false;
            bgPreview.parentElement.style.backgroundImage = "none";
            bgPreview.parentElement.style.backgroundRepeat = "no-repeat";
            bgPreview.style.objectFit = "cover";
            bgPreview.style.width = "100%";
            bgPreview.style.height = "100%";
            bgPreview.style.transform = `scale(${scale})`;
            bgPreview.style.transformOrigin = "center";
        }
    }

    objScale.addEventListener("input", () => {
        updateRotationLabels();
        drawObjPreview();
    });

    [rotX, rotY, rotZ, transX, transY].forEach((slider) => {
        slider.addEventListener("input", () => {
            updateRotationLabels();
            drawObjPreview();
        });
    });

    bgScale.addEventListener("input", () => {
        updateRotationLabels();
        updateBackgroundPreview();
    });

    depthLevel.addEventListener("input", updateRotationLabels);
    bgModeSelect.addEventListener("change", updateBackgroundPreview);

    bgInput.addEventListener("change", () => {
        if (bgInput.files && bgInput.files[0]) {
            bgExisting.value = "";
        }

        const file = bgInput.files && bgInput.files[0];
        if (!file) {
            bgPreview.hidden = true;
            bgPlaceholder.hidden = false;
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            bgPreview.src = String(reader.result || "");
            bgPreview.hidden = false;
            bgPlaceholder.hidden = true;
            updateBackgroundPreview();
        };
        reader.readAsDataURL(file);
    });

    bgExisting.addEventListener("change", () => {
        const filename = bgExisting.value;
        if (!filename) {
            return;
        }

        bgInput.value = "";
        bgPreview.src = `?action=serve&type=background&file=${encodeURIComponent(filename)}`;
        bgPreview.hidden = false;
        bgPlaceholder.hidden = true;
        updateBackgroundPreview();
    });

    objInput.addEventListener("change", () => {
        if (objInput.files && objInput.files[0]) {
            objExisting.value = "";
        }

        const file = objInput.files && objInput.files[0];
        if (!file) {
            modelData = null;
            drawObjPreview();
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            try {
                modelData = parseObj(String(reader.result || ""));
                if (!modelData) {
                    showObjMessage("OBJ non valido o senza geometria");
                    return;
                }
                drawObjPreview();
            } catch (error) {
                console.error("Errore anteprima OBJ:", error);
                showObjMessage("Errore caricamento anteprima OBJ");
            }
        };
        reader.readAsText(file);
    });

    objExisting.addEventListener("change", async () => {
        const filename = objExisting.value;
        if (!filename) {
            return;
        }

        objInput.value = "";

        try {
            const response = await fetch(`?action=serve&type=obj&file=${encodeURIComponent(filename)}`);
            if (!response.ok) {
                throw new Error("Errore fetch OBJ server");
            }
            const text = await response.text();
            modelData = parseObj(text);
            if (!modelData) {
                showObjMessage("OBJ server non valido o senza geometria");
                return;
            }
            drawObjPreview();
        } catch (error) {
            console.error("Errore anteprima OBJ server:", error);
            showObjMessage("Errore caricamento OBJ da server");
        }
    });

    canvas.addEventListener("pointerdown", (event) => {
        pointerDragging = true;
        lastPointerX = event.clientX;
        lastPointerY = event.clientY;
        canvas.setPointerCapture(event.pointerId);
    });

    canvas.addEventListener("pointermove", (event) => {
        if (!pointerDragging) {
            return;
        }

        const dx = event.clientX - lastPointerX;
        const dy = event.clientY - lastPointerY;
        lastPointerX = event.clientX;
        lastPointerY = event.clientY;

        const nextY = Math.max(-180, Math.min(180, Number(rotY.value) + dx * 0.45));
        const nextX = Math.max(-180, Math.min(180, Number(rotX.value) + dy * 0.45));
        rotY.value = String(nextY);
        rotX.value = String(nextX);
        updateRotationLabels();
        drawObjPreview();
    });

    canvas.addEventListener("pointerup", (event) => {
        pointerDragging = false;
        canvas.releasePointerCapture(event.pointerId);
    });

    canvas.addEventListener("pointerleave", () => {
        pointerDragging = false;
    });

    window.addEventListener("resize", drawObjPreview);

    updateRotationLabels();
    showObjMessage("Nessun file OBJ selezionato");
    tick(0);
</script>
</body>
</html>
