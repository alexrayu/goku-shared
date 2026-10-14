<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Image\ImageProcessor;
use App\Image\ImagickImageProcessor;

final class ImagickImageProcessorTest extends ImageProcessorTestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('imagick')) {
            self::markTestSkipped('Needs the Imagick extension.');
        }
        parent::setUp();
    }

    protected function processor(): ImageProcessor
    {
        return new ImagickImageProcessor();
    }
}
