<?php

use App\Models\Book;
use App\Models\StockMovement;
use App\Models\Warehouse;

beforeEach(function () {
    $this->warehouse = Warehouse::factory()->create();
    $this->book = Book::factory()->create(['title' => 'Кобзар']);
    $this->warehouse->books()->attach($this->book, ['quantity' => 10, 'reserved' => 0]);
    $this->stock = $this->warehouse->stocks()->first();
});

test('a manager updates stock: the movement records the reason and the user', function () {
    $manager = asManager($this->warehouse);

    $this->get(route('manage.warehouses.stocks.edit', [$this->warehouse, $this->stock]))->assertOk()->assertSee('Кобзар');

    $this->put(route('manage.warehouses.stocks.update', [$this->warehouse, $this->stock]), [
        'quantity' => 7, 'reserved' => 2, 'shelf_code' => 'B-02-3', 'reason' => 'sale',
    ])->assertRedirect(route('manage.warehouses.stocks.index', $this->warehouse))->assertSessionHas('status');

    $movement = StockMovement::latest('id')->first();

    expect($this->stock->fresh()->only(['quantity', 'reserved', 'shelf_code']))->toBe(['quantity' => 7, 'reserved' => 2, 'shelf_code' => 'B-02-3'])
        ->and($movement->delta)->toBe(-3)
        ->and($movement->reason->value)->toBe('sale')
        ->and($movement->user_id)->toBe($manager->id);

    $this->get(route('manage.warehouses.movements.index', $this->warehouse))->assertOk()->assertSee('-3')->assertSee($manager->name);
});

test('a manager can change only the shelf code: no reason, no movement', function () {
    asManager($this->warehouse);
    $movementsBefore = StockMovement::count();

    $this->put(route('manage.warehouses.stocks.update', [$this->warehouse, $this->stock]), [
        'quantity' => 10, 'reserved' => 0, 'shelf_code' => 'C-01-2',
    ])->assertRedirect(route('manage.warehouses.stocks.index', $this->warehouse))->assertSessionHasNoErrors();

    expect($this->stock->fresh()->only(['quantity', 'shelf_code']))->toBe(['quantity' => 10, 'shelf_code' => 'C-01-2'])
        ->and(StockMovement::count())->toBe($movementsBefore);
});

test('receipt is rejected when the quantity decreases', function () {
    asManager($this->warehouse);

    $this->put(route('manage.warehouses.stocks.update', [$this->warehouse, $this->stock]), [
        'quantity' => 8, 'reserved' => 0, 'shelf_code' => 'B-02-3', 'reason' => 'receipt',
    ])->assertSessionHasErrors(['reason']);

    expect($this->stock->fresh()->quantity)->toBe(10)
        ->and(StockMovement::count())->toBe(1); // only the initial attach receipt

    // Receipt stays valid when the quantity grows.
    $this->put(route('manage.warehouses.stocks.update', [$this->warehouse, $this->stock]), [
        'quantity' => 12, 'reserved' => 0, 'shelf_code' => 'B-02-3', 'reason' => 'receipt',
    ])->assertSessionHasNoErrors();

    expect($this->stock->fresh()->quantity)->toBe(12)
        ->and(StockMovement::latest('id')->first()->delta)->toBe(2);
});

test('stock validation: reserved cannot exceed quantity; import is not a manual reason', function () {
    asManager($this->warehouse);

    $this->put(route('manage.warehouses.stocks.update', [$this->warehouse, $this->stock]), ['quantity' => 1, 'reserved' => 2, 'reason' => 'import'])
        ->assertSessionHasErrors(['reserved', 'reason']);

    expect($this->stock->fresh()->quantity)->toBe(10);
});

test('the public book page shows status only, no per-warehouse data', function () {
    $this->get(route('books.show', $this->book))
        ->assertOk()
        ->assertSee(__('ui.stock_badge.in'))
        ->assertDontSee($this->warehouse->name)
        ->assertDontSee(route('manage.warehouses.index'));
});
