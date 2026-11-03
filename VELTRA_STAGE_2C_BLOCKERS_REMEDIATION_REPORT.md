# VELTRA 2.0 — raport remediacji blokad Stage 2C

**Data:** 3 listopada 2026  
**Zakres:** naprawa precyzji PostgreSQL `NUMERIC` w HALF-EVEN oraz jawne nadawanie nowych permissionów istniejącej organizacji  
**Werdykt remediacji:** **CONDITIONAL ACCEPT** — poprawki i opisane niżej testy przeszły. Nie jest to formalna akceptacja całej Stage 2C; przed nią wymagany jest niezależny audyt bezpieczeństwa.

## Podsumowanie

Usunięto dwie blokady opisane w [raporcie zamknięcia Stage 2C](./VELTRA_STAGE_2C_CLOSURE_REPORT.md). Funkcja zaokrąglania nie odtwarza już wyniku przez dzielenie `numeric`, które obcinało część cyfr u dużych wartości. Dodano także jawną, audytowaną i idempotentną ścieżkę nadania wskazanych permissionów zaufanemu odbiorcy w już zainicjalizowanej organizacji.

Żaden grant nie jest nadawany automatycznie podczas migracji ani uruchamiania aplikacji. `RAW_MATERIALS` nie został aktywowany ani oznaczony jako gotowy. Nie wykonywano migracji na bazie aplikacyjnej, nie zmieniano istniejących kontenerów ani wolumenów aplikacyjnych, nie wdrażano zmian i nie tworzono commitów.

## Blokada 1 — utrata precyzji obliczeń

### Przyczyna

Dotychczasowa funkcja HALF-EVEN wykonywała zaokrąglenie na `numeric`, po czym odtwarzała wynik jako `integral / factor`. Dzielenie `numeric` w PostgreSQL wyznacza skalę wyniku według reguł precyzji cyfr znaczących. Przy dużej części całkowitej skala wyniku spadała do 12 miejsc, zanim funkcja mogła zachować wymagane 14 miejsc. Nie była to utrata w kolumnie bazy ani konwersja PHP do `float`.

Reprodukcja z raportu zamknięcia:

```text
Wejście:  50000000.12345678901234
Wynik:    50000000.12345678901200
```

### Zmiana

Nowa migracja [2026_11_03_000015_precision_and_permission_upgrade.php](./backend/database/migrations/2026_11_03_000015_precision_and_permission_upgrade.php) zastępuje odtwarzanie przez dzielenie dokładnym formatowaniem tekstowej postaci zaokrąglonej części całkowitej. Funkcja zachowuje kontrolę skali 0–14 i dotychczasową regułę HALF-EVEN; do wyznaczenia końcowego zapisu dziesiętnego nie wprowadza `float` ani przedwczesnego zaokrąglenia.

Regresja w [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php) sprawdza duże wartości, ułamki, liczby dodatnie i ujemne, dokładność 14 miejsc oraz przypadki remisowe HALF-EVEN.

## Blokada 2 — permissiony dla istniejącej organizacji

Dodano serwis [RawMaterialPermissionUpgrade.php](./backend/app/Domain/RawMaterials/RawMaterialPermissionUpgrade.php) i interaktywną komendę [UpgradeRawMaterialPermissions.php](./backend/app/Console/Commands/UpgradeRawMaterialPermissions.php):

- Akcja wymaga aktywnego, zweryfikowanego administratora organizacji z aktywnym członkostwem oraz skutecznym `RAW_MATERIALS_PERMISSION_DELEGATE`.
- Administrator ponownie potwierdza hasło i jawnie wybiera innego odbiorcę, dokładny zestaw permissionów, uzasadnienie oraz klucz idempotencji.
- Wskazane permissiony są walidowane względem allowlisty; nadawanie pustego lub nieznanego zakresu jest odrzucane.
- Operacja, granty i append-only event audytowy powstają w jednej transakcji pod blokadą organizacji.
- Identyczne ponowienie zwraca wcześniej zapisany wynik bez nowych grantów; ten sam klucz z innymi parametrami kończy się konfliktem.
- Migracja utrwala operację i jej źródłowe prawo delegowania. Skuteczność grantów z upgrade’u zależy od dalszej skuteczności tego źródła; zawieszenie/odwołanie nie może zostać cofnięte przez reaktywację.
- Nie dodano auto-grantowania przy migracji lub starcie i nie zmieniono bramki ani gotowości `RAW_MATERIALS`.

Zaktualizowano także [architekturę Stage 2C](./VELTRA_STAGE_2C_ARCHITECTURE.md), aby opisać procedurę upgrade’u dla istniejących organizacji.

## Weryfikacja wykonana w tej remediacji

Testy i migracje uruchomiono w dedykowanej, izolowanej sieci Docker na testowej bazie PostgreSQL oraz Redis. Runner backendu używał tymczasowej kopii kodu, a nie montowania danych aplikacyjnych. Dane i baza użyte do sprawdzenia ścieżki upgrade’u były jednorazowe. Środowisko testowe nie zmieniało gotowości modułu.

| Sprawdzenie | Wynik |
|---|---:|
| Skupione testy `RawMaterialsTest.php` i `RawMaterialPermissionBootstrapTest.php` | **26/26 PASS, 296 asercji** |
| Pełna regresja backendu | **173/173 PASS, 1435 asercji** |
| Świeże zastosowanie migracji | **15/15 migracji PASS** |
| PHP syntax — zmienione pliki PHP | **6/6 PASS** |
| Laravel Pint `--test` — zmienione pliki PHP | **6/6 PASS** |
| `git diff --check` | **PASS** |

### Ścieżka aktualizacji istniejącej bazy

W odrębnej, pustej bazie wykonano migracje **001–014** przez względną ścieżkę zawierającą wyłącznie te pliki. Przed migracją 015 utworzono przez modele/usługi aplikacyjne jedną testową, zainicjalizowaną organizację z aktywnymi zweryfikowanymi użytkownikami i dwoma wcześniejszymi grantami. Następnie:

1. Migracja **015** zastosowała się jako jedyna nowa migracja.
2. Oba wcześniejsze granty pozostały w bazie.
3. Autoryzowany upgrade istniejącej organizacji utworzył jeden nowy, wybrany grant, jeden rekord operacji i event audytowy.
4. Ponowienie tego samego żądania zwróciło `replayed=true`, ten sam identyfikator operacji i nie utworzyło dodatkowego grantu.
5. Migracja uruchomiona ponownie zwróciła `Nothing to migrate`.
6. Bezpośrednie sprawdzenie nowej funkcji PostgreSQL dla `50000000.12345678901234` przy skali 14 zwróciło dokładnie `50000000.12345678901234`.

Weryfikacja obejmuje syntetyczną organizację i granty w disposable DB. Nie jest zgodą na migrację istniejącej bazy klienta ani na uruchomienie procedury uprawnień w środowisku użytkowym.

## Zmienione pliki

- [RawMaterialPermissionUpgrade.php](./backend/app/Domain/RawMaterials/RawMaterialPermissionUpgrade.php) — autoryzacja, ponowne uwierzytelnienie, transakcja, idempotencja, audyt i granty.
- [UpgradeRawMaterialPermissions.php](./backend/app/Console/Commands/UpgradeRawMaterialPermissions.php) — interaktywny workflow Artisan, który nie ujawnia hasła.
- [2026_11_03_000015_precision_and_permission_upgrade.php](./backend/database/migrations/2026_11_03_000015_precision_and_permission_upgrade.php) — nowa forward-only migracja: precyzja HALF-EVEN, rejestr operacji i ograniczenia bazy.
- [RawMaterialAuthorization.php](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php) — skuteczność upgrade’owych grantów zależna od źródłowego prawa delegowania.
- [RawMaterialPermissionBootstrapTest.php](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) — autoryzacja, odmowy, audyt, idempotencja, konflikt, niezmienność i trwałe odwołanie.
- [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php) — precyzja PostgreSQL i przypadki HALF-EVEN.
- [VELTRA_STAGE_2C_ARCHITECTURE.md](./VELTRA_STAGE_2C_ARCHITECTURE.md) — procedura jawnego upgrade’u istniejącej organizacji.

## Pozostałe ryzyka i wymagane działania

1. Niezależny audyt bezpieczeństwa zmian jest wymagany przed finalną akceptacją Stage 2C.
2. Weryfikacja zgodności migracji objęła czystą instalację oraz syntetyczny upgrade z 001–014; nie obejmowała rzeczywistej bazy klienta, migracji produkcyjnej ani wdrożenia.
3. Procedura uprawnień jest wrażliwą operacją administracyjną. Przed użyciem należy niezależnie ocenić interaktywny runbook, tożsamości aktora i odbiorcy oraz dokładny zakres permissionów.
4. Brak testu migracji na zachowywanych danych produkcyjnych jest celowy i pozostaje warunkiem operacyjnym, nie wynikiem pominięcia.

**Status końcowy:** blokady objęte tym zadaniem zostały technicznie zaadresowane i przetestowane. Cała Stage 2C pozostaje bez finalnego `ACCEPT` do czasu niezależnego audytu. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne.
