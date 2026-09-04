<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spezitest\Admin\Testing\TestEntryValidator;
use Spezitest\Admin\Validation\ValidationException;

final class TestEntryValidatorTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function fullBody(): array
    {
        return [
            'manu_optik' => '9', 'manu_sueffigkeit' => '10', 'manu_geschmack' => '10',
            'fabi_optik' => '9', 'fabi_sueffigkeit' => '10', 'fabi_geschmack' => '10',
            'schorsch_optik' => '8', 'schorsch_sueffigkeit' => '8', 'schorsch_geschmack' => '8',
        ];
    }

    public function testAcceptsAFullSetAndReportsItComplete(): void
    {
        $input = (new TestEntryValidator())->validate($this->fullBody(), true);

        self::assertTrue($input->isComplete());
        self::assertSame(['optik' => 9, 'sueffigkeit' => 10, 'geschmack' => 10], $input->ratings['manu']);
        self::assertSame(['optik' => 8, 'sueffigkeit' => 8, 'geschmack' => 8], $input->ratings['schorsch']);
    }

    public function testDraftAllowsAPartialButPerTesterCompleteSet(): void
    {
        $body = $this->fullBody();
        unset($body['fabi_optik'], $body['fabi_sueffigkeit'], $body['fabi_geschmack']);

        $input = (new TestEntryValidator())->validate($body, false);

        self::assertFalse($input->isComplete());
        self::assertArrayNotHasKey('fabi', $input->ratings);
        self::assertArrayHasKey('manu', $input->ratings);
    }

    public function testRejectsAPartiallyFilledTester(): void
    {
        $body = $this->fullBody();
        unset($body['fabi_geschmack']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Fabi/');
        (new TestEntryValidator())->validate($body, false);
    }

    public function testAcceptsTheZeroAndTenBoundariesUsedInLegacyData(): void
    {
        $body = $this->fullBody();
        $body['manu_optik'] = '0';
        $body['manu_geschmack'] = '10';

        $input = (new TestEntryValidator())->validate($body, true);

        self::assertSame(0, $input->ratings['manu']['optik']);
        self::assertSame(10, $input->ratings['manu']['geschmack']);
    }

    #[DataProvider('outOfRangeGrades')]
    public function testRejectsGradesOutsideZeroToTen(string $bad): void
    {
        $body = $this->fullBody();
        $body['manu_optik'] = $bad;

        $this->expectException(ValidationException::class);
        (new TestEntryValidator())->validate($body, false);
    }

    /** @return iterable<string, array{string}> */
    public static function outOfRangeGrades(): iterable
    {
        yield 'eleven' => ['11'];
        yield 'negative' => ['-1'];
        yield 'hundred' => ['100'];
    }

    public function testRejectsFractionalGrades(): void
    {
        $body = $this->fullBody();
        $body['fabi_sueffigkeit'] = '5.5';

        $this->expectException(ValidationException::class);
        (new TestEntryValidator())->validate($body, false);
    }

    public function testCompletingRequiresAllNineGrades(): void
    {
        $body = $this->fullBody();
        unset($body['schorsch_optik'], $body['schorsch_sueffigkeit'], $body['schorsch_geschmack']);

        $this->expectException(ValidationException::class);
        (new TestEntryValidator())->validate($body, true);
    }

    public function testParsesGermanAndPlainPriceNotation(): void
    {
        $withGerman = (new TestEntryValidator())->validate($this->fullBody() + ['price' => '0,89 €'], true);
        self::assertSame('0.8900', $withGerman->priceAmount);

        $withThousands = (new TestEntryValidator())->validate($this->fullBody() + ['price' => '1.234,50'], true);
        self::assertSame('1234.5000', $withThousands->priceAmount);

        $withPlain = (new TestEntryValidator())->validate($this->fullBody() + ['price' => '1.5'], true);
        self::assertSame('1.5000', $withPlain->priceAmount);
    }

    public function testRejectsNegativePrice(): void
    {
        $this->expectException(ValidationException::class);
        (new TestEntryValidator())->validate($this->fullBody() + ['price' => '-1'], true);
    }
}
