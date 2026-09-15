<?php

namespace Tests\Unit\Services;

use App\Services\GenAiFileHelper;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class GenAiFileHelperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_file_api_provider_uploads_and_deletes_the_temporary_file(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'synthetic document');
        rewind($stream);

        $client = Mockery::mock(GenAiClient::class);
        $client->shouldReceive('supportsFileApi')->once()->andReturn(true);
        $client->shouldReceive('uploadFile')->once()->with($stream, 'application/pdf', 'synthetic.pdf')->andReturn('files/synthetic');
        $client->shouldReceive('converseWithFileRef')->once()->with('files/synthetic', 'application/pdf', 'Extract.', null)->andReturn(['text' => 'done']);
        $client->shouldReceive('deleteFile')->once()->with('files/synthetic');

        $this->assertSame(
            ['text' => 'done'],
            GenAiFileHelper::send($client, $stream, 'application/pdf', 'synthetic.pdf', 'Extract.'),
        );
        fclose($stream);
    }

    public function test_provider_without_file_api_uses_the_mime_aware_inline_path(): void
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'synthetic document');
        rewind($stream);

        $client = Mockery::mock(GenAiClient::class);
        $client->shouldReceive('supportsFileApi')->once()->andReturn(false);
        $client->shouldNotReceive('uploadFile');
        $client->shouldReceive('converseWithInlineFile')
            ->once()
            ->with(base64_encode('synthetic document'), 'application/pdf', 'Extract.', '', null)
            ->andReturn(['text' => 'done']);

        $this->assertSame(
            ['text' => 'done'],
            GenAiFileHelper::send($client, $stream, 'application/pdf', 'synthetic.pdf', 'Extract.'),
        );
        fclose($stream);
    }

    public function test_size_limit_uses_the_inline_mime_specific_ceiling(): void
    {
        $inlineClient = Mockery::mock(GenAiClient::class);
        $inlineClient->shouldReceive('supportsFileApi')->twice()->andReturn(false);
        $inlineClient->shouldReceive('maxInlineFileBytes')->twice()->with('image/png')->andReturn(50);
        $this->assertTrue(GenAiFileHelper::withinSizeLimit($inlineClient, 50, 'image/png'));
        $this->assertFalse(GenAiFileHelper::withinSizeLimit($inlineClient, 51, 'image/png'));
    }

    public function test_size_limit_uses_the_uploaded_file_ceiling_when_available(): void
    {
        $uploadClient = Mockery::mock(GenAiClient::class);
        $uploadClient->shouldReceive('supportsFileApi')->twice()->andReturn(true);
        $uploadClient->shouldReceive('maxUploadedFileBytes')->twice()->andReturn(100);
        $this->assertTrue(GenAiFileHelper::withinSizeLimit($uploadClient, 100, 'application/pdf'));
        $this->assertFalse(GenAiFileHelper::withinSizeLimit($uploadClient, 101, 'application/pdf'));
    }
}
