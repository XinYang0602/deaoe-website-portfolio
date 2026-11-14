<?php
declare(strict_types=1);

/** Encode uploads once on the server. Both previews and downloads use these bytes. */
function serviceWebpVariants(array $file, array $edges): array {
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('请选择不超过8 MB的图片');
    }
    $source = $file['tmp_name'];
    $info = @getimagesize($source);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        || max($info[0], $info[1]) > 10000 || $info[0] * $info[1] > 16000000) {
        throw new InvalidArgumentException('支持JPG、PNG、WebP，最多1600万像素、单边10000像素');
    }
    $image = @imagecreatefromstring(file_get_contents($source));
    if (!$image) throw new InvalidArgumentException('图片无法解码');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = (int)((@exif_read_data($source))['Orientation'] ?? 1);
        if (in_array($orientation, [2,4,5,7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        $angle = match ($orientation) { 3,4 => 180, 6,7 => -90, 5,8 => 90, default => 0 };
        if ($angle) $image = imagerotate($image, $angle, 0);
    }
    $variants = [];
    foreach ($edges as $key => $edge) {
        $scale = min(1, $edge / max(imagesx($image), imagesy($image)));
        $width = max(1, (int)round(imagesx($image) * $scale));
        $height = max(1, (int)round(imagesy($image) * $scale));
        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $variants[$key] = serviceEncodeWebp($out);
    }
    return $variants;
}

function serviceEncodeWebp(GdImage $image): string {
    if (!imageistruecolor($image)) imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    ob_start();
    try { $ok = imagewebp($image, null, 80); $bytes = ob_get_contents(); }
    finally { ob_end_clean(); }
    $info = $ok ? @getimagesizefromstring($bytes) : false;
    if (!$info || $info[2] !== IMAGETYPE_WEBP) throw new RuntimeException('WebP转换失败');
    return $bytes;
}

function serviceWebpName(string $name): string {
    $stem = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', '-', pathinfo(basename($name), PATHINFO_FILENAME));
    return (mb_substr(trim($stem ?? '', '. '), 0, 100) ?: 'image') . '.webp';
}

function serviceWebpHeaders(string $id, string $name, int $size, bool $download): void {
    header('Content-Type: image/webp');
    header('Content-Length: ' . $size);
    $disposition = $download ? 'attachment' : 'inline';
    header("Content-Disposition: $disposition; filename=\"$id.webp\"; filename*=UTF-8''" . rawurlencode(serviceWebpName($name)));
}
