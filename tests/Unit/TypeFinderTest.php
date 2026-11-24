<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Services\TypeFinder;
use BuybackManager\Tests\Sqlite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class TypeFinderTest extends TestCase
{
    protected function setUp(): void
    {
        Sqlite::migrate('000001', '000016', '000017');
        Schema::create('invCategories', function (Blueprint $table) {
            $table->integer('categoryID')->primary();
            $table->string('categoryName');
        });
        Schema::create('invGroups', function (Blueprint $table) {
            $table->integer('groupID')->primary();
            $table->integer('categoryID');
            $table->string('groupName');
        });
        Schema::create('invTypes', function (Blueprint $table) {
            $table->integer('typeID')->primary();
            $table->integer('groupID');
            $table->string('typeName');
            $table->boolean('published')->default(true);
        });

        DB::table('invCategories')->insert([
            ['categoryID' => 25, 'categoryName' => 'Asteroid'],
            ['categoryID' => 4, 'categoryName' => 'Material'],
        ]);
        DB::table('invGroups')->insert([
            ['groupID' => 462, 'categoryID' => 25, 'groupName' => 'Veldspar'],
            ['groupID' => 1922, 'categoryID' => 25, 'groupName' => 'Rare Moon Asteroids'],
            ['groupID' => 427, 'categoryID' => 4, 'groupName' => 'Moon Materials'],
        ]);
        DB::table('invTypes')->insert([
            ['typeID' => 1230, 'groupID' => 462, 'typeName' => 'Veldspar', 'published' => 1],
            ['typeID' => 17470, 'groupID' => 462, 'typeName' => 'Concentrated Veldspar', 'published' => 1],
            ['typeID' => 28432, 'groupID' => 462, 'typeName' => 'Compressed Veldspar', 'published' => 1],
            ['typeID' => 45506, 'groupID' => 1922, 'typeName' => 'Cinnabar', 'published' => 1],
            ['typeID' => 99001, 'groupID' => 462, 'typeName' => 'Veldspar Test Rock', 'published' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    public function testAnItemComesWithItsGroupAndCategory(): void
    {
        $found = (new TypeFinder())->search('Cinnabar');

        $this->assertSame([[
            'id' => 45506,
            'name' => 'Cinnabar',
            'group' => ['id' => 1922, 'name' => 'Rare Moon Asteroids'],
            'category' => ['id' => 25, 'name' => 'Asteroid'],
        ]], $found['items']);
    }

    public function testANameMatchingTheStartComesFirstAndUnpublishedTypesAreLeftOut(): void
    {
        $names = array_column((new TypeFinder())->search('veldspar')['items'], 'name');

        $this->assertSame(['Veldspar', 'Compressed Veldspar', 'Concentrated Veldspar'], $names);
    }

    public function testAnIdFindsTheItemGroupAndCategoryWithThatId(): void
    {
        $finder = new TypeFinder();

        $this->assertSame('Veldspar', $finder->search('1230')['items'][0]['name']);
        $this->assertSame('Veldspar Test Rock', $finder->search('99001')['items'][0]['name']);
        $this->assertSame('Rare Moon Asteroids', $finder->search('1922')['groups'][0]['name']);
        $this->assertSame('Asteroid', $finder->search('25')['categories'][0]['name']);
    }

    public function testGroupsAndCategoriesAreFoundByName(): void
    {
        $found = (new TypeFinder())->search('moon');

        $this->assertSame(['Moon Materials', 'Rare Moon Asteroids'], array_column($found['groups'], 'name'));
        $this->assertSame(['id' => 25, 'name' => 'Asteroid'], $found['groups'][1]['category']);
        $this->assertSame([], $found['items']);
    }

    public function testOneLetterFindsNothing(): void
    {
        $this->assertSame(['items' => [], 'groups' => [], 'categories' => []], (new TypeFinder())->search(' v '));
    }
}
