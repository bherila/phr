<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * public/ohif/ is uploaded to the server out of band and is not in git, so the
 * bundle is normally absent here. These tests stand a throwaway index.html up
 * and tear it down again, and restore a real bundle's entrypoint if one happens
 * to be present locally.
 */
class OhifViewerRouteTest extends TestCase
{
    private string $indexPath;

    private bool $createdOhifDirectory = false;

    private ?string $originalIndexHtml = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPath = public_path('ohif/index.html');
        $ohifDirectory = dirname($this->indexPath);

        if (is_file($this->indexPath)) {
            $this->originalIndexHtml = (string) file_get_contents($this->indexPath);
        } else {
            $this->createdOhifDirectory = ! is_dir($ohifDirectory);
            File::ensureDirectoryExists($ohifDirectory);
        }

        File::put($this->indexPath, '<!doctype html><title>OHIF test entry</title>');
    }

    protected function tearDown(): void
    {
        if ($this->originalIndexHtml !== null) {
            File::put($this->indexPath, $this->originalIndexHtml);
        } else {
            File::delete($this->indexPath);
        }

        if ($this->createdOhifDirectory) {
            File::deleteDirectory(dirname($this->indexPath));
        }

        parent::tearDown();
    }

    public function test_viewer_index_serves_the_static_viewer_entrypoint(): void
    {
        $response = $this->actingAs($this->createUser())->get('/ohif');

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(realpath($this->indexPath), $response->baseResponse->getFile()->getRealPath());
    }

    public function test_client_routed_viewer_path_serves_the_static_viewer_entrypoint(): void
    {
        $response = $this->actingAs($this->createUser())
            ->get('/ohif/viewer/dicomjson?url=%2Fapi%2Fphr%2Fpatients%2F2%2Fdicom%2Fstudies%2F1%2Fviewer-json');

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(realpath($this->indexPath), $response->baseResponse->getFile()->getRealPath());
    }

    public function test_missing_ohif_assets_do_not_fall_back_to_entrypoint(): void
    {
        $this->actingAs($this->createUser())
            ->get('/ohif/assets/missing.js')
            ->assertNotFound();
    }

    public function test_viewer_requires_authentication(): void
    {
        $this->get('/ohif')->assertRedirect('/login');
        $this->get('/ohif/viewer/dicomjson')->assertRedirect('/login');
    }

    public function test_viewer_404s_when_the_static_bundle_is_absent(): void
    {
        File::delete($this->indexPath);

        $this->actingAs($this->createUser())
            ->get('/ohif/viewer/dicomjson')
            ->assertNotFound();
    }
}
