<?php

namespace App\Http\Controllers;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\Tag;
use Illuminate\View\View;

class TagController extends Controller
{
    /**
     * Tag page: the catalog filtered by one tag.
     */
    public function show(Tag $tag): View
    {
        return view('books.index', [
            'books' => Book::withCardData()
                ->tagged($tag)
                ->sorted('newest')
                ->orderBy('id')
                ->paginate(24)
                ->withQueryString(),
            'filters' => ['tag' => '#'.$tag->name],
            'activeGenre' => null,
            'activeTag' => $tag,
            'pageTitle' => __('ui.tags.title', ['tag' => $tag->name]),
            'sort' => 'newest',
            'formats' => BookFormat::cases(),
        ]);
    }
}
