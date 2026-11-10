# 09 — Черги, задачі, події, сповіщення, планувальник

## Мета

Винести повільну й ненадійну роботу (перерахунок рейтингу, листи, імпорт CSV) з HTTP-запиту в
**чергу** (queue). Розібратися з життєвим циклом задачі (job), повторами, унікальністю,
ланцюжками й пакетами (chain / batch), подіями зі слухачами (events / listeners), сповіщеннями
(notifications) і планувальником (scheduler). Побачити N+1, який задачі приносять із собою через
`SerializesModels`.

## Де дивитися в коді

- `app/Providers/AppServiceProvider.php` — `configureQueues()`: мапа «задача → черга» (`Queue::route()`),
  псевдонім `user` у мапі морфів (таблиця `notifications`).
- `app/Enums/QueueName.php`, `config/queue.php` (`after_commit`, `retry_after`), `config/warehouse.php`.
- `app/Events/Review*.php`, `app/Listeners/*` — події відгуків і слухачі (автовиявлення).
- `app/Jobs/RecalculateBookRating.php` (унікальна задача), `RecalculateBookRatings.php`,
  `app/Console/Commands/RecalculateRatings.php` (`books:recalculate-ratings`).
- `app/Jobs/CheckLowStock.php` + `app/Notifications/LowStockNotification.php`, `routes/console.php` (розклад).
- Імпорт: `app/Http/Controllers/Admin/ImportController.php`, `app/Jobs/{ValidateImportFile,ImportBooksChunk,SendImportReport}.php`,
  `app/Imports/BookCsv.php`, `app/Models/Import.php`, `app/Console/Commands/MakeImportSample.php`.
- `app/Notifications/*`, `resources/views/mail/*` (Markdown-листи), `lang/{uk,en}/mail.php`,
  `app/Http/Controllers/NotificationController.php` (дзвіночок і список).
- Лабораторія `/lab/queues`: `app/Http/Controllers/Lab/LabQueuesController.php`, `app/Lab/Queues/*`.
- `compose.yaml` — сервіси `queue` і `scheduler`.
- `tests/Feature/Queues/*`, `tests/Fixtures/import-*.csv`.

## Ключові поняття

### Синхронно чи в черзі; обробники

`QUEUE_CONNECTION=database`: `dispatch()` лише записує рядок у таблицю `jobs`, а виконує його
окремий процес — **обробник** (worker):

```bash
sail artisan queue:work --queue=ratings,imports,notifications,default --tries=3
```

Порядок у `--queue` — пріоритет: поки є задачі в `ratings`, обробник не бере `default`. У Sail це
робить сервіс `queue` (`compose.yaml`), розклад — сервіс `scheduler` (`schedule:work`).

**Обробник тримає код у пам’яті.** Після зміни PHP-коду: `sail artisan queue:restart` — обробники
завершать поточну задачу й вийдуть, Docker (`restart: unless-stopped`) запустить їх заново.

У тестах `QUEUE_CONNECTION=sync` (`phpunit.xml`): задача виконується одразу, у тому самому процесі,
але **так само серіалізується** — отже, `SerializesModels` і помилки серіалізації видно й у тестах.
Повторів на `sync` немає: перший виняток летить у код, що поставив задачу.

| Спосіб | Коли виконується |
|---|---|
| `Job::dispatch()` | обробником, коли дійде черга |
| `->delay(now()->addSeconds(10))` | обробником, не раніше ніж через 10 с |
| `Job::dispatchSync()` | зараз, у цьому процесі |
| `Job::dispatchAfterResponse()` | у цьому процесі, після надсилання відповіді |
| `Job::dispatchIf($condition)` | лише якщо умова істинна |

### Мапа черг: `Queue::route()`

Laravel 13 дозволяє зібрати «яка задача в яку чергу» в одному місці:

```php
Queue::route([
    RecalculateBookRating::class => QueueName::Ratings,
    ImportBooksChunk::class => QueueName::Imports,
    Notification::class => QueueName::Notifications, // батьківський клас: усі сповіщення
    // ...
]);
```

Маршрут — це значення **за замовчуванням**: атрибут `#[Queue(QueueName::Imports)]` на класі
(`ValidateImportFile`) або `->onQueue('…')` під час постановки мають пріоритет. Маршрути діють і для
слухачів (`ShouldQueue`), і для сповіщень.

### Життєвий цикл задачі

```text
dispatch → [jobs] → обробник резервує рядок → unserialize (SerializesModels: запити!) → middleware() → handle()
   ├─ успіх → рядок видалено
   ├─ виняток, спроби є → release(backoff) → знову в [jobs] через N секунд
   ├─ виняток, спроби скінчилися → failed($e) → [failed_jobs]
   └─ довше за timeout → процес обробника вбито → спроба зараховується
```

`ImportBooksChunk`: `#[Tries(3)]`, `#[Backoff(10, 60)]` (пауза перед 2-ю і 3-ю спробою), `#[Timeout(120)]`.
`retry_after` з’єднання (180 с) має бути **більшим** за найдовший timeout — інакше другий обробник
візьме задачу, яку перший ще виконує. `retryUntil()` (`RetryUntilJob` у лабораторії) — повтори за
часом замість кількості.

Невдалі задачі: `queue:failed`, `queue:retry <uuid>|all`, `queue:forget <uuid>`, `queue:flush`.

### Унікальні задачі: `ShouldBeUnique` проти `ShouldBeUniqueUntilProcessing`

`RecalculateBookRating` унікальна за `uniqueId() = bookId`: десять схвалень поспіль, поки обробник
зайнятий, — один перерахунок. Різниця — **коли знімається блокування**:

- `ShouldBeUnique` — після завершення задачі. Якщо відгук схвалили, поки задача вже рахує `AVG()`,
  нова задача відкидається як дублікат, а щойно порахований середній уже застарів. Оновлення втрачено.
- `ShouldBeUniqueUntilProcessing` — щойно обробник **почав** задачу. Схвалення під час виконання
  ставить новий перерахунок. Саме це потрібно для «перерахуй за актуальними даними».

Блокування живе в кеші (`CACHE_STORE`); у тестах — `array`, тест `ShouldBeUniqueUntilProcessing`
перевіряє це на справжній черзі `database`.

Сам перерахунок — один `UPDATE … SET rating_avg = (SELECT ROUND(AVG(rating), 2) …)`: середнє рахує
SQL, моделі не завантажуються, подій моделей немає. `books:recalculate-ratings` проходить книги
`lazyById()` порціями й ставить **пакет** задач; з `--sync` — рахує в поточному процесі (так робить
`DatabaseSeeder`, тож сортування каталогу «за рейтингом» працює одразу після сидування). Каталог
тепер сортує за збереженим `books.rating_avg`, а не за підзапитом `AVG()` для кожної книги.

### Події й слухачі проти прямого виклику

```php
ReviewCreated::dispatch($review);   // ReviewController::store()
ReviewApproved::dispatch($review);  // Admin\ReviewController::approve()
```

Контролер не знає, хто реагує: слухачі в `app/Listeners` знаходяться автоматично за типом
параметра `handle()` (тип-об’єднання `ReviewApproved|ReviewRejected|ReviewDeleted` реєструє слухача
на всі три події). Пряма постановка задачі (`CheckLowStock::dispatch()`) простіша, коли реакція одна
і належить цьому ж коду; подія — коли реакцій кілька або вони з іншої частини системи.

- Слухачі `ShouldQueue` виконуються в черзі; `shouldQueue($event)` може відмовитися (видалення
  відгуку на модерації не змінює рейтинг — задачу навіть не ставимо).
- Видалені відгуки передаються **ідентифікаторами**, не моделлю: на момент виконання слухача рядка
  вже немає, і `SerializesModels` кинув би `ModelNotFoundException`.
- Відгук на модерації відновлюється попри `ApprovedScope`: `newQueryForRestoration()` ігнорує глобальні скоупи.

### `afterCommit`

Задача, поставлена всередині транзакції, може дійти до обробника **раніше за COMMIT** — і не знайти
рядок або (після ROLLBACK) обробити те, чого ніколи не було.

- `config/queue.php`: `'after_commit' => true` для з’єднання `database` — задачі чекають на COMMIT і
  зникають при ROLLBACK.
- Для однієї задачі: `Job::dispatch()->afterCommit()` (лабораторія, варіант «afterCommit() + відкат»).
- Для подій: інтерфейс `ShouldDispatchAfterCommit` (усі події відгуків).

### Ланцюжки й пакети; ідемпотентність; middleware задач

Імпорт CSV (`/admin/imports`):

```php
Bus::chain([
    new ValidateImportFile($import),                        // зупиняє ланцюжок, якщо файл не той
    Bus::batch($chunks)->allowFailures()                    // ImportBooksChunk × N паралельно
        ->before(...)->then(...)->catch(...)->finally(...),
    new CheckLowStock($import->warehouse_id),
    new SendImportReport($import),
])->dispatch();
```

| | Ланцюжок (chain) | Пакет (batch) |
|---|---|---|
| Порядок | послідовно | паралельно (скільки є обробників) |
| Збій | зупиняє решту | з `allowFailures()` решта продовжує |
| Стан | — | `job_batches`: прогрес, кількість збоїв, `Bus::findBatch($id)` |
| Колбеки | `catch` | `before`, `progress`, `then` (усе успішно), `catch` (перший збій), `finally` (усе завершилося) |

Пакет усередині ланцюжка продовжує ланцюжок зі свого `finally` — тому звіт надсилається й тоді,
коли якась порція впала. Колбеки — серіалізовані замикання: беремо в них лише прості значення
(`$importId`), не `$this` і не моделі.

**Ідемпотентність.** Будь-яку задачу можуть виконати двічі (повтор після винятку; обробник помер
після COMMIT, але до видалення рядка з `jobs`). `ImportBooksChunk`:

- одна транзакція на порцію — виняток відкочує всю порцію;
- рядки, для яких уже є рух `(import_id, source_row)`, пропускаються, а унікальний індекс на цих
  стовпцях не дасть записати рух двічі навіть у гонці;
- помилки рядків зберігаються в JSON **за номером рядка** (повтор перезаписує, а не дописує), лічильники
  перераховуються з даних, а не інкрементуються; паралельні порції оновлюють імпорт під `lockForUpdate()`.

**Middleware задач.** `CheckLowStock::middleware()` → `WithoutOverlapping('warehouse:7')`: якщо нічна
перевірка й перевірка після імпорту того самого складу зустрілися, друга повертається в чергу
(`releaseAfter(30)`), а не шле дубль листа. Middleware працює навколо `handle()` — **після**
десеріалізації.

### `SerializesModels`: N+1 у задачах (обіцяне в етапі 4)

Модель у задачі зберігається як `ModelIdentifier(клас, id, зв’язки)`, а обробник **запитує її знову**
разом з усіма зв’язками, завантаженими на момент постановки. Лабораторія `/lab/queues` міряє це на
боці обробника (`lab_job_runs`, стовпець «Запитів на відновлення»):

| Варіант | Що в задачі | Запитів до `handle()` |
|---|---|---|
| Book з `genre, authors, reviews.user, stocks, tags` | id + перелік зв’язків | 7 (книга + кожен зв’язок) |
| `#[WithoutRelations]` / `->withoutRelations()` | лише id | 1 |
| Колекція з 1000 моделей | 1000 id (малий payload!) | 1, але гідратація 1000 моделей з усіма стовпцями |
| Масив із 1000 id | 1000 чисел | 0; `handle()` бере лише потрібне, порціями |
| Модель видалили до виконання | id | `ModelNotFoundException` → з `#[DeleteWhenMissingModels]` задачу тихо відкинуто |

Висновок: передавайте в задачі **ідентифікатори або знімки даних** (як `LowStockNotification` зі
списком рядків), а моделі — без зв’язків.

### Планувальник і `Prunable`

```php
Schedule::job(new CheckLowStock)->dailyAt('07:00')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [StockMovement::class]])->dailyAt('03:00')->onOneServer();
```

`schedule:work` (або cron `* * * * * php artisan schedule:run`) щохвилини вирішує, що пора запускати;
`schedule:list` показує розклад. `onOneServer()` — блокування в спільному кеші, щоб із кількох серверів
запускав лише один. `StockMovement` — `MassPrunable`: `prunable()` вибирає рухи, старші за
`warehouse.movements_retention_days`, і `model:prune` видаляє їх одним `DELETE` без подій моделей.
Компроміс: старі кількості більше не пояснюються журналом руху.

### Сповіщення та канали

`$user->notify(new LowStockNotification(...))` → `via()` повертає канали:

- `mail` — Markdown-лист (`resources/views/mail/*.blade.php`, компоненти `<x-mail::message>`,
  `<x-mail::table>`, `<x-mail::button>`), тексти — `lang/{uk,en}/mail.php`; локально — Mailpit
  (<http://localhost:8025>);
- `database` — рядок у `notifications` (`toArray()`): зберігаємо **вид і параметри**, а текст
  будуємо під час показу (`ui.notifications.kinds.*`), тож він іде за поточною мовою. Дзвіночок у
  меню — один `COUNT` на сторінку; список без зв’язків — стала кількість запитів.

`ShouldQueue` на сповіщенні: кожен канал — окрема задача в черзі `notifications`; повільний SMTP не
затримує запис у базу.

### Швидкість імпорту: профілювання

Замір: 2000 рядків (5 % з помилками), чанки по 500, один обробник, дані в dev-базі після кількох
попередніх імпортів.

| | мс / чанк | мс / рядок | запитів / чанк |
|---|---|---|---|
| Початково (обробник у контейнері `queue`) | ~23 000 | ~46 | ~5 000 |
| + `XDEBUG_MODE=off` для `queue`, `scheduler`, `reverb` | ~5 000 | ~10 | ~5 000 |
| + запити: слаг одним запитом, автори й залишки — наперед, `attach()` | ~3 300 | ~6,5 | ~2 400 |

Що з’ясувалося:

1. **Найбільша втрата — не в коді.** `sail artisan ...` виконується в `laravel.test`, де compose
   задає `XDEBUG_MODE=off`. Сервіси `queue`, `scheduler` і `reverb` цієї змінної не мали, тож Xdebug
   працював у режимі `develop` (типовий для образу Sail), а це вповільнює кожен виклик функції. Тому
   той самий чанк у tinker ішов 5 с, а в обробнику — 23 с. Перевірка:
   `docker compose exec queue php -r 'var_dump(xdebug_info("mode"));'`. **Профілюйте там, де код
   справді виконується.**
2. **N+1 у `HasSlug`.** На кожен кандидат слага йшов окремий `EXISTS` (`kobzar`, `kobzar-2`, ...).
   Тепер це один запит `slug = ? OR slug LIKE '?-%'`, а вільний суфікс підбирається в PHP.
3. **Пошук на кожен рядок → один запит на чанк.** Автори (`Author::firstOrCreate` для кожного
   імені) і залишки складу (`Stock::firstOrNew`) завантажуються наперед, як уже робилося для
   жанрів і книг. Нові автори створюються як і раніше, через `firstOrCreate`.
4. **`attach()` для нової книги.** `syncWithoutDetaching()` спершу читає наявні зв’язки; у нової книги їх
   немає, тож один багаторядковий `INSERT`.
5. **`increment()` замість `quantity = старе + n`.** `UPDATE ... SET quantity = quantity + ?` атомарний:
   два обробники, що паралельно імпортують ту саму книгу, не перезапишуть надходження одне одного.
   Події моделі спрацьовують, тож `StockObserver` і далі пише рух залишків.

Що лишилося: ~1,4 мс/рядок на валідацію (правила `StoreBookRequest`) і по одному `INSERT` у `books`,
`author_book`, `stocks`, `stock_movements` на рядок. Ці вставки потрібні заради подій моделей і журналу
рухів. Далі масштабуйтеся кількістю обробників: `sail up -d --scale queue=4` (чанки пакета йдуть
паралельно). Тест `chunk: a constant number of queries per row` у `ImportTest` не дає повернутися до
запитів на кожен рядок.

### Horizon

Для Redis-черг є Laravel Horizon: панель, метрики, автомасштабування обробників. Тут черга —
`database`, тож Horizon не використовуємо; роль панелі грають `/lab/queues`, `queue:failed` і
`job_batches`.

## Вправа

1. Додайте подію `ReviewUpdated` (автор змінив відгук на модерації) і слухача, що пише в лог.
   Перевірте: `Event::fake()` + `Event::assertDispatched()` у `tests/Feature/Queues/ReviewEventsTest.php`.
2. Запустіть два обробники (`sail up -d --scale queue=2`) і порівняйте час імпорту 5000 рядків з
   одним. Чому лічильники в `imports` лишаються правильними? (підказка: `lockForUpdate()` і перерахунок).
