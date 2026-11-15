# VELTRA 2.0 — Audyt środowiska testowego i SUPER ADMIN

**Data audytu:** 14.11.2026  
**Zakres:** stan lokalnego repozytorium i aktywnych kontenerów, bez zmian w aplikacji ani danych.  
**Werdykt:** **NIE** — obecny stan nie pozwala właścicielowi rzetelnie odebrać modułów.

## 1. Podsumowanie

W środowisku działają dwa lokalne stosy. Starszy `veltra` ma sprawny frontend i API, lecz korzysta z obrazów `stage1f-polish-localization`; ekran otwarty na porcie 5173 oznacza się jako Stage 1A. Drugi stos `veltra-stage6a1` zawiera nowszy ekran RAW na porcie 5174, ale jego backend jest niezdrowy: `/api/health` zwraca 503 z `postgresql: down`. Nie ma uruchomionego kontenera PostgreSQL tego stosu, mimo że jego nazwany wolumen istnieje.

W repozytorium znajduje się działający kod RAW, ale produkcyjna definicja modułu ma domyślną gotowość `NOT_READY`; lokalna ścieżka Stage 6A.1 jest osobnym, udokumentowanym opt-inem, a nie dowodem gotowości do odbioru. INT i PRD występują w dokumentacji architektury, nie jako wdrożone moduły. W interfejsie Stage 6A.1 pozycje administracji, lokalizacji, konfiguracji modułów, finansów i dziennika są oznaczone jako niedostępne.

W aktywnej bazie `veltra` jest jedna aktywna i zweryfikowana tożsamość początkowa, aktywna organizacja, zakończony bootstrap autoryzacji, jedno aktywne członkostwo i jedna aktywna rola administratora. Odczyt nie obejmował e-maila, hasła, tokenów ani hashy. Nie da się jednak potwierdzić bieżącego `isSuperAdmin()` zgodnie z aktualnym kodem: działający backend używa starszego obrazu, a baza nie zawiera kolumn wymaganych przez migracje 038–039. Nie potwierdzono, że właściciel może obecnie zalogować się ze swojego komputera.

## 2. Metoda i granice weryfikacji

- Odczytano konfigurację Compose, README, aktualny kod, raporty etapów i status Git.
- Sprawdzono tylko odczytowe informacje Docker: kontenery, obrazy, stany health, porty, sieci i wolumeny.
- Wysłano wyłącznie żądania HTTP GET do stron i endpointów health.
- W PostgreSQL wykonano wyłącznie SELECT; zapytania diagnostyczne nie odczytywały danych uwierzytelniających ani tokenów.
- Nie użyto poświadczeń aplikacji, nie próbowano logowania, nie czytano skrzynek pocztowych ani sekretów z `.env`.
- Automatycznych testów nie uruchamiano: nie są dowodem działającego środowiska odbiorowego, a celem było zachowanie READ-ONLY.

Wyniki poniżej rozdzielają **stan potwierdzony** od **niezweryfikowanego**. Odpowiedź HTTP 200 lub zdrowy kontener oznacza tylko dostępność konkretnego procesu, nie gotowość biznesową.

## 3. Inwentaryzacja środowisk

| Środowisko | Stan i skład | Dostęp i izolacja |
|---|---|---|
| Lokalny `veltra` | Aktywne: backend `veltra-backend:stage1f-polish-localization` (healthy), frontend z tego samego etapu (healthy), PostgreSQL 17 (healthy), Redis, worker i Mailpit. Frontend/API/Mailpit są opublikowane na `127.0.0.1:5173`, `:8000`, `:8025`. GET `/api/health`: 200, PostgreSQL i Redis `up`; frontend i proxy health: 200. | Lokalna instalacja Compose z własnym wolumenem `veltra_postgres-data`; nie znaleziono hostowanej konfiguracji stagingowej ani publicznego URL. To nie jest aktualny obraz kodu Stage 6A. |
| Lokalny `veltra-stage6a1` | Frontend (healthy), Redis, Mailpit i worker działają; backend jest **unhealthy**. GET `http://127.0.0.1:8001/api/health`: 503, `postgresql: down`, `redis: up`. Nie ma uruchomionego kontenera PostgreSQL w tym projekcie. Wolumen `veltra-stage6a1_postgres-data` istnieje, lecz nie jest podłączony do aktywnego kontenera. | Osobna sieć `veltra-stage6a1_default`; porty frontendu/API/Mailpit są wyłącznie na loopback: 5174/8001/8026. Sama obecność wolumenu nie potwierdza jego zawartości ani pochodzenia danych. |
| Testy automatyczne | `compose.yaml` zawiera profil `testing` i osobną bazę `veltra_testing` w klastrze PostgreSQL usługi `veltra`; nie jest to osobny aktywny stos. Dodatkowe pliki testowe definiują izolowane sieci wewnętrzne i/lub `tmpfs`. Żaden kontener testowy nie był uruchomiony podczas audytu. | Profile testowe nie są środowiskiem, do którego właściciel loguje się i odbiera funkcje przez przeglądarkę. |
| Staging/odbiór hostowany | Nie znaleziono aktywnego hostowanego stosu ani adresu środowiska odbiorowego w sprawdzonych konfiguracjach. | Brak dowodu na dostępność spoza hosta. Istnienie odrębnego środowiska poza tym workspace: **NIEZWERYFIKOWANE**. |

Konfiguracja podstawowego Compose używa `APP_ENV: local`, `DB_HOST: postgres`, lokalnego wolumenu Postgresa oraz obrazów `stage1f` ([compose.yaml](compose.yaml#L5), [compose.yaml](compose.yaml#L19), [compose.yaml](compose.yaml#L41), [compose.yaml](compose.yaml#L125), [compose.yaml](compose.yaml#L154)). README opisuje adresy localhost i publikowanie portów na `127.0.0.1` ([README.md](README.md#L47), [README.md](README.md#L51), [README.md](README.md#L54)).

### Izolacja danych

**Potwierdzone na poziomie konfiguracji:** podstawowy Compose ma własną usługę PostgreSQL i lokalny wolumen; sieć `veltra-stage6a1_default` jest odrębna od `veltra_default`; API i frontend nie są wystawione na interfejs sieciowy `0.0.0.0`. Testowe konfiguracje używają `veltra_testing` albo izolowanej sieci/`tmpfs`.

**Niepotwierdzone:** czy jakikolwiek wolumen zawiera wyłącznie dane syntetyczne, czy wolumen Stage 6A.1 nadal odpowiada pierwotnej instancji testowej oraz czy istnieje zewnętrzne środowisko produkcyjne poza widoczną konfiguracją. Nie znaleziono połączenia do produkcyjnej bazy, ale nie jest to dowód o wszystkich środowiskach właściciela.

## 4. Faktyczna dostępność i użyteczność

| Funkcja / czynność właściciela | Status | Dowód i ograniczenie |
|---|---|---|
| Otworzyć stronę z bieżącego hosta | **CZĘŚCIOWO** | `http://127.0.0.1:5173/` i `:5174/` zwracają HTML 200. Na 5174 ekran wyświetla „Zaloguj się”, a API tego stosu zwraca 503. |
| Otworzyć aplikację z innego komputera właściciela | **NIEZWERYFIKOWANE** | Porty wiążą się z loopbackiem hosta. Nie ma adresu LAN, VPN, tunelu ani hostowanego URL w sprawdzonych ustawieniach. |
| Zalogować się kontem właściciela teraz | **NIEZWERYFIKOWANE** | W przeglądarce audytowej nie było sesji; nie testowano hasła. Historyczny [raport Stage 6A.6](VELTRA_STAGE_6A6_REPORT.md#L25) odnotowuje wcześniejsze logowanie, ale nie potwierdza dzisiejszego dostępu ani stanu obecnych kontenerów. |
| Zobaczyć rzeczywisty panel administracyjny | **NIE DZIAŁA** | UI 5174 jawnie oznacza „Organizacja”, „Konta i uprawnienia”, „Lokalizacje”, „Moduły”, „Finanse” i „Dziennik zdarzeń” jako niedostępne. Kod [ApplicationShell.tsx](frontend/src/ApplicationShell.tsx#L21) renderuje te pozycje jako wyłączone. |
| Przeglądać lokalizacje i konfigurację modułów w UI | **NIE DZIAŁA** | Brak dostępnego panelu w UI; backend Stage 6A.1 jest niedostępny. Sama obecność API lub kodu domenowego nie daje właścicielowi ekranu do obsługi. |
| Wykonywać procesy RAW w zwykłym UI i na rzeczywistym backendzie | **CZĘŚCIOWO** | Kod katalogu, edycji i workflow istnieje; README określa RAW jako częściowy i `NOT_READY` ([backend/README.md](backend/README.md#L46), [README.md](README.md#L771)). Aktualny endpoint Stage 6A.1 zwraca 503, a historyczny raport potwierdza, że globalny RAW pozostał `NOT_READY` ([VELTRA_STAGE_6A6_REPORT.md](VELTRA_STAGE_6A6_REPORT.md#L80)). Nie jest to obecnie odbiór end-to-end. |
| Korzystać z INT i PRD | **NIE DZIAŁA** | Dokument Stage 7A opisuje architekturę i wyraźnie wyłącza implementację ([VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.1.md](VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.1.md#L13), [VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.1.md](VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.1.md#L15)). Same wartości prefiksów `INT`/`PRD` w enumie SKU nie tworzą modułów. |
| Testować bez symulacji i ręcznego wywoływania endpointów | **NIE DZIAŁA** dla odbioru w obecnym stosie Stage 6A.1 | Normalny ekran RAW jest obecny, ale backend nie działa. Istnieje też osobny preview, który sam oznacza dane, API i uprawnienia jako syntetyczne; jego `READY` jest wyłącznie symulacją ([visualPreview.tsx](frontend/src/visualPreview.tsx#L75), [visualPreview.tsx](frontend/src/visualPreview.tsx#L94), [visualPreview.tsx](frontend/src/visualPreview.tsx#L120)). Preview nie jest odbiorem. |
| Zgłaszać i śledzić błędy z aplikacji | **NIEZWERYFIKOWANE** | Nie znaleziono w UI kanału zgłoszeń/ticketów. Nie ma dowodu, że właściciel ma dostęp do uzgodnionego rejestru błędów. |

**Ważne:** port 5173 pokazuje ekran Stage 1A i sprawny health check, ale nie dowodzi działania UI RAW ani aktualnego kodu. Port 5174 pokazuje UI opisany jako „ETAP 4A / FUNDAMENT RAW_MATERIALS”, lecz jego backend jest obecnie odłączony od PostgreSQL. Oba obrazy różnią się od pełnego kodu bieżącego checkoutu.

## 5. Konto i mechanizm SUPER ADMIN

### Implementacja

`is_initial_identity` jest chronionym znacznikiem tożsamości początkowej, a SUPER ADMIN nie jest siódmą rolą. `BusinessAuthorization::isSuperAdmin()` wymaga m.in. aktywnej tożsamości i organizacji, zakończonego bootstrapu, rozstrzygniętej aktywnej kuchni centralnej oznaczonej jako primary, aktywnej roli administratora i członkostwa w tej kuchni ([BusinessAuthorization.php](backend/app/Domain/Authorization/BusinessAuthorization.php#L39), [migracja 039](backend/database/migrations/2026_11_08_000039_enforce_super_admin_invariants.php#L27)). Kod chroni tę tożsamość przed samodzielnym przeniesieniem/odebraniem kluczowych uprawnień; nie należy obchodzić tego SQL-em ani tworzyć drugiego SUPER ADMINA.

### Stan odczytany w aktywnej bazie `veltra`

Wyniki bez ujawniania danych logowania:

- dokładnie **1** użytkownik i **1** `is_initial_identity`;
- tożsamość aktywna i e-mail zweryfikowany;
- organizacja aktywna, `authorization_initialized = true`;
- jedno aktywne członkostwo w aktywnej lokalizacji;
- jedna aktywna rola `ADMINISTRATOR`;
- brak aktywnych rekordów w `permission_grants` dla tej tożsamości.

Wcześniejszy raport Stage 6A.6 identyfikuje tę chronioną tożsamość jako istniejące konto `developer@veltra.test` i odnotowuje brak efektywnych grantów RAW ([VELTRA_STAGE_6A6_REPORT.md](VELTRA_STAGE_6A6_REPORT.md#L5), [VELTRA_STAGE_6A6_REPORT.md](VELTRA_STAGE_6A6_REPORT.md#L13)). Nie potwierdza to, że aktualny właściciel kontroluje hasło.

### Istotna rozbieżność schematu

Aktywny backend na porcie 8000 jest obrazem `stage1f`, natomiast aktualny kod wymaga późniejszych reguł SUPER ADMIN. Odczyt tabeli `migrations` kończy się na migracji `2026_11_02_000010`; brak migracji 038–041, a w aktywnej bazie nie ma kolumn `organizations.primary_central_kitchen_unresolved` i `locations.is_primary_central_kitchen`, które wprowadza migracja 038. Dlatego nie można potwierdzić, że **działająca instalacja** spełnia pełną obecną definicję `isSuperAdmin()` ani że jej zabezpieczenia bazodanowe są wdrożone. Nie wykonywano migracji.

### Pierwsza inicjalizacja i dostęp właściciela

README dokumentuje interaktywny `veltra:provision-initial-identity` bez domyślnego konta; operacja odrzuca ponowne utworzenie tożsamości ([README.md](README.md#L509), [IdentityService.php](backend/app/Domain/Identity/IdentityService.php#L19), [IdentityService.php](backend/app/Domain/Identity/IdentityService.php#L42)). Następny interaktywny `veltra:bootstrap-authorization` tworzy kontekst/rolę administratora i wysyła aktywację. Po zakończonym bootstrapie komenda odmawia ponownego użycia; odbiorca sam ustala hasło ([README.md](README.md#L606), [BootstrapAuthorization.php](backend/app/Console/Commands/BootstrapAuthorization.php#L31), [BootstrapAuthorization.php](backend/app/Console/Commands/BootstrapAuthorization.php#L112)).

W tej bazie bootstrap jest już zakończony. Nie wolno ponownie tworzyć tożsamości ani używać procedury odzyskiwania Stage 6A.1, bo dotyczy ona wyłącznie nieaktywowanego bootstrapu. Bieżące posiadanie hasła przez właściciela pozostaje **NIEZWERYFIKOWANE**; nie pytano o nie i go nie odczytywano. Historyczny raport potwierdza wcześniejsze logowanie, ale dzisiejsza awaria stosu 5174 i brak zgodnego UI uniemożliwiają praktyczne potwierdzenie. RBAC nie daje podstaw do samonadania grantów RAW; poprzednie raporty odnotowują, że tożsamość SUPER ADMIN nie ma tych grantów i że nie wolno obchodzić blokad ([VELTRA_STAGE_6A6_REPORT.md](VELTRA_STAGE_6A6_REPORT.md#L13), [VELTRA_STAGE_6A6_FIX_REPORT.md](VELTRA_STAGE_6A6_FIX_REPORT.md#L37)).

## 6. Konkretne przeszkody blokujące odbiór

1. **Stos RAW jest niekompletny w runtime:** backend 8001 odpowiada 503, bo PostgreSQL jest niedostępny; kontenera DB tego projektu nie ma.
2. **Uruchomione obrazy są nieaktualne względem bieżącego kodu:** główny stos używa `stage1f`; frontend Stage 6A.1 na 5174 jest opisany jako fundament Stage 4A.
3. **Rozjazd migracji:** aktywna baza nie ma migracji/kolumn wymaganych przez obecną ochronę SUPER ADMIN.
4. **Brak panelu odbiorowego:** sekcje administracyjne i konfiguracji są niedostępne w UI.
5. **RAW nie jest gotowy:** kod jest częściowy, moduł pozostaje `NOT_READY`, a bieżący backend RAW nie działa. Same testy automatyczne ani lokalny opt-in nie oznaczają odbioru.
6. **Brak INT/PRD:** istnieje projekt architektury, nie implementacja.
7. **Brak potwierdzonego zdalnego dostępu:** adresy są loopback-only; dostęp z komputera właściciela spoza hosta jest niepotwierdzony.
8. **Brak potwierdzenia aktualnego logowania właściciela:** konto istnieje, ale nie sprawdzano jego hasła ani nie wykonano logowania.
9. **Brak dowodu na procedurę zgłaszania błędów w aplikacji.**

## 7. Najprostszy bezpieczny sposób naprawy i odbioru

Preferowany jest **powrót do istniejącego, odrębnego stosu `veltra-stage6a1`**, nie budowa nowej platformy:

1. Po osobnej akceptacji właściciela zweryfikować tożsamość i zawartość istniejącego wolumenu `veltra-stage6a1_postgres-data` oraz izolację sieci. Nie resetować ani nie usuwać wolumenu; nie podłączać go do produkcji.
2. Odtworzyć wyłącznie brakującą usługę PostgreSQL w tym projekcie, używając istniejącego wolumenu i dotychczasowej konfiguracji. Najpierw potwierdzić połączenie, bazę i rolę zapytaniami tylko do odczytu.
3. Zgodnie z osobno zatwierdzonym planem wykonać analizę różnic migracji i kopię zapasową. Migracje zlecić dopiero po zatwierdzeniu zakresu; najpierw sprawdzić je na odizolowanej bazie testowej. Nie uruchamiać ich na podstawowym `veltra` w ramach tego audytu.
4. Zbudować i uruchomić zgodne wersje frontend/backend z bieżącego zatwierdzonego kodu w istniejącym stosie Stage 6A.1. Sprawdzić identyfikator wersji, health, sesję, kontekst lokalizacji i UI — sam health nie wystarczy.
5. Pozostawić istniejącą, pojedynczą chronioną tożsamość. Właściciel sam loguje się własnym hasłem. Jeśli go nie posiada, uzgodnić przed działaniem obsługiwany, audytowany reset tej samej tożsamości; bez ujawniania hasła agentowi, bez SQL i bez drugiego SUPER ADMINA.
6. Do testów RAW użyć wyłącznie odizolowanego środowiska Stage 6A.1 i udokumentowanego, tymczasowego opt-inu lokalnego po jego odrębnej akceptacji. Zachować globalne `NOT_READY`. Dla operacji wymagających grantów zastosować dozwolony, audytowany mechanizm i zasadę najmniejszych uprawnień; jeśli aktualny kontrakt nie pozwala na bezpieczne nadanie uprawnień, najpierw uzyskać decyzję właściciela o zgodnej z RBAC procedurze. Nie nadawać ich przez SQL ani „bo admin”.
7. Udostępnić aplikację właścicielowi przez prywatny VPN/tunel lub kontrolowane przekierowanie portu IDE, a nie publiczne wystawienie API, PostgreSQL, Redis ani Mailpit. Zweryfikować logowanie z komputera właściciela.
8. Zapewnić wyłącznie syntetyczne dane odbiorowe i normalne ścieżki UI. Uzgodnić kanał zgłoszeń (np. prywatny rejestr/GitHub) z wersją aplikacji, rolą i lokalizacją, krokami, wynikiem oczekiwanym/rzeczywistym oraz bezpiecznym logiem bez sekretów.
9. Odbierać RAW na procesach biznesowych z uprawnieniami i właściwym kontekstem, nie na preview. INT i PRD udostępnić do odbioru dopiero po ich implementacji, osobnym uruchomieniu, testach pełnych procesów i jawnej akceptacji właściciela. Nie ustawiać `READY` bez spełnienia bramek modułu.

## 8. Działania wymagane od właściciela

- Potwierdzić, że istniejący wolumen Stage 6A.1 zawiera wyłącznie dane testowe i może być użyty; zatwierdzić przywrócenie usługi DB bez resetu.
- Zatwierdzić plan migracji i kopii zapasowej po obejrzeniu różnic schematu.
- Samodzielnie potwierdzić dostęp do istniejącego konta albo uzgodnić zatwierdzony reset tej samej tożsamości.
- Zatwierdzić metodę bezpiecznego zdalnego dostępu (VPN/tunel/IDE) i kanał zgłaszania błędów.
- Podjąć decyzję o zgodnym z RBAC nadawaniu potrzebnych, ograniczonych grantów RAW; nie żądać samonadania ani obejścia zabezpieczeń.
- Zatwierdzić opt-in RAW wyłącznie w izolowanym środowisku lokalnym. Odbiór INT/PRD będzie możliwy po ich implementacji.

## 9. Potwierdzenie trybu READ-ONLY

Podczas audytu nie zmieniono kodu, konfiguracji, bazy, kont, uprawnień, wolumenów ani kontenerów. Nie wykonano migracji, resetu, deploymentu, logowania, operacji biznesowej, aktywacji modułu ani testowego zapisu. Wykonano wyłącznie odczyt konfiguracji i stanu, SELECT-y oraz GET-y. Jedynym utworzonym plikiem jest niniejszy raport. Próba przesłania go do wskazanego folderu Drive nie powiodła się, ponieważ przeglądarka Windows nie może odczytać ścieżki WSL; plik nie został przesłany i wymaga ręcznego uploadu.

## Odpowiedź końcowa

**Czy obecne środowisko VELTRA 2.0 nadaje się do osobistego odbioru modułów przez właściciela? NIE.**

Aby nadawało się do odbioru, należy — po akceptacji właściciela — przywrócić bazę istniejącego izolowanego stosu Stage 6A.1 bez resetu, zatwierdzić i wykonać niezbędne migracje na tej bazie, uruchomić zgodny frontend i backend, potwierdzić bezpieczny dostęp właściciela i jego istniejącego konta, a RAW testować wyłącznie przez zatwierdzony opt-in i prawidłowe RBAC. INT/PRD wymagają najpierw implementacji. Żadne z tych działań nie zostało wykonane w ramach audytu.
