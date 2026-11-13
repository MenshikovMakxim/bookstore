<?php

namespace App\Http\Requests;

use App\Enums\BookFormat;
use App\Http\Controllers\BookController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Catalog query string (GET /books). Unlike a form, an invalid filter is not an
 * error to show: it comes from an old bookmark or a hand-edited URL. Such filters
 * are dropped and the rest of the page works — no redirect, no 422.
 *
 * Trade-off: the visitor is not told that a filter was ignored. Fine for optional
 * filters; a required parameter would deserve an error instead.
 */
class CatalogFilterRequest extends FormRequest
{
    /**
     * Keys of the filters that failed validation and were dropped.
     *
     * @var list<string>
     */
    public array $dropped = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'genre' => ['sometimes', 'string', Rule::exists('genres', 'slug')],
            // Rule::exists() with a constraint: only authors credited in at least one book.
            'author' => ['sometimes', 'string', Rule::exists('authors', 'slug')->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $credits) => $credits->from('author_book')->whereColumn('author_book.author_id', 'authors.id')))],
            'format' => ['sometimes', Rule::enum(BookFormat::class)],
            'in_stock' => ['sometimes', 'boolean'],
            'tag' => ['sometimes', 'string', Rule::exists('tags', 'slug')],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(BookController::SORTS)],
        ];
    }

    /**
     * The default throws ValidationException (redirect back with errors). Here a
     * failure only records which filters to drop; validateResolved() then goes on.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->dropped = array_keys($validator->invalid());
    }

    /**
     * The valid filters. Not validated(): it throws while any attribute is invalid.
     * valid() = the input without the failed attributes; Arr::only() keeps the keys
     * that have rules (never the whole query string).
     *
     * @return array{genre?: string, author?: string, format?: string, in_stock?: string, tag?: string, q?: string, sort?: string}
     */
    public function filters(): array
    {
        return Arr::only($this->getValidatorInstance()->valid(), array_keys($this->rules()));
    }
}
