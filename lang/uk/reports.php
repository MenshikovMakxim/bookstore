<?php

// Generated from one (en, uk) source to keep both locales in sync.
// Terms follow docs/glossary.md.

return [
    'nav' => [
        'stock' => 'Залишки за складами та жанрами',
        'top_authors' => 'Найкращі автори',
        'csv' => 'Завантажити залишки в CSV ↓',
    ],
    'stock' => [
        'title' => 'Звіт про залишки',
        'units' => 'примірників',
        'reserved' => 'у резерві',
        'value' => 'вартість залишків',
        'empty' => 'Залишків немає.',
        'filtered' => 'Показано лише склад: :warehouse',
        'all' => 'Усі склади',
        'only' => 'Лише цей склад',
        'warehouse_csv' => 'CSV цього складу ↓',
        'columns' => [
            'genre' => 'Кореневий жанр',
            'titles' => 'Назв',
            'quantity' => 'Кількість',
            'reserved' => 'Резерв',
            'value' => 'Вартість',
        ],
    ],
    'top_authors' => [
        'title' => 'Найкращі автори',
        'intro' => 'Середня оцінка схвалених відгуків на книги автора (як автора чи співавтора); щонайменше :min відгуків. «Книги» — книги, що мають оцінки.',
        'empty' => 'Жоден автор ще не має :min+ схвалених відгуків.',
        'columns' => [
            'rank' => '№',
            'author' => 'Автор',
            'rating' => 'Рейтинг',
            'reviews' => 'Відгуки',
            'books' => 'Книги',
        ],
    ],
];
