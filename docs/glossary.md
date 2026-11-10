# Глосарій

Єдині терміни для інтерфейсу, лабораторій і документації. Перекладаючи нові тексти, беріть
слова звідси. Терміни Laravel під час першої появи в тексті подаємо з англійським оригіналом
у дужках, далі — українською (або в оригіналі, якщо це ідентифікатор коду).

## Предметна область

| English | Українською | Примітка |
|---|---|---|
| warehouse | склад | |
| warehouse manager | менеджер складу | роль `manager` |
| reader | читач | роль `reader` |
| administrator | адміністратор | роль `admin` |
| stock (stock level) | залишок, залишки | рядок таблиці `stocks` — «залишок книги на складі» |
| quantity | кількість | |
| reserved | резерв, у резерві | `stocks.reserved` |
| available | доступно | `quantity - reserved` |
| in stock / out of stock / low stock | в наявності / немає в наявності / закінчується | статуси `StockBadge` |
| shelf, shelf code | полиця, код полиці | `stocks.shelf_code` |
| stock movement | рух залишків | таблиця `stock_movements` |
| receipt | надходження | причина руху `receipt` |
| sale | продаж | причина руху `sale` |
| adjustment | коригування | причина руху `adjustment` |
| import | імпорт | причина руху `import` |
| write-off | списання | від’ємне коригування |
| unit, copy | примірник | одиниця кількості |
| title (as a count) | назва (позиція) | «12 назв, 340 примірників» |
| book | книга | |
| author / co-author / translator | автор / співавтор / перекладач | ролі в `author_book` |
| credit | участь у книзі (кредит) | рядок `author_book`; в коді — `credit` |
| genre / sub-genre | жанр / піджанр | |
| main genre / secondary genre | основний жанр / додатковий жанр | `books.genre_id` / `book_genre` |
| root genre | кореневий жанр | жанр без батьківського |
| review | відгук | |
| approved review | схвалений відгук | `is_approved = true` |
| rating | оцінка, рейтинг | оцінка — одного відгуку; рейтинг — середнє |
| cover | обкладинка | зображення типу `cover` |
| hardcover / paperback / e-book | тверда обкладинка / м’яка обкладинка / електронна книга | `BookFormat` |
| photo / gallery | фото / галерея | `ImageType` |
| tag | тег | |
| catalog | каталог | |
| price | ціна | зберігаємо в копійках, показуємо в гривнях (₴) |
| report | звіт | |

## Laravel і бази даних

| English | Українською (перша згадка) |
|---|---|
| eager loading | жадібне завантаження (eager loading) |
| lazy loading | ліниве завантаження (lazy loading) |
| N+1 query problem | проблема N+1 запитів |
| strict mode | суворий режим (strict mode) |
| pivot table | проміжна таблиця (pivot) |
| custom pivot model | власна pivot-модель |
| relationship | зв’язок |
| polymorphic relation | поліморфний зв’язок |
| morph map | мапа морфів (morph map) |
| route model binding | прив’язка моделі до маршруту (route model binding) |
| scoped binding | вкладена прив’язка (scoped binding) |
| soft delete | м’яке видалення (soft delete) |
| query builder | конструктор запитів (Query Builder) |
| collection | колекція |
| hydration | гідратація (створення моделей із рядків) |
| chunk | порція |
| cursor | курсор |
| accessor / mutator | аксесор / мутатор |
| cast | приведення типів (cast) |
| custom cast / value object | власний каст / об’єкт-значення (value object) |
| encrypted cast | шифрований каст |
| serialization | серіалізація |
| scope | скоуп (scope) |
| local scope / global scope | локальний скоуп / глобальний скоуп |
| model event | подія моделі |
| observer | спостерігач (observer) |
| mass assignment | масове присвоєння (mass assignment) |
| slug | слаг (slug) |
| factory / seeder | фабрика / сидер |
| migration | міграція |
| component / slot / props | компонент / слот / властивості (props) |
| layout | макет (layout) |
| view composer | компонувальник представлення (view composer) |
| fallback route | резервний маршрут (fallback) |
| middleware | проміжне ПЗ (middleware) |
| terminable middleware | термінальне middleware (terminate()) |
| authentication / authorization | автентифікація / авторизація |
| gate / policy | гейт / політика (policy) |
| email verification | підтвердження електронної пошти |
| signed URL | підписаний URL |
| rate limiting | обмеження частоти (rate limiting) |
| IDOR | IDOR (підміна ідентифікатора в URL) |
| queue / job | черга / задача (job) |
| event / listener | подія / слухач (listener) |
| worker | обробник (worker) |
| broadcasting | трансляція подій (broadcasting) |
| broadcaster | транслятор (broadcaster) |
| channel (public / private / presence) | канал (публічний / приватний / присутності) |
| WebSocket | WebSocket (без перекладу) |
| socket id | socket id — ідентифікатор з’єднання |
| polling | опитування (polling) |
| foreign key | зовнішній ключ |
| index | індекс |
| subquery | підзапит |
| transaction / rollback | транзакція / відкат |
| query log | журнал запитів |
| wall time | загальний час |
| peak memory | пікова пам’ять |

## Стиль

- Пишемо природною технічною українською, без дослівного перекладу.
- Назви класів, методів, таблиць, маршрутів і змінних не перекладаємо: `Book::with()`, `stocks`, `books.index`.
- Апостроф — типографський (’): «зв’язок», «пам’ять».
- Лапки — «ялинки»; у коді — як у мові програмування.
- Числа: десяткова кома, пробіл між тисячами в тексті інтерфейсу (форматує `Number`); у таблицях вимірювань документації числа залишаємо як виміряно.
