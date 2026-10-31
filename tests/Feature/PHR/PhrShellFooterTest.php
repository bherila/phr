<?php

namespace Tests\Feature\PHR;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PhrShellFooterTest extends TestCase
{
    public function test_phr_shell_renders_without_the_site_footer(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->createUser())->get('/phr/patients');

        $response->assertOk();
        $response->assertViewIs('phr.shell');
        $response->assertSee('id="PhrShell"', false);
        $response->assertDontSee('<footer', false);
        $response->assertDontSee('&copy; '.date('Y'), false);
    }

    public function test_other_pages_on_the_same_layout_keep_the_site_footer(): void
    {
        $this->withoutVite();

        $html = Blade::render("@extends('layouts.app')\n@section('content')<p>plain page</p>@endsection");

        $this->assertStringContainsString('plain page', $html);
        $this->assertStringContainsString('<footer', $html);
        $this->assertStringContainsString('&copy; '.date('Y'), $html);
    }
}
