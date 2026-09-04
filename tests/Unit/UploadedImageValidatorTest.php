<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;
use Spezitest\Admin\Image\ImageValidationException;
use Spezitest\Admin\Image\UploadedImageValidator;

final class UploadedImageValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function imageProvider(): iterable
    {
        yield 'PNG' => [
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'image/png',
            'png',
        ];
        yield 'JPEG' => [
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=',
            'image/jpeg',
            'jpg',
        ];
        yield 'WebP' => [
            'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==',
            'image/webp',
            'webp',
        ];
    }

    #[DataProvider('imageProvider')]
    public function testDetectsSupportedImageFromContent(
        string $encoded,
        string $expectedMime,
        string $expectedExtension,
    ): void {
        $bytes = base64_decode($encoded, true);
        self::assertIsString($bytes);
        $result = (new UploadedImageValidator(1024 * 1024))->validate(
            $this->upload($bytes, 'untrusted.php', 'application/x-php'),
        );

        self::assertNotNull($result);
        self::assertSame($expectedMime, $result->mimeType);
        self::assertSame($expectedExtension, $result->extension);
        self::assertSame(1, $result->width);
        self::assertSame(1, $result->height);
    }

    public function testRejectsExecutableContentEvenWithImageClaims(): void
    {
        $validator = new UploadedImageValidator(1024);

        $this->expectException(ImageValidationException::class);
        $validator->validate($this->upload('<?php echo "unsafe";', 'image.png', 'image/png'));
    }

    public function testRejectsReportedFileOverConfiguredLimitBeforeReading(): void
    {
        $validator = new UploadedImageValidator(10);

        $this->expectException(ImageValidationException::class);
        $validator->validate($this->upload(str_repeat('x', 11), 'large.png', 'image/png'));
    }

    private function upload(string $bytes, string $name, string $clientMime): UploadedFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        self::assertSame(strlen($bytes), fwrite($resource, $bytes));
        rewind($resource);

        return new UploadedFile(new Stream($resource), $name, $clientMime, strlen($bytes));
    }
}
