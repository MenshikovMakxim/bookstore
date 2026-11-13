<?php

namespace App\Http\Controllers;

use App\Enums\BookFormat;
use App\Http\Controllers\Concerns\ShowsBook;
use App\Http\Requests\CatalogFilterRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookController extends Controller
{
    use ShowsBook;

    /**
     * Sort keys accepted by the catalog (CatalogFilterRequest); anything else falls back to "newest".
     * Labels: lang/{en,uk}/ui.php => catalog.sorts.*
     */
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'rating'];

    public function index(CatalogFilterRequest $request): View
    {
        // Only filters that passed validation; invalid ones were dropped by the request.
        $filters = $request->filters();

        $genre = isset($filters['genre']) ? Genre::firstWhere('slug', $filters['genre']) : null;
        $author = isset($filters['author']) ? Author::firstWhere('slug', $filters['author']) : null;
        $format = isset($filters['format']) ? BookFormat::from($filters['format']) : null;
        $tag = isset($filters['tag']) ? Tag::firstWhere('slug', $filters['tag']) : null;
        $inStock = filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = trim($filters['q'] ?? '');
        $sort = $filters['sort'] ?? 'newest';

        // Conditional query building with when() + local scopes (Book model): each
        // filter reads like a sentence, and the same scopes serve other pages and tests.
        // Stage 3 had these conditions inline — see git history / docs/06-attributes-casts.md.
        $books = Book::withCardData()
            ->when($genre, fn ($query, Genre $genre) => $query->ofGenre($genre))
            ->when($author, fn ($query, Author $author) => $query->byAuthor($author))
            ->when($format, fn ($query, BookFormat $format) => $query->where('format', $format))
            ->when($tag, fn ($query, Tag $tag) => $query->tagged($tag))
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($inStock, fn ($query) => $query->inStock())
            ->sorted($sort)
            ->orderBy('id')
            ->paginate(24)
            ->withQueryString();

        return view('books.index', [
            'books' => $books,
            'filters' => array_filter([
                'genre' => $genre?->name,
                'author' => $author?->full_name,
                'format' => $format?->label(),
                'in_stock' => $inStock ? __('ui.catalog.chip_in_stock') : null,
                'tag' => $tag ? '#'.$tag->name : null,
                'q' => $search !== '' ? __('ui.catalog.search_chip', ['term' => $search]) : null,
            ]),
            'activeGenre' => $genre,
            'sort' => $sort,
            'formats' => BookFormat::cases(),
        ]);
    }

    public function show(Request $request, Book $book): View
    {
        return $this->bookPage($request, $book);
    }
}
