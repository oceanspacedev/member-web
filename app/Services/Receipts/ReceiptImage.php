<?php

namespace App\Services\Receipts;

use Carbon\CarbonImmutable;
use GdImage;
use Throwable;

/**
 * Pengolahan foto struk: versi kecil untuk dibaca dan metadata EXIF sebagai sinyal tambahan.
 */
final class ReceiptImage
{
    /** Sisi terpanjang gambar yang dikirim ke pembaca; cukup untuk teks struk, hemat biaya. */
    private const MAX_EDGE = 1800;

    /** Aplikasi edit foto yang tertulis di EXIF "Software". */
    private const EDITORS = ['photoshop', 'lightroom', 'snapseed', 'picsart', 'canva', 'gimp', 'pixlr', 'facetune', 'remini', 'meitu', 'polarr', 'vsco', 'affinity'];

    private function __construct(private readonly GdImage $image, private readonly array $exif) {}

    public static function fromBytes(string $bytes): ?self
    {
        $image = @imagecreatefromstring($bytes);

        // HEIC dan format lain yang tidak dikenal GD: coba lewat Imagick bila ada.
        if (! $image && class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($bytes);
                $im->setImageFormat('png');
                $image = @imagecreatefromstring($im->getImageBlob());
            } catch (Throwable) {
                $image = false;
            }
        }

        if (! $image) {
            return null;
        }

        $exif = [];
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes)) ?: [];
            } catch (Throwable) {
                $exif = [];
            }
        }

        return new self(self::orient($image, (int) ($exif['Orientation'] ?? 1)), $exif);
    }

    /** JPEG yang sudah diperkecil, untuk dibaca. */
    public function forReading(): string
    {
        $w = imagesx($this->image);
        $h = imagesy($this->image);
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        $img = $scale < 1 ? imagescale($this->image, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) : $this->image;

        ob_start();
        imagejpeg($img, null, 88);

        return (string) ob_get_clean();
    }

    /** Waktu foto diambil menurut EXIF (waktu setempat), bila ada. */
    public function takenAt(string $timezone): ?CarbonImmutable
    {
        $raw = $this->exif['DateTimeOriginal'] ?? $this->exif['DateTime'] ?? null;

        try {
            return is_string($raw) ? CarbonImmutable::createFromFormat('Y:m:d H:i:s', $raw, $timezone) ?: null : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Nama aplikasi edit foto bila EXIF menyebutnya. */
    public function editedWith(): ?string
    {
        $software = strtolower((string) ($this->exif['Software'] ?? ''));

        foreach (self::EDITORS as $editor) {
            if (str_contains($software, $editor)) {
                return $this->exif['Software'];
            }
        }

        return null;
    }

    private static function orient(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
