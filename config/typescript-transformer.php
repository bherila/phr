<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

return [
    'auto_discover_types' => [
        app_path('Services/PHR/HealthLog/Data'),
    ],

    'collectors' => [
        'Spatie\\TypeScriptTransformer\\Collectors\\DefaultCollector',
    ],

    'transformers' => [
        'Spatie\\TypeScriptTransformer\\Transformers\\EnumTransformer',
        'Spatie\\LaravelTypeScriptTransformer\\Transformers\\DtoTransformer',
    ],

    'default_type_replacements' => [
        DateTime::class => 'string',
        DateTimeImmutable::class => 'string',
        CarbonInterface::class => 'string',
        CarbonImmutable::class => 'string',
        Carbon\Carbon::class => 'string',
    ],

    'output_file' => resource_path('js/types/generated/phr.ts'),

    'writer' => 'Spatie\\TypeScriptTransformer\\Writers\\ModuleWriter',

    'formatter' => null,

    'transform_to_native_enums' => false,

    'transform_null_to_optional' => false,
];
