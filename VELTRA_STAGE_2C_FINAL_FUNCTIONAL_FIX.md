# VELTRA 2.0 — Stage 2C: końcowa naprawa błędów regresji funkcjonalnej

**Data:** 4 listopada 2026  
**Zakres:** dwa potwierdzone problemy blokujące trzy testy funkcjonalne  
**Werdykt funkcjonalny:** naprawy i dozwolona regresja zaliczone  
**Werdykt Stage 2C:** bez zmian — nie wydano ACCEPT

## Podsumowanie

Usunięto dwie przyczyny trzech błędów opisanych w
[`VELTRA_STAGE_2C_TEST_INFRASTRUCTURE_REPAIR.md`](./VELTRA_STAGE_2C_TEST_INFRASTRUCTURE_REPAIR.md):

1. Dezaktywacja członkostwa uruchamiała bazowy trigger, który cofa przypisane
   role jako skutek uboczny, lecz walidator `role_assignments` nie rozpoznawał
   podpisanego zakresu `membership.deactivate` ani braku aktora dla tej
   automatycznej operacji.
2. Bootstrap zweryfikowanej tożsamości ustawiał `authorization_initialized`
   przed utworzeniem jej aktywnego przypisania operacyjnego, wymaganego przez
   odroczony constraint PostgreSQL.

Zastosowano forward-only migrację 027 i kontrolę kolejności w usłudze domenowej.
Wszystkie trzy wskazane testy przeszły. Pełny dozwolony zestaw zakończył się
wynikiem **170/170 PASS, 1394 asercje, 0 błędów, 0 ostrzeżeń PHPUnit,
0 pominiętych**. Siedemnaście ograniczonych testów bezpieczeństwa pozostało
wyłączonych.

Nie uruchamiano scenariuszy adwersarialnych ani wcześniej zablokowanych
operacji. Wynik nie jest niezależną akceptacją bezpieczeństwa ani akceptacją
Stage 2C.

## Analiza przyczyn i zmiany

### 1. Dezaktywacja członkostwa i cofanie ról

`MembershipService::deactivate()` podpisuje transakcję zakresem
`membership.deactivate`. Trigger `revoke_membership_authorization()` aktualizuje
powiązane role, ustawiając wyłącznie `revoked_at`; jest to automatyczne,
nieodwracalne cofnięcie wynikające z dezaktywacji członkostwa i nie ma
`revoked_by`.

Sama obecność tego zakresu w ogólnej tabeli uprawnień nie wystarczała:
`validate_role_assignment_ticket()` dopuszczał aktualizację przypisania
członkostwa tylko dla `membership.remove`, z aktorem równym `revoked_by`.
Dlatego wcześniejsza, wstępna korekta zakresu ujawniła dalszy błąd semantyczny
walidatora w dwóch docelowych testach.

Forward-only migracja
[`2026_11_06_000027_authorize_membership_deactivation_role_cleanup.php`](./backend/database/migrations/2026_11_06_000027_authorize_membership_deactivation_role_cleanup.php):

- dodaje wyłącznie zakres `role_assignments / UPDATE /
  membership.deactivate`; istniejący `membership.remove` pozostaje bez zmian;
- zastępuje walidator w sposób zachowujący dotychczasowe sprawdzanie aktora dla
  `membership.remove` i `role.revoke.*`;
- dopuszcza systemowe cofnięcie tylko wtedy, gdy podpis nie ma aktora, `revoked_by`
  pozostaje niezmienione, zmienia się wyłącznie `revoked_at`, a przypisane
  członkostwo jest nieaktywne;
- sprawdza wymagane obiekty i dotychczasowy zakres przed zmianą, wykonuje całość
  atomowo i ustawia zaufany `search_path`.

Nie poszerzono zakresu do `membership.*` i nie osłabiono sprawdzenia aktora
w legalnej ścieżce usuwania członkostwa przez administratora. Migracja jest
forward-only; jej wycofanie ponownie blokowałoby prawidłowy przepływ.

### 2. Bootstrap administratora

Flaga `authorization_initialized` oznacza gotowość autoryzacji, a nie samo
utworzenie roli. Dla zweryfikowanej tożsamości administratora wymagane są
łącznie aktywne konto, rola administratora i aktywne przypisanie do czynnej
lokalizacji. Zgodnie z tą regułą usługa najpierw pobiera aktualny rekord
tożsamości pod blokadą; jeśli tożsamość jest zweryfikowana, lecz nie ma
operacyjnego członkostwa, zgłasza polski błąd domenowy przed utworzeniem roli
i ustawieniem flagi.

Test w [`RoleAdministrationTest.php`](./backend/tests/Feature/RoleAdministrationTest.php)
sprawdza obie strony kontraktu: nieprawidłowa kolejność jest jawnie odrzucana
bez roli i bez ustawienia flagi, a przypisanie członkostwa przed bootstrapem
pozwala zakończyć inicjalizację. Przepływ CLI dla niezweryfikowanej tożsamości
pozostaje bez zmian; flaga nadal jest ustawiana po pomyślnej aktywacji konta.
Odroczony constraint bazy nie został usunięty ani osłabiony.

## Izolacja i konfiguracja

Weryfikację wykonano w odrębnej sieci Docker `internal=true` z PostgreSQL
**17.11** i Redis **7.4.12**. Nie publikowano portów; kontenery bazy i Redis
nie miały wolumenów ani mountów, a dane znajdowały się wyłącznie w `tmpfs`.
Kod backendu w runnerze był zamontowany tylko do odczytu. Sześć istniejących
kontenerów VELTRA, ich bazy i wolumeny nie były podłączane ani modyfikowane.
Użyto wyłącznie syntetycznych, efemerycznych haseł i kluczy.

- Migracje i setup wykonywano jako `veltra_migrator`, który nie był
  superuserem, właścicielem bazy, twórcą ról/baz ani posiadaczem `BYPASSRLS`.
- Testy aplikacyjne połączyły się jako `veltra_app`. Bootstrap testów potwierdzał
  tożsamość runtime oraz rozdzielenie połączenia setup.
- Odczytane uprawnienia `veltra_app`: brak `CREATE` na bazie i schemacie `public`
  oraz brak `TRUNCATE` na tabeli `organizations`.
- Testy `HealthIntegrationTest` w końcowym zestawie używały rzeczywistych
  połączeń PostgreSQL i Redis, sprawdzając health API, Redis cache, kolejkę
  i `PING`.
- PostgreSQL i Redis nie były osiągane przez istniejące usługi aplikacyjne.

## Dowody testowe

### Trzy wskazane testy

Po zastosowaniu finalnej migracji:

| Test | Wynik |
|---|---:|
| `BusinessRoleDefinitionTest::test_multiple_explicit_kitchen_roles_require_matching_operating_context` | PASS |
| `PermissionDelegationTest::test_overlapping_scheduled_delegations_merge_and_revocation_includes_future_grants` | PASS |
| `RoleAdministrationTest::test_admin_grants_require_fresh_password_and_rejections_are_durable` | PASS |
| **Razem** | **3 testy, 26 asercji** |

### Pełna dozwolona regresja

Filtr został sprawdzony przed wykonaniem: PHPUnit wykrył 187 przypadków,
wybrano 170, a 17 ograniczonych testów pozostało poza zestawem.

| Wynik | Liczba |
|---|---:|
| Wykryte | 187 |
| Wyłączone ograniczeniem | 17 |
| Uruchomione i zaliczone | 170 |
| Asercyjne FAIL | 0 |
| Błędy | 0 |
| Ostrzeżenia PHPUnit | 0 |
| Pominięte | 0 |
| Asercje | 1394 |

Końcowy komunikat PHPUnit: `OK (170 tests, 1394 assertions)`.
W logu Laravel pojawił się wpis `testing.ERROR` związany z oczekiwanym
odrzuceniem dostępu do draftu; test przeszedł i nie był to warning ani błąd
PHPUnit.

Pierwsze uruchomienie pełnego zestawu w nowym runnerze miało pięć błędów
konfiguracji: tymczasowy mount `storage` nie zawierał katalogu kompilowanych
widoków. Utworzono wymagane katalogi wyłącznie w `tmpfs` i powtórzono pełny
zestaw; powyższe wyniki dotyczą tego końcowego przebiegu.

### Migracje i role

- Świeża baza PostgreSQL 17: migracje **001–027**, wszystkie 27 zakończone
  powodzeniem.
- Syntetyczny upgrade: zastosowano **001–026**, a następnie migrację **027**;
  przebieg zakończony powodzeniem.
- Po obu ścieżkach weryfikowano wpis migracji 027, oba wąskie zakresy
  `membership.remove` i `membership.deactivate` dla `role_assignments/UPDATE`,
  właściwość `SECURITY DEFINER` i przypięty `search_path`.
- W obu bazach potwierdzono brak `CREATE` dla `veltra_app` na bazie/schemacie
  oraz brak `TRUNCATE`. Redis odpowiadał `PONG`.
- PHP lint zmienionych plików i Pint `--test` dla pięciu plików: PASS.
- `git diff --check`: PASS.

Baza upgrade była syntetyczna i nie zawierała danych instalacji ani danych
historycznych VELTRA. Migracja 027 modyfikuje wyłącznie prywatny rejestr
podpisanych zakresów i funkcję walidującą role; nie przepisuje danych
biznesowych.

## Niewykonane testy bezpieczeństwa

Żaden z poniższych testów nie był uruchamiany:

Klasa `Tests\Feature\RawMaterialPermissionBootstrapTest` — wszystkie 15:

1. `test_migration_role_is_not_superuser_or_database_owner`
2. `test_runtime_cannot_write_to_permission_operation_ledger_directly`
3. `test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table`
4. `test_permission_grant_validators_pin_public_before_temporary_schemas`
5. `test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables`
6. `test_initial_grant_is_explicit_audited_one_time_and_never_self_granted`
7. `test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql`
8. `test_migration_guard_fails_closed_for_an_active_historical_duplicate`
9. `test_bootstrap_rejects_unknown_permissions_and_invalid_passwords`
10. `test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable`
11. `test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql`
12. `test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants`
13. `test_delegation_is_limited_to_effective_authority_and_revocation_is_audited`
14. `test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants`
15. `test_delegated_grants_expire_with_source_permission_and_delegation_authority`

Pozostałe 2:

16. `Tests\Feature\AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
17. `Tests\Feature\RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

## Pozostałe warunki akceptacji

- Niezależny, upoważniony re-audyt bezpieczeństwa pozostaje wymagany; raport
  dokumentuje wyłącznie dozwoloną regresję funkcjonalną.
- Siedemnaście wymienionych testów bezpieczeństwa pozostaje nieweryfikowanych
  i nie wolno wnioskować o ich wyniku z PASS reszty zestawu.
- Nie wydaje się ACCEPT dla Stage 2C. `RAW_MATERIALS` pozostaje `NOT_READY`;
  Stage 2D nie rozpoczęto, VSEF nie aktywowano.
- Nie wykonywano commitów, pushy, wdrożeń ani migracji na istniejących bazach.
