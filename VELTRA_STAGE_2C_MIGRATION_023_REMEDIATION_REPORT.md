# VELTRA 2.0 — raport remediacji ustaleń audytu migracji 023

**Data:** 6 listopada 2026  
**Zakres:** remediacja F-1, R-1 i części luki pokrycia G-1 oraz wykonanie macierzy bezpośredniego DML F-2 na disposable PostgreSQL 17.  
**Werdykt:** **REMEDIACJA CZĘŚCIOWA — Stage 2C nie jest zaakceptowany.** F-1 i R-1 zostały zamknięte na testowym środowisku; istotne ryzyko bezpośredniego DML dziewięciu tabel (F-2) pozostaje jawne. Wymagany jest niezależny re-audyt.

## Zakres i bezpieczeństwo testów

Weryfikację wykonano na odrębnych zasobach Docker:

- PostgreSQL 17.11 i Redis 7.4 działały w dedykowanej sieci `internal=true`, bez opublikowanych portów.
- Dane PostgreSQL i Redis znajdowały się w `tmpfs`; runner również nie miał wolumenów aplikacyjnych. PostgreSQL miał wyłącznie montowanie tylko do odczytu skryptu inicjalizującego rolę runtime.
- Kod backendu testowano z kopii bez `.env`; testy logowały się rolą runtime, a nie rolą migracyjną.
- Istniejące kontenery, bazy i wolumeny VELTRA nie były używane do testów ani modyfikowane. Nie ustalano przez zmianę bazy, czy migracja 023 została wdrożona w jakiejkolwiek trwałej instalacji.

## Wprowadzone zmiany

1. Dodano forward-only migrację [2026_11_05_000024_harden_raw_material_integrity_search_path.php](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php). Migracje 011–023 pozostały niezmienione.
2. Funkcja walidująca `raw_materials` używa stałego `search_path`, a odwołania do tabel zatwierdzeń i rewizji są kwalifikowane przez `public`. Migracja ustawia również stały `search_path` funkcjom triggerów integralności z określonej grupy tabel.
3. Migracja 024 odrzuca stan niekompletny zamiast przechodzić dalej: wymaga oznaczenia migracji 022 i 023, prywatnych relacji wymaganych przez podpisy oraz dokładnie jednego poprawnego, 32-bajtowego klucza. Kontroluje także istnienie i zakres triggerów ochronnych.
4. Zakresy podpisów dla `role_assignments` są rozdzielone według operacji i roli. Trigger sprawdza, czy podpisany aktor oraz scope odpowiadają wierszowi i operacji. To zamyka potwierdzone ponowne użycie biletu `role.grant.ADMINISTRATOR` do unieważnienia przypisania oraz próbę podstawienia aktora.
5. Rozszerzono [AuthorizationMutationBoundaryTest.php](backend/tests/Feature/AuthorizationMutationBoundaryTest.php) o testy z faktycznymi poświadczeniami runtime: cieniowanie tabel przez `pg_temp`, odmowę modyfikacji chronionych ledgerów, ponowne użycie scope’u, zgodność aktora oraz macierz DML dziewięciu tabel.

Migracja 024 jest celowo nieodwracalna przez `down()`. Powrót lub dalsza korekta wymagają nowej, przetestowanej migracji do przodu.

## Ustalenia po remediacji

### F-1 — obejście walidacji zatwierdzenia przez `pg_temp`: zamknięte w testach

Test z rolą runtime tworzy tymczasowe tabele `raw_material_revision_approval_events` i `raw_material_revisions`, ustawia `search_path=pg_temp,public` i próbuje przejścia materiału z `VERIFICATION` do `ACTIVE`. Po migracji 024 operacja jest odrzucana; stan pozostaje `VERIFICATION`, zatwierdzona rewizja nadal jest pusta, a w publicznym ledgerze nie pojawia się fałszywy event. Próba potwierdza ustawiony `search_path` funkcji triggera.

Wynik dotyczy testowej bazy i dokładnie sprawdzonej ścieżki. Nie jest deklaracją bezpieczeństwa wszystkich funkcji bazy, wszystkich możliwych operacji SQL ani przejętego procesu Laravel.

### R-1 — migracja przechodząca bez wymaganej infrastruktury: zamknięte w testach

Na disposable bazie oznaczonej jako po migracjach 022/023, lecz bez `veltra_private.mutation_signing_keys`, migracja 024 zakończyła się błędem precondition. Nie wykonano częściowych zmian i nie zapisano wersji migracji 024. Świeża i standardowa ścieżka upgrade, w której wymagane obiekty istnieją, przeszły.

### G-1 — zakres ticketów i protected ledgers: częściowo zamknięte

- Odrzucono użycie scope’u grantu jako operacji revoke, niezgodność podpisanego aktora z `revoked_by` oraz użycie scope’u dla tabeli, do której nie jest przypisany.
- Bezpośrednie INSERT/UPDATE/DELETE do chronionych tabel RBAC i audytu, `TRUNCATE`, zmiana triggerów, `SET ROLE` do migratora i wywołanie prywatnej funkcji przez runtime zostały odrzucone w testach.
- Legalny bootstrap administratora, nadanie roli administratora przez serwis domenowy, bootstrap delegacji i upgrade uprawnień z eventem `PERMISSION_UPGRADE` działały w teście z runtime credentials.
- **Pozostaje ograniczenie architektury:** ticket nie jest związany z konkretnym identyfikatorem wiersza ani payloadem mutacji. Nie należy traktować go jako dowodu autoryzacji biznesowej ani jako ochrony przed SQL injection wykonywanym w czasie, gdy aplikacja ma aktywny ticket w danym scope. Ograniczenie to jest zgodne z zatwierdzonym modelem zaufania dla procesu Laravel, ale wymaga odrębnej oceny przy deklarowaniu gwarancji przeciw SQL-only.

### F-2 — bezpośredni DML dziewięciu tabel

Macierz wykonano z rolą runtime; wyniki poniżej są obserwacjami testowymi, a nie wnioskami wyłącznie z ACL.

| Tabela | Próba i wynik |
|---|---|
| `organization_suppliers` | Zmiana nazwy dostawcy przez bezpośredni UPDATE powiodła się; liczba wpisów komercyjnego audytu nie zmieniła się. |
| `supplier_products` | Bezpośredni INSERT produktu dla istniejących referencji organizacji powiódł się; produkt otrzymał stan `ACTIVE`. |
| `supplier_offers` | Próba INSERT niekompletnej oferty została odrzucona przez walidację bazy. Nie testowano poprawnego INSERT oferty ani manipulacji ceną. Ceny są zapisywane w osobnym chronionym ledgerze zdarzeń ofert. |
| `raw_material_categories` | Zmiana nazwy kategorii przez bezpośredni UPDATE powiodła się. |
| `raw_material_code_counters` | Bezpośrednia zmiana licznika kodów powiodła się i zmieniła jego wartość. |
| `raw_materials` | Dozwolona zmiana stanu `DRAFT → VERIFICATION → DRAFT` powiodła się bez serwisu Laravel; próba fałszywego zatwierdzenia po 024 została odrzucona (F-1). |
| `raw_material_revisions` | UPDATE istniejącej rewizji został odrzucony; INSERT rewizji wskazanej jako bieżąca robocza powiódł się bez kontroli Laravel. |
| `raw_material_revision_units` | UPDATE istniejącej jednostki został odrzucony; INSERT jednostki do nowej rewizji roboczej powiódł się. |
| `raw_material_aliases` | UPDATE istniejącego aliasu został odrzucony; INSERT aliasu do rewizji roboczej powiódł się. |

To potwierdza ekspozycję opisaną w F-2: constrainty i triggery ograniczają integralność strukturalną, ale nie zawsze dowodzą, że aktor jest uprawniony do operacji. Nie wykazano w tej macierzy obejścia izolacji między organizacjami ani manipulacji ceną. Nie dodano ogólnych triggerów podpisu do tych tabel: obecny ticket nie wiąże podpisu z celem ani treścią, więc jego rozszerzenie mogłoby dać mylące poczucie ochrony i ponownie otworzyć ryzyko wykorzystania biletu w aktywnej transakcji.

Bezpośredni DML pozostaje **otwartym ryzykiem**, niezamkniętym ustaleniem. Przed deklarowaniem ochrony SQL-only dla tych tabel należy podjąć osobną decyzję dla każdej domeny: pozostawić kontrolę w Laravel i zaakceptować ekspozycję runtime SQL, albo zaprojektować wąski interfejs DB z dowodem związanym z aktorem, organizacją, celem, payloadem i transakcją. Nie wdrożono takiego interfejsu bez projektu i testów.

## Wyniki weryfikacji

| Sprawdzenie | Wynik |
|---|---|
| Test granicy autoryzacji po rozszerzeniu macierzy DML | PASS — 1 test, 101 asercji, runtime credentials |
| Pełna regresja backendu po zmianach | PASS — 184 testy, 1619 asercji, 0 błędów, 0 pominięć |
| Świeże migracje 001–024 | PASS na disposable PostgreSQL 17 |
| Upgrade 001–021 → 022–024 | PASS na disposable PostgreSQL; syntetyczny historyczny rekord zachowany |
| Fail-closed przy brakującej infrastrukturze 022/023 | PASS — migracja 024 odrzucona bez częściowej zmiany |
| `php -l` dla migracji 024 i testu | PASS |
| Pint dla dwóch zmienionych plików | PASS (`php vendor/bin/pint --test ...`) |
| `sh -n docker/postgres/10-create-runtime-role.sh` | PASS |
| `docker compose config --quiet` | PASS z wyłącznie testowymi wartościami wymaganych zmiennych |
| `git diff --check` | PASS |

Wyniki migracji fresh/upgrade i fail-closed pochodzą z wcześniejszych wykonań w tej samej izolowanej sesji; późniejsza zmiana testowa zwiększyła pełną regresję z 1615 do 1619 asercji. Wszystkie powyższe testy backendu wykonano bez pominięć.

Syntetyczny rekord i testowa wartość atestacji dowodzą jedynie, że migracja zachowuje przykładowy rekord i przechodzi ścieżkę upgrade. **Nie potwierdzają prawdziwości historii autoryzacji ani rzetelności przeglądu danych w rzeczywistej instalacji.** Weryfikacja historii każdej instalacji pozostaje osobną czynnością operacyjną.

## Ograniczenia i dalsze kroki

- Nie sprawdzono baz trwałych ani historii wdrożeń. Nie można stwierdzić, czy 022/023 zostały zastosowane poza disposable testami. Migracji już zastosowanych w trwałym środowisku nie wolno przepisywać; wdrożenie 024 wymaga kontroli historii i spełnienia preconditions.
- Wartość atestacji historycznych nie jest dowodem poprawności danych. Przed migracją istniejącej instalacji należy uzgodnić granty i ledgery ze źródłami audytowymi, udokumentować niezależny przegląd i przerwać wdrożenie przy każdej niespójności.
- Potwierdzone ryzyko F-2 wymaga jawnej akceptacji lub odrębnej, ukierunkowanej kontroli przed twierdzeniem, że bezpośredni runtime SQL nie może ominąć autoryzacji aplikacyjnej dla dziewięciu tabel.
- Raport nie zamyka niezależnej reaudytacji; pełny test PHPUnit nie zastępuje testów adwersarialnych poza scenariuszami wymienionymi powyżej.
- Nie rozpoczęto Stage 2D; `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; VSEF nie aktywowano. Nie wykonano commitów, pushy ani wdrożeń.
