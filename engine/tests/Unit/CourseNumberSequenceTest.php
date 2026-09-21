<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CourseNumberSequence;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Die Nummernfolge, die über einen Spaltenbereich fortgeschrieben wird.
 *
 * Der wichtigste Punkt sind die führenden Nullen: `courses.number` ist ein Textfeld, und `03`
 * und `3` sind zwei verschiedene Kurse. Geraten wird nichts — die Breite folgt der Eingabe.
 */
final class CourseNumberSequenceTest extends TestCase
{
    public function test_parse_expands_a_range(): void
    {
        $this->assertSame(['1', '2', '3', '4'], CourseNumberSequence::parse('1-4'));
    }

    public function test_parse_keeps_leading_zeros(): void
    {
        $this->assertSame(['01', '02', '03', '04', '05', '06', '07', '08'], CourseNumberSequence::parse('01-08'));
    }

    public function test_parse_without_leading_zeros_stays_unpadded(): void
    {
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], CourseNumberSequence::parse('1-8'));
    }

    public function test_parse_pads_to_the_wider_end(): void
    {
        // Ein Ende trägt die Absicht (die Null), das andere die Länge.
        $this->assertSame(['08', '09', '10', '11', '12'], CourseNumberSequence::parse('08-12'));
    }

    public function test_parse_handles_a_higher_range(): void
    {
        $this->assertSame(['31', '32', '33', '34', '35'], CourseNumberSequence::parse('31-35'));
    }

    public function test_parse_accepts_a_comma_list_with_mixed_forms(): void
    {
        $this->assertSame(
            ['1', '2', '3', '4', '7', '9', '10', '11', '12'],
            CourseNumberSequence::parse('1-4, 7, 9-12'),
        );
    }

    public function test_parse_counts_down(): void
    {
        $this->assertSame(['8', '7', '6', '5'], CourseNumberSequence::parse('8-5'));
    }

    public function test_parse_accepts_a_single_non_numeric_token(): void
    {
        // `courses.number` ist ein Textfeld; eine Bezeichnung wie „A1" muss sich eintippen lassen.
        $this->assertSame(['A1'], CourseNumberSequence::parse('A1'));
    }

    public function test_parse_ignores_stray_separators(): void
    {
        $this->assertSame(['1', '2'], CourseNumberSequence::parse(' 1 , , 2 '));
    }

    public function test_parse_rejects_an_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CourseNumberSequence::parse('   ');
    }

    public function test_parse_rejects_an_open_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CourseNumberSequence::parse('1-');
    }

    public function test_parse_rejects_a_non_numeric_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CourseNumberSequence::parse('A-C');
    }

    public function test_parse_rejects_a_number_longer_than_the_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CourseNumberSequence::parse('123456789');
    }

    public function test_parse_rejects_an_excessive_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CourseNumberSequence::parse('1-99999999');
    }
}
