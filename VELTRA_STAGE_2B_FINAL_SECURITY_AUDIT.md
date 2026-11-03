# VELTRA 2.0 — Końcowy niezależny audyt bezpieczeństwa Stage 2B

## Werdykt

**ACCEPT — zaakceptować zabezpieczenia Stage 2B.**

W odrębnym, tylko do odczytu przeglądzie potwierdzono, że trzy ustalenia poprzedniego audytu są zaadresowane, a nowa polityka trwale unieważnia dotknięte granty po zawieszeniu konta lub członkostwa. Ocenę bezpieczeństwa należy odróżnić od zgody operacyjnej: raport **nie** zatwierdza wdrożenia ani aktywacji modułu. `RAW_MATERIALS` musi pozostać `NOT_READY` i nieaktywny do czasu osobnej autoryzacji.

## Zakres i niezależność

Audyt obejmował zatwierdzoną architekturę Stage 2A, poprzedni audyt Stage 2B, raporty implementacji/remediacji oraz aktualny kod, migracje i testy. Niezależnie przejrzano ścieżki RBAC, autoryzację, listę/szczegóły/revisions, edycję, triggery PostgreSQL i testy.

Audytor bezpieczeństwa nie uruchamiał testów ani migracji. Wyniki wykonania niżej pochodzą z odrębnej fazy implementacji przeprowadzonej przez wykonawcę na potwierdzonych zasobach disposable; nie są przedstawiane jako testy wykonane osobiście przez audytora.

## Weryfikacja pierwotnych ustaleń

| Ustalenie | Ocena końcowa | Dowody |
|---|---|---|
| **HIGH — delegacje po utracie authority grantora** | **Zaadresowane** | Migracja `2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension` unieważnia granty użytkownika i zależne granty potomne przy przejściu konta lub członkostwa z aktywnego do nieaktywnego. Zdarzenia `REVOKED` są append-only i trwale wykluczają stare granty po reaktywacji. Wspólna blokada organizacji oraz ponowna kontrola w transakcji serializują zmianę statusu z nadawaniem. |
| **MEDIUM — ujawnienie szkiców i pending revisions** | **Zaadresowane** | Lista i wyszukiwanie opierają się na zatwierdzonych rewizjach; niezatwierdzone szczegóły i historia rewizji podlegają osobnej kontroli `EDIT`/`AUDIT_VIEW`. Test obejmuje listę, filtry, wyszukiwanie, szczegóły, odgadnięte identyfikatory oraz pending revisions. |
| **MEDIUM — edycja szkiców niedostępna** | **Zaadresowane** | Edycja tworzy kolejną niezmienną rewizję zamiast nadpisywać istniejącą; zatwierdzony snapshot pozostaje odrębny. Regresje sprawdzają edycję i zachowanie historii. |

## Weryfikacja końcowej polityki RBAC

- Dezaktywacja użytkownika i członkostwa generuje zdarzenia odwołania w tej samej transakcji co zmiana statusu. Rekordy grantów, zdarzenia odwołania i audyt nie są usuwane ani modyfikowane.
- Rekurencyjne unieważnianie obejmuje wszystkie granty zawieszanego użytkownika i granty zależne przez `delegation_source_id`; reaktywacja nie usuwa zdarzenia `REVOKED`.
- Po ponownym aktywowaniu członkostwa test wymaga jawnego nadania nowego grantu, innego identyfikatora oraz wpisu `PERMISSION_GRANTED`. Cofnięcie takiego grantu pozostaje skuteczne.
- Testy sprawdzają dezaktywację grantora, członkostwa odbiorcy, reaktywację, próbę direct SQL, niedozwolone delegowanie, jawne odwołanie oraz wycofanie transakcji bez pozostawienia zdarzeń.
- Procesowa próba nadania, która czeka na blokadzie organizacji podczas zawieszania członkostwa, zostaje odrzucona po zatwierdzeniu zawieszenia. Osobny proces zawiesza konto pod tą samą blokadą; grant nie odzyskuje skuteczności po reaktywacji.
- Izolacja organizacji, brak obejścia przez role lokalizacyjne i fail-closed gating modułu są sprawdzane przez istniejące testy API/RBAC. Moduł nie został włączony produkcyjnie.
- Niezmienność revisions i append-only audit jest egzekwowana triggerami PostgreSQL i pokryta próbami niedozwolonych bezpośrednich mutacji.

## Wyniki wykonania testów i narzędzi

Poniższe wyniki wykonano poza audytem, po zakończeniu implementacji, na disposable PostgreSQL **17.11** (`veltra_testing`, `stage2b_test`) i Redis **7.4.12**. Sieć Docker miała `internal=true`; kontenery PostgreSQL i Redis nie miały mountów, PostgreSQL używał wyłącznie `tmpfs`. Migracje `000001`–`000012` zastosowano tylko na tej nowej bazie. Sprawdzono aktywność obu nowych triggerów i nullable `actor_user_id`.

| Weryfikacja | Wynik |
|---|---:|
| Testy ukierunkowane: `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest`, `IdentityMembershipTest`, `RawMaterialsTest` | **29 testów, 238 asercji — PASS** |
| Pełny backend PHPUnit | **163 testy, 1289 asercji — PASS** |
| Osobny test procesowy wyścigu nadania i zawieszenia | **1 test, 15 asercji — PASS** |
| PHP syntax i Pint dla zmienionych plików | **PASS** |
| `git diff --check` | **PASS** |
| Izolowany Redis | **PONG** |

Testy zawierają między innymi próbę bezpośredniego wstawienia delegowanego grantu przez nieaktywnego grantora; PostgreSQL odrzuca ją kodem `23514`. Test rollbacku potwierdza, że nieudana transakcja nie pozostawia częściowego zawieszenia ani zdarzeń. Audytor nie wykonywał tych poleceń samodzielnie.

## Uwagi i ryzyka operacyjne

1. **Automatyczne zdarzenia bez aktora osobowego:** `actor_user_id = NULL` jest właściwe dla triggera systemowego i unika przypisania działania niewłaściwemu grantorowi. Przyczyna, źródło zawieszenia i dotknięty użytkownik pozostają w zdarzeniach. Nadal nie wskazuje to konkretnej osoby inicjującej zawieszenie; powiązany dziennik administracyjny powinien zachować tę atrybucję, jeśli ścieżka aplikacyjna ją rejestruje. Nie uznano tego za podatność.
2. **Rollback migracji:** `down()` świadomie odmawia ustawienia `actor_user_id NOT NULL`, jeżeli istnieje systemowe zdarzenie bez aktora. Po takim zdarzeniu migracja jest operacyjnie forward-only bez odrębnej procedury zachowania historii. Nie uznano tego za podatność; wdrożenie powinno mieć plan kopii/odtworzenia i forward-fix.
3. **Zakres wdrożenia:** testy nie stanowią zgody na migrację live ani aktywację. Nie zmieniano istniejących baz/wolumenów, nie wdrażano aplikacji i nie rozpoczynano Stage 2C.

## Referencje

- `VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md`
- `VELTRA_STAGE_2A_FINAL_ARCHITECTURE_REVIEW.md`
- `VELTRA_STAGE_2B_SECURITY_AUDIT.md`
- `VELTRA_STAGE_2B_SECURITY_REMEDIATION_REPORT.md`
- `VELTRA_STAGE_2B_FINAL_RBAC_FIX_REPORT.md`
- `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`
- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php`
- `backend/app/Domain/Identity/IdentityService.php`
- `backend/app/Domain/Identity/MembershipService.php`
- `backend/database/migrations/2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension.php`
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php`
- `backend/tests/Feature/RawMaterialConcurrencyTest.php`
- `backend/tests/Feature/RawMaterialsTest.php`

## Konkluzja

**ACCEPT dla zabezpieczeń Stage 2B, z zastrzeżeniami operacyjnymi opisanymi wyżej.** Ustalenia HIGH/MEDIUM nie są obecnie potwierdzone jako nadal występujące. Moduł pozostaje `NOT_READY`; wdrożenie i aktywacja wymagają osobnej zgody.
