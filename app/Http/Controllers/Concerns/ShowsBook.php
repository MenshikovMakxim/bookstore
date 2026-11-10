<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Author;
use App\Models\Book;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book page shared by books.show and the scoped authors.books.show route.
 */
trait ShowsBook
{
    protected function bookPage(Request $request, Book $book, ?Author $viaAuthor = null): View
    {
        // Load everything the view touches (strict mode: no lazy loading).
        $book->load([
            'genre.parent',
            'genres:id,name,slug',
            'authors:id,first_name,last_name,slug',
            'tags:id,name,slug',
            'cover',
            'latestReview.user:id,name',
            'bestReview.user:id,name',
        ])->loadCount('reviews')->loadAvg('reviews', 'rating')
            // Public page: overall availability only; per-warehouse stock is staff data (/manage).
            ->loadSum('stocks', 'quantity')->loadSum('stocks', 'reserved');

        // Approved only: Review's global ApprovedScope applies to relation queries too.
        $reviews = $book->reviews()
            ->with('user:id,name')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        // The visitor's own review, pending included: the global scope is bypassed, but
        // only together with user_id = the visitor — nobody else sees a pending review.
        $user = $request->user();
        $myReview = $user
            ? Review::withoutGlobalScope(ApprovedScope::class)->whereBelongsTo($user)->whereBelongsTo($book)->first()
            : null;
        $canReview = (bool) $user?->can('create', [Review::class, $book]);

        return view('books.show', compact('book', 'reviews', 'viaAuthor', 'myReview', 'canReview'));
    }
}
