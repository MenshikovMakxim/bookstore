<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GenreController extends Controller
{
    public function index(): View
    {
        return view('genres.index', [
            'genres' => Genre::whereNull('parent_id')
                ->withCount('books')
                ->with([
                    // A limit inside eager loading applies per parent (ROW_NUMBER()).
                    'books' => fn ($query) => $query->orderBy('title')->limit(3),
                    'children' => fn ($query) => $query->withCount('books')->orderBy('name'),
                    'children.books' => fn ($query) => $query->orderBy('title')->limit(3),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * A genre page is just the filtered catalog.
     */
    public function show(Genre $genre): RedirectResponse
    {
        return redirect()->route('books.index', ['genre' => $genre->slug]);
    }
}
