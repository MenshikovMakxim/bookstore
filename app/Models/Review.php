<?php

namespace App\Models;

use App\Models\Scopes\ApprovedScope;
use App\ValueObjects\Rating;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'book_id', 'rating', 'body', 'is_approved'])]
// Global scope: queries see approved reviews only (withoutGlobalScope() to opt out).
#[ScopedBy(ApprovedScope::class)]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    // Legacy registration, equivalent to #[ScopedBy]:
    // protected static function booted(): void
    // {
    //     static::addGlobalScope(new ApprovedScope);
    // }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => Rating::class,
            'is_approved' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
