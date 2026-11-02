# VELTRA 2.0 - Stage 1C Implementation Report

Data weryfikacji: 2026-11-02. Repozytorium: `/home/veltra/projekty/veltra`.
Zakres: User Identity, Authentication and Location Memberships, tylko Stage 1C.

## 1. Podsumowanie i stan koncowy

Zaimplementowano tozsamosc uzytkownika calej instalacji, logowanie i logout
przez sesje Laravel/Sanctum, historyczne przypisania do lokalizacji oraz
przechowywany po stronie serwera kontekst operacyjny. Frontend ma formularz
logowania, profil, wybor lokalizacji, odswiezanie przypisan i wylogowanie.

Jedna osoba moze miec jedno konto i wiele niezaleznych przypisan do restauracji
oraz kuchni centralnych. Przypisanie nie nadaje uprawnien biznesowych ani
administracyjnych i nie wynika z relacji zarzadzania lub zaopatrywania.

Wynik finalnej suity: **73 testy passed, 485 assertions**.
Build, lint i formatowanie przeszly. Wykonano tez testy rzeczywistego HTTP
i przegladarki. Baza instalacji pozostala bez organizacji, kont i przypisan
testowych. Mechanizm developerski Stage 1B jest domyslnie wylaczony.

**Stage 1D nie zostal rozpoczety. Nie wykonano git commit ani git push.**

## 2. Inspekcja poczatkowa i kompatybilnosc

Przed zmianami przeczytano raport Stage 1B oraz istniejace migracje,
serwisy, modele, routing, middleware, konfiguracje Docker i testy.
Sprawdzono dzialanie kontenerow i PostgreSQL. Bazowa regresja Stage 1A/1B
przeszla: 44 testy, 278 asercji.

Nie odtworzono aplikacji ani nie zmieniono migracji Stage 1B.
Zachowano singleton organizacji, zlozone FK, ograniczenia aktywacji,
GiST EXCLUDE okresow zarzadzania i niezmiennosc historii.
Sanctum 4.3.3 zainstalowano Composerem w kontenerze, po potwierdzeniu
kompatybilnosci z Laravel 13. Nie instalowano PHP ani Composera globalnie.

Zidentyfikowane istotne granice:

- Sesja nie moze zastapic lokalnego tokenu developerskiego Stage 1B.
- Redis jest teraz takze magazynem sesji; awaria nie moze otworzyc dostepu.
- Nie wolno logowac SQL/bindings operacji tozsamosci, gdy moga zawierac hash.
- Lokalny HTTP wymaga odrebnego traktowania od produkcyjnego HTTPS.
- Sam zapis identyfikatora lokalizacji nie jest dowodem aktywnego przypisania.

## 3. Wersje technologii

| Technologia | Wersja rzeczywiscie sprawdzona |
|---|---|
| Docker Engine / Compose | 29.9.2 / 5.6.1 |
| PHP | 8.4.27 |
| Laravel | 13.39.0 |
| Laravel Sanctum | 4.3.3 |
| Composer | 2.11.1 |
| PostgreSQL | 17.11 |
| Redis / phpredis | 7.4.12 / 6.2.0 |
| Node.js | 24.22.2 |
| React | 19.3.1 |
| TypeScript | 7.0.2 |
| Vite | 8.3.5 |

Dodano tylko zaleznosc backendowa Sanctum; bez nowych zaleznosci frontendu.

## 4. Struktura i pliki Stage 1C

Glowne katalogi pozostaly: `backend/`, `frontend/`, `scripts/`.
Backend rozszerzono o `app/Domain/Identity/` i komende w `app/Console/Commands/`.

### Dodane

- `backend/app/Console/Commands/ProvisionInitialIdentity.php`
- `backend/app/Domain/Identity/IdentityService.php`
- `backend/app/Domain/Identity/MembershipService.php`
- `backend/app/Http/Controllers/Api/V1/AuthenticationController.php`
- `backend/app/Http/Middleware/RequireSpaSession.php`
- `backend/app/Http/Middleware/ValidateIdentityContext.php`
- `backend/app/Http/Requests/LoginRequest.php`
- `backend/app/Http/Requests/SelectOperatingLocationRequest.php`
- `backend/app/Http/Resources/UserResource.php`
- `backend/app/Models/User.php`
- `backend/app/Models/LocationMembership.php`
- `backend/config/sanctum.php`
- `backend/database/migrations/2026_11_02_000002_create_user_identity_and_memberships.php`
- `backend/tests/IdentityTestCase.php`
- `backend/tests/Feature/IdentityAuthenticationTest.php`
- `backend/tests/Feature/IdentityMembershipTest.php`
- `backend/tests/Feature/InitialIdentityProvisioningTest.php`
- `backend/tests/Support/browser_identity_fixture.php`
- `frontend/src/AuthPanel.tsx`
- `VELTRA_STAGE_1C_IMPLEMENTATION_REPORT.md`

### Zmienione / rozszerzone

- `README.md`
- `backend/README.md`
- `backend/.env.example`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/bootstrap/app.php`
- `backend/app/Models/Organization.php`
- `backend/app/Models/Location.php`
- `backend/composer.json`
- `backend/composer.lock`
- `backend/config/auth.php`
- `backend/config/session.php`
- `backend/phpunit.xml`
- `backend/routes/api.php`
- `backend/tests/Feature/HealthIntegrationTest.php`
- `compose.yaml`
- `frontend/src/App.tsx`
- `frontend/src/App.css`
- `frontend/vite.config.ts`

Istniejacy raport i niezacommitowane pliki Stage 1B zachowano.
Przyklady srodowiska nie zawieraja rzeczywistych sekretow.

## 5. Migracje i integralnosc PostgreSQL

Obie migracje maja status Ran w bazie instalacji i bazie testowej:

1. `2026_11_02_000001_create_installation_structure` - bez zmian.
2. `2026_11_02_000002_create_user_identity_and_memberships` - nowa.

`users` zawiera UUID, organizacje, imie, nazwisko, email, hash hasla,
stan aktywnosci, wewnetrzny znacznik tozsamosci poczatkowej i timestamps.
PostgreSQL wymusza email w znormalizowanej postaci i unikalny `lower(email)`
w calej instalacji. Czesciowy UNIQUE pozwala na tylko jedna tozsamosc poczatkowa.
Trigger zabrania usuwania kont oraz zmiany UUID, organizacji i tego znacznika.

`location_memberships` zawiera UUID, organizacje, uzytkownika, lokalizacje,
aktywnosc, `assigned_at`, `deactivated_at`, `deactivation_reason` i timestamps.
Zlozone FK pilnuja wspolnej organizacji uzytkownika i lokalizacji.
Czesciowy UNIQUE blokuje zduplikowana aktywna pare uzytkownik/lokalizacja.
CHECK wymusza spojnosc statusu, czasu i powodu deaktywacji.
Trigger dopuszcza tylko zakonczenie aktywnego przypisania, bez usuwania,
przepisywania historii i reaktywacji starego rekordu.

Rollback i ponowne wykonanie tylko migracji Stage 1C przetestowano na
tymczasowej bazie przegladarkowej. Obie operacje zakonczyly sie poprawnie.
Nie wykonywano rollbacku na bazie instalacji.

## 6. Uwierzytelnianie i bezpieczenstwo sesji

- Natywny Laravel guard `web`, Eloquent User provider i Sanctum stateful SPA.
- Serwerowa sesja Redis, payload szyfrowany przez Laravel.
- Logowanie email/haslo; hashowanie bcrypt przez cast `hashed`.
- Email normalizowany wspolna regula UTF-8 w modelu, provisioningu, loginie
  i limiterze; brak konta, bledne haslo i konto wylaczone maja ten sam
  ogolny komunikat 401. Nieaktywna organizacja rowniez nie pozwala na logowanie.
- Regeneracja sesji po sukcesie; logout uniewaznia sesje i odnawia CSRF.
- Wylaczenie konta/organizacji usuwa autoryzowany dostep przy kolejnym zadaniu.
- CSRF middleware Sanctum/Laravel; XSRF cookie i naglowek X-XSRF-TOKEN.
  Framework obsluguje rowniez zaufane browserowe Fetch Metadata same-origin.
- First-party Origin/Referer musi odpowiadac jawnej konfiguracji Sanctum.
- Cookie sesji: HttpOnly, domyslnie host-only, SameSite=Lax, 120 minut
  bezczynnosci. Secure jest wymuszany poza local/testing, nawet przy override false.
- Limit logowania: 5/min email+IP i 30/min IP. Email w kluczu limitera jest hashowany.
- Brak tokenow PAT, tabeli personal_access_tokens i alternatywnej sciezki Bearer.
- Profil API to allowlista id, first_name, last_name, email.
- Poprawne odpowiedzi chronione maja Cache-Control no-store.
- Raportowanie QueryException dla tabel tozsamosci usuwa SQL/bindings;
  odpowiedzi bledow API i komendy nie wypisuja hasha ani hasla.
- Nie ma publicznej rejestracji, odzyskiwania hasla ani trwalego usuwania kont.

## 7. API, przypisania i kontekst operacyjny

| Metoda | Endpoint | Wymagana ochrona |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | Inicjalizacja first-party cookies |
| POST | `/api/v1/auth/login` | First-party sesja, CSRF, limity, email i haslo |
| POST | `/api/v1/auth/logout` | First-party, CSRF, auth:sanctum; pozostaje dostepny bez poprawnego kontekstu |
| GET | `/api/v1/auth/me` | First-party, auth:sanctum, aktywna tozsamosc i ponowna walidacja kontekstu |
| GET | `/api/v1/auth/locations` | Jak wyzej; tylko wlasne aktywne przypisania |
| PUT | `/api/v1/auth/operating-location` | Jak wyzej + CSRF; UUID location_id jest sprawdzany przez aktywne przypisanie |
| GET | `/api/v1/auth/operating-location` | Jak wyzej; lokalizacja lub data:null |
| GET | `/api/health` | Nadal publiczny, bez sesji |

Sesja zapisuje UUID przypisania, a nie zaufany identyfikator z przegladarki.
Kazde chronione zadanie ponownie sprawdza konto, organizacje, przypisanie
i lokalizacje w bazie. Odebranie przypisania lub dezaktywacja lokalizacji
odrzuca nastepne zadanie (403) i czysci kontekst. Ponowne przypisanie nie
przywraca starego kontekstu. Wybor innej lokalizacji wymaga jej wlasnego
przypisania. Brak wybranego kontekstu pozwala na profil i wybor lokalizacji,
ale nie istnieja jeszcze operacje biznesowe.

Relacje zarzadzania i dostaw Stage 1B nie nadaja dostepu.
Zalogowana sesja, tozsamosc poczatkowa i przypisania nie otwieraja API
zarzadzania Stage 1B. Nadal wymaga ono jednoczesnie APP_ENV=local,
jawnego wlaczenia, skonfigurowanego losowego tokenu i poprawnego naglowka.
Poza tym mechanizm pozostaje fail-closed; produkcyjny dostep czeka na Stage 1D.

## 8. Provisioning poczatkowej tozsamosci

Po zainicjalizowaniu aktywnej organizacji, w terminalu z TTY:

```bash
docker compose exec backend php artisan veltra:provision-initial-identity
```

Bez `-T`, parametrow hasla lub przekazywania hasla przez powloke.
Komenda pyta o profil, ukryte haslo i ukryte potwierdzenie.
Wymaga 12+ znakow, malych/wielkich liter, cyfry i symbolu, maksymalnie 72 bajtow.
Odrzuca brak interaktywnego terminala i ponowne provisioning.
Blokada doradcza, blokada organizacji i indeks UNIQUE chronia przed duplikacja.

Nie tworzy roli ADMINISTRATOR, przypisan ani dostepu do API biznesowego.
Nie dodano domyslnych kont w migracjach/seederach. Zarzadzanie dalszymi kontami
i publiczne nadawanie przypisan zostawiono poza Stage 1C.
Serwisy wewnetrzne umozliwiaja modelowanie przypisan i ich deaktywacje.
Nie utworzono rzeczywistego konta poczatkowego w bazie instalacji.

## 9. Rzeczywiscie wykonane testy

Finalne obrazy zostaly przebudowane poleceniem `docker compose up -d --build`
i sprawdzone przez `docker compose up -d --wait --wait-timeout 120`.

| Polecenie / sprawdzenie | Rzeczywisty wynik |
|---|---|
| `docker compose exec -T backend composer test` | **73 passed, 485 assertions**, 11.92 s |
| `docker compose exec -T backend vendor/bin/pint --test` | PASS, 70 plikow PHP |
| `docker compose exec -T backend composer validate --strict` | composer.json is valid |
| `docker compose exec -T frontend npm run build` | PASS: TypeScript + Vite |
| `docker compose exec -T frontend npm run lint` | 0 warnings, 0 errors |
| `docker compose config --quiet` | PASS |
| GET health na 8000 i przez proxy 5173 | HTTP 200; PostgreSQL up, Redis up |
| GET auth/me bez sesji, rowniez bez naglowka Accept | HTTP 401 JSON |
| GET Stage 1B organization bez jawnych warunkow | HTTP 404 |
| Production config, SESSION_SECURE_COOKIE=false | PASS: Secure/HttpOnly/encryption wymuszone |
| Rollback/reapply migracji C na bazie tymczasowej | PASS |

Nowe 29 testow obejmuje: login/logout, bledne dane, limity email+IP/IP,
regeneracje sesji, rzeczywiste middleware CSRF, cookies, disabled user/organization,
normalizacje i unikalnosc emaila (rowniez Unicode), ochrone hasla/logow, brak rejestracji i resetu,
brak alternatywnego Bearer, niezaleznosc granicy Stage 1B, wiele przypisan,
duplikaty aktywnej pary, historie, odebranie i ponowne nadanie,
nieaktywne lokalizacje/uzytkownikow, cudze i cross-organization przypisania,
nieprawidlowy wybor kontekstu, izolacje kontekstu i provisioning CLI.

Regresja obejmuje wszystkie testy Stage 1A/1B, lacznie z czterema testami
rzeczywistej wspolbieznosci PostgreSQL. Testy integralnosci uzywaja PostgreSQL,
nie SQLite. PHPUnit wymusza veltra_testing, APP_ENV=testing oraz izolowany
cache/sesje array. Testy uruchamiano sekwencyjnie.

Dodatkowy rzeczywisty probe HTTP uruchomiono jako:
`docker exec veltra-stage1c-browser-frontend node /tmp/stage1c-http-probe.mjs`.
PASS: 419 bez CSRF przy login/logout, 200 z CSRF, odtworzenie starej sesji
goscia po login daje 401, odtworzenie sesji po logout daje 401,
cookie flags, no-store, allowlista profilu i zamknieta granica Stage 1B.
Probe znajduje sie w obszarze sesji narzedzia, nie w aplikacji.

Kontrola 128 plikow widocznych dla Git, lacznie z raportem, nie znalazla wartosci wygenerowanych
lokalnych sekretow ani markerow kluczy prywatnych. Wartosci nie byly wypisywane.
Ignorowanie .env, backend/vendor i frontend/node_modules potwierdzono git check-ignore.
To kontrola ukierunkowana, nie deklaracja pelnego audytu/SAST/pentestu.

## 10. Testy przegladarki

Wykonano je w rzeczywistej zintegrowanej przegladarce, przez Playwright,
na frontendzie localhost:5174 i backendzie localhost:8001.
Osobna baza veltra_browser_stage1c oraz odrebne kontenery i cookie sesji
zapobiegaly zapisom do instalacji. Fixture mial jedno testowe konto,
kuchnie, dwa przypisane i jeden nieprzypisany lokal.

Potwierdzono:

- formularz oraz ogolny blad niepoprawnego logowania;
- poprawne logowanie i stan uwierzytelnienia;
- liste dokladnie trzech przypisanych lokalizacji, bez nieprzypisanej restauracji
  pomimo powiazania zarzadzania i zaopatrywania;
- wybor restauracji R1, przetrwanie sesji/kontekstu po reload,
  zmiane na R2 i z powrotem;
- natychmiastowy 403 po odebraniu R1, usuniecie starego kontekstu
  i poprawne odswiezenie pozostalych przypisan;
- logout, powrot do formularza bez bledu, ponowne logowanie i wybor R2;
- utrate sesji przy kolejnym zadaniu po wylaczeniu konta;
- brak kluczy localStorage.

Uzyto programowego submit formularza i dispatch zdarzen przyciskow przez
Playwright. Zwykle klikniecia zintegrowanej przegladarki byly niestabilne;
nie deklaruje sie manualnego testu wszystkich gestow, wielu przegladarek,
testu dostepnosci ani kompletnej suity E2E w CI.
Odczyt cookies przez browserContext.cookies nie byl wspierany przez most CDP;
flagi i odtwarzanie sesji sprawdzono niezaleznie rzeczywistym HTTP.
401/403 w konsoli podczas scenariuszy negatywnych byly oczekiwane.

Po testach usunieto oba nazwane kontenery oraz baze veltra_browser_stage1c.
Pozostawiono dzialajace normalne srodowisko, a strone otwarto ponownie na 5173.

## 11. Docker i dane

| Kontener | Stan po weryfikacji |
|---|---|
| veltra-backend-1 | running, healthy; 127.0.0.1:8000 |
| veltra-frontend-1 | running, healthy; 127.0.0.1:5173 |
| veltra-postgres-1 | running, healthy; bez portu hosta |
| veltra-redis-1 | running, healthy; bez portu hosta |
| veltra-queue-1 | running; brak osobnego healthchecka workera |

Obraz backendu zachowal historyczny tag veltra-backend:stage1a, ale zostal
przebudowany z aktualnym kodem Stage 1C dla backendu i kolejki.
Kontrola bazy instalacji po testach: organizations=0, users=0, memberships=0.
Nie zmieniono istniejacych wolumenow ani sekretow srodowiska.

## 12. Problemy rozwiazane i pozostale ryzyka

Rozwiazane podczas implementacji:

1. Wywolywanie app()->environment() z wczesnej konfiguracji zaklocalo discovery;
   konfiguracja odczytuje APP_ENV przed utworzeniem bindingow aplikacji.
2. Zmienne serwerowe Compose nadpisywaly ustawienia PHPUnit, zanieczyszczajac
   limity prob przez Redis; dodano wymuszone env i server dla cache/sesji.
3. Cache guarda Sanctum miedzy zadaniami jednego testu PHPUnit wymagal resetu;
   dodatkowo rzeczywisty replay HTTP potwierdzil poprawna izolacje i logout.
4. Laravelowy runner komend jest nieinteraktywny; scenariusze ukrytych hasel
   testuje Symfony CommandTester z jawnym interactive=true, bez oslabiania CLI.
5. Logout poczatkowo zwracal format niezgodny z frontendem; dodano data:null
   i sprawdzono pelny przeplyw wylogowania w przegladarce.
6. Zadanie API bez Accept probowalo przekierowac do nieistniejacej strony login;
   usunieto redirect gosci i dodano regresje 401 JSON bez tego naglowka.
7. Pint wymagal uruchomienia jako UID/GID wlasciciela montowanych plikow;
   poprawiono uprawnienia procesu, bez zmieniania globalnych praw plikow.
8. Ujednolicono normalizacje emaila UTF-8 we wszystkich sciezkach; dodatkowy
   test PostgreSQL potwierdza provisioning, login i unikalnosc emaili Unicode.

Granice/pozostale ryzyka:

- Compose nadal jest wylacznie srodowiskiem lokalnym Laravel/Vite; produkcja
  wymaga HTTPS, reverse proxy, poprawnych hostow Sanctum, uprawnien DB/Redis,
  bezpiecznego zarzadzania kluczami i konfiguracja oraz dodatkowego hardeningu.
- Brak RBAC, roli ADMINISTRATOR, zarzadzania kontami i przypisaniami przez API,
  MFA, resetu hasla i infrastruktury audytu biznesowego - zgodnie z zakresem.
- Blokada wiersza organizacji jest prosta serializacja pojedynczej instalacji,
  nie mechanizm wspoldzielonej wielodzierzawnosci.
- Zmiana aktywnosci przypisania odcina kolejne zadanie; juz trwajace zadania
  nie sa przerywane asynchronicznie.
- Baza testow PHPUnit moze byc czyszczona przez testy wspolbieznosci;
  nazwa veltra_testing nie moze oznaczac danych rzeczywistych.
- Nie wykonano niezaleznego audytu bezpieczenstwa, pelnego pentestu ani testu
  produkcyjnego HTTPS. Nie deklaruje sie ich zaliczenia.
- Nie ma nierozwiazanych bledow w wykonanych testach aplikacji.

## 13. Git status

Drzewo jest dirty: zawiera zachowane, niezacommitowane pliki Stage 1B,
zmiany Stage 1C oraz ten raport. git status --short --untracked-files=all
zostal wykonany. Lista zmian Stage 1C znajduje sie w sekcji 4; nie wszystkie
widoczne w statusie pliki pochodza z tego etapu.
Lokalny .env jest ignorowany. Nie wykonano commit, amend, reset ani push.

## 14. Google Drive

Folder docelowy:
https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t

Status: **przeslano i potwierdzono obecnosc pliku w dokladnym folderze docelowym**.
Google Drive wyswietlil komunikat "Przeslano 1 element"; lista folderu zawierala
dokladnie jeden plik o wymaganej nazwie. Identyfikator pliku odczytano z jego
wiersza w tym folderze.

Bezposredni link:
https://drive.google.com/file/d/16Xp7GCXPFHGXUAVWpt64VskFrTHUXug7/view

Uzyto istniejacej, uwierzytelnionej sesji Google i natywnej sciezki C: do kopii
w katalogu tymczasowym Windows. Nie zastosowano odrzuconej metody UNC
wsl.localhost. Skopiowano wylacznie raport, sprawdzajac zgodnosc z lokalnym
oryginalem; bez konfiguracji/sekretow i bez zmian ustawien bezpieczenstwa.
Koncowa wersja zawierajaca ten status i link zastepuje pierwsza wersje raportu.

## 15. Granica zakonczenia

Stage 1C zakonczony w zakresie implementacji i wykonanych testow.
Przekazano do weryfikacji; status dostarczenia raportu opisuje sekcja 14.
Nie wdrozono Stage 1D, rol, delegacji, modulow biznesowych, produkcji,
magazynu, receptur, dostaw, POS, social login ani migracji danych KC Technologia.
Po przekazaniu raportu prace sa zatrzymane do decyzji uzytkownika.
