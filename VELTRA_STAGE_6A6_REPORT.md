# Stage 6A.6 — BLOCKED

## Cel i wynik

Przygotowanie konta Super Administratora do pełnego odbioru RAW i finansów
w izolowanej instalacji lokalnej. Istniejące konto `developer@veltra.test`
jest aktywne, zweryfikowane i spełnia warunki Super Administratora.
Nie utworzono konta ani nie zmieniono hasła, grantów lub kodu RBAC.

Konto nie ma efektywnych grantów RAW. Pierwsze nadanie uprawnień jest
jednorazowe i wymaga innego odbiorcy; dalsze procedury również zabraniają
samonadania. Inny użytkownik nie może zarządzać chronioną tożsamością
początkową, a baza dodatkowo chroni jej uprawnienia. Nie ma więc dostępnej
standardowej ścieżki nadania jej grantów RAW bez zmiany kontraktu bezpieczeństwa.
Ominięcie zabezpieczeń SQL lub utworzenie innej tożsamości nie jest rozwiązaniem.

Cena referencyjna wymaga `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; koszt użytkowy
wymaga dodatkowo skutecznego `FOODCOST_VIEW`. `BusinessAuthorization::allows`
przyznaje administratorowi uprawnienia biznesowe po sprawdzeniu kontekstu i
dostępności modułu; nie zastępuje to wymaganego grantu ceny RAW. Nie
zweryfikowano praktycznego dostępu do cen ani kosztów na tym koncie.

## Weryfikacja

- Właściciel potwierdził rzeczywiste zalogowanie, zachowanie sesji po
  odświeżeniu i wylogowanie. Potwierdził również brak dostępu do Surowców.
  Nie odczytywano hasła ani cookies.
- Frontend `http://localhost:5174/`: HTTP 200. Backend, frontend, PostgreSQL,
  Redis i Mailpit są uruchomione i zdrowe; kolejka działa.
- Capabilities bez sesji: HTTP 401.
- Testy na bieżącym drzewie w izolowanej bazie `veltra_testing`: **8 PASS,
  92 asercje**. Obejmują logowanie i wylogowanie, CSRF, brak sesji,
  niepowtarzalny bootstrap bez samonadania, ochronę Super Administratora,
  ograniczenia administratora i upgrade oraz odrzucenie fałszywego grantu.
- `git diff --check`: PASS.
- Nie przetestowano na tym koncie katalogu, szkiców, cen, historii cen ani
  operacji administracyjnych: brak skutecznych grantów RAW.

Dokładna komenda testów:

```bash
docker compose -p veltra-stage6a1 --profile testing --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env -f compose.yaml -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-test-services.yaml run --rm --no-deps -v /home/veltra/projekty/veltra/backend/app:/var/www/html/app:ro -v /home/veltra/projekty/veltra/backend/tests:/var/www/html/tests:ro -v /home/veltra/projekty/veltra/backend/routes:/var/www/html/routes:ro backend-test php artisan test --filter='test_initial_identity_is_the_only_super_admin|test_administrators_cannot_modify_super_admin|test_initial_grant_is_explicit_audited_one_time_and_never_self_granted|test_ordinary_administrator_cannot_bootstrap_permissions_for_another_administrator|test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants|test_email_password_login_regenerates_session_and_logout_invalidates_it|test_csrf_is_enforced_with_the_real_middleware_for_login_selection_and_logout|test_capabilities_requires_valid_session_and_operating_context_and_is_read_only'
```

## Stan i przekazanie

Poza niniejszym raportem nie zmieniono plików. Zachowano wcześniejsze
niezatwierdzone zmiany oraz dane i historię. Stan `git status --short` przed
dodaniem raportu:

```text
 M README.md
 M backend/app/Domain/RawMaterials/RawMaterialService.php
 M backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php
 M backend/app/Http/Controllers/Api/V1/RawMaterialController.php
 M backend/app/Http/Requests/RawMaterialAllergenDeclarationRequest.php
 M backend/app/Http/Requests/RawMaterialDataRequest.php
 M backend/routes/api.php
 M backend/tests/Feature/RawMaterialsTest.php
 M frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx
 M frontend/src/rawMaterials/RawMaterialCreateForm.tsx
 M frontend/src/rawMaterials/RawMaterialEditForm.test.tsx
 M frontend/src/rawMaterials/RawMaterialEditForm.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx
 M frontend/src/rawMaterials/api.test.ts
 M frontend/src/rawMaterials/api.ts
 M frontend/src/rawMaterials/catalog.test.ts
 M frontend/src/rawMaterials/catalog.ts
 M frontend/src/visualPreviewApi.ts
?? VELTRA_STAGE_6A4_LOGIN_REPAIR_REPORT.md
?? VELTRA_STAGE_6A5_REPORT.md
?? backend/app/Http/Requests/RawMaterialCreateApprovedRequest.php
?? backend/tests/Concerns/RawFormWorkflowTests.php
?? frontend/src/rawMaterials/AllergenFields.tsx
```

Kontener testowy usunięto automatycznie po wykonaniu testów; istniejące usługi
lokalne pozostawiono uruchomione. Brak zrzutów ekranu, operacji na Drive,
commitu, pushu i deploymentu. Globalnie `RAW_MATERIALS = NOT_READY`.

Przed pełnym odbiorem konieczna jest osobna decyzja właściciela o zgodnej
z architekturą, audytowanej procedurze nadawania wymaganych grantów RAW
chronionej tożsamości początkowej; sam status Super Administratora nie
upoważnia do obejścia istniejących kontroli. Osobno pozostaje wcześniejsze
ograniczenie Stage 6A.5: łączne zatwierdzanie edycji nie jest zaimplementowane.
