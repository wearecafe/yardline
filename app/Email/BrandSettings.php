<?php

namespace App\Email;

use App\Models\Tenant;

/**
 * How a yard looks to its customers, in tenants.settings['branding'].
 *
 * The owner or an admin sets the primary colour, logo, header style and
 * footer under Settings → Branding. Two switches belong to YardRunner staff
 * and are only changed in the back office: the full colour system (off by
 * default, switched on for tenants who ask for it) and the "Sent with
 * YardRunner" footer line (on by default).
 */
final readonly class BrandSettings
{
    public const HEADER_STYLES = ['light' => 'Light: logo on white with a colour bar', 'brand' => 'Brand: logo on your primary colour'];

    /**
     * @param  array<string, string>  $overrides  token => #RRGGBB, used only while the colour system is on
     */
    public function __construct(
        public string $primary = ColourSystem::DEFAULT_PRIMARY,
        public array $overrides = [],
        public string $headerStyle = 'light',
        public ?string $logoPath = null,
        public ?string $logoVersion = null,
        public string $footerNote = '',
        public string $taxNumbers = '',
        public bool $colourSystem = false,
        public bool $sentWithYardRunner = true,
        public int $generatorVersion = ColourSystem::VERSION,
    ) {}

    public static function for(?Tenant $tenant): self
    {
        $v = $tenant?->settings['branding'] ?? null;

        if (! is_array($v)) {
            return new self;
        }

        $overrides = [];

        foreach ((array) ($v['overrides'] ?? []) as $token => $value) {
            if (isset(ColourSystem::TOKENS[$token]) && is_string($value) && ($hex = ColourSystem::normalize($value)) !== null) {
                $overrides[(string) $token] = $hex;
            }
        }

        $header = (string) ($v['header_style'] ?? 'light');

        return new self(
            primary: ColourSystem::normalize(is_string($v['primary'] ?? null) ? $v['primary'] : null) ?? ColourSystem::DEFAULT_PRIMARY,
            overrides: $overrides,
            headerStyle: isset(self::HEADER_STYLES[$header]) ? $header : 'light',
            logoPath: is_string($v['logo_path'] ?? null) && $v['logo_path'] !== '' ? $v['logo_path'] : null,
            logoVersion: is_string($v['logo_version'] ?? null) ? $v['logo_version'] : null,
            footerNote: (string) ($v['footer_note'] ?? ''),
            taxNumbers: (string) ($v['tax_numbers'] ?? ''),
            colourSystem: (bool) ($v['colour_system'] ?? false),
            sentWithYardRunner: (bool) ($v['sent_with_yardrunner'] ?? true),
            generatorVersion: (int) ($v['generator_version'] ?? ColourSystem::VERSION),
        );
    }

    /**
     * The colours customer emails use. With the colour system off, the
     * tenant's earlier changes are kept in settings but ignored.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return ColourSystem::resolve($this->primary, $this->colourSystem ? $this->overrides : []);
    }

    /**
     * @param  array<string, mixed>  $changes  snake_case keys, as stored
     */
    public static function save(Tenant $tenant, array $changes): void
    {
        $settings = $tenant->settings ?? [];
        $settings['branding'] = [...self::for($tenant)->toArray(), ...$changes];
        $tenant->settings = $settings;
        $tenant->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary' => $this->primary,
            'overrides' => $this->overrides,
            'header_style' => $this->headerStyle,
            'logo_path' => $this->logoPath,
            'logo_version' => $this->logoVersion,
            'footer_note' => $this->footerNote,
            'tax_numbers' => $this->taxNumbers,
            'colour_system' => $this->colourSystem,
            'sent_with_yardrunner' => $this->sentWithYardRunner,
            'generator_version' => $this->generatorVersion,
        ];
    }
}
