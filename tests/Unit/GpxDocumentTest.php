<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Website\Map\GpxDocument;

final class GpxDocumentTest extends TestCase
{
    public function testRendersValidGpxWithNamedWaypoints(): void
    {
        $gpx = (new GpxDocument([
            ['latitude' => 48.63270, 'longitude' => 12.85590, 'name' => 'Adldorf · 2 Spezis', 'description' => 'Adldorfer Cola-Mix, Fitella Cola Mix'],
            ['latitude' => 52.4, 'longitude' => 9.73, 'name' => 'Herrenhäuser Cola-Mix', 'description' => null],
        ]))->render();

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $gpx);
        self::assertStringContainsString('<gpx version="1.1"', $gpx);
        self::assertStringContainsString('<wpt lat="48.63270" lon="12.85590">', $gpx);
        self::assertStringContainsString('<name>Adldorf · 2 Spezis</name>', $gpx);
        self::assertStringContainsString('<desc>Adldorfer Cola-Mix, Fitella Cola Mix</desc>', $gpx);
        self::assertStringContainsString('<name>Herrenhäuser Cola-Mix</name>', $gpx);
        self::assertStringEndsWith("</gpx>\n", $gpx);

        // Parses as XML.
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($gpx));
    }

    public function testEscapesNamesForXml(): void
    {
        $gpx = (new GpxDocument([
            ['latitude' => 1.0, 'longitude' => 2.0, 'name' => 'Fritz & <Söhne> "Spezi"', 'description' => null],
        ]))->render();

        self::assertStringContainsString('<name>Fritz &amp; &lt;Söhne&gt; &quot;Spezi&quot;</name>', $gpx);
        self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($gpx));
    }

    public function testFilenameStem(): void
    {
        self::assertSame('kloster-limo-cola-mix', GpxDocument::filename('Kloster Limo Cola Mix'));
        self::assertSame('adldorf-2-spezis', GpxDocument::filename('Adldorf · 2 Spezis'));
        self::assertSame('giessen', GpxDocument::filename('Gießen'));
        self::assertSame('spezi', GpxDocument::filename('···'));
    }
}
