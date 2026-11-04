# VELTRA 2.0 — końcowy re-audyt bezpieczeństwa RBAC-1 Stage 2C

**Data:** 3 listopada 2026  
**Zakres:** niezależna weryfikacja poprawki rekurencyjnego cofania authority grantów, trwałości revocation, enforcementu PostgreSQL i migracji 016  
**Werdykt RBAC-1: REJECT**

## Podsumowanie

Poprawka multi-hop w migracji 016 działa w sprawdzonym scenariuszu: cofnięcie nadrzędnego authority grant unieważnia granty zależne, a bezpośrednia próba SQL utworzenia kolejnego upgrade'u opartego na cofniętym authority jest odrzucana.

Audyt ujawnił jednak odrębny, powiązany z trwałością cofnięcia bypass. PostgreSQL pozwala ponownie użyć niezmiennego `PERMISSION_BOOTSTRAP` operation ID do wstawienia nowego grantu dla wcześniej cofniętego permissionu. Nowy grant staje się skuteczny bez nowego eventu audytowego. Wymaga to SQL-writera mogącego wstawiać do tabeli grantów i znajomości prawidłowego bootstrap operation ID. Ponieważ narusza to trwałe cofnięcie uprawnień i ochronę triggerami przed bezpośrednim SQL, RBAC-1 nie może otrzymać `ACCEPT`.

Nie zmieniano kodu aplikacji ani konfiguracji. Testy/migracje wykonano wyłącznie w jednorazowych zasobach PostgreSQL 17 i Redis 7.4. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Nie wykonywano migracji live, deploymentu, commitów ani Stage 2D.

## Ustalenia

| # | Ważność | Status | Lokalizacja | Dowód | Pewność |
|---|---|---|---|---|---:|
| 1 | MEDIUM | Poprawka potwierdzona | [migracja 016](./backend/database/migrations/2026_11_03_000016_enforce_recursive_permission_upgrade_authority.php#L11), [RawMaterialAuthorization](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php#L47) | Rekurencyjne przejście po operacjach authority sprawdza skuteczność każdego authority grantu w łańcuchu. Test pre-016 odtwarza błąd; po 016 dalsze granty tracą skuteczność, a bezpośredni SQL z cofniętym authority zostaje odrzucony. | 10/10 |
| 2 | MEDIUM | **Otwarte — blokuje akceptację** | [migracja 016](./backend/database/migrations/2026_11_03_000016_enforce_recursive_permission_upgrade_authority.php#L37), [migracja 015](./backend/database/migrations/2026_11_03_000015_precision_and_permission_upgrade.php#L297) | Brak pasującego rekordu upgrade-operation jest traktowany jako skuteczny bootstrap. Trigger migracji 015 dopuszcza nowy grant oparty o istniejący event bootstrap, nie zużywa ani nie unieważnia tego poświadczenia po revocation. Ponowiony grant odzyskuje skuteczność i nie tworzy nowego eventu audytowego. | 10/10 |

### Ustalenie 1 — poprawka multi-hop

Niezależna reprodukcja została wykonana na bazie z migracjami 001–015, bez migracji 016. Test regresyjny zakończył się oczekiwanym błędem: po cofnięciu grantu źródłowego dalszy grant nadal był skuteczny. Po zastosowaniu 016 ten sam test przeszedł.

Test potwierdził też, że:

- delegowany administrator traci efektywne prawo `RAW_MATERIALS_PERMISSION_DELEGATE` po cofnięciu nadrzędnego źródła;
- dalszy grant `RAW_MATERIALS_SUPPLIER_VIEW` przestaje być efektywny;
- funkcja PostgreSQL zwraca `false` dla operacji zależnej;
- ponowny upgrade przez aplikację zostaje odrzucony;
- bezpośrednia próba SQL utworzenia grantu z operacją upgrade opartą na cofniętym authority kończy się SQLSTATE `23514`.

Rekurencyjne zapytanie używa `UNION`, więc przetwarzanie deduplikuje powtórzone pary operacji/organizacji i kończy się również przy cyklicznym grafie. W sprawdzonym modelu rekordy operacji i grantów są append-only, a kolejne operacje wskazują istniejący grant authority; nie stwierdzono odrębnego sposobu utworzenia cyklu ani osobnego problemu współbieżności w poprawionym łańcuchu.

### Ustalenie 2 — replay bootstrapu omija trwałe cofnięcie

Po utworzeniu legalnych grantów bootstrapowych i odwołaniu `RAW_MATERIALS_VIEW`:

1. Uprawnienie odbiorcy nie było skuteczne.
2. Wykonano bezpośredni SQL `INSERT` nowego wiersza `organization_permission_grants`, używając tego samego `bootstrap_operation_id` co historyczny event `PERMISSION_BOOTSTRAP`.
3. PostgreSQL zaakceptował wiersz; nowe ID grantu nie miało własnego eventu `REVOKED`.
4. Uprawnienie ponownie stało się skuteczne.
5. Liczba historycznych eventów audytowych nie zmieniła się — insert grantu nie wygenerował odrębnego zdarzenia.

Warunki i ograniczenia: wymagany jest dostęp SQL z prawem wstawiania grantów, znajomość poprawnego bootstrap operation ID oraz zgodność organizacji, aktora, odbiorcy i permissionu z historycznym eventem. Odbiorca nadal musi spełniać zwykłe warunki skuteczności grantu, m.in. aktywność, weryfikację adresu e-mail, aktywne członkostwo/lokalizację i ważny okres obowiązywania. Sam wspierany serwis bootstrapu nie dopuszcza ponownego bootstrapu; luka występuje na granicy PostgreSQL/direct-SQL, którą testy i triggery mają chronić.

Historyczny event pozostaje niezmienny, ale może zostać ponownie wykorzystany jako uzasadnienie nowego grantu po revocation. To osłabia zarówno semantykę trwałego cofnięcia, jak i spójność audytu.

## Ocena wymagań weryfikacyjnych

| Obszar | Wynik |
|---|---|
| Pierwotna reprodukcja multi-hop pre-016 | **Potwierdzona** na disposable DB: dalszy grant pozostał skuteczny po cofnięciu nadrzędnego authority. |
| Multi-hop po 016 | **PASS**: granty zależne tracą skuteczność; bezpośredni SQL z revoked authority jest odrzucony. |
| PostgreSQL enforcement niezależnie od PHP | **FAIL dla pełnej gwarancji revocation**: replay starego `PERMISSION_BOOTSTRAP` SQL-em przywraca grant. |
| Historyczna integralność audytu | Append-only zachowane, lecz bootstrap event można ponownie wykorzystać; test potwierdził brak nowego eventu dla przywróconego grantu. |
| Reaktywacja i standardowy replay upgrade'u | Testy aplikacyjne potwierdzają trwałe cofnięcie dla sprawdzonych ścieżek i idempotencję upgrade'u. Nie rozwiązują one SQL-owego replay bootstrapu. |
| Cykle i współbieżność | `UNION` deduplikuje rekurencję. Nie znaleziono odrębnego błędu cyklu; testy współbieżności Stage 2C/Stage 2B przeszły. Nie wykonano równoległego wyścigu HTTP upgrade kontra revocation w tej rundzie. |
| Upgrade 001–015 → 016 | **PASS** na disposable DB z istniejącą organizacją i dwoma wcześniejszymi grantami; po migracji oba granty zachowały skuteczność. Powtórne `migrate` zwróciło `Nothing to migrate`. |

## Testy wykonane niezależnie w tym re-audycie

Zasoby testowe działały w dedykowanej sieci Docker `internal=true`. PostgreSQL i Redis używały wyłącznie `tmpfs`, nie miały trwałych mountów ani opublikowanych portów. Backend uruchomiono z tymczasowej kopii. Istniejące bazy, kontenery i wolumeny aplikacyjne nie były używane.

| Test | Wynik |
|---|---:|
| Regresja multi-hop na migracjach 001–015 | **1 test nie przeszedł, 6 asercji** — oczekiwany błąd reprodukujący lukę przed 016 |
| Ten sam test po zastosowaniu 016 | **1 test, 11 asercji — PASS** |
| Próba SQL replay bootstrapu po revocation | **1 test, 4 asercje — exploit potwierdzony**; nowy grant skuteczny, bez nowego eventu audytowego |
| Skupione testy Stage 2C/RBAC/concurrency | **29/29 testów, 331 asercji — PASS** |
| Pełna regresja backendu | **174/174 testy, 1446 asercji — PASS** |
| Upgrade istniejącej bazy z migracji 001–015 do 016 z dwoma wcześniejszymi grantami | **PASS**; zachowano 2/2 granty, oba pozostały skuteczne |
| Powtórne `migrate` po zastosowaniu 016 | **PASS — Nothing to migrate** |

Wynik testu replay jest wynikiem pozytywnym dla reprodukcji luki, a nie potwierdzeniem bezpiecznego zachowania.

## Wyniki historyczne — oddzielone

Poprzedni raport [końcowego audytu akceptacyjnego Stage 2C](./VELTRA_STAGE_2C_FINAL_ACCEPTANCE_AUDIT.md) zawierał wyniki z wcześniejszej rundy, w tym 26 testów skupionych, 173 testy pełnej regresji oraz odtworzenie multi-hop przed poprawką. Nie są one doliczane do wyników powyżej. Bieżące testy wykazały 29/331 oraz 174/1446, a także osobno odtworzyły pierwotny problem i ujawniły replay bootstrapu.

## Wniosek i wymagane działanie

**REJECT — poprawka multi-hop działa, ale gwarancja trwałego revocation nadal nie jest kompletna.** Przed akceptacją należy uniemożliwić ponowne wykorzystanie bootstrap operation ID dla permissionu, którego grant już istniał — także po revocation — oraz dodać regresję direct-SQL sprawdzającą, że historyczny bootstrap nie może odtworzyć cofniętego prawa ani dopisać grantu bez nowego audytowalnego zdarzenia. Wymagana jest niezależna weryfikacja forward-only migracji i testów.

Do czasu ponownej akceptacji nie należy traktować granicy direct-SQL dla grantów bootstrapowych jako gwarancji trwałego cofnięcia. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; Stage 2D pozostaje poza zakresem.
