<?php

namespace App\Email;

use App\Models\Location;
use App\Models\Tenant;

/**
 * Everything the email layout needs to draw one brand: colours, the logo
 * or wordmark, the header and the footer. YardRunner's theme is for emails
 * to the tenant's own users; a tenant's theme is for emails to its
 * customers and suppliers.
 */
final readonly class EmailTheme
{
    /**
     * YardRunner's light concrete and safety orange.
     *
     * @var array<string, string>
     */
    public const YARDRUNNER = [
        'primary' => '#F26A1B',
        'primary_hover' => '#D9560B',
        'primary_strong' => '#B4470A',
        'primary_subtle' => '#F7F6F3',
        'primary_border' => '#D9D6D0',
        'on_primary' => '#1F1F1C',
        'ink' => '#1F1F1C',
        'muted' => '#6B6862',
        'line' => '#D9D6D0',
        'background' => '#EEECE8',
        'surface' => '#FFFFFF',
        'danger' => '#B42318',
    ];

    /**
     * @param  array<string, string>  $colours  every ColourSystem token
     * @param  list<string>  $contactLines
     * @param  list<string>  $footerLines  shown under the card
     */
    public function __construct(
        public bool $isYardRunner,
        public string $name,
        public ?string $logoUrl,
        public array $colours,
        public string $buttonFill,
        public string $buttonText,
        public string $headerStyle,
        public ?string $headerNote,
        public ?string $contactName,
        public array $contactLines,
        public ?string $replyLine,
        public array $footerLines,
        public bool $sentWithYardRunner,
    ) {}

    /**
     * For emails to the tenant's own users. Names the business they belong
     * to, when there is one, in the header.
     */
    public static function yardRunner(?string $business = null, ?string $why = null): self
    {
        $footer = array_values(array_filter([
            $why,
            'YardRunner · '.config('yardline.email.postal_address').' · yardrunner.ca',
        ]));

        return new self(
            isYardRunner: true,
            name: 'YardRunner',
            logoUrl: null,
            colours: self::YARDRUNNER,
            buttonFill: self::YARDRUNNER['primary'],
            buttonText: self::YARDRUNNER['on_primary'],
            headerStyle: 'light',
            headerNote: $business,
            contactName: null,
            contactLines: [],
            replyLine: null,
            footerLines: $footer,
            sentWithYardRunner: false,
        );
    }

    /**
     * For emails to the tenant's customers. The yard the email is about
     * (an order's or rental's) gives the contact block; otherwise the
     * default yard does.
     */
    public static function tenant(Tenant $tenant, ?Location $yard = null): self
    {
        $brand = BrandSettings::for($tenant);
        $colours = $brand->tokens();
        $button = ColourSystem::button($colours);

        $yard ??= Location::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        $address = $yard === null ? null : implode(', ', array_filter([
            $yard->address_line1,
            $yard->city,
            trim(($yard->region ?? '').' '.($yard->postal_code ?? '')),
        ]));

        $contact = array_values(array_filter([
            $address !== '' ? $address : null,
            $brand->taxNumbers !== '' ? "{$tenant->name} {$brand->taxNumbers}" : null,
        ]));

        return new self(
            isYardRunner: false,
            name: $tenant->name,
            logoUrl: $brand->logoPath !== null ? route('brand.logo', ['tenant' => $tenant->slug, 'v' => $brand->logoVersion]) : null,
            colours: $colours,
            buttonFill: $button['fill'],
            buttonText: $button['text'],
            headerStyle: $brand->headerStyle,
            headerNote: $yard?->name,
            contactName: $tenant->name.($yard !== null ? " · {$yard->name}" : ''),
            contactLines: $contact,
            replyLine: "Reply to this email to reach {$tenant->name}.",
            footerLines: $brand->footerNote !== '' ? [$brand->footerNote] : [],
            sentWithYardRunner: $brand->sentWithYardRunner,
        );
    }

    /**
     * Text colour that reads on the header: on the brand colour for a
     * Brand header, otherwise ink.
     */
    public function headerText(): string
    {
        return $this->headerStyle === 'brand' ? $this->colours['on_primary'] : $this->colours['ink'];
    }

    public function headerBackground(): string
    {
        return $this->headerStyle === 'brand' ? $this->colours['primary'] : $this->colours['surface'];
    }
}
