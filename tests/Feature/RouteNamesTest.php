<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RouteNamesTest extends TestCase
{
    /** `php artisan route:cache` refuses two routes with one name, which would stop a deploy that uses it. */
    public function test_every_route_name_is_unique(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->countBy();

        $this->assertSame([], $names->filter(static fn (int $count): bool => $count > 1)->all());
    }
}
