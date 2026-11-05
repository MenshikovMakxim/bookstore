<?php

namespace App\Models;

use App\Enums\ImageType;
use App\Enums\UserRole;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['name', 'city', 'address'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    /**
     * The manager is a user whose users.warehouse_id points here and role = manager.
     *
     * @return HasOne<User, $this>
     */
    public function manager(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::Manager);
    }

    /**
     * warehouses -> users (users.warehouse_id) -> profiles (profiles.user_id).
     *
     * @return HasOneThrough<Profile, User, $this>
     */
    public function managerProfile(): HasOneThrough
    {
        return $this->hasOneThrough(
            Profile::class, // final model
            User::class,    // intermediate model
            'warehouse_id', // FK on users -> warehouses
            'user_id',      // FK on profiles -> users
        )->where('users.role', UserRole::Manager);
    }

    /**
     * @return BelongsToMany<Book, $this, Stock, 'stock'>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'stocks')
            ->using(Stock::class)
            ->withPivot(['id', 'quantity', 'reserved', 'shelf_code'])
            ->withTimestamps()
            ->as('stock');
    }

    /**
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Building photo (ImageType::Photo).
     *
     * @return MorphOne<Image, $this>
     */
    public function photo(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('type', ImageType::Photo);
    }

    /**
     * @return MorphMany<Image, $this>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
