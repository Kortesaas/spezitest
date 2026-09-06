<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Admin\Validation\DrinkInputValidator;
use Spezitest\Admin\Validation\ValidationException;

final class DrinkInputValidatorTest extends TestCase
{
    /** @return array<string, string> */
    private function baseBody(): array
    {
        return ['name' => 'Testspezi', 'lifecycle_status' => 'acquired'];
    }

    public function testPriceAndVolumeAreOptional(): void
    {
        $input = (new DrinkInputValidator())->validate($this->baseBody());

        self::assertNull($input->priceAmount);
        self::assertNull($input->priceVolumeMl);
    }

    public function testParsesGermanAndPlainPriceNotationAlongsideVolume(): void
    {
        $withGerman = (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '0,89 €', 'price_volume_ml' => '500'],
        );
        self::assertSame('0.8900', $withGerman->priceAmount);
        self::assertSame(500, $withGerman->priceVolumeMl);

        $withThousands = (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '1.234,50', 'price_volume_ml' => '1000'],
        );
        self::assertSame('1234.5000', $withThousands->priceAmount);
        self::assertSame(1000, $withThousands->priceVolumeMl);

        $withPlain = (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '1.5', 'price_volume_ml' => '330'],
        );
        self::assertSame('1.5000', $withPlain->priceAmount);
        self::assertSame(330, $withPlain->priceVolumeMl);
    }

    public function testRejectsPriceWithoutVolume(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate($this->baseBody() + ['price' => '0,89']);
    }

    public function testRejectsVolumeWithoutPrice(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate($this->baseBody() + ['price_volume_ml' => '500']);
    }

    public function testRejectsZeroOrNegativePrice(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '0', 'price_volume_ml' => '500'],
        );
    }

    public function testRejectsNonPositiveVolume(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '0,89', 'price_volume_ml' => '0'],
        );
    }

    public function testRejectsOutOfRangeVolume(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate(
            $this->baseBody() + ['price' => '0,89', 'price_volume_ml' => '99999'],
        );
    }

    public function testCreatingCapturesPriceVolumeAndOtherOptionalFieldsInline(): void
    {
        $input = (new DrinkInputValidator())->validate(
            $this->baseBody() + [
                'manufacturer' => 'Talbach',
                'origin_location' => 'Rosenheim',
                'origin_region' => 'Bayern',
                'notes' => 'Gefunden im Getränkemarkt.',
                'price' => '0,89',
                'price_volume_ml' => '500',
            ],
            true,
        );

        self::assertSame('Talbach', $input->manufacturer);
        self::assertSame('Rosenheim', $input->originLocation);
        self::assertSame('Bayern', $input->originRegion);
        self::assertSame('Gefunden im Getränkemarkt.', $input->notes);
        self::assertSame('0.8900', $input->priceAmount);
        self::assertSame(500, $input->priceVolumeMl);
    }

    public function testCreatingCannotSetTestedStatus(): void
    {
        $this->expectException(ValidationException::class);
        (new DrinkInputValidator())->validate(
            ['name' => 'Testspezi', 'lifecycle_status' => 'tested'],
            true,
        );
    }
}
