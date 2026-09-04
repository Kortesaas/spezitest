<?php

declare(strict_types=1);

namespace Spezitest\Admin\Image;

use finfo;
use Psr\Http\Message\UploadedFileInterface;

final readonly class UploadedImageValidator
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private int $maximumBytes)
    {
    }

    public function validate(?UploadedFileInterface $upload): ?ValidatedImage
    {
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ImageValidationException('Das Bild konnte nicht sicher hochgeladen werden.');
        }

        $reportedSize = $upload->getSize();

        if ($reportedSize !== null && $reportedSize > $this->maximumBytes) {
            throw new ImageValidationException('Das Bild überschreitet die erlaubte Dateigröße.');
        }

        $stream = $upload->getStream();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $bytes = $stream->getContents();
        $length = strlen($bytes);

        if ($length < 1 || $length > $this->maximumBytes) {
            throw new ImageValidationException('Das Bild ist leer oder überschreitet die erlaubte Dateigröße.');
        }

        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $dimensions = getimagesizefromstring($bytes);

        if (!is_string($detectedMime) || !isset(self::MIME_EXTENSIONS[$detectedMime]) || $dimensions === false) {
            throw new ImageValidationException('Erlaubt sind ausschließlich gültige JPEG-, PNG- oder WebP-Bilder.');
        }

        $imageMime = $dimensions['mime'];

        if (!hash_equals($detectedMime, $imageMime)) {
            throw new ImageValidationException('Der tatsächliche Bildtyp ist ungültig.');
        }

        $width = $dimensions[0];
        $height = $dimensions[1];

        if ($width < 1 || $height < 1) {
            throw new ImageValidationException('Die Bildabmessungen sind ungültig.');
        }

        return new ValidatedImage(
            $bytes,
            $detectedMime,
            self::MIME_EXTENSIONS[$detectedMime],
            $width,
            $height,
        );
    }
}
