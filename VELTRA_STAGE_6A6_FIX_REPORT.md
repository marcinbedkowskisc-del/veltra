# Stage 6A.6-FIX — BLOCKED

## Cel i wynik

Zweryfikowano możliwość rozszerzenia lokalnego konta
`raw.steward@veltra.test` do pełnego odbioru RAW bez zmiany chronionego
administratora, obejścia RBAC lub nadania szerokich uprawnień systemowych.
Nie dodano grantów, kont ani ról i nie zmieniono kodu. Pełny odbiór RAW,
w tym finansów, pozostaje zablokowany.

Izolację potwierdzono przez `app()->environment() = local`,
`current_database() = veltra_stage6a1`, rolę `veltra_app` i zgodność adresu
serwera bazy z kontenerem PostgreSQL projektu `veltra-stage6a1`.

## Efektywne uprawnienia

W odczycie resolvera opiekun ma:

`RAW_MATERIALS_VIEW`, `RAW_MATERIALS_CREATE`, `RAW_MATERIALS_EDIT`,
`RAW_MATERIALS_SUBMIT`, `RAW_MATERIALS_APPROVE`,
`RAW_MATERIALS_CATEGORY_MANAGE`, `RAW_MATERIALS_ALLERGEN_MANAGE`,
`RAW_MATERIALS_AUDIT_VIEW` oraz `RAW_MATERIALS_PERMISSION_DELEGATE`.

Uprawnienie delegowania istniało przed tym etapem; nie dodano go ani nie
odebrano. Stanowi prawo administrowania grantami, nie jest wymagane do
wykonywania zwykłych operacji RAW i nie zapewnia prawa samonadania.

Brakujące efektywne pozycje rejestru dla pełnego zakresu RAW to:

`RAW_MATERIALS_REVIEW`, `RAW_MATERIALS_BLOCK`, `RAW_MATERIALS_UNBLOCK`,
`RAW_MATERIALS_ARCHIVE`, `RAW_MATERIALS_RESTORE`,
`RAW_MATERIALS_DELETE_DRAFT`, `RAW_MATERIALS_SUPPLIER_VIEW`,
`RAW_MATERIALS_SUPPLIER_MANAGE`, `RAW_MATERIALS_PURCHASING_COSTS_VIEW`,
`RAW_MATERIALS_PURCHASING_COSTS_MANAGE`,
`RAW_MATERIALS_REFERENCE_PRICE_VIEW`,
`RAW_MATERIALS_REFERENCE_PRICE_MANAGE`, `RAW_MATERIALS_EXCHANGE_RATE_VIEW`,
`RAW_MATERIALS_EXCHANGE_RATE_MANAGE`, `RAW_MATERIALS_STORAGE_MANAGE` i
`RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`.

To zestaw wynikający z rejestru, **nie wykonana operacja nadania**. Granty
RAW są organizacyjne, a konkretne operacje nadal wymagają dozwolonego
kontekstu lokalizacji. `DELETE_DRAFT` i `PERMISSION_DELEGATE` nie są zwykle
delegowalne. `REFERENCE_PRICE_MANAGE` wymaga również administratora i
głównej kuchni. Koszt użytkowy wymaga ponadto `FOODCOST_VIEW`, obecnie
nieskutecznego w głównej kuchni. Standardowy grant uprawnień biznesowych
dotyczy restauracji, nie kuchni centralnej; nie nadano innej roli.

## Blokada

Zwykła delegacja wymaga odrębnego odbiorcy, efektywnego grantu delegacji
i efektywnego grantu przekazywanego uprawnienia. Upgrade wymaga odrębnego
odbiorcy oraz administratora z efektywnym, niedelegowanym grantem
`RAW_MATERIALS_PERMISSION_DELEGATE` i potwierdzeniem hasła. Pierwszy
bootstrap był już wykorzystany.

Opiekun ma delegację, ale nie jest administratorem i nie może nadać sobie
brakujących uprawnień. `developer@veltra.test` jest administratorem i może
zarządzać opiekunem, ale nie ma efektywnej delegacji. Nie znaleziono innego
administratora z efektywną delegacją w tej organizacji. Nadanie opiekunowi
roli administratora zwiększyłoby jego uprawnienia poza modułem, nie rozwiązało
zakazu samonadania i nie zostało wykonane. Nowe konto samo w sobie nie
zapewni brakujących uprawnień. Operacja CREATE+APPROVE wymaga administratora;
łącznego EDIT+APPROVE dotąd nie zaimplementowano.

Przed kontynuacją potrzebna jest osobno zatwierdzona, zgodna z RBAC procedura
uzyskania odpowiedniej podstawy delegacji i finansowego dostępu w kontekście
kuchni centralnej oraz decyzja, jak testować operacje wymagające roli
administratora bez przyznawania niepożądanego zakresu władzy. Nie określono
obejścia ani nie zmieniono kontraktu bezpieczeństwa.

## Weryfikacja

- Właściciel zalogował się na konto opiekuna; po odświeżeniu rzeczywista sesja
  i główna lokalizacja pozostały aktywne. Hasła ani cookies nie odczytywano.
- Przez uwierzytelnione API: capabilities 200, `readiness = NOT_READY`,
  lokalna dostępność `true`, CREATE i EDIT dostępne, CREATE+APPROVE
  niedostępne. Katalog zatwierdzonych, workbench i historia zmian: 200.
  Pola ceny i kosztu nie występowały w odpowiedzi katalogu, a historia cen
  zwróciła 403. To potwierdza brak dostępu finansowego, nie pełen odbiór.
- Na tym koncie nie wykonywano zapisów CREATE, EDIT, SUBMIT ani APPROVE
  w tym etapie. Sama obecność grantów nie dowodzi wykonania tych operacji.
- Izolację obcej lokalizacji oraz niezależność uprawnień finansowych
  zweryfikowano testami na PostgreSQL `veltra_testing`, nie przez próbę
  odczytu obcych danych w lokalnej instalacji. Braku dostępu do wszystkich
  innych uprzywilejowanych operacji nie udowodniono.
- Testy aktualnego drzewa: **10 PASS, 121 asercji**, w tym bootstrap,
  upgrade, delegacja i finanse, izolacja lokalizacji oraz administracyjny
  CREATE+APPROVE i wymagane granty. Komenda:

```bash
docker compose -p veltra-stage6a1 --profile testing --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env -f compose.yaml -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-test-services.yaml run --rm --no-deps -v /home/veltra/projekty/veltra/backend/app:/var/www/html/app:ro -v /home/veltra/projekty/veltra/backend/tests:/var/www/html/tests:ro -v /home/veltra/projekty/veltra/backend/routes:/var/www/html/routes:ro backend-test php artisan test --filter='test_initial_grant_is_explicit_audited_one_time_and_never_self_granted|test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable|test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants|test_financial_and_report_permissions_are_independent_and_export_checks_sources|test_manager_cannot_grant_financial_permissions_or_delegate_unavailable_modules|test_stage3e_b1_financial_fields_and_sorts_are_independently_authorized|test_stage3e_b1_foodcost_grant_does_not_grant_reference_prices_or_purchase_prices|test_create_approved_requires_administrator_and_is_atomic_and_idempotent|test_create_approved_requires_each_effective_grant_even_for_administrators|test_requested_foreign_location_cannot_override_the_session_context'
```

- `git diff --check`: PASS. Zrzutów ekranu nie zapisywano.

## Stan i przekazanie

Poza niniejszym raportem nie zmieniono plików. Zachowano wcześniejsze
niezatwierdzone zmiany i raporty wymienione w
[raporcie Stage 6A.6](./VELTRA_STAGE_6A6_REPORT.md). Właściciel zachowuje
zalogowaną sesję opiekuna. Możliwe wpisy audytu wynikają z rzeczywistych
żądań API; grantów i rekordów surowców nie zmieniano. Tymczasowy kontener
testowy usunięto automatycznie; podstawowe usługi lokalne pozostają aktywne.

Adres: http://localhost:5174/. Globalnie `RAW_MATERIALS = NOT_READY`.
Bez resetu bazy, Drive, commitu, pushu i deploymentu.
