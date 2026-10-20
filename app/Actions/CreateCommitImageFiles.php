<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;

final readonly class CreateCommitImageFiles
{
    private const int MAX_STORED_DIMENSION = 1600;

    private const int QUALITY = 60;

    private const array ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Every image is decoded, scaled down, and re-encoded as WebP: metadata such as GPS location is dropped, and anything smuggled alongside the pixels is not kept.
     * All of them are encoded before any is stored, so a bad one leaves nothing behind, and a stored one is erased again if the transaction saving it rolls back.
     *
     * @param  list<UploadedFile>  $images
     * @param  array<int, string|null>  $descriptions
     * @return list<array{path: string, width: int, height: int, description: string|null, position: int}>
     */
    public function handle(array $images, array $descriptions = [], string $directory = 'commit-images'): array
    {
        $optimized = array_map($this->optimize(...), array_slice($images, 0, CreateCommitImages::MAX_IMAGES));

        $files = [];

        foreach ($optimized as $position => $image) {
            $path = $directory.'/'.Str::uuid7()->toString().'.webp';
            $stored = Storage::disk()->put($path, $image['bytes']);
            assert($stored);

            DB::afterRollBack(fn (): bool => Storage::disk()->delete($path));

            $files[] = [
                'path' => $path,
                'width' => $image['width'],
                'height' => $image['height'],
                'description' => $descriptions[$position] ?? null,
                'position' => $position,
            ];
        }

        return $files;
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function optimize(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false
            || ! in_array($info[2], self::ALLOWED_TYPES, true)
            || $info[0] > CreateCommitImages::MAX_SOURCE_DIMENSION
            || $info[1] > CreateCommitImages::MAX_SOURCE_DIMENSION) {
            throw $this->invalid();
        }

        try {
            $image = Image::newFromFile($file->getRealPath(), ['fail_on' => 'warning'])->autorot();

            if ($image->getType('icc-profile-data') !== 0) {
                $image = $image->icc_transform('srgb', ['embedded' => true]);
            }

            $image = $image->thumbnail_image(self::MAX_STORED_DIMENSION, [
                'height' => self::MAX_STORED_DIMENSION,
                'size' => 'down',
            ]);

            return [
                'bytes' => $image->writeToBuffer('.webp', ['Q' => self::QUALITY, 'strip' => true]),
                'width' => $image->width,
                'height' => $image->height,
            ];
        } catch (VipsException) {
            throw $this->invalid();
        }
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'images' => 'Images must be JPEG, PNG, or WebP files of at most '.CreateCommitImages::MAX_SOURCE_DIMENSION.' pixels per side.',
        ]);
    }
}
