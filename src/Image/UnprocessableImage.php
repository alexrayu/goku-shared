<?php

declare(strict_types=1);

namespace App\Image;

// The input is not an image the processor accepts, or too large for the host to process.
// Retrying does not help.
final class UnprocessableImage extends \RuntimeException
{
}
