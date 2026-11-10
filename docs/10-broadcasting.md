# 10 — Трансляція подій: Reverb, Echo, канали

## Мета

Доставити подію із сервера в браузер без перезавантаження сторінки: **трансляція подій**
(broadcasting) через WebSocket-сервер Laravel Reverb і клієнт Laravel Echo. Побачити, що трансляція —
це той самий механізм подій і слухачів з етапу 9, лише з чергою й мережею посередині. Розібратися з
публічними й приватними каналами, авторизацією підписки, `toOthers()` і тим, що саме не працює без
обробника черги.

## Де дивитися в коді

- `compose.yaml` — сервіс `reverb` (`reverb:start --debug`), обробник `queue` тепер слухає й
  чергу `broadcasts` (першою).
- `.env.example` — `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=reverb` (для PHP, всередині мережі
  Docker) проти `VITE_REVERB_HOST=localhost` (для браузера).
- `config/broadcasting.php`, `config/reverb.php`, `bootstrap/app.php` (`channels:` у `withRouting`) —
  створено командою `install:broadcasting --reverb`.
- `routes/channels.php` — авторизація приватних каналів.
- `app/Events/BookStockChanged.php` + `app/Observers/StockObserver.php` — живий бейдж наявності.
- `app/Events/ImportProgressed.php`, `app/Jobs/ImportBooksChunk.php`, `app/Models/Import.php`,
  `resources/views/admin/imports/show.blade.php` — прогрес імпорту: push замість опитування.
- `app/Notifications/PendingReviewNotification.php` — канал `broadcast`; дзвіночок у
  `resources/views/components/layouts/app.blade.php` (`data-bell data-user-id`).
- `resources/js/echo.js`, `resources/js/live.js` (дзвіночок, бейджі), `resources/js/lab-realtime.js`.
- `app/Providers/AppServiceProvider.php` — `Queue::route(ShouldBroadcast::class => broadcasts)`,
  `configureLab()` (слухачі лабораторії).
- Лабораторія `/lab/realtime`: `app/Http/Controllers/Lab/LabRealtimeController.php`, `app/Lab/Realtime/*`.
- `tests/Feature/Broadcasting/*`.

## Ключові поняття

### Як доходить повідомлення

```
запит ──event()──► Dispatcher ──► ShouldBroadcast? ──► задача BroadcastEvent у черзі broadcasts
                                                            │
                                         обробник (worker) ─┘──HTTP──► Reverb ──WebSocket──► браузер (Echo)
```

- **Reverb** — окремий процес (`sail artisan reverb:start`), що тримає WebSocket-з’єднання з
  браузерами. PHP не говорить із браузером напряму: він робить HTTP-запит до Reverb («надішли це в
  канал X»), а Reverb розсилає повідомлення підписникам.
- Reverb говорить протоколом Pusher, тому на клієнті — `laravel-echo` + `pusher-js`, а на сервері —
  драйвер `reverb` (обгортка над `pusher-php-server`).
- Дві адреси одного сервера: PHP-контейнери звертаються до `reverb:8080` (ім’я сервісу), браузер —
  до `localhost:8080` (перенаправлений порт). Звідси `REVERB_HOST` ≠ `VITE_REVERB_HOST`.
- `VITE_*` вшиваються в JS **під час збирання**: після зміни ключів — `sail npm run build`.
  Без `VITE_REVERB_APP_KEY` `echo.js` не створює `window.Echo`, і сторінки просто працюють без
  живих оновлень.

### `ShouldBroadcast` проти `ShouldBroadcastNow`

| | `ShouldBroadcast` | `ShouldBroadcastNow` |
|---|---|---|
| де надсилається | обробником черги (`broadcasts`) | у тому самому процесі (запит або задача) |
| без обробника | **не доходить** — лежить у `jobs` | доходить |
| Reverb вимкнено | падає задача (повтори, `failed_jobs`) | падає запит (`BroadcastException`), якщо немає `ShouldRescue` |
| ціна для запиту | один `INSERT` | HTTP-запит до Reverb |

«Сокети не працюють» найчастіше означає «не запущено обробник»: `ShouldBroadcast` — це звичайна
задача в черзі. `Queue::route([ShouldBroadcast::class => QueueName::Broadcasts])` — ключем може бути
інтерфейс, тож правило покриває всі трансляції, включно з `BroadcastNotificationCreated` каналу
сповіщень. Черга `broadcasts` іде першою в `--queue=...`: живе оновлення, що чекає за імпортом
CSV, уже не живе.

`ShouldRescue` (`ImportProgressed`, `LabTrace`): виняток трансляції записується в лог і
ковтається — мертвий WebSocket-сервер не має валити імпорт.

### Що потрапляє в повідомлення

- Без `broadcastWith()` — **усі публічні властивості** події (моделі серіалізуються через
  `toArray()`, тобто з урахуванням `#[Hidden]`, але з усіма завантаженими зв’язками). З
  `broadcastWith()` — лише те, що повернули. Ліміт розміру повідомлення в Reverb —
  `REVERB_MAX_REQUEST_SIZE` (10 КБ за замовчуванням).
- `broadcastWith()` виконується **там, де надсилається**: для `ShouldBroadcast` — на обробнику.
  `BookStockChanged` рахує суми залишків саме там, тож дані актуальні на момент надсилання.
- Суворий режим: у `broadcastWith()` не можна торкатися незавантажених зв’язків — на обробнику
  це `LazyLoadingViolationException`. Тому в подію передаємо `book_id`, а не модель зі зв’язками.
- `broadcastAs()` задає ім’я події на клієнті: `.stock.changed` (з крапкою — «без простору імен»).
  Без нього Echo слухав би `App\Events\BookStockChanged`.
- `broadcastWhen()` перевіряється **до** постановки в чергу. `StockObserver` позначає зміни з
  імпорту (`fromImport`), і `BookStockChanged` їх не транслює: інакше 5000 рядків CSV — 5000
  повідомлень кожному відкритому каталогу.
- `ShouldDispatchAfterCommit`: зміна залишку, яку відкотили, не транслюється.

### Сигнал проти даних: прогрес імпорту

`ImportProgressed` несе лише `importId`. Отримавши його, сторінка робить той самий `fetch` до
`admin.imports.progress`, що й під час опитування на етапі 9. Плюси: дані йдуть через звичайний
авторизований HTTP, повідомлення крихітне, а код показу не дублюється. Опитування лишилося
запасним варіантом: кожні 2 с без з’єднання, кожні 10 с із ним (на випадок втраченого повідомлення —
WebSocket не гарантує доставку).

### Канали й авторизація

| Канал | Клас | Хто може підписатися |
|---|---|---|
| `warehouse`, `lab.timeline` | `Channel` | будь-хто, без перевірок |
| `private-App.Models.User.{id}` | `PrivateChannel` | власник — `routes/channels.php` |
| `private-imports.{import}` | `PrivateChannel` | адміністратор |

Перед підпискою на приватний канал Echo робить `POST /broadcasting/auth` (сесійна кука + CSRF з
`<meta name="csrf-token">`). Laravel викликає замикання з `routes/channels.php`: `true` → 200 і
підпис, який Reverb перевіряє; `false` або гість → 403. Параметри імені каналу працюють як
параметри маршруту, з прив’язкою моделей (`Import $import`: неіснуючий імпорт → 403).

Це той самий IDOR з етапу 7: ім’я каналу задає клієнт, тож без перевірки будь-хто підписався б на
чужі сповіщення, просто змінивши `id`. У лабораторії це видно на кнопці «Чужий приватний канал».

### `toOthers()` і `X-Socket-ID`

Кожне з’єднання має socket id (`Echo.socketId()`). Якщо запит приніс його в заголовку
`X-Socket-ID`, `broadcast($event)->toOthers()` (трейт `InteractsWithSockets`) передає його в Reverb, і
той пропускає це з’єднання. Типовий випадок: автор дії вже оновив свою сторінку сам, а інші вкладки
мають отримати подію. Echo додає заголовок до запитів axios; для `fetch()` ставимо його вручну
(`lab-realtime.js`).

### Сповіщення через `broadcast`

`via()` → `['mail', 'database', 'broadcast']`. Без `toBroadcast()` Laravel бере `toArray()` (як і
для `database`). Повідомлення йде в приватний канал `App.Models.User.{id}`
(`receivesBroadcastNotificationsOn()` за замовчуванням), а на клієнті —
`Echo.private(...).notification(cb)`. Дзвіночок збільшує лічильник без перезавантаження.

### Лабораторія `/lab/realtime`

Відкрийте її у двох вікнах (друге — інкогніто). Стрічка показує канал, подію, хто надіслав
(`web#pid` — запит, `worker#pid` — обробник) і затримку.

1. **Подія й слухачі** — `LabSignal` зі слухачами sync / `ShouldQueue` / `#[Delay(5)]`. Зупиніть
   обробник (`sail stop queue`): лишаються тільки кроки `web`. `return false` у синхронному
   слухачі зупиняє решту — і в чергу нічого не потрапляє.
2. **`ShouldBroadcast` проти `ShouldBroadcastNow`** — «у черзі N мс» = час між створенням події в
   запиті й надсиланням з обробника. Без обробника перша кнопка мовчить. Зупиніть Reverb
   (`sail stop reverb`) — друга кнопка поверне помилку.
3. **`toOthers()`** — у вікні, що натиснуло кнопку, повідомлення немає, у сусідньому є.
4. **Залишок ±1** — відкрийте демо-книгу в іншому вікні: бейдж змінюється сам.
5. **`notify()` → broadcast** — сповіщення у власний приватний канал (потрібен вхід).
6. **Чужий канал** — 403 від `/broadcasting/auth`.
7. **Імпорт CSV** (`/admin/imports`) — прогрес оновлюється за сигналом, а не за таймером.

Кадри WebSocket видно в `sail logs -f reverb` (`--debug`).

### Тести

- Трансляції в тестах вимкнено (`BROADCAST_CONNECTION=null`): перевіряємо **що** і **куди**
  ставиться (`Event::fake()`, `Queue::fake()` + `assertPushedOn('broadcasts', BroadcastEvent::class)`),
  `broadcastOn()` / `broadcastWith()` / `broadcastWhen()` — як звичайні методи.
- Авторизацію каналів (`ChannelAuthTest`) перевіряємо справжнім `POST /broadcasting/auth`: тест
  перемикає драйвер на `reverb` з фейковими ключами (для підпису мережа не потрібна) і повторно
  реєструє `routes/channels.php`.

## Вправа

1. **Канал присутності** «зараз переглядають»: `PresenceChannel('books.{book}')`, у
   `routes/channels.php` поверніть `['id' => $user->id, 'name' => $user->name]` (не `true`), а на
   сторінці книги — `Echo.join(...).here().joining().leaving()`. Перевірте: тест
   `/broadcasting/auth` для `presence-books.1` повертає `channel_data` з іменем користувача.
2. **Клієнтські події (whisper)** «модератор друкує…»: `channel.whisper('typing', {...})` і
   `.listenForWhisper('typing', cb)` на приватному каналі. Переконайтеся в `sail logs -f reverb`,
   що такі повідомлення не проходять через Laravel (їх немає в `jobs` і в логах застосунку). Чому
   whisper можна робити лише в приватних і presence-каналах?
3. Транслюйте `ReviewApproved` у публічний канал книги, щоб рейтинг на її сторінці оновлювався після
   модерації. Де краще надсилати — у самій події чи після `RecalculateBookRating`? (підказка:
   рейтинг перераховує задача в черзі `ratings`).
