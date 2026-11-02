# VELTRA 2.0 — Stage 1B Implementation Report

Data: 2026-11-02  
Repozytorium: `/home/veltra/projekty/veltra`  
Zakres: tylko Stage 1B — Organizations, Locations and Management Relationships.

## 1. Podsumowanie implementacji

Dodano fundament organizacyjny single-tenant do istniejacej aplikacji Laravel:
organizacje instalacji, jednolity model lokalizacji, historie zarzadzania
restauracjami i niezalezne relacje kwalifikowalnosci zaopatrywania.

Jedna instalacja i jedna baza obsluguja jednego klienta komercyjnego. Organizacja
jest korzeniem domeny, a nie tenantem wspoldzielonej platformy. Nie dodano wyboru
organizacji, kont, uwierzytelniania, RBAC ani dostepu miedzy organizacjami.

Reguly sa realizowane w `InstallationService`, modelach Eloquent i PostgreSQL.
Kontrolery odpowiadaja za HTTP, delegujac operacje do serwisu. Dodano 15 tras
pod `/api/v1` oraz jawna, domyslnie wylaczona granice dostepu developerskiego.

Stage 1A zostal zachowany: ten sam frontend, publiczny `/api/health`, porty,
PostgreSQL, Redis i worker. Nie przebudowywano ani nie tworzono aplikacji od nowa.

Wykorzystane wersje: PHP 8.4.27, Laravel 13.39.0, Composer 2.11.1,
PostgreSQL 17.11, Redis 7.4.12, phpredis 6.2.0, Node 24.22.2,
React 19.3.1, TypeScript 7.0.2, Vite 8.3.5.
PHP i Composer nie byly instalowane globalnie w Ubuntu.

## 2. Inspekcja poczatkowa i zgodnosc

Przed edycja sprawdzono strukture aplikacji, Compose, konfiguracje Laravel,
modele, testy i stan migracji:

- Wszystkie piec uslug Stage 1A dzialalo.
- `php artisan test --filter=Health`: 7 passed, 44 assertions.
- Brak migracji domenowych; `migrate:status` zglosil brak tabeli migracji.
  Byl to oczekiwany stan fundamentu Stage 1A, nie awaria PostgreSQL.
- Test integracyjny health wymagal pustego schematu `public`. W Stage 1B
  zmieniono tylko to zalozenie: nadal sprawdza realne polaczenia i brak
  niepotrzebnych tabel infrastrukturalnych `users`, `sessions`, `cache`, `jobs`.
- Testy zostaly odseparowane od bazy instalacji przez `veltra_testing`.

## 3. Tabele i ograniczenia PostgreSQL

Migracja: `2026_11_02_000001_create_installation_structure`.
Zostala wykonana w bazie instalacji i osobnej bazie testowej.
Laravel utworzyl dodatkowo standardowa techniczna tabele `migrations`.

### organizations

- UUID PK; `name`, `code`, `is_active`, timestamps.
- Nazwa niepusta, kod `[A-Z0-9_-]+`, kod unikalny.
- Techniczne `installation=true`, CHECK i UNIQUE wymuszaja jedna organizacje
  lacznie, nie tylko jedna aktywna.
- Pole techniczne nie jest wystawiane w JSON modelu.
- Serwis dodatkowo wykorzystuje transakcyjna blokade doradcza przy inicjalizacji,
  takze zanim istnieje wiersz organizacji.

### locations

- UUID PK; FK `organization_id`; `name`, `code`, `type`, `is_active`, timestamps.
- Wspolna tabela dla `CENTRAL_KITCHEN` i `RESTAURANT`.
- Enum aplikacyjny oraz CHECK SQL sprawdzaja dozwolony typ.
- UNIQUE `(organization_id, code)` i `(id, organization_id)`.
- Trigger zabrania zmiany UUID, typu i organizacji istniejacej lokalizacji.
- FK z `ON DELETE RESTRICT`.

### location_management_assignments

- UUID PK; `organization_id`, `restaurant_id`, `managing_kitchen_id`.
- `effective_from`, nullable `effective_until`, timestamps typu timestamptz.
- Okresy maja granice `[from, until)`, bez nakladania sie; sasiednie okresy
  moga miec identyczny punkt konca/poczatku.
- Rozszerzenie `btree_gist` i EXCLUDE GiST na restauracji oraz `tstzrange`
  blokuja wszystkie nakladajace sie okresy, w tym dwie otwarte relacje.
- CHECK odrzuca puste, odwrocone i nieskonczone okresy.
- Zlozone FK gwarantuja jedna organizacje wszystkich koncow relacji.
- Trigger wymusza restauracje po stronie zarzadzanej i kuchnie po stronie
  zarzadzajacej.
- Historia nie moze byc usuwana. W otwartym okresie mozna tylko jednokrotnie
  ustawic koniec. Tozsamosc, poczatek, referencje i zamkniete okresy sa niezmienne.

### location_supply_relationships

- UUID PK; `organization_id`, `supplier_kitchen_id`, `restaurant_id`.
- `is_active`, timestamps.
- Zlozone FK gwarantuja wspolna organizacje.
- Trigger wymusza kuchnie -> restauracja.
- Czesciowy UNIQUE na parze dostawca/restauracja, tylko `WHERE is_active`,
  zabrania powtorzenia aktywnej relacji.
- Wylaczenie ustawia `is_active=false`, nie usuwa rekordu. Ponowne ustanowienie
  tworzy nowy rekord.

### Aktywacja i wspolbieznosc

- Odroczone constraint triggery sprawdzaja na koncu transakcji, czy kazda aktywna
  restauracja ma aktualne przypisanie do aktywnej kuchni.
- Mozna najpierw przygotowac nieaktywna restauracje bez przypisania.
- Nie mozna wylaczyc kuchni zarzadzajacej aktywna restauracja bez uprzedniej
  zmiany zarzadzania lub dezaktywacji restauracji.
- Modyfikacje sa serializowane blokada wiersza organizacji. Serwis pobiera
  blokade przed odczytem biezacego stanu; odpowiednie triggery obejmuja tez
  bezposrednie zapisy SQL.
- Zmiana kuchni zamyka stary okres i tworzy nowy w jednej transakcji.
- Punkt zmiany pochodzi z `clock_timestamp()` PostgreSQL. Zachowano precyzje
  mikrosekund, aby poprawnie obslugiwac kilka zmian w tej samej sekundzie.

## 4. API i granica developerska

Wszystkie ponizsze sciezki sa wzgledem `/api/v1`:

| Metoda | Sciezka | Funkcja |
|---|---|---|
| GET | `/organization` | Organizacja instalacji |
| POST | `/organization` | Jednorazowa inicjalizacja |
| GET | `/locations` | Lista lokalizacji |
| POST | `/locations` | Utworzenie lokalizacji |
| GET | `/locations/{id}` | Odczyt lokalizacji |
| PATCH | `/locations/{id}` | Zmiana nazwy, kodu lub aktywnosci |
| GET | `/locations/{id}/management-assignments` | Historia zarzadzania |
| GET | `/locations/{id}/managing-kitchen` | Biezaca kuchnia lub null |
| POST | `/locations/{id}/managing-kitchen` | Przypisanie kuchni |
| PUT | `/locations/{id}/managing-kitchen` | Zmiana obecnej kuchni |
| GET | `/locations/{id}/managed-restaurants` | Restauracje zarzadzane przez kuchnie |
| GET | `/locations/{id}/supply-relationships` | Aktywne i wylaczone relacje dostaw |
| POST | `/locations/{id}/supply-relationships` | Ustanowienie relacji dostaw |
| GET | `/locations/{id}/suppliers` | Dostawcy z aktywna relacja |
| DELETE | `/supply-relationships/{id}` | Idempotentne wylaczenie relacji |

Pojedyncze zasoby maja `data`, listy `data`, `links`, `meta`.
Listy sa stronicowane: `page>=1`, `per_page` od 1 do 100, domyslnie 20.
Lista lokalizacji obsluguje filtry typu i aktywnosci.
Walidowane sa UUID, nazwy, kody, typy i wartosci boolean; nieznane pola sa
odrzucane. Nie mozna podac dowolnego `organization_id` ani zmienic typu.

Statusy: 200 odczyt/zmiana, 201 utworzenie, 404 brak zasobu lub wylaczone API,
403 niepoprawny/brakujacy naglowek w poprawnie wlaczonym srodowisku,
422 walidacja (`message`, `errors`), 409 konflikt reguly biznesowej (`message`).
Odpowiedzi o konfliktach SQL nie ujawniaja zapytan ani szczegolow sterownika.

### Ochrona

Kazdy endpoint wymaga jednoczesnie:

1. Srodowiska aplikacji `local`.
2. Jawnego `DEV_MANAGEMENT_ENABLED=true`.
3. Skonfigurowanego `DEV_MANAGEMENT_TOKEN` dlugosci minimum 32 znakow.
4. Naglowka `X-Veltra-Development-Token`, porownywanego przez `hash_equals`.

Brak warunkow 1–3 daje 404, takze poza lokalnym srodowiskiem przy wlaczonym
przelaczniku i poprawnym tokenie. Middleware dziala przed route model binding.
Sekret nie jest przekazywany przez query string ani frontend.

To **nie jest produkcyjne uwierzytelnianie ani autoryzacja**. Nie wystawiac
serwerow developerskich przez publiczny tunel. Porty pozostaly na `127.0.0.1`.
Relacja dostaw nie nadaje zadnego dostepu do API lub danych restauracji.

Skrypt `scripts/configure-management.php --enable|--disable` jawnie aktualizuje
lokalny `.env`, generuje brakujacy losowy token, zachowuje pozostale sekrety
i uprawnienia 0600 oraz nie wypisuje tokenu. README zawiera polecenia kontenerowe.
Po weryfikacji **API zostalo ponownie wylaczone**; kontenery odtworzono.

## 5. Wdrozone reguly biznesowe i decyzje zakresowe

- Organizacja jest inicjalizowana raz. Nawet dezaktywacja pierwszej organizacji
  nie pozwala zalozyc drugiej.
- Kuchnia domyslnie jest aktywna, restauracja nieaktywna.
- Aktywacja operacyjna restauracji wymaga biezacej aktywnej kuchni.
- Zarzadzanie moze dotyczyc tylko wlasciwych typow i tej samej organizacji.
- API wykonuje zmiany natychmiast. Nie dodano harmonogramowania, backdatingu
  ani edycji historii.
- POST odrzuca probe przypisania drugiego obecnego zarzadcy; PUT zmienia
  istniejacego i zachowuje historie.
- Wiele kuchni moze zaopatrywac wiele restauracji niezaleznie od zarzadzania.
- Aktywna para dostaw moze istniec tylko raz.
- Wylaczenie dostaw nie zmienia zarzadzania.
- Brak biznesowej logiki w seederach i brak automatycznych danych przykladowych.

## 6. Rzeczywiscie wykonane polecenia i wyniki

### Uruchomienie i migracje

- `docker compose up -d --build`: sukces.
- `docker compose up -d --wait --wait-timeout 120`: sukces.
- Utworzono osobna baze PostgreSQL `veltra_testing` przez `createdb`.
- `docker compose exec -T backend php artisan migrate --force`: sukces,
  migracja w bazie instalacji.
- `docker compose exec -T -e DB_DATABASE=veltra_testing backend php artisan migrate --force`:
  sukces, migracja w bazie testowej.
- `docker compose exec -T -e DB_DATABASE=veltra_testing backend php artisan migrate:rollback --step=1 --force`:
  sukces, sprawdzenie down tylko w bazie testowej.
- Ponowne `migrate --force` w bazie testowej: sukces.
- `docker compose exec -T backend php artisan migrate:status`: migracja Ran.

### Testy automatyczne

Ostatni pelny przebieg:

`docker compose exec -T backend composer test`

**44 passed, 278 assertions, 3.43 s. Bez ostrzezen i pominietych testow.**

Po rollback i ponownej migracji:

`docker compose exec -T backend php artisan test --filter='Installation|ManagementConcurrency|Health'`

**42 passed, 276 assertions, 3.40 s.**

Pokryto wszystkie wymagane obszary:

- inicjalizacja, UUID i singleton organizacji w serwisie i SQL;
- tworzenie kuchni/restauracji, typy, kody, aktualizacje i relacje Eloquent;
- aktywacja restauracji, nieaktywna kuchnia, odroczona kontrola SQL;
- przypisywanie, nakladanie okresow, dwoch zarzadcow, sasiednie okresy;
- zachowanie i niezmiennosc historii, blokada usuwania;
- niewlasciwe typy i relacje miedzy organizacjami;
- wielu dostawcow, zakaz duplikatow, wylaczanie i niezaleznosc zarzadzania;
- walidacja API, statusy, paginacja i nieznane pola;
- fail-closed w production/staging/testing i przy niepelnej konfiguracji local;
- brak/bledny token, kontrola przed model binding, brak dostepu przez relacje dostaw;
- dotychczasowe testy health.

Cztery scenariusze wspolbieznosci zostaly **rzeczywiscie uruchomione** na
PostgreSQL w osobnych procesach PHP, bez SQLite i bez mockowania blokad:

| Scenariusz | Potwierdzony rezultat |
|---|---|
| Pierwsze przypisanie, pierwszy COMMIT | Drugi proces czeka, potem konflikt; jeden zarzadca |
| Pierwsze przypisanie, pierwszy ROLLBACK | Drugi proces czeka, potem poprawne przypisanie |
| Zmiana kuchni, pierwszy COMMIT | Dwie zserializowane zmiany; 3 rekordy historii, jeden obecny |
| Zmiana kuchni, pierwszy ROLLBACK | Druga zmiana poprawna; 2 rekordy historii, jeden obecny |

Test potwierdza `wait_event_type='Lock'` w `pg_stat_activity` przed zwolnieniem
pierwszej transakcji i sprawdza finalne dane po COMMIT/ROLLBACK.

Test cross-organization wymaga sztucznego stanu, ktorego normalna instalacja
nie moze utworzyc. Tylko w wycofywanej transakcji testowej zniesiono singleton,
tworzac druga organizacje; ograniczenia relacji zostaly zachowane. Potwierdzono
odrzucenie przez serwis i PostgreSQL FK, po czym cala fixture zostala wycofana.

### Jakosc i Stage 1A

- `docker compose exec -T backend vendor/bin/pint --test`: PASS, 52 pliki PHP.
- `docker compose exec -T backend composer validate --strict`: valid.
- `docker compose exec -T frontend npm run build`: sukces TypeScript i Vite.
- `docker compose exec -T frontend npm run lint`: 0 bledow, 0 ostrzezen.
- `php -l` obu skryptow konfiguracji w kontenerze: poprawna skladnia.
- `docker compose config --quiet`: sukces, bez wypisywania sekretow.
- `git diff --check`: sukces.
- `curl` na `localhost:8000/api/health` i `localhost:5173/api/health`:
  HTTP 200, aplikacja ok, PostgreSQL up, Redis up.
- Sprawdzenie w przegladarce po wdrozeniu: tytul VELTRA 2.0, backend dziala,
  PostgreSQL i Redis dostepne. Nie zmieniano frontendu Stage 1A.

Rzeczywiste HTTP przy jawnie wlaczonym API:

- GET locations bez tokenu: 403.
- GET locations z tokenem: 200.
- GET organization z tokenem, przed inicjalizacja: 404.
- POST organization z tokenem i niekompletnym body: 422.
- Po wylaczeniu API: GET locations z poprawnym tokenem daje 404.

Nie inicjalizowano organizacji klienta w bazie instalacji. Koncowe zapytanie SQL
potwierdzilo po 0 rekordow we wszystkich czterech tabelach domenowych.
Wszystkie dane testowe byly w odrebnej bazie.

### Sekrety

Sprawdzono wszystkie 107 plikow wersjonowalnych istniejacych przed dodaniem tego
raportu, a nastepnie 108 plikow lacznie z raportem, pod katem obecnosci trzech rzeczywistych, lokalnie wygenerowanych
wartosci: APP_KEY, hasla PostgreSQL i tokenu developerskiego. Wynik PASS.
Raport nie zawiera ich wartosci ani tresci lokalnego `.env`.
`git check-ignore` potwierdzil wykluczenie `.env`, vendor, node_modules i dist.
Lokalny `.env` ma tryb 0600. Nie wykonano commitow ani pushow.

## 7. Status kontenerow po weryfikacji

| Kontener | Stan | Port hosta |
|---|---|---|
| veltra-backend-1 | Up, healthy | 127.0.0.1:8000 |
| veltra-frontend-1 | Up, healthy | 127.0.0.1:5173 |
| veltra-postgres-1 | Up, healthy | brak publikacji |
| veltra-redis-1 | Up, healthy | brak publikacji |
| veltra-queue-1 | Up, worker dziala; brak healthchecka | brak publikacji |

Zachowano istniejaca etykiete obrazu backendu `veltra-backend:stage1a`;
obraz zostal przebudowany z kodem Stage 1B. Nie oznacza to starego kodu.
Uslugi pozostawiono uruchomione do weryfikacji.

## 8. Napotkane problemy i rozwiazania

1. Zalozenie pustego schematu w health bylo niezgodne z nowymi tabelami.
   Zmieniono tylko asercje dotyczaca tabel, zachowujac testy polaczen.
2. Zmienne serwerowe PHP z Compose mialy pierwszenstwo nad `<env>` PHPUnit.
   Pierwszy przebieg zostal przerwany asercja nazwy bazy przed zapisami.
   Dodano rowniez `<server>` dla APP_ENV i DB_DATABASE. Kolejne testy uzywaly
   wyraznie osobnej bazy i przeszly.
3. `pg_stat_activity` buforowalo snapshot wewnatrz transakcji testowej.
   Dodano `pg_stat_clear_snapshot()` przed obserwacja blokady. Finalne scenariusze
   naprawde wykryly oczekiwanie drugiej sesji i przeszly.
4. Domyslna wartosc SQL `is_active` organizacji nie byla od razu obecna
   w nowym obiekcie Eloquent. Dodano zgodny domyslny atrybut modelu.
5. Pierwsza proba skryptu konfiguracji uzywala niedostepnego `fchmod`.
   Poprawiono na obslugiwane `chmod`; rzeczywiste wlaczenie i wylaczenie,
   zachowanie tokenu oraz uprawnienia pliku zostaly nastepnie zweryfikowane.

Nie przedstawiano nieudanych przebiegow jako zaliczonych. Wyniki w sekcji 6
dotycza finalnych, faktycznie wykonanych polecen.

## 9. Ograniczenia i kwestie otwarte

- Granica tokenowa jest tylko dla lokalnego developmentu. Nie zastapi
  przyszlego uwierzytelniania ani produkcyjnych uprawnien.
- Brak UI zarzadzania, kont, rol, modulow, audytu, receptur, produkcji, magazynu,
  zamowien, dostaw, POS i migracji danych KC Technologia.
- Zmiany zarzadzania sa natychmiastowe. Nie ma harmonogramowania, backdatingu
  ani automatycznego wykonywania operacji wraz z uplywem czasu.
- Trigger nie uruchamia sie sam wraz z uplywem czasu. Bezposredni SQL z przyszlym
  koncem okresu wymagalby osobnej obslugi operacyjnej po uplywie tego czasu;
  nowe publiczne API nie pozwala tworzyc takiego harmonogramu.
- Blokada wiersza organizacji serializuje wszystkie zmiany tej instalacji.
  Jest to proste i bezpieczne rozwiazanie fundamentu, nie optymalizacja pod
  wysoki poziom rownoleglych zapisow.
- Testy wspolbieznosci wymagaja dedykowanej bazy `veltra_testing` i uruchamiania
  sekwencyjnego. README jawnie opisuje czyszczenie tej bazy.
- `btree_gist` musi byc dostepne. Rollback nie usuwa rozszerzenia, aby nie
  uszkodzic innych obiektow potencjalnie z niego korzystajacych.
- Brak niezakonczonych wymagan implementacyjnych Stage 1B; zewnetrzny upload
  raportu jest odrebna czynnoscia, ktorej status opisano ponizej.

## 10. Pelna lista plikow Stage 1B

### Zmienione pliki — 10

1. `.env.example`
2. `README.md`
3. `backend/.env.example`
4. `backend/README.md`
5. `backend/bootstrap/app.php`
6. `backend/phpunit.xml`
7. `backend/routes/api.php`
8. `backend/tests/Feature/HealthIntegrationTest.php`
9. `compose.yaml`
10. `scripts/init-env.php`

### Nowe pliki — 29, lacznie z raportem

1. `backend/app/Domain/Installation/BusinessRuleViolation.php`
2. `backend/app/Domain/Installation/InstallationService.php`
3. `backend/app/Domain/Installation/LocationType.php`
4. `backend/app/Http/Controllers/Api/V1/LocationController.php`
5. `backend/app/Http/Controllers/Api/V1/ManagementController.php`
6. `backend/app/Http/Controllers/Api/V1/OrganizationController.php`
7. `backend/app/Http/Controllers/Api/V1/SupplyController.php`
8. `backend/app/Http/Middleware/LocalManagementAccess.php`
9. `backend/app/Http/Requests/CreateLocationRequest.php`
10. `backend/app/Http/Requests/InitializeOrganizationRequest.php`
11. `backend/app/Http/Requests/InstallationRequest.php`
12. `backend/app/Http/Requests/ListLocationsRequest.php`
13. `backend/app/Http/Requests/ManagingKitchenRequest.php`
14. `backend/app/Http/Requests/PaginationRequest.php`
15. `backend/app/Http/Requests/SupplyRequest.php`
16. `backend/app/Http/Requests/UpdateLocationRequest.php`
17. `backend/app/Models/Location.php`
18. `backend/app/Models/LocationManagementAssignment.php`
19. `backend/app/Models/LocationSupplyRelationship.php`
20. `backend/app/Models/Organization.php`
21. `backend/config/development.php`
22. `backend/database/migrations/2026_11_02_000001_create_installation_structure.php`
23. `backend/tests/Feature/InstallationApiTest.php`
24. `backend/tests/Feature/InstallationDomainTest.php`
25. `backend/tests/Feature/ManagementConcurrencyTest.php`
26. `backend/tests/InstallationTestCase.php`
27. `backend/tests/Support/concurrent_assignment.php`
28. `scripts/configure-management.php`
29. `VELTRA_STAGE_1B_IMPLEMENTATION_REPORT.md`

Lokalny, ignorowany `.env` uzupelniono o wygenerowany token i jawny przelacznik;
po weryfikacji przelacznik ma wartosc false. Nie jest plikiem do wersjonowania.
Nie usunieto zadnego istniejacego pliku Stage 1A.

## 11. Git i potwierdzenie zakresu

`git status --short` wskazuje 10 zmienionych plikow sledzonych oraz nowe pliki
wymienione powyzej (katalogi sa grupowane w skroconym widoku Git).
Nie wykonano `git add`, `git commit` ani `git push` w ramach Stage 1B.
Zmiany pozostaja lokalne do weryfikacji architektonicznej.

**Stage 1C nie zostal rozpoczety. Nie rozpoczeto zadnego kolejnego etapu.**

## 12. Google Drive — status przekazania raportu

Raport najpierw zapisano lokalnie jako
`VELTRA_STAGE_1B_IMPLEMENTATION_REPORT.md` w katalogu glownym repozytorium.

Folder docelowy:
<https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t?usp=drive_link>

Status: **upload technicznie zablokowany; raport zachowany lokalnie**.
Otwarto dokladnie wskazany folder przez dostepna przegladarke. Dysk pokazal
odnosnik "Zaloguj sie"; otwarto formularz logowania Google prowadzacy z powrotem
do folderu docelowego. Nie wpisywano ani nie pobierano danych logowania.

Zgodnie z wymagana procedura przesylanie zatrzymano do recznego logowania
i wyraznego potwierdzenia uzytkownika. Uzytkownik nastepnie potwierdzil:
"Logowanie zakonczone — wznow przesylanie raportu".
Po tym potwierdzeniu ponownie otwarto dokladny folder docelowy i potwierdzono
dostep do funkcji przesylania w folderze RAPORTY_COPILOTA. Wybrano "Przeslij plik".

Podjeto dwie proby przekazania istniejacego lokalnego pliku przez standardowe
API wyboru pliku przegladarki:

1. Sciezka Linux zostala zinterpretowana przez narzedzie Windows jako sciezka
   na dysku C: i zakonczyla sie ENOENT. Raport istnieje w WSL, nie w tym miejscu.
2. Odczytano prawidlowa sciezke Windows przez `wslpath -w` i uzyto jej w API
   przesylania. Narzedzie odmowilo dostepu z komunikatem:
   `UNC host 'wsl.localhost' access is not allowed`.

Nie zmieniano zabezpieczen ani list dozwolonych hostow i nie obchodzono blokady.
Wyszukanie dostepnej integracji Google Drive nie udostepnilo osobnego narzedzia
uploadu. Nie przeslano raportu i nie zweryfikowano jego istnienia na Dysku,
dlatego **nie ma linku do przeslanego pliku**.

To jedyny niezakonczony element zewnetrznego przekazania Stage 1B.
Raport jest gotowy lokalnie; mozna przeslac go recznie do powyzszego folderu.
Nie sa potrzebne zadne dodatkowe dane logowania przekazywane asystentowi.
