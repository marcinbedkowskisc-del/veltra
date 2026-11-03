# VELTRA 2.0 — końcowy niezależny audyt bezpieczeństwa Stage 2C

**Data:** 3 listopada 2026
**Zakres:** niezależny przegląd architektury i implementacji Stage 2C, regresji po remediacji oraz zabezpieczeń Stage 2B.
**Werdykt:** **CONDITIONAL ACCEPT** — nie potwierdziłem pozostającej podatności o ważności MEDIUM lub wyższej. Dwa wcześniejsze ustalenia MEDIUM i problem LOW dotyczący rewizji po archiwizacji są naprawione; przed wdrożeniem wymagane są opisane niżej ograniczenia operacyjne i niezależne bramki wydania.

## Zakres i metoda

Przeczytano dokumentację Stage 2C: architekturę, jej przegląd, raport implementacji, pierwotny audyt bezpieczeństwa, ponowny audyt po remediacji i raport końcowej poprawki. Sprawdzono także końcowy audyt Stage 2B. Kod oceniono niezależnie od deklaracji w raportach, w szczególności serwis komercyjny, migracje 000012–000014, routing, gating modułu, autoryzację i regresje.

Testy uruchomiono wyłącznie w jednorazowych zasobach:

- PostgreSQL 17 i Redis 7.4 w prywatnej sieci Docker `internal=true`;
- PostgreSQL i Redis używały `tmpfs`, bez mountów, wolumenów trwałych i opublikowanych portów;
- runner backendu nie miał mountów;
- istniejące usługi i ich wolumeny aplikacyjne były w innej sieci i nie zostały użyte;
- sekwencja wszystkich 14 migracji została wykonana na czystej, disposable bazie.

Nie zmieniano kodu aplikacji ani istniejących konfiguracji. Nie wykonano migracji w istniejącej bazie aplikacji, deploymentu, commitów ani zmian statusu modułu.

## Ustalenia

| # | Ważność | Status | Dowody i ocena |
|---|---|---|---|
| 1 | MEDIUM | Naprawione | Odczyt ceny referencyjnej bez `PURCHASING_COSTS_VIEW` nie zwraca już źródłowej kwoty ani waluty; serializer ukrywa `source`. Test sprawdza widok bez prawa i z prawem kosztowym. [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:703), [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php:605) |
| 2 | MEDIUM | Naprawione | Tworzenie ofert, zdarzeń cenowych i wybór źródła ceny referencyjnej ponownie weryfikują wymagane stany lifecycle oraz kwalifikację materiału/produktu/dostawcy. Kontrole są obecne także w triggerach PostgreSQL. Testy API i SQL odrzucają operacje na zablokowanym materiale oraz użycie zarchiwizowanego dostawcy jako nowego źródła ceny. [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:329), [migracja 000013](./backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php:589), [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php:643) |
| 3 | LOW | Naprawione | `reviseProduct()` sprawdza aktywność dostawcy w organizacji produktu; nowa migracja 000014 rozszerza funkcję triggera tak, by INSERT odrzucał archiwalny produkt lub dostawcę. Regresja potwierdza pozytywną rewizję aktywnego produktu oraz odmowę API i bezpośredniego SQL dla obu stanów archiwalnych. [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:260), [migracja 000014](./backend/database/migrations/2026_11_03_000014_enforce_active_supplier_product_revisions.php:10), [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php:730) |
| 4 | LOW | Pozostaje — niezawodność | Synchronizacja NBP wykonuje żądanie HTTP przed sprawdzeniem klucza idempotencji w `mutate()`. Ponowienie wcześniej udanej operacji przy niedostępności NBP może zwrócić 502, mimo zapisanego wyniku. Nie stwierdzono ponownego zapisu, obejścia autoryzacji ani naruszenia niezmienności; ograniczenie dotyczy dostępności odpowiedzi przy retry. [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:566) |
| 5 | LOW | Pozostaje — operacje bazą | `migrate:fresh` nie wywołuje `down()` dla wcześniejszych migracji. Po usunięciu tabel zostaje `serialize_installation_mutation()`, a ponowna migracja 000001 próbuje utworzyć ją ponownie i kończy się `SQLSTATE 42723`. Błąd został odtworzony w oddzielnej, disposable bazie `migrate_repro`; funkcja nadal istniała po nieudanym resecie. [migracja 000001](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php:70), [down() migracji 000001](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php:164) |

### Ocena pozostałych kontroli

- **RBAC i separacja cen:** osobne uprawnienia dla katalogu, informacji dostawcy, kosztów zakupu, cen referencyjnych i FX; brak wycieku źródła ceny w ścieżce bez prawa kosztowego. Zwykłe endpointy katalogowe nie zwracają kwot zakupowych.
- **Tenant isolation:** odczyty i mutacje są zakresowane po `organization_id`; relacje bazodanowe używają kluczy tenantowych. Nie można było wykonać dynamicznego testu dwóch rzeczywistych organizacji: zatwierdzony model instalacji ma constraint pojedynczej organizacji. Jest to ograniczenie dowodu, a nie potwierdzone obejście izolacji.
- **Lifecycle i gating:** ścieżki nowych operacji finansowych odrzucają niekwalifikujące się encje. Trasy Stage 2C korzystają z middleware modułu. Definicja `RAW_MATERIALS` pozostaje `ready=false`, a brak gotowości lub aktywacji jest rozstrzygany fail-closed. Moduł nie był aktywowany w istniejącym środowisku.
- **Niezmienność i transakcje:** rewizje, zdarzenia cenowe/FX, migawki, restatements, audyt i rejestr idempotencji są chronione przed UPDATE/DELETE przez triggery. Mutacja, wpis audytu i odpowiedź idempotencyjna pozostają w tej samej transakcji; test rollbacku po błędzie audytu przechodzi.
- **FX i HALF-EVEN:** przegląd i testy potwierdzają wybór kursu „as of”, kontrolowane override, niezmienne snapshots oraz zaokrąglenie HALF-EVEN dla remisów dodatnich i ujemnych. Nie wykonano żądań do zewnętrznego NBP; scenariusze upstream używają kontrolowanych odpowiedzi testowych.
- **Stage 2B:** trwałe odwołanie grantów przy dezaktywacji użytkownika/członkostwa obejmuje granty zależne, a reaktywacja nie przywraca dawnych uprawnień. Testy regresyjne i współbieżności dla tej polityki przechodzą.
- **Migracja 000014:** jest celowo forward-only — `down()` odmawia usunięcia ochrony lifecycle. Przed wdrożeniem należy zatwierdzić strategię rollbacku opartą na migracji naprzód/odtworzeniu kopii; nie należy zakładać, że zwykłe cofnięcie tej migracji jest dostępne.

## Wyniki testów wykonanych w tej rundzie

Poniższe wyniki zostały uzyskane podczas tego audytu, a nie przepisane z raportów implementacji:

| Test | Wynik |
|---|---:|
| Skupione testy Stage 2B/2C — `RawMaterialsTest.php`, `RawMaterialPermissionBootstrapTest.php`, `RawMaterialConcurrencyTest.php` | **25 testów, 277 asercji — PASS** |
| Pełny backend | **170 testów, 1392 asercje — PASS** |
| Migracje na czystym PostgreSQL 17 | **14/14 — PASS** |
| Regresja rewizji aktywnej/archiwizowanej przez API i bezpośredni SQL | Włączona w testach skupionych; **PASS** |
| Odtworzenie problemu `migrate:fresh` | **FAIL oczekiwany**: `SQLSTATE 42723`, duplikat `serialize_installation_mutation()` w migracji 000001 |

### Interpretacja `migrate:fresh` i wymóg wdrożeniowy

Problem dotyczy resetu środowiska przez `migrate:fresh`, a nie standardowej migracji naprzód. W tej rundzie wszystkie migracje, w tym 000014, wykonały się poprawnie na czystej bazie; odtworzenie 42723 wykonano wyłącznie na osobnej, disposable bazie. Nie jest wymagana poprawka aplikacyjna przed wdrożeniem **pod warunkiem**, że proces wydania uruchamia zwykłe `php artisan migrate --force`, nie `migrate:fresh`, oraz weryfikuje zastosowanie migracji 000014. Nie wolno używać `migrate:fresh` na bazie aplikacyjnej ani na danych, które mają zostać zachowane. Usunięcie problemu resetu może być osobną poprawką narzędziową.

## Wyniki wcześniejszych raportów — odrębne od tej rundy

Pierwotny audyt Stage 2C raportował 166 testów i 1356 asercji oraz dwa problemy MEDIUM. Raport implementacji także przedstawiał własne wyniki testów. Nie są one dodawane do wyników niezależnie uzyskanych powyżej; stanowią historię projektu, a nie dowód z tej rundy.

## Ograniczenia i ryzyka przed wydaniem

1. Nie udowodniono dynamicznie izolacji dwóch tenantów z powodu zatwierdzonego constraintu jednej organizacji na instalację.
2. Nie wykonano równoległych żądań HTTP do rzeczywistego serwera aplikacji; testy współbieżności obejmują warstwę aplikacji/DB w runnerze.
3. Awaria upstream NBP może blokować odpowiedź przy ponowieniu wcześniej zakończonej synchronizacji mimo klucza idempotencji.
4. Migracja 000014 wymaga forward-only planu operacyjnego i potwierdzenia zastosowania przed ewentualną gotowością lub aktywacją modułu.
5. Należy sprawdzić, że wydanie nie użyje `migrate:fresh`; samo 42723 nie wymaga poprawki przed normalną migracją naprzód.

## Werdykt

**CONDITIONAL ACCEPT.** W zakresie sprawdzonym w tej rundzie obie pierwotne podatności MEDIUM oraz LOW dotyczące archiwizacji produktu/dostawcy są naprawione w PHP i PostgreSQL. Nie potwierdzono pozostającej podatności o ważności MEDIUM lub wyższej. Akceptacja wydania pozostaje warunkowa ze względu na niezawodność retry NBP, ograniczenie testu wielotenantowego oraz forward-only charakter migracji 000014.

`RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Raport nie jest zgodą na wdrożenie, uruchomienie migracji na istniejącej bazie ani rozpoczęcie Stage 2D.
