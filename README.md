# Книжковий склад (Bookstore Warehouse)

Навчальний проєкт на Laravel 13. Крок за кроком будуємо невеликий книжковий склад (книги,
залишки, постачальники, замовлення); кожен етап демонструє групу можливостей Laravel.
Конспекти уроків — у [`docs/`](docs), терміни — у [глосарії](docs/glossary.md).

## Стек

Laravel 13 · PHP 8.3+ · MySQL 8.4 · Mailpit · Laravel Reverb · Laravel Sail · Blade · Pest · Debugbar

## Запуск

Потрібні Docker і PHP/Composer на хості (або встановіть `vendor` через контейнер Sail).

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
npm install && npm run build              # Tailwind assets (or `npm run dev`)
```

Обсяг демонстраційних даних задає `SEED_SCALE` (`small` за замовчуванням, `full` ≈ 50 тис. книг /
200 тис. відгуків, ~1,5–2 хв):

```bash
docker compose exec -e SEED_SCALE=full laravel.test php artisan migrate:fresh --seed
```

Облікові записи з сидерів (пароль `password`, пошту вже підтверджено):

| Пошта | Роль | Доступ |
|---|---|---|
| `admin@example.com` | адміністратор | усе: `/admin`, `/manage` (усі склади), `/reports`, лабораторії |
| `manager1@example.com` … `manager5@example.com` | менеджер складу №1…5 | `/manage` (лише свій склад), `/reports` |
| інші адреси з таблиці `users` | читач | публічні сторінки |

Нові користувачі реєструються на `/register` і підтверджують пошту листом — локально його видно
в Mailpit (<http://localhost:8025>).

Порада: `alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'`.

| Сервіс | Адреса |
|---|---|
| Застосунок | <http://localhost> |
| Mailpit | <http://localhost:8025> |
| Reverb (WebSocket) | `ws://localhost:8080`, кадри: `sail logs -f reverb` |
| Лабораторія зв’язків | <http://localhost/lab/relations> |
| Лабораторія N+1 | <http://localhost/lab/n-plus-one> |
| Лабораторія колекцій | <http://localhost/lab/collections> |
| Лабораторія моделей | <http://localhost/lab/models> |
| Лабораторія XSS | <http://localhost/lab/xss> |
| Лабораторія черг | <http://localhost/lab/queues> |
| Лабораторія реального часу | <http://localhost/lab/realtime> |
| Імпорт CSV (адмін) | <http://localhost/admin/imports> |
| Склади (менеджер/адмін) | <http://localhost/manage/warehouses> |
| Адмінка | <http://localhost/admin> |
| Звіти (менеджер/адмін) | <http://localhost/reports/stock>, <http://localhost/reports/top-authors>, `/reports/stock.csv` |
| MySQL (з хоста) | `127.0.0.1:3307`, користувач `sail` / `password` |

Корисні команди:

```bash
./vendor/bin/sail artisan test   # Pest test suite
./vendor/bin/sail pint           # code style
./vendor/bin/sail artisan queue:restart   # after PHP changes: workers reload the code
./vendor/bin/sail artisan import:make-sample --rows=5000 --errors=5   # CSV for /admin/imports
./vendor/bin/sail down           # stop containers
```

`MODEL_STRICT` (за замовчуванням `true`) вмикає суворий режим Eloquent — див.
[docs/00-setup.md](docs/00-setup.md).

Черга й планувальник працюють як сервіси Sail `queue` (`queue:work --queue=ratings,imports,notifications,default`)
і `scheduler` (`schedule:work`). Обробник тримає код у пам’яті: **після змін PHP-коду виконайте
`sail artisan queue:restart`**. Див. [docs/09-queues.md](docs/09-queues.md).

## Мова інтерфейсу

Інтерфейс, лабораторії та документація — українською; код, ідентифікатори й коментарі в коді —
англійською.

- `APP_LOCALE=uk`, `APP_FALLBACK_LOCALE=en`, `APP_FAKER_LOCALE=uk_UA`.
- Тексти інтерфейсу — у `lang/{uk,en}/{ui,lab,reports,enums}.php`, у шаблонах — лише `__()` і
  `trans_choice()` (українські форми множини: 1 книга / 2 книги / 5 книг).
- Повідомлення фреймворку (`validation`, `auth`, `pagination`, `passwords`) — пакет
  `laravel-lang/common` (`php artisan lang:add uk`). Команда `lang:update` може перезаписати
  ручні правки в цих файлах.
- Назви enum — метод `label()`; ціни — у гривнях: `$book->price->format()` (об’єкт-значення `Money`,
  у базі — копійки; для сирих сум зі звітів — `Money::fromCents($cents)->format()`).
- Слаги завжди ASCII: `Str::slug($value, '-', 'uk')` («Тіні забутих предків» → `tini-zabutykh-predkiv`).

## Карта уроків

| Етап | Тема | Конспект |
|---|---|---|
| 0 | Налаштування проєкту: Sail, Debugbar, суворий режим, черга в базі даних | [docs/00-setup.md](docs/00-setup.md) |
| 1 | Схема БД, моделі (PHP-атрибути), enum, фабрики, сидери | [docs/01-schema.md](docs/01-schema.md) |
| 2 | Зв’язки Eloquent, власна pivot-модель, мапа морфів, `/lab/relations` | [docs/02-relations.md](docs/02-relations.md) |
| 3 | Маршрути (прив’язки, scoped, withTrashed, групи, fallback), макети й компоненти Blade, каталог | [docs/03-routes-blade.md](docs/03-routes-blade.md) |
| 4 | N+1: суворий режим, профайлер, лабораторія з 19 варіантів, тести сталої кількості запитів | [docs/04-n-plus-one.md](docs/04-n-plus-one.md) |
| 5 | Колекції проти Query Builder, chunk/lazy/cursor, звіти, потокове CSV | [docs/05-collections.md](docs/05-collections.md) |
| 6 | Аксесори й мутатори, касти (`Money`, `encrypted`), серіалізація, локальні й глобальні скоупи, події та спостерігачі, масове присвоєння, `/lab/models` | [docs/06-attributes-casts.md](docs/06-attributes-casts.md) |
| 7 | Автентифікація без стартових наборів, підтвердження пошти, middleware (до/після/термінальне), гейти й політики, `Gate::before`, IDOR і scoped bindings, обмеження частоти, адмінка й керування залишками | [docs/07-middleware-auth.md](docs/07-middleware-auth.md) |
| 8 | Form Request (життєвий цикл, `authorize()` через політики, `Rule::enum/exists/unique`, масиви, умовні правила, власне правило й замикання, `after()`, іменовані сумки помилок), компоненти форм, відгуки з модерацією, `/lab/xss` | [docs/08-forms-validation.md](docs/08-forms-validation.md) |
| 9 | Черги (`database`), `Queue::route()`, задачі й повтори, унікальні задачі, події й слухачі, `afterCommit`, ланцюжки й пакети (імпорт CSV), middleware задач, сповіщення (mail + database), планувальник і `Prunable`, `/lab/queues` | [docs/09-queues.md](docs/09-queues.md) |
| 10 | Трансляція подій (broadcasting): Reverb, Echo, `ShouldBroadcast` / `ShouldBroadcastNow`, `toOthers()`, публічні й приватні канали, авторизація в `routes/channels.php`, broadcast-сповіщення, живий залишок і прогрес імпорту, `/lab/realtime` | [docs/10-broadcasting.md](docs/10-broadcasting.md) |
| 11 | _буде згодом_ | |

Нові уроки починайте з шаблону [docs/_template.md](docs/_template.md).
