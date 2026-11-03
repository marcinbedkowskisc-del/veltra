# VELTRA 2.0 — Raport remediacji bezpieczeństwa Stage 2B

## Podsumowanie

Zremediowano trzy ustalenia z niezależnego audytu Stage 2B: nieskuteczne unieważnianie delegacji po utracie authority grantora, ujawnianie danych szkiców i rewizji oczekujących oraz brak możliwości poprawiania uprawnionych szkiców. Zmiany ograniczono do logiki domenowej, migracji zabezpieczeń PostgreSQL i testów regresyjnych.

Moduł `RAW_MATERIALS` pozostaje produkcyjnie `NOT_READY` i nie został aktywowany. Nie uruchomiono migracji na bazie aplikacyjnej, nie wdrażano zmian, nie zmieniano istniejących wolumenów, nie commitowano ani nie pushowano. Rekomendacja wdrożeniowa pozostaje wstrzymana do czasu niezależnego ponownego audytu.

## Ustalenie 1 — HIGH: delegowane uprawnienia po utracie authority grantora

### Przyczyna

Zapytanie ustalające efektywne granty sprawdzało ważność i odwołanie rekordów, ale nie sprawdzało bieżącego statusu organizacji, użytkowników i ich członkostw w całym łańcuchu delegacji. Ponadto operacja odwołania wymagała, aby aktor był pierwotnym grantorem i nadal miał tę samą permission, co blokowało audytowane awaryjne cofnięcie.

### Zmiana

- `RawMaterialAuthorization` wylicza efektywne granty wyłącznie wtedy, gdy organizacja, użytkownicy, weryfikacja adresu e-mail i członkostwa/lokalizacje są aktywne. Potomny grant wymaga efektywnego grantu źródłowego oraz aktualnego, nieodwołanego, bezpośredniego grantu `RAW_MATERIALS_PERMISSION_DELEGATE` grantora.
- Zmiana jest sprawdzana przy każdym żądaniu; nie opiera się na utrwalonym cache autoryzacji.
- `OrganizationRawMaterialPermissionAdministration::revoke` dopuszcza audytowane cofnięcie grantu w tej samej organizacji przez użytkownika z efektywnym prawem delegowania, także gdy pierwotny grantor utracił authority. Rekordy grantów i zdarzenia pozostają append-only.
- Trigger walidujący granty w migracji PostgreSQL egzekwuje aktualną aktywność i authority także przy bezpośrednim zapisie SQL.

### Dowody regresyjne

Testy sprawdzają dezaktywację grantora przy istniejącej sesji, utratę i ponowne nadanie członkostwa, wygaśnięcie źródłowego permission i authority delegowania, próbę bezpośredniego SQL przez nieaktywnego grantora, odmowę delegacji poza zakresem oraz audytowane cofnięcie źródłowego i delegowanego grantu.

## Ustalenie 2 — MEDIUM: ujawnianie szkiców i oczekujących rewizji

### Przyczyna

Ścieżki odczytu listy i szczegółów nie odgradzały konsekwentnie aktualnej rewizji roboczej od ostatniej zatwierdzonej wersji. Ogólne prawo odczytu mogło przez listę, filtry lub odgadnięty identyfikator ujawnić dane niezatwierdzone.

### Zmiana

- Lista, wyszukiwanie, filtry i paginacja opierają się wyłącznie na `approved_revision_id`; materiały bez zatwierdzonej rewizji nie uczestniczą w wynikach ani licznikach.
- Szczegóły zwracają zatwierdzony snapshot. Szkic bez zatwierdzonej wersji jest ukryty przed zwykłym czytelnikiem; odczyt danych roboczych wymaga dodatkowego uprawnienia `EDIT` albo `AUDIT_VIEW`.
- Dostęp do historii rewizji jest sprawdzany oddzielnie. Nieznane i niedostępne identyfikatory nie ujawniają metadanych.
- Wyszukiwanie naprawiono również w zakresie przechwytywania filtra w domknięciu zapytania.

### Dowody regresyjne

Test API obejmuje szkic bez zatwierdzonej rewizji, oczekującą rewizję, listę, wyszukiwanie, filtry, paginację, odgadnięty UUID, historię rewizji i zachowanie zatwierdzonego snapshotu.

## Ustalenie 3 — MEDIUM: brak możliwości edycji szkicu

### Przyczyna

Walidacja edycji odrzucała materiał mający już `working_revision_id`, przez co utworzony szkic nie mógł przejść do kolejnej rewizji roboczej.

### Zmiana

- Uprawniona edycja szkicu i zwróconej do poprawek rewizji tworzy nową niezmienną rewizję; nie nadpisuje wcześniejszej.
- Edycja aktywnego materiału tworzy rewizję oczekującą, zachowuje zatwierdzony snapshot i wymaga ponownego zatwierdzenia przed zastąpieniem zatwierdzonych danych.
- Nie zmieniono granic organizacji, autoryzacji ani walidacji cyklu życia.

### Dowody regresyjne

Testy przechodzą pełny przepływ tworzenia i edycji szkicu, zwrotu do poprawek, zachowania historycznych rewizji oraz oczekiwania na ponowne zatwierdzenie zmian aktywnego materiału.

## Zmienione pliki

| Plik | Zakres |
|---|---|
| `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php` | Bieżąca efektywność grantów i walidacja całego łańcucha |
| `backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php` | Audytowane odwołanie grantów przy utracie authority pierwotnego grantora |
| `backend/app/Domain/RawMaterials/RawMaterialService.php` | Izolacja zatwierdzonych odczytów i tworzenie rewizji podczas edycji |
| `backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php` | Ochrona grantów egzekwowana przez PostgreSQL |
| `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | Regresje delegacji, wygaśnięć, dezaktywacji, SQL i audytu |
| `backend/tests/Feature/RawMaterialsTest.php` | Regresje poufności szkiców, edycji i niezmienności |

## Weryfikacja

Testy uruchomiono w dedykowanej sieci Docker `internal=true` z tymczasowym PostgreSQL 17 i Redis 7.4. Oba kontenery miały puste listy mountów; PostgreSQL używał wyłącznie tymczasowego storage, Redis — `tmpfs`. Migracje zostały zastosowane wyłącznie do bazy testowej `veltra_testing` użytkownika `stage2b_test`. Nie użyto istniejących baz ani wolumenów aplikacyjnych.

| Sprawdzenie | Wynik |
|---|---:|
| Testy ukierunkowane: `RawMaterialsTest`, `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest` | **16 testów, 139 asercji — PASS** |
| Pełny backend PHPUnit | **161 testów, 1254 asercje — PASS** |
| PHP syntax — sześć zmienionych plików PHP | **PASS** |
| Pint — sześć zmienionych plików | **PASS** |
| `git diff --check` | **PASS** |
| Izolowany Redis | `PONG` |
| Izolacja Docker | sieć `internal=true`, PostgreSQL i Redis bez mountów |

W logach testów pojawiają się oczekiwane odpowiedzi 404 dla prób odczytu ukrytych lub nieistniejących zasobów; nie są to błędy testów. Nie zmieniano frontendu ani współdzielonych kontraktów wymagających testów frontendowych.

## Ograniczenia i pozostałe ryzyka

- Testy nie stanowią niezależnego potwierdzenia bezpieczeństwa. Wymagany jest odrębny re-audyt przed uznaniem Stage 2B za zaakceptowanym lub wdrożeniem.
- Granty zachowują historyczne rekordy i są oceniane dynamicznie. Jeżeli konto lub członkostwo zostanie ponownie aktywowane, nieodwołany i niewygasły grant może ponownie stać się efektywny. Trwałe kaskadowe cofnięcie przy każdym czasowym zawieszeniu nie zostało dodane jako odrębna polityka biznesowa; re-audyt powinien potwierdzić oczekiwane zachowanie po przywróceniu dostępu.
- Zmieniono istniejącą definicję migracji Stage 2B; zastosowano ją wyłącznie na świeżej, jednorazowej bazie testowej. Nie wykonano migracji w środowisku aplikacyjnym.
- Moduł nadal pozostaje `NOT_READY`; nie uruchomiono Stage 2C ani wdrożenia.

## Rekomendacja

**Remediacja techniczna: gotowa do niezależnego re-audytu. Stage 2B: nie uznawać za zaakceptowany ani gotowy do wdrożenia do czasu pozytywnego niezależnego ponownego audytu.**
