<?php

namespace App\Models;

use App\Enums\ImageType;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['imageable_type', 'imageable_id', 'path', 'type', 'sort_order'])]
class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ImageType::class,
        ];
    }

    /**
     * The owner: a Book, an Author or a Warehouse (resolved via the morph map).
     *
     * @return MorphTo<Model, $this>
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
