<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExtractEventsTest extends TestCase
{
    public function test_mixed_items_only_arrays_kept(): void
    {
        $input = [
            ['id' => 1],
            'string',
            123,
            null,
            ['id' => 2],
            (object) ['id' => 3],
        ];

        $out = zeffy_sync_extract_events($input);
        $this->assertCount(2, $out);
        $this->assertSame(['id' => 1], $out[0]);
        $this->assertSame(['id' => 2], $out[1]);
    }

    public function test_non_arrays_return_empty(): void
    {
        $input = ['a', 1, null, (object) ['x' => 'y']];
        $out = zeffy_sync_extract_events($input);
        $this->assertSame([], $out);
    }

    public function test_associative_items_preserved_inner_structure(): void
    {
        $input = [
            'first' => ['foo' => 'bar'],
            'second' => ['baz' => 'quux'],
        ];
        $out = zeffy_sync_extract_events($input);
        $this->assertCount(2, $out);
        $this->assertSame(['foo' => 'bar'], $out[0]);
        $this->assertSame(['baz' => 'quux'], $out[1]);
    }
}
