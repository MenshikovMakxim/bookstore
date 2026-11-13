# 06 — Аксесори, касти, скоупи, події моделей

## Мета

Перенести правила домену в шар моделей: гроші — об’єкт-значення `Money` з власним кастом, а не
«ціле число, яке всі пам’ятають ділити на 100»; аксесори й мутатори; приведення типів (casts),
зокрема шифрування; серіалізація; локальні та глобальні скоупи (scopes); події моделей і
спостерігачі (observers); захист від масового присвоєння. Знати, **які операції обходять** касти
й події.

Відкрийте **<http://localhost/lab/models>** — сім розділів, у кожному прикладі код, результат і SQL;
приклади із записом виконуються в транзакції з відкатом.

## Де дивитися в коді

- `app/ValueObjects/Money.php`, `app/Casts/MoneyCast.php` — об’єкт-значення та власний каст (`Castable`).
- `app/Models/Book.php` — касти `price`/`published_at`/`meta`, аксесори `isbn`/`isbnFormatted`,
  `#[Appends]`, локальні скоупи з `#[Scope]`.
- `app/Models/Author.php` (`fullName`), `Profile.php` (`encrypted`), `User.php` (`#[Hidden]`, `#[Fillable]` без `role`).
- `app/Models/Scopes/ApprovedScope.php` + `#[ScopedBy]` на `Review`.
- `app/Models/Concerns/HasSlug.php` — трейт із `bootHasSlug()`.
- `app/Observers/StockObserver.php` + `#[ObservedBy]` на `Stock`.
- `app/Lab/Models/LabBook.php`, `LabUser.php` — старий синтаксис і небезпечний `$guarded = []` для порівняння.
- `app/Http/Controllers/BookController.php` — каталог на скоупах замість `when()`-ланцюжка етапу 3.
- `app/Lab/ModelExamples.php` — усі приклади лабораторії.
- `tests/Unit/MoneyTest.php`, `tests/Feature/Models/*`, `tests/Feature/Lab/LabModelsTest.php`.

## Ключові поняття

### Гроші: об’єкт-значення і власний каст

`books.price` у базі — `unsigned int` копійок. У моделі `$book->price` — незмінний (`readonly`)
`Money`: `fromCents()`, `fromUah('199.99')` (рядок розбирається регулярним виразом, без `float`),
`add()`, `multiply(int)`, `toUah()` → `"199.99"`, `format()` → `"199,99 ₴"`. Ділення на 100 є лише
у `format()`, на рівні представлення.

```php
'price' => Money::class,   // Money implements Castable: castUsing() → MoneyCast
```

`MoneyCast::set()` приймає `Money` або `int` (копійки) і **кидає виняток на `float`**: `19.99`
могло означати і гривні, і копійки, а `19.99 * 100 === 1998.9999…`. `serialize()`
(`SerializesCastableAttributes`) віддає в `toArray()`/JSON `{"cents": 19999, "formatted": "199,99 ₴"}`.

SQL-звіти (`StockByWarehouseGenreReport`, CSV) рахують `sum(quantity * price)` у копійках — касти
працюють лише в моделях, а Query Builder повертає сирі числа; `Money::fromCents()` лише форматує
результат у шаблоні.

### Аксесори та мутатори: сучасний і старий синтаксис

| | Сучасний (`Attribute`) | Старий (досі працює) |
|---|---|---|
| Читання | `protected function fullName(): Attribute { return Attribute::get(fn () => …); }` | `public function getFullNameAttribute()` |
| Запис | `Attribute::set(fn ($value) => …)` — повертає значення або масив стовпців | `public function setTitleAttribute($v) { $this->attributes['title'] = …; }` |
| Обидва | `Attribute::make(get: …, set: …)` — один метод на атрибут | два окремі методи |
| Кеш | `->shouldCache()` | — |

- `Author::full_name` — лише читання.
- `Book::isbn` — лише мутатор: `"978-617-7489-12-4"` зберігається як `9786177489124`.
- `Book::isbn_formatted` — аксесор + `shouldCache()` (обчислюється для кожної книги в кожному
  `toArray()`). **Пастка:** кеш прив’язаний до назви атрибута й **не скидається**, коли змінюється
  `isbn` на тому самому екземплярі — навіть після `save()`. Допомагає `fresh()`/`refresh()` або
  `setRawAttributes()`. Кешуйте лише те, що не змінюється протягом життя екземпляра.
- Аксесор у `$appends` читає `$attributes['isbn'] ?? null`, а не `$this->isbn`: книга, вибрана без
  стовпця `isbn` (`select id, title`), серіалізується з `isbn_formatted: null`, а не падає з
  `MissingAttributeException` суворого режиму.
- `Stock::available` (етап 2) — `max(0, quantity - reserved)`.
- Для порівняння в `LabBook`: `getDisplayTitleAttribute()`, `setTitleAttribute()` (`Str::squish`).

### Типи кастів

| Каст | Стовпець | У моделі | Примітка |
|---|---|---|---|
| enum (`BookFormat::class`) | `books.format` | `BookFormat::Paperback` | з етапу 1 |
| `Money::class` (власний) | `books.price` | `Money` | `Castable` → `MoneyCast` |
| `immutable_datetime` | `books.published_at` | `CarbonImmutable` | `addYear()` повертає копію |
| `date:Y-m-d` | `authors.birth_date` | `Carbon` (північ) | формат — лише для серіалізації |
| `AsCollection::class` | `books.meta` (JSON) | `Collection` | `->put()` змінює модель (dirty) |
| `AsArrayObject::class` | те саме, лише в `LabBook` | `ArrayObject` | `$book->meta['x'] = 1` працює |
| `array` (було до етапу 6) | — | `array` | `$book->meta['x'] = 1` → «Indirect modification of overloaded property» |
| `encrypted` | `profiles.phone` | `string` | у базі — base64-шифротекст |
| `hashed` | `users.password` | хеш | з коробки |
| `Rating::class` (власний) | `reviews.rating` | `Rating` | `Castable` → `RatingCast`; `stars()` → `★★★★☆` |
| `boolean` | `reviews.is_approved` | `bool` | |

Чому `immutable_datetime`: зі змінним `Carbon` код `$date = $book->published_at; $date->addYear();`
змінює `$date` — легко передати цей об’єкт далі й несподівано зіпсувати дату. `CarbonImmutable`
повертає нову дату й не чіпає стару.

### Шифровані поля й APP_KEY

`'phone' => 'encrypted'` шифрує значення через `Crypt::encryptString()` (AES-256-CBC + MAC) ключем
`APP_KEY`. Наслідки:

- шукати й індексувати неможливо: шифротекст щоразу інший, `where('phone', …)` нічого не знайде;
- стовпець — `text` (шифротекст довгий, див. міграцію `profiles`);
- `DB::table()` і масові вставки каст **не застосовують**: `UserSeeder` шифрує телефон сам;
- **ротація `APP_KEY`**: після `key:generate` старі значення не розшифруються. Перелічіть старі ключі в
  `APP_PREVIOUS_KEYS=base64:old1,base64:old2` — Laravel шифрує новим ключем, а розшифровує будь-яким
  із переліку. Перешифрування старих рядків — окрема задача (прочитати й зберегти модель);
- втрата `APP_KEY` = втрата всіх зашифрованих даних. Ключ — у сховищі секретів, не в репозиторії.

Після зміни кастів дані перезасіяно: `sail artisan migrate:fresh --seed`.

### Серіалізація

- `#[Appends(['isbn_formatted'])]` додає обчислений атрибут у `toArray()`/`toJson()`.
- `Money` → `{cents, formatted}`; `Rating` → `{value, stars}`; enum → значення; дати → ISO-8601.
- `User`: `#[Hidden(['password', 'remember_token', 'email'])]` — облікові дані й контакти не потрапляють
  у JSON. Разово: `$user->makeVisible('email')`, `makeHidden([...])` (також на колекції).

### Локальні скоупи: `#[Scope]` проти `scopeX`

```php
#[Scope]
protected function inStock(Builder $query): void { … }   // Book::inStock()

public function scopeCheaperThan(Builder $query, int $cents) { … }  // старий синтаксис, LabBook::cheaperThan()
```

Скоупи `Book`: `published()`, `inStock()`, `ofGenre(Genre|string $slug, bool $withChildren = true)`,
`byAuthor(Author|string $slug)`, `search($term)` (назва або цифри ISBN, дефіси й пробіли дозволено),
`sorted($key)` (невідомий ключ → `newest`), `withCardData()` (усе для `<x-book-card>`).
`ofGenre('slug')` будує ідентифікатори жанрів підзапитом — без окремого запиту.

Каталог (`BookController::index`) тепер: `Book::withCardData()->when($genre, fn ($q, $g) => $q->ofGenre($g))…->when($search !== '', fn ($q) => $q->search($search))->sorted($sort)`. Параметр `?q=` шукає за назвою або ISBN; порожній рядок ігнорується, а валідований `q` показується знімним чипом.
Тест `catalog: scopes return exactly what the stage 3 when() chain returned` зберігає старий
ланцюжок і порівнює ідентифікатори для набору фільтрів на засіяних даних — поведінка не змінилася.

### Глобальні скоупи

`#[ScopedBy(ApprovedScope::class)]` на `Review` додає `where reviews.is_approved = 1` до **кожного**
запиту відгуків: `Review::count()`, `$book->reviews`, `withCount('reviews')`, `whereHas('reviews')`,
`latestReview`, `hasManyThrough`. Тому `Book::approvedReviews()` став зайвим і його видалено
(`reviews_count`/`reviews_avg_rating` замість `approved_reviews_*`). Вимикати явно там, де потрібні
й неперевірені: `->withoutGlobalScope(ApprovedScope::class)` (адмінська сторінка книги — «на
модерації», лабораторії, тести), `withoutGlobalScopes()` — усі.

- `SoftDeletes` — вбудований глобальний скоуп (`deleted_at is null`); `withTrashed()`/`onlyTrashed()`.
- `Collection::toQuery()` і `refresh()` використовують запит **без** глобальних скоупів.
- Глобальний скоуп не діє на `DB::table('reviews')` (звіти, сидери) — там умову пишемо самі.
- Ціна: умова прихована. Хто не знає про скоуп, здивується, що `Review::find($id)` повертає `null`
  для неперевіреного відгуку. Додавайте глобальні скоупи лише для правил, які справді мають
  діяти «майже завжди».

### Події: замикання, трейт, спостерігач

| Спосіб | Де | Коли доречно |
|---|---|---|
| `static::creating(fn …)` у `booted()` моделі | модель | одна-дві дрібні дії |
| `bootHasSlug()` у трейті | `HasSlug` (Book, Author, Genre, Tag) | одна поведінка для багатьох моделей |
| `#[ObservedBy(StockObserver::class)]` | окремий клас | кілька подій, залежності, бізнес-логіка |

**`HasSlug`**: на `creating`, якщо `slug` порожній, — `Str::slug($source, '-', 'uk')` і суфікс
`-2`, `-3`… за колізії (перевіряє й м’яко видалені книги — унікальний індекс їх теж враховує).
Під час оновлення назви слаг **не змінюється**: він — частина публічних URL (`/books/{slug}`),
зміна зламала б посилання, закладки й пошукові індекси. (Якщо колись знадобиться — нова адреса +
редірект зі старої.)

**`StockObserver`**: `created` → рух `receipt` на всю кількість; `updated` зі зміненим `quantity`
→ рух з `delta = нове − старе` (`getOriginal()`); `deleted` → рух `adjustment` на `−quantity`. Причину задає тимчасова властивість
`$stock->movementReason` (звичайна PHP-властивість, не стовпець; за замовчуванням `receipt`/`adjustment`),
спостерігач її скидає після запису. `user_id` — `auth()->id()` або `null`. Нульова дельта
руху не створює.

### Які операції викликають події `Stock` (перевірено тестами)

`Stock` — власна pivot-модель, підключена через `->using(Stock::class)`. Тоді `BelongsToMany`
створює й зберігає **моделі** pivot, а не робить `insert`/`update` напряму:

| Операція | Події `Stock` | Рух |
|---|---|---|
| `$book->warehouses()->attach($w, ['quantity' => 5])` | `created` | +5 `receipt` |
| `->updateExistingPivot($id, ['quantity' => 8])` | `updated` | +3 `adjustment` |
| `->sync([...])` | `updated` для наявних зі зміною, `created` для нових, `deleted` для прибраних | так |
| `->detach($w)` | `deleted` | `−quantity` `adjustment` |
| `->updateExistingPivot()` без змін | — (нічого не dirty, `save()` не викликається) | — |
| `$stock->save()`, `$stock->update()`, `$stock->increment()` | `updated` | так |
| `Stock::whereKey(…)->update()`, `$stocks->toQuery()->update()` | **немає** | **немає** |
| `DB::table('stocks')->…` | **немає** | **немає** |
| `saveQuietly()`, `Stock::withoutEvents(fn …)` | **немає** | **немає** |

Без `->using()` (звичайний pivot) `attach`/`updateExistingPivot` — це запити, подій немає.
Тести: `tests/Feature/Models/EventsTest.php`.

**Сидери** подій не викликають двічі: масові вставки `DB::table()->insert()` обходять модель, а
`DatabaseSeeder` ще й має `WithoutModelEvents` (вимикає диспетчер навіть для `Model::create()`).
Тому `StockSeeder` пише рухи `receipt` сам, а сидери жанрів і тегів задають слаги явно.

### Масове присвоєння

- `#[Fillable]` — білий список для `create()`/`fill()`/`update()`. `role` у `User` **не** fillable:
  інакше `User::create($request->all())` з полем `role=admin`, дописаним у DevTools, створив би
  адміністратора. Роль призначають явно: `$user->role = UserRole::Admin`.
- `LabUser` має `$guarded = []` (сучасний аналог — `#[Unguarded]`): той самий ввід дає `role = admin`.
- Суворий режим (`Model::shouldBeStrict()` → `preventSilentlyDiscardingAttributes`) перетворює
  відкидання не-fillable атрибута на `MassAssignmentException`: помилку видно одразу, а не через
  тиждень «чомусь не зберігається». Без суворого режиму атрибут мовчки ігнорується.
- Фабрики працюють у `Model::unguarded()`, тож `UserFactory` задає `role` попри `#[Fillable]`.
- Наступний крок — валідація й `$request->validated()` (етап 8).

## Вправа

1. Додайте касти для `stocks` або `Review::rating` (наприклад, власний `Rating` value object з
   `stars()` → «★★★★☆») і виведіть його на сторінці книги. Перевірте: тест, що `toArray()` віддає
   зручну структуру, а `DB::table('reviews')` — сире число.
2. Додайте до `StockObserver` обробку `deleted` (рух `adjustment` на `−quantity`), а до каталогу —
   параметр `?q=` через скоуп `search()`. Перевірте: розширте `EventsTest` (рядок про `detach`) і
   `CatalogTest`; `sail artisan test`.
