# VELTRA 2.0 — Stage 1D implementation report

Data weryfikacji: 2026-11-02. Repozytorium: `/home/veltra/projekty/veltra`.

## 1. Wynik i granice

Zaimplementowano Stage 1D: szesc rol, autoryzacje zakresowa, nadawanie i
odwolywanie uprawnien, zatwierdzanie przypisan, delegacje czasowe,
weryfikacje e-mail, reset hasla przez przelozonego oraz minimalny trwaly audyt.

Wynik koncowy: **109 testow / 757 asercji — PASS**, rzeczywisty PostgreSQL.
Frontend build i lint przechodza; Pint: **115 plikow — PASS**.
Wykonano dodatkowe scenariusze przegladarki, rzeczywiste SMTP/Mailpit
i niezalezny test uniewaznienia starej sesji Redis przez HTTP.

Nie uruchomiono Stage 1E ani Stage 1F. Nie powstaly moduly biznesowe,
generowanie raportow/eksportow, rozbudowany panel administracyjny ani nowa
architektura tenancy. Nie wykonano git commit, push, reset ani rebase.
To nie jest certyfikacja bezpieczenstwa ani deklaracja gotowosci produkcyjnej.

Specyfikacja autorytatywna przeczytana w calosci przed implementacja:
https://docs.google.com/document/d/1Hbt31CoeEeJV9nmwOtkEXVmAFRsKK3VQabCtCuzvN6E/edit

Przed zmianami przeczytano raporty 1B/1C i istniejaca architekture,
sprawdzono Compose i izolacje baz. Baseline: **73 testy / 485 asercji — PASS**.
Zachowano oryginalne migracje i ograniczenia 1B/1C oraz ich API developerskie.

## 2. Implementacja wedlug czesci A–F

| Czesc | Zmiany i rzeczywista walidacja etapowa |
|---|---|
| A | Dokladnie szesc rol, zakres organizacji/lokalizacji, jawne prawa, deny-by-default i zamknieta granica modulow. 5 testow / 50 asercji. |
| B | Hierarchia rol, swieze potwierdzenie hasla administratora, ostatni administrator, blokada organizacji, odroczone triggery i niezmienny audyt. A+B: 12 / 94. |
| C | Personel, jedna tozsamosc, wnioski o dodatkowe przypisania, zatwierdzanie i ochrona wyzszych rol. A–C: 14 / 110. |
| D | Osobne prawa specjalne, delegacje natychmiastowe/przyszle, union okresow, atomowe cofanie wszystkich grantow. A–D: 17 / 133. |
| E | Hashe jednorazowych tokenow Laravel, aktywacja/reset, wspolne limity poczty, wersjonowane sesje, odzyskiwalny bootstrap CLI. Pelna regresja przed F: 95 / 652. |
| F | Chronione API, audyt odrzucen, osobne administrowanie struktura, kontrakt udostepniania raportow, formularze linkow, testy API i dalsze wyscigi PG. Koncowo 109 / 757. |

W toku prac wykryto i naprawiono m.in. utrate mikrosekund w historycznych
datach Eloquent, ograniczenia odroczonych triggerow w adversarialnych fixtures,
odswiezanie migawki pg_stat_activity w testach blokad oraz mozliwosc wyslania
linku na nieaktualny adres z przestarzalego modelu. Najnowszy adres jest
odczytywany pod blokada i ten sam adres jest odbiorca wiadomosci.

## 3. Model danych i integralnosc

Dodano siedem migracji, 000003–000009; wszystkie zastosowane w bazie instalacji
i `veltra_testing`. Oryginalne migracje 000001/000002 nie zostaly zmienione.

| Migracja | Obiekty / ograniczenia |
|---|---|
| 000003 | `users.email_verified_at`; `role_assignments`; szesc wartosci roli; globalny zakres ADMINISTRATOR/BOSS, lokalny dla pozostalych; czesciowe UNIQUE aktywnej roli/zakresu; zlozony FK do konkretnego przypisania; kontrola typu lokalizacji; niezmienna historia. |
| 000004 | Jednokierunkowe `organizations.authorization_initialized`; append-only `security_events`; serializacja users/memberships; odroczone zabezpieczenie aktywnego zweryfikowanego administratora. |
| 000005 | `membership_requests`; jedna oczekujaca para user/location, niezmienny wnioskodawca i ostateczna decyzja, spojnosc organizacji. |
| 000006 | `permission_grants`; jawna allowlista, przedzialy czasowe, zlozony FK do przypisania, zachowanie historii; trigger zamykajacy role/granty po odebraniu przypisania. |
| 000007 | `users.auth_version`; `activation_tokens`, `password_reset_tokens` z hashami; append-only `account_email_deliveries`; zmiana hasla/e-maila lub dezaktywacja uniewaznia sesje; zmiana adresu resetuje weryfikacje; zmiana hasla/adresu usuwa stare tokeny. |
| 000008 | `report_shares`; niezmienne referencyjne udostepnienia raportu BOSS z autorytatywnym zrodlem kuchni i historycznym cofnieciem. |
| 000009 | Administrator musi zachowac takze uzywalny kontekst: aktywne przypisanie do aktywnej lokalizacji. Odroczone sprawdzanie zmian przypisan/lokalizacji zapobiega technicznemu zablokowaniu ostatniego administratora. |

UUID, historyczne rekordy i ON DELETE RESTRICT pozostaja zachowane.
Nie wprowadzono trwalego usuwania osob, rol, przypisan ani zdarzen.
Blokada wiersza organizacji serializuje mutacje jednej instalacji; nie jest to
mechanizm wspoldzielonej autoryzacji wielu klientow.

## 4. Macierz RBAC i punkty egzekwowania

| Rola | Zakres | Dozwolone / zabronione |
|---|---|---|
| ADMINISTRATOR | Organizacja | Struktura, konta, role, uprawnienia. Nadanie/cofniecie ADMINISTRATOR wymaga aktualnego hasla przy kazdym zadaniu. Brak obejscia niedostepnych modulow. |
| CENTRAL_KITCHEN_CHEF | Jawnie przypisana kuchnia i aktualnie zarzadzane restauracje | Personel, role restauracyjne, specjalne prawa i raporty. Nie tworzy struktury i nie nadaje ADMINISTRATOR/BOSS/roli szefa kuchni. |
| BOSS | Wszystkie restauracje, takze przyszle | Odczyt i eksport dozwolonych raportow. Bez edycji, zatwierdzen, domyslnego foodcost/cen i bez danych zrodlowych kuchni. |
| RESTAURANT_MANAGER | Wlasna restauracja | Nowy personel, EMPLOYEE/RESTAURANT_CHEF, delegacje z allowlisty. Bez zarzadzania kierownikami, wyzszymi rolami i globalnym stanem konta. |
| RESTAURANT_CHEF | Wlasna restauracja | Kontrakt receptur/kalkulatora ilosci. Brak automatycznego foodcost/cen. |
| EMPLOYEE | Wlasna restauracja | Podstawowy odczyt lokalizacji; operacje wymagaja jawnego grantu i dostepnego modulu. |

`BusinessAuthorization` odswieza konto, organizacje i lokalizacje; wymaga
zweryfikowanego e-maila oraz aktywnego przypisania do wybranego kontekstu.
Zakres roli moze obejmowac cele bez dopisywania im przypisan. Zgodnie z
potwierdzona decyzja kontekstu szef dziala z przypisanej kuchni na jej
restauracje; inna przypisana kuchnia wymaga przelaczenia kontekstu.
Sprawdzana jest aktualna relacja zarzadzania, nigdy relacja dostaw.

Role lokalne sa powiazane z UUID przypisania, nie tylko user/location.
Odebranie przypisania zamyka jego role i granty, takze zaplanowane.
Ponowne przypisanie nie ozywia starego kontekstu ani praw.

`RoleAdministration`, `PersonnelAdministration`, `PermissionAdministration`
i `ReportAuthorization` egzekwuja dodatkowo hierarchie i zakres mutacji.
Kierownik nie usuwa przypisania osoby z chroniona rola takze poza jego lokalem.
Istniejaca tozsamosc nie jest duplikowana; wniosek nie daje dostepu przed
zatwierdzeniem przez administratora albo uprawnionego szefa zarzadzajacej kuchni.
Tylko administrator zmienia globalny stan konta; bezposrednio przypisuje tez
istniejace osoby do kuchni. Nowa osoba kuchni nie dostaje roli restauracyjnej.

## 5. Prawa specjalne, delegacje i raporty

Niezalezne prawa restauracyjne: FOODCOST_VIEW, PURCHASE_PRICES_VIEW,
REPORTS_VIEW, REPORTS_EXPORT. Nie wynikaja wzajemnie z siebie.
Eksport wymaga odczytu raportu, prawa eksportu oraz prawa do zrodla wybranego
przez backend, nie zadeklarowanego przez klienta.

Potwierdzona allowlista operacyjna:

- TEMPERATURE_REGISTER → TEMPERATURES
- CLEANING_ENTRY_CREATE → CLEANING
- LOSS_REPORT_CREATE → LOSSES
- INVENTORY_COUNT_ENTRY → INVENTORY

`ModuleAvailability` domyslnie zwraca false. Nie wlaczono modulow i nie ma ich
administracji. Dodatnie scenariusze delegacji z aktywnym modulem sa badane
przez testowe podstawienie tego kontraktu, nie produkcyjne obejscie.

Ocena przedzialow `[valid_from, valid_until)` uzywa czasu PostgreSQL.
`range_agg(tstzrange(...))` scala nakladanie/przyleganie efektywnych okresow,
zachowujac kazdy oryginalny grant. Cofniecie atomowo zamyka wszystkie nadal
nieodwolane granty prawa, wlacznie z przyszlymi. Pozniejszy nowy jawny grant
jest nowa decyzja, nie samoczynnym wznowieniem.

`ReportCatalog` domyslnie nie udostepnia raportow; `ReportAuthorization`
implementuje/testuje jawne udostepnienie BOSS i audyt decyzji eksportu.
Udostepniony raport nie daje bezposredniego dostepu do kuchni ani cen/zrodel.
To wylacznie kontrakt integracji: nie ma generowania raportow/eksportow ani
publicznego API udostepniajacego nieistniejacy podsystem biznesowy.

## 6. API i uwierzytelnianie

Zachowano Sanctum stateful SPA, natywne sesje serwerowe Redis, CSRF,
regeneracje przy loginie i uniewaznienie przy logout. Brak bearer/PAT i
sekretow sesji w localStorage. HttpOnly, SameSite=Lax, host-only;
Secure wymuszane poza local/testing, payload sesji szyfrowany przez Laravel.

`/api/v1/security/*`: first-party sesja, aktywne zweryfikowane konto,
walidowany kontekst, limit 60/minute/konto+IP i autoryzacja domenowa.
Mutacje maja CSRF, walidacje, transakcje i audyt. Potwierdzenie hasla dla
roli ADMINISTRATOR ma dodatkowy limit 5/minute/konto+IP.

| Metody | Sciezka pod `/api/v1/security` |
|---|---|
| GET | `/access?location_id=&permission=` |
| POST / PATCH | `/locations` / `/locations/{location}` |
| POST / PUT | `/locations/{location}/managing-kitchen` |
| GET | `/locations/{location}/users`, `/users/{user}/assignments` |
| POST | `/locations/{location}/personnel` |
| GET / POST | `/locations/{location}/membership-requests` / `/membership-requests/{request}/decision` |
| POST / DELETE | `/users/{user}/locations/{location}/membership` / `/memberships/{membership}` |
| POST / DELETE | `/users/{user}/roles` / `/roles/{assignment}` |
| PATCH | `/users/{user}/status`, `/users/{user}/email` |
| POST / DELETE | `/users/{user}/locations/{location}/permissions` |
| POST | `/users/{identifier}/links` |

Email inicjowany przez przelozonego daje jednakowe ogolne 202 dla odbiorcy
nieznanego, niedozwolonego, limitowanego i poprawnego; nie zwraca tokenu.
Nowa/istniejaca tozsamosc w formularzu personelu tez daje jednolite 202.
Lista personelu jest zakresowa i stronicowana.

`POST /api/v1/account/activate` i `/api/v1/account/reset-password` wymieniaja
sekret odbiorcy na nowe haslo: CSRF, first-party sesja, limit 10/minute/IP.
Nie ma publicznej inicjacji resetu ani rejestracji.
Statusy: 401 tozsamosc, 403 uprawnienia/kontekst, 404 zasob, 409 konflikt,
419 CSRF, 422 walidacja, 429 limiter; JSON bez SQL/bindings/sekretow.

Stare API Stage 1B nadal wymaga jednoczesnie APP_ENV=local, jawnego wlaczenia,
poprawnego skonfigurowanego tokenu i naglowka. Sesja ani nowe role nie
zastepuja zadnego warunku. Poza granica local mechanizm pozostaje zamkniety.

## 7. Poczta, reset i bootstrap

Laravel DatabaseTokenRepository: hashe, aktywacja 24h, reset 30min, pojedyncze
uzycie, nowe wydanie uniewaznia poprzednie tego rodzaju. Zmiana adresu wymaga
ponownej weryfikacji. Adres moze zmienic inny administrator; samodzielna zmiana
nie jest udostepniona. Trigger uniewaznia stare tokeny i wersje sesji.

Przelozony nie widzi/nie wybiera hasla ani tokenu personelu. Weryfikowane sa
wszystkie aktywne role celu, rowniez w innych lokalizacjach. Zasada jest
konserwatywna: wszystkie role musza miescic sie w dozwolonej hierarchii/zakresie.

Wspolny budzet aktywacji/resetu: 3/konto/rolling60min, odstep >=60s,
20/IP/godzine. Rezerwacja pod blokada organizacji jest atomowa.
SMTP jest synchroniczny; nie ma tokenow w kolejkach/logach. Awaria SMTP ma
bezpieczny komunikat, log techniczny i zdarzenie rejected; rezerwacja pozostaje.
202 oznacza przyjecie zadania, nie zapewnienie wysylki.

Mailpit v1.33.0, obraz przypiety digestem, UI tylko 127.0.0.1:8025;
SMTP bez publikacji portu hosta. Transport log jest zabroniony dla linkow.
Linki zawieraja sekret we fragmencie URL, usuwanym przez frontend po odczycie.

Bootstrap: jawna inicjalizacja organizacji → istniejaca komenda
`veltra:provision-initial-identity` → interaktywne
`veltra:bootstrap-authorization` → aktywacja e-mail i nowe haslo odbiorcy.
Komenda potwierdza haslo w ukrytym TTY, tworzy potrzebny kontekst i oczekujaca
role; pozwala ponowic wysylke przed aktywacja. Po aktywacji latch zamyka
bootstrap. Ostatniego uzywalnego administratora nie mozna odebrac/dezaktywowac
ani pozbawic ostatniego kontekstu, takze bezposrednim SQL.

## 8. Audyt

Append-only `security_events`: organizacja, actor_id, target_id, location_id,
action, outcome accepted/rejected, subject_id i czas serwera.
Brak dowolnych payloadow i brak sekretow, hasel, hashow tokenow czy konfiguracji SMTP.
Sukces jest atomowy ze zmiana; odrzucenie domenowe jest zapisane poza rollbackiem.
Middleware dopisuje istotne nieudane mutacje HTTP; mozliwe dodatkowe zdarzenie
techniczne obok domenowego, nie sa to dwa sukcesy biznesowe.
Przetestowano odrzucanie UPDATE/DELETE historii.
Nie wdrozono pelnego audytu biznesowego ani infrastruktury Stage 1F.

## 9. Rzeczywiste testy

Polecenia koncowe:

```bash
docker compose build backend
docker compose up -d --build --wait --wait-timeout 120
docker compose exec -T backend php artisan migrate --force
docker compose run --rm --no-deps -e DB_DATABASE=veltra_testing backend php artisan migrate --force
docker compose exec -T backend composer test
docker compose exec -T backend vendor/bin/pint --test
docker compose exec -T backend composer validate --strict
docker compose run --rm --no-deps frontend sh -c 'npm run build && npm run lint'
docker compose config --quiet
git diff --check
```

- Pelna regresja A–D: **109 passed, 757 assertions, 21.45s**.
- PostgreSQL, nie SQLite. Sesje/cache jednostkowych testow sa izolowane array;
  rzeczywiste Redis i HTTP sprawdzono oddzielnie.
- Build frontend: TypeScript + Vite, 20 modulow, PASS.
- oxlint: **0 bledow, 0 ostrzezen**.
- Pint: **115 plikow, PASS**; Composer strict: poprawny.
- Compose i git diff --check: PASS.
- Celowany skan 176 plikow repozytorium pod katem rzeczywistych sekretow
  instalacji z ignorowanego .env: **0 dopasowan**. Nie jest to pelny audyt
  typu gitleaks ani niezalezny test penetracyjny.

Rzeczywiste wyscigi PostgreSQL, dwa procesy PHP/polaczenia, potwierdzone
oczekiwanie na Lock w pg_stat_activity:

| Scenariusz | Wynik |
|---|---|
| Ostatni administrator: parent revoke COMMIT, child self-revoke | Child odrzucony; pozostaje 1 administrator. |
| Ten sam parent ROLLBACK, child self-revoke | Child zaakceptowany; pozostaje 1 administrator. |
| Parent revoke COMMIT/ROLLBACK, child global deactivate | Odpowiednio rejected/accepted; pozostaje 1 administrator. |
| Parent natychmiastowy i przyszly grant COMMIT, child revoke | Wszystkie granty zamkniete. |
| Parent grant ROLLBACK, child revoke | Brak odzywajacych grantow. |
| Parent revoke COMMIT, child nowy jawny grant | Jeden nowy grant po odwolaniu, zgodnie z kolejnoscia serializacji. |
| Parent rezerwuje email COMMIT, child send | Child odrzucony przez wspolny cooldown, 1 dostawa. |
| Parent rezerwuje email ROLLBACK, child send | Child zaakceptowany, lacznie 1 dostawa. |

Razem **9 rzeczywistych scenariuszy wspolbieznosci**.

## 10. Przegladarka i rzeczywiste HTTP/SMTP

Wykorzystano izolowana baze `veltra_browser_stage1d`, backend na 8002,
frontend na 5175 i oddzielne Redis DB12/13; nie utworzono kont w instalacji.

Potwierdzono:

1. Rzeczywiste dostarczenie aktywacji przez SMTP do Mailpit.
2. Formularz aktywacji i usuniecie sekretu z fragmentu URL.
3. Ustawienie hasla, login, wybor przypisanej restauracji, biezacy kontekst.
4. Proba zmiany struktury przez EMPLOYEE: 403; stare API developerskie: 404.
5. Logout, login administratora, wybor kuchni i uprawniona inicjacja resetu: 202.
6. Rzeczywisty email resetu i formularz zmiany hasla: sukces.
7. Osobny klient Node HTTP zachowal starsza sesje Redis sprzed resetu:
   przed resetem 200, po resecie **401**; cookie sesji mial HttpOnly.
8. Stare haslo odrzucone; nowe haslo pozwala zalogowac sie i wybrac kontekst.
9. Odebranie przypisania przez autoryzowany serwis: nastepne chronione zadanie
   **403**, biezacy kontekst `null`.
10. localStorage pozostalo puste.

Ograniczenia narzedzia: automatyczne klikniecia czekaly bezskutecznie na
stabilnosc elementu; formularze wyslano przez DOM requestSubmit, a przyciski
przez zdarzenie click. Nadal wykonano rzeczywiste handlery React i siec,
bez mockowania odpowiedzi. Interfejs przegladarki nie wspieral Storage.getCookies;
dlatego replay starej sesji wykonano niezaleznym klientem HTTP, nie fikcyjnym
testem dwoch kontekstow Playwright. Nie badano innych przegladarek/mobile.

Usunieto dwa named kontenery testowe, tymczasowa baze, dwie wiadomosci fixture
i tymczasowy skrypt replay. Testowe wpisy Redis maja zwykly TTL sesji;
nie wykonywano globalnego FLUSHDB/FLUSHALL.

## 11. Stan Docker i wersje

| Usluga | Stan koncowy |
|---|---|
| backend | running / healthy; health 200, PostgreSQL up, Redis up |
| frontend | running / healthy; localhost:5173 |
| postgres | running / healthy |
| redis | running / healthy |
| queue | running; brak zdefiniowanego healthcheck |
| mailpit | running / healthy; localhost:8025 |

PHP 8.4.27, Laravel 13.39.0, Sanctum 4.3.3, Composer 2.11.1;
PostgreSQL 17.11, Redis 7.4.12; Node 24.22.2, React 19.3.1,
TypeScript 7.0.2, Vite 8.3.5. Mailpit 1.33.0.
Nie instalowano PHP/Composer globalnie. Dane instalacji: 0 organizacji,
0 uzytkownikow; nie wykonano bootstrapu za uzytkownika.

## 12. Pliki dodane i zmodyfikowane

Nowe:

- `backend/app/Console/Commands/BootstrapAuthorization.php`
- `backend/app/Domain/Authorization/BusinessAuthorization.php`
- `backend/app/Domain/Authorization/BusinessRole.php`
- `backend/app/Domain/Authorization/ModuleAvailability.php`
- `backend/app/Domain/Authorization/Permission.php`
- `backend/app/Domain/Authorization/PermissionAdministration.php`
- `backend/app/Domain/Authorization/PersonnelAdministration.php`
- `backend/app/Domain/Authorization/ReportAuthorization.php`
- `backend/app/Domain/Authorization/ReportCatalog.php`
- `backend/app/Domain/Authorization/RoleAdministration.php`
- `backend/app/Domain/Authorization/SecurityMutation.php`
- `backend/app/Domain/Authorization/UnavailableModules.php`
- `backend/app/Domain/Authorization/UnavailableReports.php`
- `backend/app/Domain/Identity/AccountLinks.php`
- `backend/app/Http/Controllers/Api/V1/AccountLinkController.php`
- `backend/app/Http/Controllers/Api/V1/SecurityController.php`
- `backend/app/Http/Controllers/Api/V1/SecurityStructureController.php`
- `backend/app/Http/Middleware/AuditSecurityRejection.php`
- `backend/app/Http/Middleware/RequireOperatingContext.php`
- `backend/app/Http/Requests/SecurityRequest.php`
- `backend/app/Mail/IdentityLink.php`
- `backend/app/Models/MembershipRequest.php`
- `backend/app/Models/PermissionGrant.php`
- `backend/app/Models/ReportShare.php`
- `backend/app/Models/RoleAssignment.php`
- `backend/database/migrations/2026_11_02_000003_create_business_role_scopes.php`
- `backend/database/migrations/2026_11_02_000004_protect_administrators_and_security_history.php`
- `backend/database/migrations/2026_11_02_000005_create_membership_approval_requests.php`
- `backend/database/migrations/2026_11_02_000006_create_permission_grants.php`
- `backend/database/migrations/2026_11_02_000007_create_account_security_links.php`
- `backend/database/migrations/2026_11_02_000008_create_explicit_report_shares.php`
- `backend/database/migrations/2026_11_02_000009_preserve_usable_administrator_context.php`
- `backend/resources/views/mail/identity-link.blade.php`
- `backend/tests/AuthorizationTestCase.php`
- `backend/tests/Feature/AccountSecurityTest.php`
- `backend/tests/Feature/AdministratorConcurrencyTest.php`
- `backend/tests/Feature/BusinessRoleDefinitionTest.php`
- `backend/tests/Feature/PermissionDelegationTest.php`
- `backend/tests/Feature/PersonnelAdministrationTest.php`
- `backend/tests/Feature/ReportAuthorizationTest.php`
- `backend/tests/Feature/RoleAdministrationTest.php`
- `backend/tests/Feature/SecurityApiTest.php`
- `backend/tests/Feature/SecurityConcurrencyTest.php`
- `backend/tests/Support/browser_authorization_fixture.php`
- `backend/tests/Support/concurrent_administrator.php`
- `backend/tests/Support/concurrent_security.php`
- `frontend/src/AccountLinkForm.tsx`
- `frontend/src/api.ts`
- `VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md`

Zmodyfikowane (20 sledzonych plikow):

- `.env.example`, `README.md`, `compose.yaml`
- `backend/.env.example`, `backend/README.md`
- `backend/app/Http/Controllers/Api/V1/AuthenticationController.php`
- `backend/app/Http/Middleware/ValidateIdentityContext.php`
- `backend/app/Models/Organization.php`, `backend/app/Models/User.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/bootstrap/app.php`, `backend/config/app.php`
- `backend/phpunit.xml`, `backend/routes/api.php`
- `backend/tests/Feature/IdentityAuthenticationTest.php`
- `backend/tests/Feature/IdentityMembershipTest.php`
- `backend/tests/Feature/InstallationDomainTest.php`
- `backend/tests/IdentityTestCase.php`
- `frontend/src/App.tsx`, `frontend/src/AuthPanel.tsx`

## 13. Ograniczenia i pozostale ryzyka

- Brak aktywowanych modulow jest zamierzony: delegacje operacyjne pozostaja
  niedostepne do czasu prawdziwej integracji Stage 1E.
- Raporty/receptury/kalkulator sa jedynie kontraktami autoryzacji; brak
  podsystemow biznesowych zgodnie z wykluczeniami.
- Historia rol/grantow jest pelna w bazie; endpoint assignments zwraca
  maks. 100 rekordow kazdego rodzaju, bez paginacji pelnej historii.
- Mailpit jest lokalnym przechwytywaczem, bez logowania do UI. Produkcja wymaga
  innego SMTP/TLS, HTTPS, wlasciwego reverse proxy, sekretow i kont DB/Redis.
- Jednolite odpowiedzi inicjacji nie sa gwarancja identycznego czasu odpowiedzi;
  synchroniczne SMTP moze wprowadzac roznice czasowe.
- Nie wykonano niezaleznego pentestu, audytu kryptograficznego, testow
  obciazeniowych ani weryfikacji zewnetrznego dostawcy poczty.
- Uprawnienia wlasciciela PostgreSQL pozwalaja administratorowi infrastruktury
  zmienic schemat; triggery nie chronia przed administratorem serwera.
- Nie ma panelu administracyjnego, samoobslugi zmiany e-maila ani publicznego
  resetu. Zarzadzanie odbywa sie przez autoryzowane API.
- Wygasle tokeny pozostaja hashowane do ponownego wydania/zuzycia; ocena
  waznosci nie zalezy od zadania okresowego.

Nie pozostawiono znanego niespelnionego obowiazkowego wymagania Stage 1D;
powyzsze ograniczenia nie sa deklaracja produkcyjnej kompletnosci.

## 14. Git i Google Drive

Zmiany pozostaja lokalnie: 20 zmodyfikowanych sledzonych plikow i nowe pliki
wymienione powyzej. Brak commit/push/reset/rebase. Poprzednie raporty pozostaly
niezmienione. Ignorowany .env nie zostal dodany do Git ani skopiowany do Windows.

Folder docelowy:
https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t

Status uploadu: **wyslano i potwierdzono jeden plik o wymaganej nazwie
w dokladnym folderze docelowym**. Bezposredni link:
https://drive.google.com/file/d/19SeIcNPM7158tSedWXH3IJHaWfvkuVb-/view

Uzyto natywnej sciezki Windows Temp, nie WSL UNC. Skopiowano wylacznie
ten raport i porownano zawartosc z lokalnym plikiem. Nie kopiowano sekretow
ani kodu. Raport z tym linkiem zastepuje pierwsza wersje tego samego pliku.
Po potwierdzeniu finalnej zawartosci kopia tymczasowa jest usuwana.

Stage 1D zakonczony do przegladu architektonicznego. **Stage 1E nie rozpoczeto.**
