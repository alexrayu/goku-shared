<?php

declare(strict_types=1);

namespace App\Install;

use App\Image\ImageProcessorFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// What the host must offer, checked before the installer asks for anything.
final class Requirements
{
    public function __construct(
        #[Autowire('%env(resolve:STORAGE_PATH)%')]
        private readonly string $storagePath,
    ) {
    }

    /**
     * @return array<string, string> unmet requirement => what to do about it
     */
    public function problems(): array
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0o750, true);
        }

        return array_filter([
            'PDO MySQL' => \extension_loaded('pdo_mysql') ? null : 'Enable the pdo_mysql PHP extension.',
            'Zip' => class_exists(\ZipArchive::class) ? null : 'Enable the zip PHP extension: chapters are uploaded as ZIP or CBZ archives.',
            'Fileinfo' => \extension_loaded('fileinfo') ? null : 'Enable the fileinfo PHP extension: uploads are checked by content.',
            'Image library' => \in_array(true, ImageProcessorFactory::available(), true) ? null : 'Enable the Imagick or GD PHP extension with WebP support.',
            'Storage' => is_dir($this->storagePath) && is_writable($this->storagePath) ? null : sprintf('Make %s writable by PHP.', $this->storagePath),
        ]);
    }
}
