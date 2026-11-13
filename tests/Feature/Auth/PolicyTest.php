<?php

use App\Models\Book;
use App\Models\Review;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\BookPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\StockPolicy;
use App\Policies\WarehousePolicy;
use Illuminate\Support\Facades\Gate;

/*
 * Policy methods are called directly (no Gate::before), so each ability is
 * tested on its own rule. The Gate tests at the bottom cover the bypass.
 */

beforeEach(function () {
    [$this->warehouse, $this->otherWarehouse] = Warehouse::factory()->count(2)->create();
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create(['warehouse_id' => $this->warehouse->id]);
    $this->reader = User::factory()->create();
});

test('BookPolicy: manage, viewTrashed, restore, forceDelete — admin only; restore/forceDelete need a trashed book', function () {
    $policy = new BookPolicy;
    $book = Book::factory()->create();
    $trashed = tap(Book::factory()->create())->delete();

    foreach ([$this->manager, $this->reader] as $user) {
        expect($policy->manage($user))->toBeFalse()
            ->and($policy->viewTrashed($user))->toBeFalse()
            ->and($policy->restore($user, $trashed))->toBeFalse()
            ->and($policy->forceDelete($user, $trashed))->toBeFalse();
    }

    expect($policy->manage($this->admin))->toBeTrue()
        ->and($policy->viewTrashed($this->admin))->toBeTrue()
        ->and($policy->restore($this->admin, $trashed))->toBeTrue()
        ->and($policy->forceDelete($this->admin, $trashed))->toBeTrue()
        ->and($policy->restore($this->admin, $book))->toBeFalse()
        ->and($policy->forceDelete($this->admin, $book))->toBeFalse();
});

test('WarehousePolicy::view: admin, or the manager of that warehouse', function () {
    $policy = new WarehousePolicy;
    $readerWithWarehouse = User::factory()->create(['warehouse_id' => $this->warehouse->id]);

    expect($policy->view($this->admin, $this->otherWarehouse))->toBeTrue()
        ->and($policy->view($this->manager, $this->warehouse))->toBeTrue()
        ->and($policy->view($this->manager, $this->otherWarehouse))->toBeFalse()
        ->and($policy->view($readerWithWarehouse, $this->warehouse))->toBeFalse(); // role matters, not only warehouse_id
});

test('StockPolicy::update: admin, or the manager of the stock\'s warehouse', function () {
    $policy = new StockPolicy;
    $book = Book::factory()->create();
    $this->warehouse->books()->attach($book, ['quantity' => 1]);
    $this->otherWarehouse->books()->attach($book, ['quantity' => 1]);
    $own = Stock::where('warehouse_id', $this->warehouse->id)->first();
    $foreign = Stock::where('warehouse_id', $this->otherWarehouse->id)->first();

    expect($policy->update($this->admin, $foreign))->toBeTrue()
        ->and($policy->update($this->manager, $own))->toBeTrue()
        ->and($policy->update($this->manager, $foreign))->toBeFalse()
        ->and($policy->update($this->reader, $own))->toBeFalse();
});

test('ReviewPolicy::create: verified, and no review yet — pending ones included', function () {
    $policy = new ReviewPolicy;
    $book = Book::factory()->create();

    expect($policy->create($this->reader, $book))->toBeTrue()
        ->and($policy->create(User::factory()->unverified()->create(), $book))->toBeFalse();

    Review::factory()->for($book)->for($this->reader)->create(); // pending, hidden by ApprovedScope

    expect($policy->create($this->reader, $book))->toBeFalse()
        ->and($policy->create($this->reader, Book::factory()->create()))->toBeTrue();
});

test('ReviewPolicy: update/delete own pending review; delete any and approve — admin', function () {
    $policy = new ReviewPolicy;
    $pending = Review::factory()->for($this->reader)->create();
    $approved = Review::factory()->approved()->for($this->reader)->create();
    $foreign = Review::factory()->create();

    expect($policy->update($this->reader, $pending))->toBeTrue()
        ->and($policy->delete($this->reader, $pending))->toBeTrue()
        ->and($policy->update($this->reader, $approved))->toBeFalse()
        ->and($policy->delete($this->reader, $approved))->toBeFalse()
        ->and($policy->update($this->reader, $foreign))->toBeFalse()
        ->and($policy->delete($this->reader, $foreign))->toBeFalse()
        ->and($policy->approve($this->reader, $pending))->toBeFalse()
        ->and($policy->delete($this->admin, $approved))->toBeTrue()
        ->and($policy->approve($this->admin, $pending))->toBeTrue()
        ->and($policy->approve($this->admin, $approved))->toBeFalse()
        ->and($policy->update($this->admin, $foreign))->toBeFalse(); // policy alone: no admin branch
});

test('view-reports gate: managers and admins', function () {
    expect(Gate::forUser($this->manager)->allows('view-reports'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view-reports'))->toBeTrue()
        ->and(Gate::forUser($this->reader)->allows('view-reports'))->toBeFalse();
});

test('export-csv gate: admins only', function () {
    expect(Gate::forUser($this->manager)->allows('export-csv'))->toBeFalse()
        ->and(Gate::forUser($this->reader)->allows('export-csv'))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('export-csv'))->toBeTrue();
});

test('Gate::before lets the admin through abilities the policy itself denies', function () {
    $foreign = Review::factory()->approved()->create();

    expect(Gate::forUser($this->admin)->allows('update', $foreign))->toBeTrue() // policy: false
        ->and(Gate::forUser($this->admin)->allows('anything-undefined'))->toBeTrue()
        // Non-admins: before() returns null, the policy decides.
        ->and(Gate::forUser($this->manager)->allows('update', Stock::make(['warehouse_id' => $this->otherWarehouse->id])))->toBeFalse();
});

test('Gate::before exclusion: state rules (restore/forceDelete/approve) still apply to the admin', function () {
    $book = Book::factory()->create();
    $approved = Review::factory()->approved()->create();

    expect(Gate::forUser($this->admin)->allows('forceDelete', $book))->toBeFalse() // not trashed
        ->and(Gate::forUser($this->admin)->allows('restore', $book))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('approve', $approved))->toBeFalse();

    $book->delete();
    expect(Gate::forUser($this->admin)->allows('forceDelete', $book))->toBeTrue();
});

test('Gate::before exclusion: one review per book applies to the admin too', function () {
    $book = Book::factory()->create();

    expect(Gate::forUser($this->admin)->allows('create', [Review::class, $book]))->toBeTrue();

    Review::factory()->for($book)->for($this->admin)->create();

    expect(Gate::forUser($this->admin)->allows('create', [Review::class, $book]))->toBeFalse();
});
