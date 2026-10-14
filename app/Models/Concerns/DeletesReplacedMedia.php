<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus file lama di disk "media" saat gambar diganti atau record dihapus —
 * berlaku sama untuk upload dari panel admin web maupun dari API mobile.
 * Foto stok bersama (stock/) dan URL eksternal tidak pernah dihapus.
 */
trait DeletesReplacedMedia
{
    /**
     * @return list<string> kolom yang menyimpan path di disk "media"
     */
    abstract protected static function mediaColumns(): array;

    protected static function bootDeletesReplacedMedia(): void
    {
        static::updated(function (Model $model) {
            foreach (static::mediaColumns() as $column) {
                if ($model->wasChanged($column)) {
                    static::deleteMediaFile($model->getOriginal($column));
                }
            }
        });

        static::deleted(function (Model $model) {
            foreach (static::mediaColumns() as $column) {
                static::deleteMediaFile($model->getAttribute($column));
            }
        });
    }

    protected static function deleteMediaFile(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'stock/') || preg_match('#^https?://#', $path)) {
            return;
        }

        Storage::disk('media')->delete($path);
    }
}
