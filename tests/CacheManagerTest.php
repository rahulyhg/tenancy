<?php

namespace Stancl\Tenancy\Tests;

class CacheManagerTest extends TestCase
{
    /** @test */
    public function default_tag_is_automatically_applied()
    {
        $this->assertArrayIsSubset([config('tenancy.cache.tag_base') . tenant('uuid')], cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_array_is_passed()
    {
        $expected = [config('tenancy.cache.tag_base') . tenant('uuid'), 'foo', 'bar'];
        $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_string_is_passed()
    {
        $expected = [config('tenancy.cache.tag_base') . tenant('uuid'), 'foo'];
        $this->assertEquals($expected, cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function exception_is_thrown_when_zero_arguments_are_passed_to_tags_method()
    {
        $this->expectException(\Exception::class);
        cache()->tags();
    }

    /** @test */
    public function exception_is_thrown_when_more_than_one_argument_is_passed_to_tags_method()
    {
        $this->expectException(\Exception::class);
        cache()->tags(1, 2);
    }

    /** @test */
    public function tags_separate_cache_well_enough()
    {
        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');

        cache()->put('foo', 'bar', 1);
        $this->assertSame('bar', cache()->get('foo'));

        tenant()->create('bar.localhost');
        tenancy()->init('bar.localhost');

        $this->assertNotSame('bar', cache()->get('foo'));
        
        cache()->put('foo', 'xyz', 1);
        $this->assertSame('xyz', cache()->get('foo'));
    }

    /** @test */
    public function invoking_the_cache_helper_works()
    {
        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');

        cache(['foo' => 'bar'], 1);
        $this->assertSame('bar', cache('foo'));

        tenant()->create('bar.localhost');
        tenancy()->init('bar.localhost');

        $this->assertNotSame('bar', cache('foo'));

        cache(['foo' => 'xyz'], 1);
        $this->assertSame('xyz', cache('foo'));
    }

    /** @test */
    public function cache_is_persisted()
    {
        tenant()->create('foo.localhost');
        tenancy()->init('foo.localhost');

        cache(['foo' => 'bar'], 10);
        $this->assertSame('bar', cache('foo'));

        tenancy()->end();

        tenancy()->init('foo.localhost');
        $this->assertSame('bar', cache('foo'));
    }

    /** @test */
    public function cache_is_persisted_when_reidentification_is_used()
    {
        tenant()->create('foo.localhost');
        tenant()->create('bar.localhost');
        tenancy()->init('foo.localhost');

        cache(['foo' => 'bar'], 10);
        $this->assertSame('bar', cache('foo'));

        tenancy()->init('bar.localhost');
        tenancy()->end();

        tenancy()->init('foo.localhost');
        $this->assertSame('bar', cache('foo'));
    }
}
