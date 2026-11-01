<?php

namespace App\Services\PHR\Ndc;

use Illuminate\Support\Carbon;

/**
 * One package that an NDC could mean, with its drug fields already normalized
 * to appropriate casing. The raw answer is kept so a fill can store it untouched
 * in its snapshot: the openFDA product listing, or, for a package openFDA no
 * longer lists, the RxNav responses (source `rxnav`).
 */
final readonly class NdcCandidate
{
    public const string SOURCE_OPENFDA = 'openfda';

    public const string SOURCE_RXNAV = RxNavListing::SOURCE;

    /**
     * @param  array<string, mixed>  $drug  normalized drug fields
     * @param  array<string, mixed>  $snapshot  the raw openFDA product listing, or the raw RxNav responses
     * @param  string  $source  which directory answered: openfda or rxnav
     * @param  bool  $discontinued  the product is off the market (only RxNav can say so)
     */
    public function __construct(
        public string $ndc10,
        public string $ndc11,
        public array $drug,
        public array $snapshot,
        public string $fetchedAt,
        public string $source = self::SOURCE_OPENFDA,
        public bool $discontinued = false,
    ) {}

    /**
     * Builds a candidate from RxNav's history of an NDC openFDA does not list. Names, strengths, form
     * and route come from the NDC's RxCUI; labeler and package from its SPL properties. The same
     * casing and unit rules as the openFDA path apply.
     *
     * @param  array<string, mixed>  $bundle  RxNavClient::findNdc's entry
     */
    public static function fromRxNav(string $ndc10, array $bundle): self
    {
        $ingredients = RxNavListing::ingredients($bundle);
        [$form, $route] = RxNavListing::doseFormAndRoute($bundle);
        $properties = RxNavListing::properties($bundle);
        $snapshot = ['source' => RxNavListing::SOURCE, ...$bundle];

        return new self(
            ndc10: $ndc10,
            ndc11: NdcNumber::toNdc11($ndc10),
            drug: [
                'substance_names' => array_values(array_filter(array_map(
                    static fn (array $ingredient): ?string => NdcCasing::lowercase($ingredient['name']),
                    $ingredients,
                ))),
                'strengths' => array_values(array_filter(array_map(
                    static fn (array $ingredient): ?string => NdcCasing::measureText($ingredient['strength']),
                    $ingredients,
                ))),
                'brand_name' => NdcCasing::brand(RxNavListing::brandName($bundle)),
                'dosage_form' => NdcCasing::lowercase($form),
                'route' => NdcCasing::lowercase($route),
                'labeler_name' => NdcCasing::labeler(self::string($properties['values']['LABELER'] ?? null)),
                'package_description' => NdcCasing::measureText(RxNavListing::packageDescription($bundle, $ndc10)),
                'rxcui' => self::string($bundle['rxcui'] ?? null),
            ],
            snapshot: $snapshot,
            fetchedAt: is_string($bundle['fetched_at'] ?? null) ? $bundle['fetched_at'] : Carbon::now()->toIso8601String(),
            source: self::SOURCE_RXNAV,
            discontinued: RxNavListing::isDiscontinued($snapshot),
        );
    }

    /**
     * Builds a candidate from a raw openFDA product and one of its packages.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $package
     */
    public static function fromProduct(array $product, array $package, string $fetchedAt): self
    {
        $ingredients = array_values(array_filter((array) ($product['active_ingredients'] ?? []), is_array(...)));
        $routes = array_values(array_filter(array_map(
            static fn (mixed $route): ?string => is_string($route) ? NdcCasing::lowercase($route) : null,
            (array) ($product['route'] ?? []),
        )));
        $rxcuis = (array) ($product['openfda']['rxcui'] ?? []);
        $ndc10 = (string) ($package['package_ndc'] ?? '');

        return new self(
            ndc10: $ndc10,
            ndc11: NdcNumber::toNdc11($ndc10),
            drug: [
                'substance_names' => array_values(array_filter(array_map(
                    static fn (array $ingredient): ?string => NdcCasing::lowercase(self::string($ingredient['name'] ?? null)),
                    $ingredients,
                ))),
                'strengths' => array_values(array_filter(array_map(
                    static fn (array $ingredient): ?string => NdcCasing::measureText(self::string($ingredient['strength'] ?? null)),
                    $ingredients,
                ))),
                'brand_name' => NdcCasing::brand(self::string($product['brand_name'] ?? null)),
                'dosage_form' => NdcCasing::lowercase(self::string($product['dosage_form'] ?? null)),
                'route' => $routes === [] ? null : implode(', ', $routes),
                'labeler_name' => NdcCasing::labeler(self::string($product['labeler_name'] ?? null)),
                'package_description' => NdcCasing::measureText(self::string($package['description'] ?? null)),
                'rxcui' => isset($rxcuis[0]) && is_scalar($rxcuis[0]) ? (string) $rxcuis[0] : null,
            ],
            snapshot: $product,
            fetchedAt: $fetchedAt,
        );
    }

    /** The product-level code (labeler and product segments), as openFDA or RxNav lists it. */
    public function productNdc(): ?string
    {
        if ($this->source === self::SOURCE_RXNAV) {
            return RxNavListing::productNdc($this->snapshot);
        }
        $value = $this->snapshot['product_ndc'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The columns a fill takes from this candidate.
     *
     * @return array<string, mixed>
     */
    public function fillAttributes(): array
    {
        return [
            'ndc_10' => $this->ndc10,
            'ndc_11' => $this->ndc11,
            'ndc_source' => $this->source,
            'substance_names' => $this->drug['substance_names'],
            'strengths' => $this->drug['strengths'],
            'brand_name' => $this->drug['brand_name'],
            'dosage_form' => $this->drug['dosage_form'],
            'route' => $this->drug['route'],
            'labeler_name' => $this->drug['labeler_name'],
            'package_description' => $this->drug['package_description'],
            'rxcui' => $this->drug['rxcui'],
            'fda_snapshot' => $this->snapshot,
            'fda_fetched_at' => $this->fetchedAt,
        ];
    }

    /** The generic name openFDA lists, for matching only; it is not a fill column. */
    public function genericName(): ?string
    {
        $value = $this->snapshot['generic_name'] ?? null;

        return is_string($value) && trim($value) !== '' ? NdcCasing::lowercase($value) : null;
    }

    /**
     * The contract's NdcCandidate.
     *
     * @param  list<string>  $matchReasons
     * @return array<string, mixed>
     */
    public function toArray(array $matchReasons = []): array
    {
        return [
            'ndc_10' => $this->ndc10,
            'ndc_11' => $this->ndc11,
            'product_ndc' => $this->productNdc(),
            'drug' => $this->drug,
            'ndc_source' => $this->source,
            'discontinued' => $this->discontinued,
            'match_reasons' => $matchReasons,
            'fetched_at' => $this->fetchedAt,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
