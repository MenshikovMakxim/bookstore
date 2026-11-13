# 07 — Автентифікація, middleware, політики, адмінка

## Мета

Побудувати вхід, реєстрацію й підтвердження пошти власноруч (без стартових наборів), розібратися,
як проміжне ПЗ (middleware) обгортає запит, і розвести два рівні доступу: **маршрут** (middleware:
«хто взагалі може зайти в цей розділ») і **ресурс** (політики: «чи може цей користувач змінити
саме цей рядок»). Захистити склад від підміни ідентифікатора в URL (IDOR) і обмежити частоту входу.

## Де дивитися в коді

- `routes/web.php` — області: публічна, `auth/verification`, `manage.*`, `reports.*`, `admin.*`, `lab.*`.
- `bootstrap/app.php` — псевдоніми middleware, `web(append: …)`.
- `app/Http/Controllers/Auth/*` — реєстрація, вхід/вихід, підтвердження пошти.
- `app/Http/Middleware/*` — `EnsureUserHasRole`, `EnsureWarehouseAccess`, `LogSlowRequests`,
  `AddQueryCountHeader`, `LabAccess`.
- `app/Policies/*`, `app/Providers/AppServiceProvider.php` — `Gate::before`, гейт `view-reports`,
  іменовані обмежувачі частоти, `Blade::if('role')`.
- `app/Http/Controllers/Admin/BookController.php` (атрибути `#[Authorize]`) і
  `Admin/ReviewController.php` (`HasMiddleware`).
- `app/Http/Controllers/Manage/*` — склади, редагування залишку, історія рухів.
- `tests/Feature/{Auth,Admin,Manage}/*`, `tests/Feature/Web/RoutesTest.php`.

Облікові записи з сидерів (пароль `password`, пошту вже підтверджено): `admin@example.com`,
`manager1..5@example.com` (менеджер складу №1..5), читачі — будь-яка інша адреса з таблиці `users`.

## Ключові поняття

### Потік автентифікації

| Крок | Що відбувається |
|---|---|
| `POST /register` | `validate()` → `User::create($data)` (лише fillable: `role` не потрапить, хоч би що прийшло у формі) → `event(new Registered)` → фреймворк надсилає лист `VerifyEmail` → `Auth::login()` + `session()->regenerate()` |
| `POST /login` | middleware `throttle:login` → `Auth::attempt($credentials, $remember)` → `session()->regenerate()` (захист від фіксації сесії) → `redirect()->intended()` — туди, куди гість ішов до входу |
| `POST /logout` | `Auth::logout()` → `session()->invalidate()` → `regenerateToken()` (новий CSRF-токен) |
| Лист | підписаний URL `verification.verify` з терміном дії; `EmailVerificationRequest` перевіряє, що `{id}/{hash}` належать поточному користувачеві; `fulfill()` ставить `email_verified_at` |

Одна помилка «облікові дані не збігаються» і для неіснуючої пошти, і для хибного пароля — не
розкриваємо, які адреси зареєстровані. `User implements MustVerifyEmail`: middleware `verified`
не пускає непідтверджених у `/manage`, `/reports`, `/admin` і перенаправляє на `verification.notice`.
Локально листи ловить Mailpit: <http://localhost:8025>.

### Життєвий цикл middleware

```text
запит → [web: cookies, session, CSRF, SubstituteBindings, …, LogSlowRequests, AddQueryCountHeader]
      → [маршрут: auth → verified → role:… → warehouse.access → can:…]
      → контролер → відповідь назад через ті самі шари (у зворотному порядку)
      → відповідь надіслано → terminate() термінальних middleware
```

- **«До»** — код перед `$next($request)`: `EnsureUserHasRole` (403), `EnsureWarehouseAccess`, `LabAccess`.
- **«Після»** — код після `$next()`: `AddQueryCountHeader` додає `X-Query-Count` до готової відповіді
  (лише `local`; дешевий сигнал N+1 з етапу 4 у вкладці «Мережа»).
- **Термінальне** — `terminate()` після надсилання відповіді: `LogSlowRequests` пише в лог запити
  довші за `SLOW_REQUEST_MS` (`config('app.slow_request_ms')`), користувач на це не чекає.
  Laravel створює middleware для `terminate()` **заново** з контейнера — без
  `$this->app->singleton(LogSlowRequests::class)` це новий об’єкт без часу старту, і нічого не
  логується (тест `without the singleton binding …` це показує).

### Реєстрація й параметри

```php
// bootstrap/app.php
$middleware->alias(['role' => EnsureUserHasRole::class, 'warehouse.access' => …, 'lab' => LabAccess::class]);
$middleware->web(append: [LogSlowRequests::class, AddQueryCountHeader::class]);
```

Параметри — після двокрапки, через кому: `role:manager,admin` → `handle($request, $next, 'manager', 'admin')`.
`UserRole::from()` у middleware кидає виняток на описці (`role:admn`) — краще гучна помилка, ніж маршрут,
куди мовчки ніхто не потрапляє.

### Middleware у контролері: атрибут проти `HasMiddleware`

```php
// Laravel 13: Illuminate\Routing\Attributes\Controllers\{Middleware, Authorize}
#[Authorize('manage', Book::class)]
#[Authorize('forceDelete', 'book', only: ['forceDelete'])]   // 'book' — параметр маршруту
class BookController extends Controller { … }

// Класичний спосіб (Admin\ReviewController):
class ReviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:approve,review', only: ['approve'])];
    }
}
```

Атрибут стоїть поруч із класом/методом і читається як декларація; `middleware()` — звичайний PHP-код
(можна будувати список умовно). Результат однаковий: middleware маршруту + middleware контролера.

### Middleware проти політики: склад

| | `warehouse.access` (middleware) | `WarehousePolicy::view` / `StockPolicy::update` |
|---|---|---|
| Рівень | маршрут: усе під `/manage/warehouses/{warehouse}/…` | ресурс: конкретний склад/рядок залишку |
| Де діє | лише в HTTP-запиті з параметром `{warehouse}` | будь-де: `@can`, `Gate::allows()`, `can:` у маршруті, черги, консоль |
| Відповідь | 403 до контролера | `false` → 403 через `can:` або приховування кнопки |

Правило «менеджер — лише свій склад» записане в обох місцях: middleware закриває піддерево URL
одним рядком, політика відповідає на `@can('update', $stock)` у списку й на `can:update,stock`.

Редагування залишку має ще одне правило стану: `UpdateStockRequest::after()` забороняє причину
`receipt`, якщо кількість зменшується. Зміна лише `shelf_code` не потребує причини й не створює
руху; `StockObserver` реагує тільки на зміну `quantity`.

### Скоуповані прив’язки як захист від IDOR

```php
Route::prefix('/warehouses/{warehouse}')->middleware('warehouse.access')->group(function () {
    Route::scopeBindings()->middleware('can:update,stock')->group(function () {
        Route::get('/stocks/{stock}/edit', …);
    });
});
```

Менеджер складу 1 підставляє в URL id залишку складу 2: `/manage/warehouses/1/stocks/{чужий id}/edit`.
`scopeBindings()` шукає `{stock}` через `$warehouse->stocks()` → **404**, до контролера справа не доходить.
Якщо підставити й чужий склад — `warehouse.access` → **403**. Третій рубіж — `can:update,stock`.

### Гейти проти політик; пастки `Gate::before`

- **Гейт** — правило без моделі: `Gate::define('view-reports', …)` (менеджер або адмін) → маршрути
  `can:view-reports` і `@can('view-reports')` у меню. `export-csv` — окремий гейт лише для адміна:
  HTML-звіт доступний менеджеру, а сирий CSV — ні; посилання сховані через `@can('export-csv')`.
- **Політика** — правила однієї моделі, знаходяться автоматично (`Book` → `App\Policies\BookPolicy`).
  Кожен метод сам перевіряє роль адміна — політика читається й тестується окремо.
- **`Gate::before`**: адмін → `true`, інакше `null` («вирішуй далі»). **Ніколи `false`**: це відібрало б
  в адміна те, що політика дозволяє, і сховало б помилки (політика навіть не викликається).
- **Пастка, яку зловили тести:** `before` повертає `true` ще до політики, тож обходить і **правила
  стану даних**: адмін зміг «назавжди видалити» книгу, що не в кошику (`BookPolicy::forceDelete`
  вимагає `trashed()`). Тому `restore`, `forceDelete`, `approve` і «один відгук на книгу»
  (`create` для `Review`) виключені з обходу.
- `ReviewPolicy::create` рахує й відгуки на модерації — через `withoutGlobalScope(ApprovedScope::class)`.

Blade: `@auth`/`@guest` (вхід/вихід у меню), `@can`/`@cannot` на кнопках дій,
власна директива `@role('manager', 'admin')` (`Blade::if`).

### Обмеження частоти

```php
RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)
    ->by(mb_strtolower($r->input('email')).'|'.$r->ip())
    ->response(fn () => back()->withErrors(['email' => __('auth.throttle', …)])));
```

Ключ «пошта + IP»: перебір пароля одного облікового запису з однієї адреси зупиняється, а
користувачі за спільним NAT не блокують одне одного. Ліміт рахує **кожну** спробу, тож шоста
не пройде навіть із правильним паролем. Обмежувач `reviews` уже зареєстровано для форми відгуків
(етап 8). Посилання підтвердження й повторне надсилання — `throttle:6,1`.

> **Етап 8:** обмеження входу переїхало в `App\Http\Requests\Auth\LoginRequest` і тепер рахує
> лише **невдалі** спроби (успішний вхід скидає лічильник) — див. [08-forms-validation.md](08-forms-validation.md).

### Інше

- Публічна сторінка книги показує лише статус наявності; кількості по складах — у `/manage`.
  Публічні сторінки `/warehouses` прибрано: це службові дані.
- `books.published_at` — стовпець `TIMESTAMP` (1970–2038), тож форма книги валідує дату в цих межах.
- Валідація тут ще в контролерах (`$request->validate()`); на етапі 8 вона переїхала у Form Request.

## Вправа

1. Додайте менеджерові право редагувати `shelf_code` без зміни кількості, але заборонте причину
   `receipt`, якщо кількість зменшується. Перевірте: тест у `tests/Feature/Manage/StockManagementTest.php`.
2. Додайте гейт `export-csv` лише для адміна й застосуйте його до `reports.stock-csv`, приховавши
   посилання через `@can`. Перевірте: менеджер отримує 403 на CSV і 200 на HTML-звіт.
