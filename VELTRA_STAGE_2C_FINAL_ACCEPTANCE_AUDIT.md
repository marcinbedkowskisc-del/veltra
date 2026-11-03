# VELTRA 2.0 — końcowy audyt akceptacyjny Stage 2C

**Tryb:** niezależny audyt bezpieczeństwa i regresji, bez zmian kodu aplikacji  
**Zakres:** precyzja finansowa, upgrade RBAC istniejącej organizacji, poprzednie ustalenia Stage 2C, regresja oraz przyczyna nieaktualnej etykiety frontendowej  
**Werdykt Stage 2C: REJECT**

## Podsumowanie

Nie można zaakceptować Stage 2C. Potwierdzono błąd **MEDIUM** w trwałości odwołania uprawnień przy wielopoziomowym łańcuchu jawnych upgrade'ów RBAC. Odwołanie nadrzędnego grantu delegowania unieważnia bezpośredni grant pochodny, ale nie unieważnia dalszego grantu utworzonego przez kolejny upgrade. Jest to sprzeczne z gwarancją trwałego cofania uprawnień Stage 2B.

Pozostałe sprawdzone zabezpieczenia oraz naprawy wcześniejszych ustaleń MEDIUM/LOW pozostają skuteczne w zakresie testów i dowodów wymienionych poniżej. Nie równoważy to otwartego błędu RBAC ani nie uzasadnia `ACCEPT`.

`RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Nie zmieniano kodu aplikacji, istniejących baz, kontenerów ani wolumenów aplikacyjnych; nie uruchamiano migracji live, deploymentu, commitów ani Stage 2D.

## Potwierdzone ustalenie

| # | Ważność | Plik | Linie | Podatność | Pewność |
|---|---|---|---|---|---:|
| 1 | 🟡 MEDIUM | `backend/database/migrations/2026_11_03_000015_precision_and_permission_upgrade.php`; `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php` | 87–129; 47–76 | Grant RBAC utworzony przez późniejszy upgrade może pozostać skuteczny po odwołaniu nadrzędnego grantu delegowania, ponieważ funkcja sprawdzająca operację nie weryfikuje rekurencyjnie `bootstrap_operation_id` grantu authority. | 10/10 |

### Dowód i reprodukcja

Funkcja PostgreSQL `raw_materials_permission_upgrade_authority_is_effective()` łączy operację z jej `authority_grant_id` i sprawdza między innymi, czy grant authority jest aktywny, ma permission delegowania oraz nie ma zdarzenia `REVOKED`. Nie sprawdza jednak skuteczności operacji bootstrapującej sam grant authority. Tymczasem upgrade zapisuje granty z `delegation_source_id = NULL` i `bootstrap_operation_id = $operationId` (`RawMaterialPermissionUpgrade.php`, około linii 121–155). Rekurencyjne wyliczenie grantów w `RawMaterialAuthorization` sprawdza operację bieżącego grantu, ale nie domyka łańcucha po stronie grantów authority.

W odizolowanym środowisku testowym odtworzono następujący łańcuch:

1. Administrator B uzyskuje skuteczne prawo delegowania z wcześniejszego grantu.
2. B wykonuje jawny upgrade, nadając C prawo delegowania.
3. C wykonuje kolejny jawny upgrade, nadając D uprawnienie do danych komercyjnych.
4. Cofnięcie nadrzędnego grantu użytego jako źródło authority dla upgrade'u C powoduje, że grant delegowania C przestaje być skuteczny.
5. Grant D pozostaje skuteczny, ponieważ sprawdzenie operacji C potwierdza wciąż nieodwołany wiersz grantu C, ale nie sprawdza rekurencyjnie źródła jego authority.

To potwierdza obejście oczekiwanej propagacji trwałego odwołania w łańcuchu wielopoziomowym. Istniejące testy upgrade'u sprawdzają m.in. pojedynczy poziom, autoryzację, idempotencję, audyt oraz cofnięcie; nie obejmują opisanego łańcucha multi-hop.

**Skutek:** odbiorca dalszego grantu może zachować dostęp po odebraniu uprawnienia delegującego, na którym oparto ten grant. Wymaga naprawy i niezależnej ponownej weryfikacji przed akceptacją Stage 2C lub użyciem upgrade'u uprawnień. W tym audycie nie wprowadzono poprawki.

## Ocena pozostałych obszarów

### Precyzja finansowa

- Migracja 015 implementuje PostgreSQL `numeric` HALF-EVEN bez końcowego odtwarzania wyniku przez dzielenie, które wcześniej traciło cyfry przy dużej części całkowitej.
- Testy PostgreSQL sprawdzają wartość `50000000.12345678901234`, wartość ujemną, ułamki oraz dodatnie i ujemne remisy HALF-EVEN; odpowiednie przypadki znajdują się w `backend/tests/Feature/RawMaterialsTest.php`, około linii 600–673.
- Niezależny test API dla dużych wartości zachował dokładny wynik dziesiętny jako tekst: `99999999999999999999.12345678 / 7` dało `14285714285714285714.16049382571429`. Nie stwierdzono konwersji PHP `float` w sprawdzonych ścieżkach finansowych.
- W osobnym upgrade-teście funkcja PostgreSQL zwróciła dokładnie `50000000.12345678901234` przy skali 14.
- Nie potwierdzono otwartego problemu precyzji w testowanych ścieżkach.

### Upgrade RBAC istniejącej organizacji

Ścieżka jest jawna, wymaga administratora i skutecznego prawa delegowania, ponownego potwierdzenia hasła, wskazania odbiorcy i permissionów, uzasadnienia oraz klucza idempotencji. Rejestr operacji i event audytowy są append-only. Migracja nie nadaje automatycznie grantów. Upgrade-path test po zastosowaniu migracji 015 zachował wcześniejsze granty, utworzył wyłącznie jawnie wskazany grant, odtworzył identyczne żądanie bez duplikacji i odrzucił zmianę parametrów przy ponownym użyciu klucza.

Te poprawne właściwości nie naprawiają luki propagacji revocation opisanej wyżej. Zgodnie z ADR-001 instalacja ma jedną organizację/klienta; brak testu dwóch organizacji w jednej instalacji jest zgodny z tą architekturą i nie był powodem odrzucenia. Nadal obowiązują granice organizacji/lokalizacji wewnątrz instancji.

### Wcześniejsze ustalenia i inne kontrole

- Dwa wcześniejsze ustalenia MEDIUM — ujawnienie źródłowej ceny bez prawa kosztowego oraz brak części kontroli lifecycle — uznano za naprawione w niezależnych testach API/PostgreSQL.
- LOW dotyczące rewizji produktu po archiwizacji produktu/dostawcy uznano za naprawione w serwisie i triggerach PostgreSQL.
- LOW dotyczące replay synchronizacji NBP oraz konfliktu zmienionych parametrów zostały zaakceptowane w osobnym audycie remediacji.
- Poprawka powtarzalności migracji historycznych została sprawdzona w osobnym disposable audycie. Powtarzanie `migrate:fresh` jest destrukcyjne i nie może być stosowane wobec zachowywanych danych.
- Nie stwierdzono nowego wycieku cen przez zwykły katalog, obejścia gatingu `RAW_MATERIALS`, ani edycji historii komercyjnej w sprawdzonych ścieżkach.
- Nie zmieniono stanu `RAW_MATERIALS`; nadal pozostaje `NOT_READY` i nieaktywne.

## Testy i ograniczenia

Wszystkie destrukcyjne migracje/testy wykonano na odizolowanych, jednorazowych zasobach PostgreSQL 17 i Redis 7.4 w sieci Docker `internal=true`, bez trwałych mountów i opublikowanych portów. Runner używał tymczasowej kopii backendu. Nie używano istniejących baz ani wolumenów aplikacyjnych.

### Wykonane niezależnie w tym audycie

| Sprawdzenie | Wynik |
|---|---|
| Skupione testy Stage 2C/RBAC | **26 testów, 296 asercji — PASS** |
| Pełna regresja backendu | **173 testy, 1435 asercji — PASS** |
| Migracje na świeżej bazie w odizolowanym środowisku | **15/15 — PASS** |
| Dodatkowa próba multi-hop revocation | **Luka odtworzona**; dalszy grant pozostawał skuteczny po cofnięciu grantu nadrzędnego |
| Test API dokładnej arytmetyki dziesiętnej | **PASS**; wynik zachowany bez utraty cyfr |
| Upgrade-path 001–014 → 015 na istniejącej, disposable bazie | **PASS**; zachowano 2 wcześniejsze granty; zastosowano wyłącznie migrację 015 |
| Idempotentny replay upgrade'u i konflikt zmienionych parametrów | **PASS**; jeden rekord operacji, jeden grant i jeden event audytowy; retry bez duplikacji, zmiana parametrów odrzucona |
| Ponowne `migrate` po migracji 015 | **PASS**; `Nothing to migrate` |

Upgrade-path używał osobnej bazy utworzonej dla audytu: najpierw zastosowano 14 migracji, przygotowano syntetyczną organizację z wcześniejszymi grantami przez usługi/model aplikacji, zastosowano 015, a następnie sprawdzono zachowanie grantów i upgrade. Nie jest to dowód wdrożenia ani migracji bazy klienta.

Wyniki 26/296 i 173/1435 oraz wcześniejsza reprodukcja multi-hop pochodzą z niezależnych testów wykonanych w tej samej rundzie audytu przed jej skróceniem. Dodatkowy test upgrade-path opisany powyżej wykonano po wznowieniu. Nie należy sumować liczby asercji niestandardowego skryptu upgrade’u z liczbą asercji PHPUnit.

### Wyniki historyczne — nie są testami tej rundy

Wcześniejsze raporty odrębnie opisywały testy napraw disclosure/lifecycle, archiwizacji rewizji, retry NBP i migracji. Ich werdykty `ACCEPT` odnoszą się wyłącznie do zakresów tych audytów. Nie zastępują bieżącej reprodukcji luki multi-hop ani nie podnoszą werdyktu całej Stage 2C.

## Etykieta etapu frontendowego

Kod repozytorium importuje `developmentStageLabel` z `frontend/src/config/developmentStage.ts`; bieżąca centralna wartość to `ETAP 2C / WERYFIKACJA KOŃCOWA`. Działający `veltra-frontend-1` nadal serwuje w `/app/src/App.tsx` tekst `STAGE 1A / FUNDAMENT TECHNICZNY`. Compose wskazuje obraz `veltra-frontend:stage1f-polish-localization`, a frontendowy Dockerfile kopiuje źródła do obrazu; Compose nie montuje źródeł hosta do kontenera. Przyczyną jest więc stary obraz/kontener, a nie centralna konfiguracja w bieżącym worktree.

Wymagana późniejsza czynność operacyjna: po odrębnej akceptacji przebudować frontendowy obraz i odtworzyć wyłącznie usługę frontendową. Nie wykonano tego w audycie read-only i nie zmieniano działających kontenerów.

## Wniosek końcowy

**REJECT — Stage 2C nie jest zaakceptowana.** Przed ponowną oceną trzeba skorygować sprawdzanie skuteczności authority grantów tak, aby trwałe cofnięcie propagowało się przez cały łańcuch upgrade'ów, dodać regresję multi-hop w API i PostgreSQL oraz zlecić niezależny audyt poprawki.

Do tego czasu nie należy traktować ścieżki upgrade'u permissionów jako bezpiecznej dla wielopoziomowego delegowania. `RAW_MATERIALS` musi pozostać `NOT_READY` i nieaktywne; Stage 2D pozostaje wstrzymana.
