<?php

use App\Models\Book;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('all stage 1 tables exist after migrations', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'warehouses', 'users', 'profiles', 'authors', 'genres', 'publishers', 'books', 'reviews',
    'author_book', 'book_genre', 'stocks', 'images', 'tags', 'taggables', 'stock_movements',
]);

test('books.publisher_id is a nullable foreign key that is cleared when the publisher is deleted', function () {
    expect(Schema::hasColumns('publishers', ['name', 'country']))->toBeTrue()
        ->and(Schema::hasColumn('books', 'publisher_id'))->toBeTrue();

    $publisherId = DB::table('publishers')->insertGetId([
        'name' => 'Наш Формат',
        'country' => 'UA',
    ]);
    $book = Book::factory()->create();

    DB::table('books')->where('id', $book->id)->update(['publisher_id' => $publisherId]);
    DB::table('publishers')->where('id', $publisherId)->delete();

    expect(DB::table('books')->where('id', $book->id)->value('publisher_id'))->toBeNull();
});

test('users table has role and warehouse_id columns', function () {
    expect(Schema::hasColumns('users', ['role', 'warehouse_id']))->toBeTrue();
});

test('books table has soft deletes and denormalized rating', function () {
    expect(Schema::hasColumns('books', ['deleted_at', 'rating_avg', 'meta', 'published_at']))->toBeTrue();
});

test('stock_movements has created_at but no updated_at', function () {
    expect(Schema::hasColumn('stock_movements', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('stock_movements', 'updated_at'))->toBeFalse();
});

test('polymorphic tables have morph columns', function () {
    expect(Schema::hasColumns('images', ['imageable_type', 'imageable_id']))->toBeTrue()
        ->and(Schema::hasColumns('taggables', ['taggable_type', 'taggable_id']))->toBeTrue();
});
