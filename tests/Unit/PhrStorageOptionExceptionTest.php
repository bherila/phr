<?php

namespace Tests\Unit;

use App\Console\Commands\Phr\PhrStorageOptionException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The storage commands print this exception's message verbatim, so it must not be
 * constructible with arbitrary text: only its fixed-text named constructors may build it.
 */
class PhrStorageOptionExceptionTest extends TestCase
{
    public function test_it_cannot_be_constructed_or_extended_with_arbitrary_text(): void
    {
        $class = new ReflectionClass(PhrStorageOptionException::class);

        $this->assertTrue($class->isFinal());
        $constructor = $class->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
        $this->assertSame(PhrStorageOptionException::class, $constructor->getDeclaringClass()->getName());
    }

    public function test_named_constructors_produce_fixed_messages(): void
    {
        $this->assertSame('--disk must be one of: a, b.', PhrStorageOptionException::invalidDisk(['a', 'b'])->getMessage());
        $this->assertSame('--artifact must be one of: x.', PhrStorageOptionException::invalidArtifact(['x'])->getMessage());
        $this->assertSame('--patient must be a positive integer.', PhrStorageOptionException::invalidPatient()->getMessage());
        $this->assertSame('--disk and --artifact select incompatible storage areas.', PhrStorageOptionException::incompatibleScope()->getMessage());
    }
}
