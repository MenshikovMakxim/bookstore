<?php

// Generated from one (en, uk) source to keep both locales in sync.
// Terms follow docs/glossary.md.

return [
    'nav' => [
        'stock' => 'Stock by warehouse & genre',
        'top_authors' => 'Top authors',
        'csv' => 'Download stock CSV ↓',
    ],
    'stock' => [
        'title' => 'Stock report',
        'units' => 'units',
        'reserved' => 'reserved',
        'value' => 'stock value',
        'empty' => 'No stock.',
        'filtered' => 'Showing only warehouse: :warehouse',
        'all' => 'All warehouses',
        'only' => 'Only this warehouse',
        'warehouse_csv' => 'CSV for this warehouse ↓',
        'columns' => [
            'genre' => 'Root genre',
            'titles' => 'Titles',
            'quantity' => 'Quantity',
            'reserved' => 'Reserved',
            'value' => 'Value',
        ],
    ],
    'top_authors' => [
        'title' => 'Top authors',
        'intro' => 'Average rating of approved reviews of their books (as author or co-author); at least :min reviews. "Books" = rated books.',
        'empty' => 'No author has :min+ approved reviews yet.',
        'columns' => [
            'rank' => '#',
            'author' => 'Author',
            'rating' => 'Rating',
            'reviews' => 'Reviews',
            'books' => 'Books',
        ],
    ],
];
