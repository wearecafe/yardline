<?php

namespace Tests\Unit;

use App\Email\ColourSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColourSystemTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function primaries(): array
    {
        return [
            'navy' => ['#1F5C99'],
            'yellow' => ['#F2B705'],
            'orange' => ['#E07B00'],
            'teal' => ['#2C7A7B'],
            'charcoal' => ['#2B2B28'],
            'white' => ['#FFFFFF'],
            'black' => ['#000000'],
            'lime' => ['#7CFC00'],
        ];
    }

    #[DataProvider('primaries')]
    public function test_every_required_pair_is_readable_whatever_the_primary(string $primary): void
    {
        $tokens = ColourSystem::generate($primary);

        $this->assertSame(array_keys(ColourSystem::TOKENS), array_keys($tokens));
        $this->assertSame($primary, $tokens['primary'], 'the chosen colour is kept exactly');

        foreach (ColourSystem::checks($tokens) as $check) {
            if ($check['required']) {
                $this->assertTrue($check['passes'], "{$primary}: {$check['label']} is {$check['ratio']}:1");
            }
        }

        foreach ($tokens as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex);
        }
    }

    public function test_dark_text_goes_on_light_primaries_and_white_on_dark(): void
    {
        $this->assertSame('#FFFFFF', ColourSystem::generate('#1F5C99')['on_primary']);
        $this->assertNotSame('#FFFFFF', ColourSystem::generate('#F2B705')['on_primary']);
    }

    public function test_overrides_replace_only_known_tokens_with_valid_colours(): void
    {
        $tokens = ColourSystem::resolve('#1F5C99', [
            'primary_subtle' => 'eef4fb',
            'nonsense' => '#000000',
            'ink' => 'not a colour',
        ]);

        $this->assertSame('#EEF4FB', $tokens['primary_subtle']);
        $this->assertArrayNotHasKey('nonsense', $tokens);
        $this->assertSame(ColourSystem::generate('#1F5C99')['ink'], $tokens['ink']);
    }

    public function test_an_unreadable_override_fails_its_check(): void
    {
        $tokens = ColourSystem::resolve('#1F5C99', ['ink' => '#DDDDDD']);
        $body = collect(ColourSystem::checks($tokens))->firstWhere('key', 'body');

        $this->assertFalse($body['passes']);
    }

    public function test_bad_input_falls_back_to_the_default(): void
    {
        $this->assertSame(ColourSystem::DEFAULT_PRIMARY, ColourSystem::generate('blue')['primary']);
        $this->assertNull(ColourSystem::normalize('#12345'));
        $this->assertSame('#ABCDEF', ColourSystem::normalize(' abcdef '));
    }
}
