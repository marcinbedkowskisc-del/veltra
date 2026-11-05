# VELTRA 2.0 — zamknięcie weryfikacji bezpieczeństwa Stage 2C

**Data:** 5 listopada 2026
**Zakres:** 17 wcześniej wyłączonych testów bezpieczeństwa, migracje 001–027,
status VEL-DEF-001–007
**Tryb:** przegląd dokumentacji, kodu testów i dostępności środowiska; bez
uruchamiania testów lub scenariuszy bezpieczeństwa

## Decyzja i podsumowanie

**Rekomendacja dla akceptacji kodu Stage 2C: NO-GO — nie wydawać ACCEPT na
podstawie dostępnych obecnie dowodów.** Kluczowe gwarancje RBAC, ochrony
ostatniego administratora, granicy podpisanych mutacji oraz ograniczeń roli
runtime pozostają nieweryfikowalne w dozwolonych warunkach tej sesji.
VEL-DEF-007 również pozostaje **NOT VERIFIED**.

Nie uruchomiono żadnego z 17 testów. W bieżącym Dockerze działa sześć
kontenerów VELTRA, ale nie ma dostępnego odrębnego, disposable środowiska
testowego. Są to używane kontenery aplikacyjne, PostgreSQL i Redis; ich
wykorzystanie do prób, czyszczenie, rekonfiguracja lub modyfikacja naruszałyby
ograniczenia. Nie wykonano więc prób SQL, DDL, replay, manipulacji rolą,
ledgerem ani innym zabezpieczeniem.

Najnowszy raport funkcjonalny dokumentuje 170/170 dozwolonych testów PASS,
1394 asercje, migracje 001–027 oraz syntetyczny upgrade 026→027 jako PASS.
Wyniki te nie zastępują dowodu dla wyłączonych testów. Status „NOT VERIFIED”
oznacza brak aktualnego, zaakceptowanego dowodu dla gwarancji; nie oznacza
wyniku FAILED ani potwierdzenia podatności.

## Status ryzyk F-2

Rejestr odroczonych problemów w Google Drive potwierdza formalną decyzję
właściciela z 5 listopada o odroczeniu **VEL-DEF-001–VEL-DEF-006**. Są one
oznaczone jako odroczone decyzją właściciela, a nie jako naprawione.
Odroczenie nie stanowi dowodu skuteczności zabezpieczeń i nie zmienia statusu
pozostałych punktów. Wcześniejsza
[formalna decyzja zamknięcia](./VELTRA_STAGE_2C_FORMAL_CLOSURE_DECISION.md)
zawierała stan sprzed tej zgody; niniejszy raport zastępuje jej ustalenie o
braku formalnej zgody.

**VEL-DEF-007 pozostaje NOT VERIFIED.** Testy funkcjonalne kodów i
współbieżności nie dowodzą, że rzeczywista rola runtime nie może bezpośrednio
cofnąć lub uszkodzić licznika.

Odroczone ryzyka należy otworzyć ponownie przy nowym konkretnym dowodzie
materialnie poważniejszego wpływu, w szczególności obejścia RBAC, zmiany
zatwierdzonych danych, fałszowania chronionej historii, utraty trwałości
cofnięcia uprawnień albo istotnej utraty integralności lub dostępności.
Warunki przeglądu przed wdrożeniem określone w rejestrze pozostają w mocy.

## Rejestr 17 testów

„Dowód równoważny” opisuje wyłącznie dostępny materiał historyczny lub
funkcjonalny; nie zmienia statusu testu ani nie jest wynikiem jego wykonania
w bieżącym snapshotcie 027. Wcześniejsze raporty mają ograniczony zakres
migracji i nie zastępują aktualnej, niezależnej weryfikacji wymaganej przez
rejestr. „Możliwa bezpieczna alternatywa” oznacza ścieżkę do rozważenia w
osobnym, jawnie autoryzowanym i disposable środowisku; nie jest zgodą na
ominięcie blokad.

| # | Test | Gwarancja | Dostępne dowody równoważne | Bezpieczne wykonanie / alternatywa | Status |
|---:|---|---|---|---|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | Rola migracyjna nie jest superuserem, właścicielem ani twórcą baz/ról; kontrolowane uprawnienia setup. | Raport funkcjonalny 027 opisuje rozdzielenie `veltra_migrator`/`veltra_app` i ograniczenia ról, ale nie stanowi niezależnego raportu tej asercji. | Odczyt metadanych byłby nieinwazyjny w disposable bazie; takiej bazy teraz nie ma. | **NOT VERIFIED** |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | Runtime może odczytywać, ale nie dopisywać, zmieniać, usuwać ani opróżniać ledgeru operacji grantów. | Raporty remediacji historycznie opisują ochronę ledgeru; nie ma aktualnego zaakceptowanego dowodu dla wszystkich sprawdzeń i rzeczywistej roli runtime w 027. | Test zawiera próby INSERT/UPDATE/DELETE/TRUNCATE; nie wykonywać bez odrębnej autoryzacji i disposable DB. | **NOT VERIFIED** |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | Rola runtime nie może podpiąć ani bezpośrednio wywołać chronionej funkcji `SECURITY DEFINER`. | Raport remediacji po migracji 021 opisuje historyczny test runtime tej granicy. Nie potwierdza aktualnego przebiegu dla 027. | Tworzenie tymczasowej tabeli/triggera i wywołanie funkcji to objęty ograniczeniem scenariusz; nie wykonywać. | **NOT VERIFIED** |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | Walidatory rozwiązują obiekty z `public` przed `pg_temp`; ochrona przed shadowingiem. | Raporty historyczne po migracji 021 dokumentują ustawienia `search_path`; brak zatwierdzonego potwierdzenia aktualnego zestawu funkcji po całym łańcuchu do 027. | Odczyt konfiguracji funkcji mógłby być nieinwazyjny w odizolowanej bazie; baza nie jest dostępna. | **NOT VERIFIED** |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | Tymczasowe relacje nie mogą podmienić obiektów walidacyjnych ani sfałszować wyniku bootstrapu. | Raport po migracji 021 opisuje osobny historyczny test runtime (1 test, 6 asercji); dowód nie obejmuje aktualnego snapshotu 027. | Test tworzy obiekty tymczasowe i bada próbę ich wykorzystania; nie wykonywać w istniejących kontenerach. | **NOT VERIFIED** |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | Bootstrap jest jawny, audytowany, jednorazowy i nie pozwala na self-grant. | Istnieją starsze wyniki testów bootstrapu i poprawnych przepływów; brak aktualnej, zaakceptowanej weryfikacji tej gwarancji dla 027. | Pozytywny przepływ aplikacyjny można byłoby badać w disposable bazie; test obejmuje gwarancje bezpieczeństwa, więc nie uruchamiać teraz. | **NOT VERIFIED** |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | Cofniętego grantu nie można przywrócić przez ponowne użycie identyfikatora bootstrapu; audyt i cofnięcie pozostają trwałe. | Wcześniejsze raporty opisują remediacje replay; brak aktualnego dowodu tej odmowy na 027. | Bezpośredni INSERT/replay SQL jest niedozwolonym scenariuszem; nie uruchamiać ani nie obchodzić blokady. | **NOT VERIFIED** |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | Strażnik migracji odrzuca aktywne historyczne duplikaty, zamiast cicho migrować niespójny stan. | Starsze materiały opisują test strażnika; aktualny dowód migracji 001–027 nie obejmuje tej wstrzykniętej anomalii. | Test wyłącza triggery, wstawia duplikat i uruchamia walidator; wprost nie wykonywać. | **NOT VERIFIED** |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | Nieznane uprawnienia i nieprawidłowe potwierdzenie hasłem są odrzucane bez utworzenia grantów. | Ogólne wyniki regresji nie raportują tej asercji; brak zaakceptowanego, aktualnego dowodu dla 027. | Walidacja wejścia aplikacji byłaby nieinwazyjna tylko w izolowanym środowisku testowym; nie jest ono dostępne. | **NOT VERIFIED** |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | Upgrade istniejącej organizacji jest jawny i idempotentny, a cofnięte uprawnienie nie wraca. | Raporty historyczne opisują ścieżki upgrade’u i cofania; nie dowodzą całej gwarancji na bieżącym kodzie 027. | Wymaga syntetycznej organizacji i testu trwałego cofnięcia; nie używać działającej bazy aplikacji. | **NOT VERIFIED** |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | Cofnięcie authority propaguje się przez zagnieżdżone upgrade’y w aplikacji i PostgreSQL. | Starsze raporty zawierają wyniki ścieżek upgrade’u; późniejszy przegląd historyczny opisał lukę propagacji multi-hop. Nie są równoważnym dowodem obecnej gwarancji. | Wymaga dedykowanego, autoryzowanego scenariusza bezpieczeństwa; nie wykonywać teraz. | **NOT VERIFIED** |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | Administrator bez skutecznego prawa delegacji jest odrzucany; fałszywy grant SQL nie omija walidacji. | Zwykłe testy RBAC nie wykazują odmowy dla dokładnego scenariusza ani ochrony przed fałszywym SQL grantem. | Test łączy próbę eskalacji z bezpośrednim SQL; nie wykonywać. | **NOT VERIFIED** |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | Delegacja nie przekracza skutecznej authority, a cofnięcie ma wymagany ślad audytowy. | Raport funkcjonalny potwierdza poprawne zwykłe przepływy delegacji, ale nie niezależną granicę authority i audytu tego testu. | Bezpieczny przepływ biznesowy wymaga disposable DB; negatywna część należy do nieweryfikowanej klasy bezpieczeństwa. | **NOT VERIFIED** |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | Ponowna aktywacja członkostwa lub użytkownika nie przywraca cofniętych grantów. | Istnieją dowody funkcjonalne dla zmian członkostwa i cofania uprawnień, ale brak raportu potwierdzającego dokładną kombinację na 027. | Weryfikacja workflowu jest możliwa tylko na syntetycznych danych w disposable środowisku. | **NOT VERIFIED** |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | Granty delegowane tracą skuteczność po wygaśnięciu źródła lub authority delegowania. | Regresja ogólna nie podaje wyniku tego testu ani równoważnego sprawdzenia czasu i propagacji. | Test wymaga czasu/danych syntetycznych; bez disposable DB i jawnego runnera nie uruchamiać. | **NOT VERIFIED** |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | Runtime nie może samodzielnie zapisywać krytycznego RBAC/audytu, podszywać podpisu ani zmieniać chronionych struktur; legalne ścieżki Laravel pozostają dostępne. | Regresja 170 testów potwierdza dozwolone ścieżki funkcjonalne, ale ten test obejmuje DML/DDL, `SET ROLE`, tabelę tymczasową i fałszywy ticket; brak dowodu równoważnego dla odmów runtime w 027. | Test zawiera jawnie ograniczone próby SQL/DDL i eskalacji; nie wykonywać ani nie rozdzielać w celu obejścia blokady. | **NOT VERIFIED** |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | Ostatni administrator nie może zostać cofnięty, dezaktywowany ani usunięty przez obejście SQL; ochrona jest atomowa. | Regresja obejmuje inne przepływy administracyjne, lecz raport nie podaje równoważnego testu ochrony ostatniego administratora ani odmów DB. | Część aplikacyjna mogłaby być osobnym nieadwersarialnym testem, ale obecny przypadek zawiera próby SQL; nie uruchamiać bez odrębnej autoryzacji i bezpiecznego planu. | **NOT VERIFIED** |

### Klasy przyczyn wyłączenia

- Testy 2, 3, 5, 7, 8, 12, 16 i 17 obejmują bezpośrednie próby odmów SQL/DDL,
  shadowing, replay, manipulację triggerem/ledgerem, `SET ROLE` lub obejście
  ochrony. Nie są uruchamiane ani przekształcane w celu ominięcia wcześniejszych
  blokad.
- Testy 1, 4, 6, 9, 10, 11, 13, 14 i 15 dotyczą ról, bootstrapu, delegacji,
  migracji i propagacji cofnięć. Nie wykonano ich, ponieważ bieżące środowisko
  nie jest autoryzowanym runnerem disposable, a poprzedni filtr wyłączył tę
  klasę bezpieczeństwa.
- Żaden z 17 testów nie ma statusu FAILED w bieżącym przebiegu, ponieważ nie
  został uruchomiony. Nie ma też podstaw, by nadać status VERIFIED wyłącznie
  na podstawie historycznych, węższych raportów lub regresji funkcjonalnej.

## Blokery i dalsza weryfikacja

1. **Bloker dla ACCEPT kodu Stage 2C:** aktualny, niezależny dowód dla
   krytycznych gwarancji RBAC/ostatniego administratora, podpisanych mutacji,
   chronionego ledgeru i ograniczeń rzeczywistej roli runtime. Zestaw testowy
   musi zostać wykonany wyłącznie przez uprawnionego recenzenta, według
   jawnie dozwolonych procedur i w osobnym disposable środowisku. Ten raport
   nie autoryzuje zablokowanych scenariuszy ani nie określa sposobu ich
   obejścia.
2. **VEL-DEF-007:** pozostaje NOT VERIFIED; przed uznaniem za rozstrzygnięty
   wymagany jest dozwolony dowód niezdolności roli runtime do regresji lub
   uszkodzenia licznika.
3. **VEL-DEF-001–006:** odroczone formalną decyzją właściciela; nie są
   ponownie otwierane tym raportem. Odroczenie nie zmienia ich w naprawione
   mechanizmy ani dowody testowe.
4. **Gotowość produkcyjna:** odrębna, niepotwierdzona bramka obejmująca
   rzeczywiste role/granty, dane historyczne, konfigurację i sekrety, sieć,
   kopie zapasowe, odtwarzanie i procedurę wdrożenia. Wynik syntetycznej
   migracji nie jest dowodem gotowości konkretnej instalacji.

RAW_MATERIALS pozostaje **NOT_READY** i nieaktywne. VSEF nie jest aktywowane;
Stage 2D nie jest rozpoczynany.

## Źródła

- [Końcowa naprawa regresji funkcjonalnej po migracji 027](./VELTRA_STAGE_2C_FINAL_FUNCTIONAL_FIX.md)
- [Testy bootstrapu uprawnień RAW_MATERIALS](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php)
- [Test granicy podpisanych mutacji](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php)
- [Test administracji rolami i ochrony ostatniego administratora](./backend/tests/Feature/RoleAdministrationTest.php)
- [Rejestr odroczonych problemów i decyzja właściciela](https://docs.google.com/document/d/1uU-hjzyb4DOr3ZOz6bs89gCjgBTR43Rp4-ZIS58Ps0g/edit)
