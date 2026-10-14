<?php

declare(strict_types=1);

namespace App\Image;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;

// Picks the best image library the host offers: libvips streams, Imagick keeps pixels outside PHP,
// GD is everywhere. IMAGE_PROCESSOR=vips|imagick|gd forces one; "auto" takes the first available.
final class ImageProcessorFactory
{
    public function __construct(
        #[Autowire('%env(IMAGE_PROCESSOR)%')]
        private readonly string $preference,
    ) {
    }

    /**
     * @return array<string, bool> library => usable on this host, best first
     */
    public static function available(): array
    {
        return [
            // proc_open is often disabled on shared hosts; function_exists() reports disabled functions as missing.
            'vips' => \function_exists('proc_open') && null !== (new ExecutableFinder())->find('vips'),
            'imagick' => \extension_loaded('imagick') && [] !== \Imagick::queryFormats('WEBP'),
            'gd' => \function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false),
        ];
    }

    public function create(): ImageProcessor
    {
        $available = array_keys(array_filter(self::available()));
        $name = 'auto' === $this->preference ? ($available[0] ?? null) : $this->preference;
        if (null === $name || !\in_array($name, $available, true)) {
            throw new \LogicException(sprintf('No usable image library (IMAGE_PROCESSOR=%s). Install libvips, or the Imagick or GD extension with WebP support.', $this->preference));
        }

        return match ($name) {
            'vips' => new VipsCliImageProcessor(),
            'imagick' => new ImagickImageProcessor(),
            default => new GdImageProcessor(),
        };
    }
}
