<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Promo;
use App\Models\Voucher;
use App\Models\VoucherCode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Impor daftar kode voucher dari POS outlet (file .xlsx atau .csv). Admin cukup memilih outlet dan file:
 * promo dibuat otomatis per nilai diskon (mis. "Voucher Rp5.000 – Ulon Ciledug"), atau kodenya ditambahkan
 * ke promo yang sama yang masih tayang.
 *
 * Tiap POS menamai kolomnya berbeda, jadi kolom dikenali dari nama yang umum dipakai (Kode/Code/Voucher Code,
 * Kadaluarsa/Expired, ...). Impor ulang file terbaru dari POS memperbarui status pemakaian: kode yang sudah
 * terpakai penuh di POS ditandai terpakai di voucher member.
 */
class VoucherCodeImporter
{
    /** Nama kolom yang dikenali per isian; dibandingkan tanpa spasi/tanda baca dan huruf kecil. */
    private const COLUMNS = [
        'code' => ['kode', 'kodevoucher', 'code', 'vouchercode', 'voucher', 'kupon', 'kodekupon', 'couponcode', 'coupon', 'promocode', 'kodepromo'],
        'title' => ['judul', 'nama', 'namavoucher', 'title', 'name', 'vouchername', 'deskripsi', 'description'],
        'discount' => ['diskon', 'discount', 'potongan', 'nilai', 'nominal', 'value', 'amount', 'discountamount', 'nilaidiskon'],
        'starts' => ['mulai', 'tanggalmulai', 'start', 'startdate', 'startson', 'berlakudari', 'validfrom', 'dari'],
        'ends' => ['berakhir', 'tanggalberakhir', 'end', 'enddate', 'selesai', 'sampai'],
        'expires' => ['kadaluarsa', 'kedaluwarsa', 'kadaluwarsa', 'expired', 'expiry', 'expirydate', 'expiredate', 'expiredat', 'berlakusampai', 'validuntil', 'masaberlaku'],
        'usage' => ['penggunaan', 'pemakaian', 'usage', 'used', 'terpakai', 'usedcount', 'redeemed'],
    ];

    public const SKIP_DUPLICATE = 'Kode ganda di file';

    public const SKIP_MULTI_USE = 'Kode multi-pakai (mis. 0/10) belum didukung';

    public const SKIP_PERCENT = 'Diskon persen belum didukung';

    public const SKIP_NO_VALUE = 'Nilai diskon kosong';

    public const SKIP_USED = 'Sudah terpakai di POS';

    public const SKIP_EXPIRED = 'Sudah kedaluwarsa';

    public const SKIP_OTHER_OUTLET = 'Sudah diimpor untuk outlet lain';

    public const SKIP_CLASH = 'Sama dengan kode voucher Space Club';

    public const SKIP_TOO_LONG = 'Kode lebih dari 40 karakter';

    public const SKIP_BAD_DATE = 'Tanggal tidak dikenali';

    /**
     * @param  array{type?: string, price_points?: ?int, ends_at?: ?string}  $options  pengaturan promo yang dibuat otomatis
     * @return array{created: int, updated: int, marked_used: int, skipped: array<string, list<string>>, promos: array<int, array{title: string, new: bool, added: int}>}
     */
    public function import(Outlet $outlet, string $path, string $extension, array $options = []): array
    {
        $rows = $this->rows($path, $extension);
        $summary = ['created' => 0, 'updated' => 0, 'marked_used' => 0, 'skipped' => [], 'promos' => []];
        $skip = function (string $reason, string $code) use (&$summary) {
            $summary['skipped'][$reason][] = $code;
        };
        $today = now(config('loyalty.timezone'))->toDateString();
        $seen = [];
        $promos = [];

        DB::transaction(function () use ($outlet, $options, $rows, $skip, $today, &$seen, &$promos, &$summary) {
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) ($row['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                if (mb_strlen($code) > 40) {
                    $skip(self::SKIP_TOO_LONG, mb_substr($code, 0, 20).'…');

                    continue;
                }
                if (isset($seen[$code])) {
                    $skip(self::SKIP_DUPLICATE, $code);

                    continue;
                }
                $seen[$code] = true;

                [$used, $max] = $this->usage($row['usage'] ?? null);
                if ($max > 1) {
                    $skip(self::SKIP_MULTI_USE, $code);

                    continue;
                }
                $discount = $this->discount($row['discount'] ?? null);
                if ($discount === 'percent') {
                    $skip(self::SKIP_PERCENT, $code);

                    continue;
                }

                try {
                    $starts = $this->date($row['starts'] ?? null);
                    // Berakhir dan Kadaluarsa: yang paling awal menjadi batas pakai.
                    $expires = collect([$this->date($row['ends'] ?? null), $this->date($row['expires'] ?? null)])->filter()->min();
                } catch (Throwable) {
                    $skip(self::SKIP_BAD_DATE, $code);

                    continue;
                }

                $existing = VoucherCode::with('voucher')->where('code', $code)->first();
                if ($existing && $existing->outlet_id !== $outlet->id) {
                    $skip(self::SKIP_OTHER_OUTLET, $code);

                    continue;
                }

                if ($existing) {
                    // Impor ulang: perbarui data dari POS, dan tandai voucher member yang sudah dipakai di POS.
                    $existing->forceFill([
                        'pos_title' => $this->title($row['title'] ?? null) ?? $existing->pos_title,
                        'starts_on' => $starts ?? $existing->starts_on,
                        'expires_on' => $expires ?? $existing->expires_on,
                        'used_count' => max($used, $existing->used_count),
                    ])->save();
                    if ($used >= $max && $existing->voucher && ! $existing->voucher->used_at) {
                        $existing->voucher->forceFill([
                            'used_at' => now(),
                            'outlet_id' => $existing->outlet_id,
                            'reference' => 'POS-'.$existing->code,
                        ])->save();
                        $summary['marked_used']++;
                    } else {
                        $summary['updated']++;
                    }

                    continue;
                }

                if (Voucher::where('code', $code)->exists()) {
                    $skip(self::SKIP_CLASH, $code);

                    continue;
                }
                if ($used >= $max) {
                    $skip(self::SKIP_USED, $code);

                    continue;
                }
                if ($expires && $expires->toDateString() < $today) {
                    $skip(self::SKIP_EXPIRED, $code);

                    continue;
                }
                if (! $discount) {
                    $skip(self::SKIP_NO_VALUE, $code);

                    continue;
                }

                // Satu promo per nilai diskon di outlet ini.
                $promo = $promos[$discount] ??= $this->promoFor($outlet, $discount, $options, $summary);

                VoucherCode::create([
                    'promo_id' => $promo->id,
                    'outlet_id' => $outlet->id,
                    'code' => $code,
                    'pos_title' => $this->title($row['title'] ?? null),
                    'value' => $discount,
                    'starts_on' => $starts?->toDateString(),
                    'expires_on' => $expires?->toDateString(),
                    'max_uses' => $max,
                    'used_count' => $used,
                ]);
                $summary['created']++;
                $summary['promos'][$promo->id]['added']++;
            }

            // Promo yang menerima kode naik ke atas daftar promo (diurutkan dari yang terakhir diubah).
            $touched = array_keys(array_filter($summary['promos'], fn ($p) => $p['added'] > 0));
            if ($touched) {
                Promo::whereKey($touched)->update(['updated_at' => now()]);
            }
        });

        return $summary;
    }

    /**
     * Promo yang menampung kode bernilai sama di outlet ini: promo kode POS yang masih tayang, atau promo
     * baru (tayang mulai hari ini) yang bisa dirapikan admin sesudahnya (judul, gambar, syarat).
     *
     * @param  array{type?: string, price_points?: ?int, ends_at?: ?string}  $options
     */
    private function promoFor(Outlet $outlet, int $value, array $options, array &$summary): Promo
    {
        $today = now(config('loyalty.timezone'))->toDateString();
        $promo = Promo::where('pos_outlet_id', $outlet->id)
            ->where('voucher_value', $value)
            ->where('is_active', true)
            ->whereDate('ends_at', '>=', $today)
            ->latest('id')
            ->first();
        $new = $promo === null;

        $promo ??= Promo::create([
            'brand_id' => $outlet->brand_id,
            'pos_outlet_id' => $outlet->id,
            'type' => ($options['type'] ?? null) === Promo::TYPE_SALE ? Promo::TYPE_SALE : Promo::TYPE_CLAIM,
            'title' => 'Voucher Rp'.number_format($value, 0, ',', '.').' – '.$outlet->name,
            'highlight' => 'Rp'.self::shortRupiah($value),
            'highlight_caption' => 'potongan',
            'description' => "Kode voucher dari kasir {$outlet->name}. Tunjukkan kodenya di aplikasi saat membayar di {$outlet->name}.",
            'voucher_value' => $value,
            'min_spend' => 0,
            'price_points' => ($options['type'] ?? null) === Promo::TYPE_SALE ? ($options['price_points'] ?? null) : null,
            'per_member_limit' => 1,
            'starts_at' => $today,
            'ends_at' => $options['ends_at'] ?? now(config('loyalty.timezone'))->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $summary['promos'][$promo->id] = ['title' => $promo->title, 'new' => $new, 'added' => 0];

        return $promo;
    }

    /** 5000 → "5rb", 2500 → "2,5rb", 1000000 → "1jt". */
    private static function shortRupiah(int $value): string
    {
        if ($value >= 1_000_000 && $value % 100_000 === 0) {
            return str_replace('.', ',', (string) ($value / 1_000_000)).'jt';
        }
        if ($value >= 1000 && $value % 100 === 0) {
            return str_replace('.', ',', (string) ($value / 1000)).'rb';
        }

        return number_format($value, 0, ',', '.');
    }

    /** Ringkasan untuk admin, mis. "Promo baru "Voucher Rp5.000 – Ulon Ciledug": 11 kode." */
    public static function describe(array $summary): string
    {
        $parts = [];
        foreach ($summary['promos'] ?? [] as $promo) {
            $parts[] = ($promo['new'] ? 'Promo baru' : 'Ditambahkan ke promo')." \"{$promo['title']}\": {$promo['added']} kode.";
        }
        if (! $summary['created']) {
            $parts[] = 'Tidak ada kode baru.';
        }
        if ($summary['updated']) {
            $parts[] = "{$summary['updated']} kode diperbarui.";
        }
        if ($summary['marked_used']) {
            $parts[] = "{$summary['marked_used']} voucher member ditandai terpakai (sudah dipakai di POS).";
        }
        foreach ($summary['skipped'] as $reason => $codes) {
            $sample = implode(', ', array_slice($codes, 0, 5)).(count($codes) > 5 ? ', …' : '');
            $parts[] = 'Dilewati '.count($codes)." — {$reason}: {$sample}.";
        }

        return implode("\n", $parts);
    }

    /**
     * Baris data sebagai [isian => nilai]. Baris judul dicari otomatis (baris pertama yang memuat kolom kode),
     * jadi baris keterangan di atas tabel hasil ekspor POS tidak masalah.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader($this->csvOptions($path)),
            default => $this->fail('Format file tidak didukung. Pakai .xlsx atau .csv.'),
        };

        $reader->open($path);
        $map = null;
        $headers = [];
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if ($map === null) {
                        $candidate = $this->headerMap($values);
                        if (isset($candidate['code'])) {
                            $map = $candidate;
                        } elseif (array_filter($values, fn ($v) => $v !== null && $v !== '') !== [] && count($headers) < 3) {
                            $headers[] = implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values)));
                        }

                        continue;
                    }
                    $item = [];
                    foreach ($map as $field => $index) {
                        $item[$field] = $values[$index] ?? null;
                    }
                    $rows[] = $item;
                }
                break; // hanya sheet pertama
            }
        } finally {
            $reader->close();
        }

        if ($map === null) {
            $this->fail('Kolom kode voucher tidak ditemukan. Beri judul kolom "Kode" (atau "Code"/"Voucher Code").'.($headers ? ' Baris teratas file: '.implode(' | ', $headers) : ''));
        }

        return $rows;
    }

    /** @return array<string, int> isian => indeks kolom */
    private function headerMap(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $name = preg_replace('/[^a-z]/', '', strtolower((string) $value));
            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /** Excel Indonesia sering menyimpan CSV dengan titik koma. */
    private function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $first = (string) fgets($handle = fopen($path, 'r'));
        fclose($handle);
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        return $options;
    }

    /** "0/1" → [0, 1]; "1" → [1, 1]; kosong → [0, 1]. */
    private function usage(mixed $value): array
    {
        $text = trim((string) $value);
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            return [(int) $m[1], max(1, (int) $m[2])];
        }

        return [ctype_digit($text) ? min(1, (int) $text) : 0, 1];
    }

    /** "IDR 5.000" / "Rp5.000" / 5000 → 5000; "100%" → 'percent'; kosong/tak terbaca → null (ikut nilai promo). */
    private function discount(mixed $value): int|string|null
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) round($value) : null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, '%')) {
            return 'percent';
        }
        // Buang sen di belakang ("5.000,00" / "5,000.00"), lalu sisakan angka.
        $digits = preg_replace('/\D/', '', preg_replace('/[.,]\d{1,2}$/', '', $text));

        return $digits !== '' && (int) $digits > 0 ? (int) $digits : null;
    }

    private function title(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $tz = config('loyalty.timezone');
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format('Y-m-d'), $tz);
        }
        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }
        // Sel tanggal tanpa format tanggal terbaca sebagai nomor seri Excel (hari sejak 30-12-1899), mis. 46234 = 2026-07-31.
        if (is_numeric($text) && (float) $text >= 20000 && (float) $text <= 80000) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $tz)->addDays((int) $text);
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd/m/y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text, $tz);
            } catch (Throwable) {
                continue;
            }
            if ($date && $date->format($format) === $text) {
                return $date->startOfDay();
            }
        }

        return CarbonImmutable::parse($text, $tz)->startOfDay(); // mis. "Jul 31, 2026"; gagal = pengecualian
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
