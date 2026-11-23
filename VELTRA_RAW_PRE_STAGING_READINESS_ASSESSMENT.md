# VELTRA 2.0 — Ocena gotowości RAW przed STAGING

## 1. Werdykt

**RAW nie jest obecnie gotowy do ręcznych testów na STAGING ani do zmiany readiness.** Jest to wniosek o dostępności, nie o awarii autoryzacji: kod produkcyjny celowo utrzymuje `RAW_MATERIALS` jako `NOT_READY`; backend odmawia dostępu do tras RAW, dopóki moduł nie będzie gotowy i osobno włączony dla lokalizacji. Nie zmieniano gotowości ani stanu żadnej capability.

Podstawowe ścieżki katalogu, szczegółów, tworzenia i edycji istnieją, mają testy i są połączone z aplikacją. Nie oznacza to, że można ich użyć w przeglądarce na bieżącym STAGING. Równocześnie część zatwierdzonego zakresu ma API bez interfejsu użytkownika, w szczególności obsługa dokumentów, import CSV i workflow zgodności produktów dostawców. Istniejący globalny gate nie pozwala włączyć wyłącznie małego fragmentu RAW do rzeczywistych testów przeglądarkowych.

**BOSS RAW jest odrębnym, tylko do odczytu zakresem.** Jego frontend i API są zaimplementowane, ale `BOSS_RAW_CATALOG_READ` także ma kodowe `ready: false`. Nie stanowi to zastępczej drogi do testowania pełnego RAW.

| Priorytet | Liczba |
|---|---:|
| P0 | 0 |
| P1 | 4 |
| P2 | 4 |
| P3 | 1 |

Brak P0 oznacza, że w tym ograniczonym przeglądzie nie stwierdzono blokera bezpieczeństwa ani integralności danych wymagającego alarmowego działania. Nie jest to szeroki audyt bezpieczeństwa.

## 2. Inwentarz funkcji

„Runtime” odnosi się do prawdziwego STAGING. Tam, gdzie endpointy należą do grupy RAW, ich bieżące użycie blokuje `NOT_READY`, niezależnie od istnienia komponentu lub testów.

| Funkcja | Klasyfikacja | Stan implementacji i ograniczenia |
|---|---|---|
| Katalog, wyszukiwanie, filtry i paginacja | **IMPLEMENTED AND TESTED** | React, parsery API i endpointy serwerowe; testy katalogu oraz backendu przeszły. Na STAGING zablokowane przez readiness. |
| Szczegóły materiału i historia rewizji | **IMPLEMENTED AND TESTED** | Drawer prezentuje szczegóły i historię rewizji/zdarzeń workflow. Nie jest to pełny interfejs dziennika wszystkich zdarzeń audytowych RAW. |
| Tworzenie materiału | **IMPLEMENTED AND TESTED** | Formularz tworzy szkice i obsługuje walidację, ceny, jednostki i odpowiednie granty; API sprawdza uprawnienia. Na STAGING zablokowane przez readiness. |
| Edycja materiału | **IMPLEMENTED AND TESTED** | Formularz pobiera wersję edytora, używa `If-Match`, rozróżnia konflikty oraz odświeża dane. Dalsze zatwierdzenie rewizji pozostaje osobnym uprawnionym workflow. |
| Kategorie | **IMPLEMENTED AND TESTED** | PA ma administrację hierarchią kategorii RAW, a RAW ma prostszy menedżer; obie ścieżki korzystają z `raw_material_categories`. Nie są to dwa niezależne katalogi danych. |
| Jednostki i przeliczenia | **IMPLEMENTED AND TESTED** | Słownik PA oraz formularze RAW obsługują jednostki i przeliczenia; serwis i testy sprawdzają wymiary oraz wartości. |
| Słownik alergenów i deklaracje | **IMPLEMENTED AND TESTED** | PA zarządza słownikami, a formularze/workflow RAW obsługują deklaracje i ich zatwierdzanie. To, że interfejs istnieje, nie omija gate'u RAW. |
| Dostawcy — katalog organizacji | **IMPLEMENTED AND TESTED** | Jest ekran PA dostawców i RAW może wyszukiwać/przypisywać dane handlowe zgodnie z osobnymi grantami. |
| Produkty dostawców i ich zgodność z rewizją surowca | **PARTIALLY IMPLEMENTED** | Backend ma produkty, rewizje, oferty i immutable oceny zgodności oparte na dowodzie; `PENDING`/`INCOMPATIBLE` nie oznacza zatwierdzonego produktu. W `frontend/src/rawMaterials` nie znaleziono interfejsu pełnego zarządzania tym workflow. |
| Ceny referencyjne i historia cen | **IMPLEMENTED AND TESTED** | Formularze/podgląd oraz historia korzystają z osobnych uprawnień finansowych; ceny nie są domyślnie ujawniane przez zwykły katalog. |
| Dokumenty, skanowanie AV i pobieranie | **PARTIALLY IMPLEMENTED** | Backend ma upload stagingowy, skanowanie, przypięcie do rewizji, odczyt/preview/download i testy. Nie ma odpowiadającego interfejsu RAW do uploadu, ponowienia skanu i obsługi dokumentów. |
| CSV — eksport | **IMPLEMENTED AND TESTED** | Istniejący katalog ma eksport zaznaczenia/filtrów przez istniejący workflow; obowiązują osobne kontrole pól finansowych i dostawców. |
| CSV — import | **PARTIALLY IMPLEMENTED** | Backend ma upload/preview/commit/wynik i testy, ale nie znaleziono interfejsu importu w katalogu RAW. |
| Audyt i historia | **PARTIALLY IMPLEMENTED** | Mutacje zapisują audyt, a szczegóły prezentują historię rewizji i cen. Nie znaleziono endpointu/ekranu RAW do przeglądania pełnego `raw_material_audit_events`. |
| Granty RAW i delegowanie organizacyjne | **IMPLEMENTED AND TESTED** | Szczegóły użytkownika PA pokazują przypisanie, efektywność, źródło, delegowanie, okres i historię. Nadawanie/cofanie korzysta z istniejącego serwisu i nie wynika z samej roli Administratora. Mutacje zachowują zależność od readiness oraz istniejącego prawa delegowania. |
| Tożsamość, membership i kontekst lokalizacji | **IMPLEMENTED AND TESTED** | Logowanie i wybór kontekstu są w istniejącym `AuthPanel`; backend ponownie sprawdza aktywną tożsamość, organizację, membership i kontekst. |
| Udostępnianie materiałów lokalizacjom | **PARTIALLY IMPLEMENTED** | Backendowe reguły i endpointy są obecne oraz objęte regresją, ale nie znaleziono interfejsu zarządzania udostępnieniami w katalogu. |
| Readiness i aktywacja RAW | **BLOCKED BY MODULE READINESS** | Produkcyjna definicja pozostaje `NOT_READY`; brak stanu lokalizacji oznacza brak dostępności. Konfiguracja modułów jest dostępna przez API Stage 1E, ale nie przez widoczną sekcję administracyjną modułów. |
| Katalog BOSS RAW | **IMPLEMENTED BUT NOT FULLY VERIFIED** | Istnieje niezależny endpoint i tylko-odczytowy ekran listy/szczegółów z nazwami dostawców z zatwierdzonych rewizji. Capability BOSS pozostaje `NOT_READY`; jej statusu nie utożsamia się z readiness RAW. |

## 3. Frontend, nawigacja i rzeczywiste przepływy

- `App.tsx` rozpoznaje `/raw-materials` i `/boss/raw-materials`, używa istniejącej powłoki i montuje osobne komponenty dla RAW/BOSS. `AuthPanel` ładuje profil i przypisane lokalizacje z serwera, a kontekst jest wymagany przed otwarciem widoku.
- Linki RAW/BOSS w powłoce nie są granicą autoryzacji. To backend sprawdza sesję, tożsamość, kontekst, membership, capability i granty; nie stwierdzono, by sam wybór roli po stronie klienta odsłaniał dane.
- Widok RAW pobiera capabilities i jawnie prezentuje `NOT_READY`/503; oczekiwane odpowiedzi 401/419 i 403 są obsługiwane. Testy obejmują reset danych przy zmianie kontekstu i odmowę dostępu.
- PA zawiera ekrany użytkowników/uprawnień RAW, kategorii, jednostek, dostawców i słowników alergenów. Nie ma interfejsu administracji readiness/stanem modułów; element „Moduły” w powłoce jest niedostępny w prototypie.
- Testy frontendu są testami komponentów/API z mockami. Nie przeprowadzono w tym zadaniu przeglądarkowego end-to-end z działającym API na STAGING. Izolowany `compose.testing.yaml` nie uruchamia frontendu.
- Przeglądany stan STAGING, migracje, sesje, granty użytkowników i wiersze `location_module_states` pozostają **niezweryfikowane**. Nie odczytywano ani nie zmieniano istniejących baz lub kont.

## 4. Autoryzacja, readiness i aktywacja

### RAW

Aktualny kontrakt w kodzie:

1. `DefinedModuleCatalog` zawiera `RAW_MATERIALS`, ale produkcyjna definicja nie ustawia `ready: true`.
2. `ConfiguredModuleAvailability` odmawia, gdy definicji nie ma, moduł nie jest gotowy, lokalizacja nie jest wspierana, brak jawnego `enabled=true` lub zależność modułu nie jest dostępna.
3. Grupa tras `/api/v1/raw-materials` korzysta z `RequireRawMaterialsAvailability`; niedostępność daje 503 `RAW_MATERIALS_MODULE_UNAVAILABLE`. Endpoint capabilities jest oddzielny i pozwala frontendowi wyświetlić stan bez otwierania katalogu.
4. `RawMaterialAuthorization` sprawdza świeżą aktywną/zweryfikowaną tożsamość, aktywną organizację i lokalizację, zgodność organizacji, membership, kontekst wymagany przez operację, dostępność modułu oraz granularny grant organizacyjny.
5. Granty organizacyjne mają oddzielną delegację, ważność, unieważnienie i pochodzenie. Administrator nie otrzymuje automatycznie prawa `RAW_MATERIALS_PERMISSION_DELEGATE` ani grantów biznesowych.
6. Konfiguracja lokalizacyjna jest osobna od grantów. Serwis Stage 1E odrzuca włączenie modułu `NOT_READY`; nie wolno obchodzić tego warunku przez grant, rolę lub UI.

### BOSS

`BOSS_RAW_CATALOG_READ` ma własną definicję organizacyjną, własne operacje `list`/`detail`, ograniczenia widoczności i niezależną gotowość `false`. BOSS nie dostaje prawa zapisu ani automatycznego RAW dostępu. Supplier names w katalogu BOSS pochodzą z zatwierdzonych snapshotów; ta ścieżka nie zastępuje zwykłego RAW API.

### Kryteria przed późniejszą zmianą readiness RAW

Nie rekomenduje się readiness `READY` ani aktywacji lokalizacji, dopóki łącznie:

- zaakceptowany pełny zakres ma działające API **i** interfejsy przeglądarkowe, w tym dokumenty/AV, zgodność dostawców, import/eksport i pozostałe wymagane przepływy;
- RBAC, delegowanie, primary-context, udostępnianie lokalizacjom i separacja danych finansowych są przetestowane end-to-end bez skrótów roli;
- rewizje/lifecycle, audyt, alergeny, dokumenty, ceny/kursy oraz import/eksport spełniają zaakceptowane kontrakty i mają pozytywne oraz odmowne testy;
- regresje backendu i frontendu przechodzą w izolacji, w tym brak dostępu przy `NOT_READY` oraz przy braku/wyłączonym stanie lokalizacji;
- jest osobna akceptacja właściciela i kontrolowana procedura zmiany definicji readiness, a dopiero później jawna konfiguracja wybranej lokalizacji.

Zmiana gotowości RAW nie aktywuje ani nie przygotowuje `BOSS_RAW_CATALOG_READ`; to odrębna decyzja i gate.

## 5. Testy wykonane w tej ocenie

Testy backendu korzystały wyłącznie z zatwierdzonego `scripts/compose-test` i projektu `veltra-test-application`: PostgreSQL 17/Redis/ClamAV w izolowanej sieci, baza testowa na `tmpfs`. PHP CLI ustawiono jawnie na `memory_limit=512M`. ClamAV odpowiedział `PONG` przed testami. Migracje wykonano wyłącznie w tym jednorazowym środowisku testowym; po zakończeniu usunięto tylko jego trzy kontenery i sieć, bez named volumes.

| Zakres | Wynik |
|---|---|
| RAW + BOSS backend: 13 klas (katalog, capabilities, bootstrap/delegowanie, zgodność dostawców, dokumenty, Stage 2D, model rewizji/audytu, integracja lokalna i współbieżność, BOSS API/autoryzacja/provenance/współbieżność) | **286 testów, 3 054 asercje — PASS**, 513,32 s |
| PA `AdministrationUsersTest` — granty RAW, efektywność, readiness i izolacja organizacji | **6 testów, 56 asercji — PASS** |
| Frontend RAW/BOSS/PA/App — 17 plików testowych | **214 testów — PASS** |
| TypeScript/Vite build | **PASS**; Vite zgłosił nieblokujące ostrzeżenie o chunku 558,27 kB |
| Oxlint | **PASS**; pozostało ostrzeżenie Fast Refresh w `frontend/src/visualPreview.tsx:39` |
| ClamAV | `clamdscan --ping=60:2` — **PONG** |
| `git diff --check` przed raportem | **PASS** |

Testy negatywnych żądań emitowały oczekiwane wpisy `testing.ERROR`; runner zakończył się kodem 0, bez nieudanych testów. Wyniki izolowanych testów nie są dowodem działania wdrożenia STAGING.

## 6. Priorytetyzowane blokery

| Priorytet | Obszar | Dokładny blocker | Dowód | Minimalne działanie naprawcze | Szacunek | Wymagane przed testami? |
|---|---|---|---|---|---|---|
| P1 | A — aktywacja; B — każdy test RAW na STAGING | `RAW_MATERIALS` jest celowo `NOT_READY`, więc cała grupa tras RAW jest niedostępna. Nie da się bezpiecznie uruchomić tylko katalogu przez konfigurację lokalizacji. | `DefinedModuleCatalog`, `ConfiguredModuleAvailability`, `RequireRawMaterialsAvailability`; test capabilities zwraca `NOT_READY`/`available=false`. | Nie obchodzić gate'u. Po zamknięciu pozostałych kryteriów uzyskać niezależną akceptację, a readiness i wybraną lokalizację zmieniać oddzielnie, uprawnionym procesem. | Gate przed jakimkolwiek testem RAW | **Tak** |
| P1 | A — pełna gotowość; B — test dokumentów | Upload/scan/attach/preview/download mają API, ale brak interfejsu użytkownika do wykonania pełnego przepływu. | `backend/routes/api.php`; kontroler dokumentów; brak klienta/komponentu dokumentów w `frontend/src/rawMaterials`. | Dodać klienta i widok dokumentów w szczegółach/formularzu, używając istniejących endpointów; pokazać stany skanowania i odmowę pobrania przed CLEAN. | 3–5 dni | **Tak** dla pełnego zakresu; nie dla osobno zatwierdzonego smoke testu podstawowego, którego obecny gate i tak nie umożliwia |
| P1 | A — pełna gotowość; B — test dostawców | Brak UI zarządzania produktami dostawców, rewizjami i oceną zgodności z dowodem dla dokładnej rewizji surowca. | Backendowe trasy `supplier-products` i `compatibility-assessments`; kontrakt B3 w Stage 2A; brak UI w `frontend/src/rawMaterials`. | Dodać workflow w szczegółach materiału, ponownie używając serwisów API; `PENDING`/`INCOMPATIBLE` nigdy nie może wyglądać na zatwierdzone. | 3–5 dni | **Tak** dla pełnego scope i testu zgodności |
| P1 | A — pełna gotowość; B — test importu | Import CSV ma endpointy preview/commit/results, ale brak interfejsu importu. Eksport katalogu jest już zintegrowany. | Trasy `catalog-imports` oraz `catalog-exports`; użycie eksportu w `RawMaterialsCatalogPage`; brak komponentu importu. | Dodać etapowy ekran preview/commit/wynik w istniejącym katalogu, bez implementowania drugiego backendowego workflow; testować odrzucenie preview z błędami i replay. | 2–4 dni | **Tak** dla pełnego scope; importu nie da się obecnie przetestować przez UI |
| P2 | B — konfiguracja testów | Administracja modułami jest API-only; PA nie ma UI do odczytu/konfiguracji stanu lokalizacji. | Trasy `/api/v1/security/modules/*` i Stage 1E; nawigacja „Moduły” jest niedostępna w `ApplicationShell`. | Albo udokumentować kontrolowaną obsługę istniejącego autoryzowanego API, albo później dodać ekran PA bez zmiany reguł readiness. | 1–3 dni | **Nie**, jeśli uprawniony operator wykona zatwierdzoną konfigurację przez API; wymagane dla samodzielnej obsługi w PA |
| P2 | B — testy wielolokalizacyjne | Brak UI do udostępniania materiałów między lokalizacjami, mimo że serwerowy kontrakt istnieje. | Backendowe trasy sharing/bulk-sharing i testy; brak klienta/ekranu sharing w RAW. | Dodać panel udostępnień do katalogu/szczegółów, używając obecnych reguł read-only dla odbiorcy. | 2–4 dni | **Tak** dla testowania udostępniania; nie dla pojedynczej lokalizacji |
| P2 | A — audytowalność; B — analiza zmian | UI pokazuje rewizje i ceny, ale nie pełny dziennik `raw_material_audit_events`. | Tabela append-only i zapisy audytu istnieją; w `backend/routes/api.php` nie znaleziono odpowiedniej trasy odczytu audytu RAW. | Potwierdzić zaakceptowany zakres audytu; jeśli wymagany podgląd pełny, dodać minimalny odczyt ograniczony do organizacji i `AUDIT_VIEW` oraz widok historii. | 2–3 dni, po potwierdzeniu zakresu | **Tak** dla pełnego audytu; nie dla podstawowego smoke testu katalogu |
| P2 | B — środowisko | Bieżące zdrowie STAGING, migrowana wersja, konta/granty/konteksty oraz stan `location_module_states` nie były badane. | W tej ocenie nie połączono się ze STAGING ani z istniejącą bazą; wcześniejsze raporty nie dowodzą bieżącego runtime. | Po spełnieniu code gate wykonać osobny read-only preflight i zatwierdzone testy browser/API na STAGING. | 0,5–1 dnia operacyjnie | **Tak**, przed ogłoszeniem testów STAGING |
| P3 | C — UX administracji | Prosty manager kategorii w RAW duplikuje część funkcji PA; oba widoki operują na tej samej tabeli, więc nie stwierdzono rozbieżnych źródeł danych. | `RawMaterialCategoryManager.tsx` oraz PA `CategoryManagementPage`/ten sam serwis kategorii. | Ujednolicić ścieżkę zarządzania w późniejszym porządkowaniu UX; bez zmiany modelu. | 0,5–1 dnia | **Nie** |

### Czy można testować mniejszy zatwierdzony zakres?

**Nie w rzeczywistym STAGING przez obecny moduł RAW.** `RAW_MATERIALS` ma jeden wspólny gate; endpointy katalogu również są nim objęte. Zmiana flagi tylko po to, aby przetestować katalog, oznaczałaby fałszywą deklarację gotowości całego modułu. Dopuszczalne pozostają testy na izolowanych fixture'ach/preview — nie są one jednak testem prawdziwego STAGING. BOSS nie jest obejściem, ponieważ jego własna capability także pozostaje `NOT_READY`.

## 7. Minimalna sekwencja prac

| Kolejność | Funkcja i pliki/punkty integracji | Testy skupione | Kryterium akceptacji | Zależności |
|---|---|---|---|---|
| 1 | Dokumenty: nowy komponent w `frontend/src/rawMaterials/`, integracja w `RawMaterialDetailDrawer.tsx`, `RawMaterialCreateForm.tsx` i `RawMaterialEditForm.tsx`; klient w `rawMaterials/api.ts`. API i skaner istnieją. | Component/API: upload, wieloplikowe błędy, retry, stan CLEAN/INFECTED/ERROR, zmiana kontekstu, preview/download i brak dostępu. Istniejące `RawMaterialDocumentStorageTest`. | UI nie ujawnia dokumentu przed skutecznym skanem; każde pobranie przechodzi istniejącą autoryzację; stale response po zmianie kontekstu nie pokazuje danych. | Istniejący backend dokumentów/AV; bez nowej ścieżki storage. |
| 2 | Produkty i zgodność dostawców: rozszerzyć `rawMaterials/api.ts`, dodać komponent w istniejącym widoku szczegółów/workflow. Zachować źródło dowodowe i powiązanie z konkretną rewizją. | `RawMaterialCompatibilityAssessmentTest`, `RawMaterialStage2dApiTest` oraz UI dla PENDING/COMPATIBLE/INCOMPATIBLE i nieaktualnego review. | Zgodność można nadać wyłącznie istniejącą autoryzowaną operacją z dowodem; produkt bez bieżącego `COMPATIBLE` nie jest prezentowany jako zatwierdzony. | Krok 1, jeśli dowód zgodności wybiera się z dokumentów. |
| 3 | Import CSV: rozszerzyć `rawMaterials/api.ts`, dodać ekran preview/commit/results i wpiąć go w `RawMaterialsCatalogPage.tsx`; nie zmieniać gotowego eksportu. | Backend CSV/import w `RawMaterialsTest`; komponent/API: walidacja, błędy preview, zatwierdzenie, konflikt/replay, anulowanie i sesja. | Operator widzi pełny preview przed zapisem; commit nie wykonuje się z błędami; wynik i błąd nie powodują sukcesopodobnego fallbacku. | Niezależne od kroku 1–2. |
| 4 | Udostępnienia i ewentualny pełny dziennik audytu: rozszerzyć `rawMaterials/api.ts` i istniejące `RawMaterialsCatalogPage.tsx`/`RawMaterialDetailDrawer.tsx`; dla dziennika brakujący read contract wymaga osobnego, minimalnego zatwierdzenia. | Testy sharing/concurrency; tenant isolation, `AUDIT_VIEW`, odmowa cross-organization i frontendowy kontekst. | Odbiorca może wyłącznie odczytać dozwoloną zatwierdzoną rewizję; historia nie wycieka między organizacjami. | Potwierdzenie dokładnego zakresu audytu. |
| 5 | Finalny gate i testy integracyjne: wykorzystać istniejące `App.tsx`, `AuthPanel.tsx`, RAW/BOSS API oraz `scripts/compose-test`; nie dodawać testowego override do kodu produkcyjnego. | Izolowane backend + frontend build/lint; regresje auth/session, grantów/delegacji, readiness 503, zwykłego RAW, BOSS/provenance i dokumentów/AV. Następnie osobny staging preflight. | Wszystkie kryteria Stage 1E spełnione i zaakceptowane; test dowodzi fail-closed dla `NOT_READY` oraz braku/wyłączonego stanu lokalizacji; żadna rola ani testowy fixture nie tworzy produkcyjnego bypassu. | Kroki 1–4; osobna akceptacja przed zmianą readiness. |

## 8. Scenariusze ręcznej akceptacji — dopiero po odrębnej gotowości

1. Zalogować istniejące aktywne konto, wybrać przypisaną lokalizację i otworzyć RAW. Bez sesji/kontekstu aplikacja nie pokazuje danych; na serwerze wymagane są oba warunki.
2. Konto z aktywnym `RAW_MATERIALS_VIEW` wyszukuje zatwierdzony materiał po kodzie/nazwie, przechodzi paginację i otwiera szczegóły. Konto bez grantu otrzymuje odmowę; samo bycie administratorem tego nie zmienia.
3. Konto z `CREATE` tworzy szkic w dozwolonym primary context. Konto z `EDIT` zmienia go; nieaktualna wersja daje konflikt i nie nadpisuje nowszej rewizji.
4. Administrator PA przygotowuje kategorię, jednostkę, dostawcę lub wpis słownika alergenu w odpowiedniej sekcji. Następnie RAW odczytuje dokładnie ten sam słownik organizacji.
5. Dodany produkt dostawcy bez aktualnego dowodu/oceny pozostaje `PENDING`; `INCOMPATIBLE` nie staje się dostępnym zatwierdzonym snapshotem. Zmiana krytycznej rewizji unieważnia poprzednią ocenę.
6. Wysłanie dokumentu pokazuje wynik skanowania; materiał i link do pobrania są dostępne wyłącznie w ramach istniejącej autoryzacji i po poprawnym wyniku skanowania.
7. Podgląd importu CSV pokazuje odrzucenia przed commit; eksport nie zawiera pól finansowych bez odpowiedniego grantu.
8. Po zmianie konta/lokalizacji żądania poprzedniego kontekstu są anulowane lub ignorowane; wcześniejsze wiersze nie są renderowane w nowym kontekście.
9. Osobny test BOSS sprawdza wyłącznie zatwierdzone snapshoty i ich zatwierdzonych dostawców; brak capability/rola inna niż BOSS nie ujawnia katalogu, a UI nie wykonuje mutacji ani fallbacku do zwykłego RAW API.

Powyższe są kryteriami przyszłych testów, nie twierdzeniem, że przeszły na STAGING.

## 9. Rozbieżności dokumentacji i kodu

- `VELTRA_STAGE_2A_FINAL_ARCHITECTURE_REVIEW.md` opisuje historyczny etap, gdy `RAW_MATERIALS` nie było jeszcze w katalogu modułów. Aktualny kod już je zawiera, ale bez `ready: true`.
- Raport Stage 4A mówił, że tworzenie i archiwizacja są odłożone w zakresie Stage 4A. Późniejsze formularze tworzenia/edycji oraz Stage 3E-B.3.1 dodały te funkcje backendowe; bulk archive ma testowany kontrakt. Nie jest to aktualnie blocker backendowej archiwizacji.
- Stage 3E-B.3 raportował brak implementacji mutacji bulk archive z powodu decyzji właściciela. Późniejszy zaakceptowany Stage 3E-B.3.1 rozstrzygnął ją jako `ALL-OR-NOTHING` i dodał endpoint/testy. Frontendowy pełny workflow bulk archive pozostaje poza zbadanym UI.
- Historyczny `VELTRA_RAW_FUNCTIONAL_INVENTORY_AUDIT_2026-11-16.md` nie jest źródłem bieżącego statusu UI; obecny kod ma późniejsze formularze, testy i PA słowniki.
- Stage 1E Implementation Report stwierdza, że administracja modułami jest API-only. Obecny shell nadal nie udostępnia UI konfiguracji modułów; PA RAW categories/units/suppliers/alergens to odrębne ekrany, nie dowód istnienia panelu aktywacji.

## 10. Stan Git i raport

- Branch: `main`
- HEAD: `e1793519c7d025b92142db5b62084df240ebb94d`
- Przed utworzeniem raportu worktree był czysty. Po utworzeniu raportu jedynym nowym plikiem jest ten dokument; kod, testy, migracje i konfiguracja pozostają bez zmian.
- Przed raportem `git diff --check`: PASS; brak zmian tracked po testach.
- Nie zmieniano readiness/aktywacji, istniejących baz, grantów, kont ani STAGING. Nie wykonano commitu, pushu ani wdrożenia.
- Nie przesłano do Google Drive: w tej sesji nie był dostępny konektor Google Drive/upload. Raport pozostaje lokalnie; nie próbowano alternatywnego, nieautoryzowanego mechanizmu.

## 11. Zalecane następne zadanie

**Implementacja interfejsu dokumentów RAW w istniejących szczegółach/formularzu, bez zmian API, autoryzacji ani readiness.** Backendowe upload/scan/version/download kontrakty istnieją i mają testy; brak UI jest konkretnym blokerem pełnego manualnego testu dokumentów. Po nim należy dokończyć UI zgodności produktów dostawców i importu CSV, a readiness oceniać ponownie dopiero po pełnej, niezależnej akceptacji.
